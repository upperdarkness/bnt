# Alignment, Federation police, NPCs and the LLM agent interface

This document describes what shipped, how to run it, and where the implementation interprets the spec
(`BNT Spec: Alignment, NPCs and LLM Agent Interface`). Everything here is off the critical path of
ordinary play: each phase has its own switch and the game stays fully playable with all of them off.

| Phase | Switch (config key / env) | Gate to enter |
| --- | --- | --- |
| 1. Alignment, FedSpace rules, Wanted, bounties, fines | `alignment.enabled` / `ALIGNMENT_ENABLED` | migration applied, unit tests green |
| 2. Extended API + rate limiting | `api.extended_enabled`, `api.rate_limit_enabled` | API integration tests green |
| 3. NPC framework + scripted behaviour | `npc.enabled` / `NPC_ENABLED` | `scripts/npc_simulate.php` clean (tick < 200 ms, no violations) |
| 4. LLM worker | `npc.llm_enabled` / `NPC_LLM_ENABLED` (default **false**) + the admin kill switch | mock-server tests green; injection suite passes for every enabled model |
| 5. Pilot | budgets in `npc.*` | 7-day pilot under `npc.global_daily_budget_usd` |

Phases 1-3 cost nothing to run. LLM spend only starts in phase 4.

## Installing

```bash
psql -v ON_ERROR_STOP=1 --single-transaction -f database/migrations/add_alignment_npcs.sql
php scripts/create_universe.php 1000 200      # new universes put sector 1 + neighbours in Federation space (zone 2)
```

The migration is idempotent. It also moves sector 1 and its neighbours into zone 2 and flags that zone
`is_federation = true` on existing universes, and folds the legacy `bounty` table into the new `bounties` table.

## Alignment

* Integer -10,000 .. +10,000 per ship (the spec's assumption; an escape pod keeps it). Tiers come from
  `alignment.tiers` in config; the numbers in the spec are only the shipped defaults.
* Every change goes through `AlignmentService::apply()`, which writes an `alignment_log` row (delta, new value,
  reason, related ship). A simulation invariant checks that the log always sums to the ship's current value.
* Only tiers are visible to other players (sector lists, rankings, profiles, combat logs, API). The exact
  number appears only to the owner (`/alignment`, `GET /api/v1/game/alignment`) and in the admin panel.
* Daily drift (`alignment_drift`, every 24 h): 1% toward zero, minimum step 1, never overshooting.
  **NPC accounts are exempt** (`alignment.drift_npcs = false`) so that a Police NPC does not slowly stop being police.
* Trading: +1 per 10,000 credits traded, capped at +20 per calendar day (remainders carry between trades).

### Interpretation notes

* **NPC kill rows replace, not add to, the generic "destroy" row.** Destroying a Xenobe NPC gives +100 (not
  +150 +100); destroying a Guild/Police/Free NPC that is Neutral or better costs -300 (not -250 -300).
  The attack row (+50 / -100) still applies. All values are in `alignment.deltas`.
* Defences that destroy a ship penalise the defence owner (-100 and -250) only if the victim was Neutral or
  better at the moment of the kill, and never for team-mates.
* "Same message as today's safe-zone block": blocked FedSpace attacks return the existing text
  *"Combat is not allowed in starbase sectors"* (`AlignmentRules::SAFE_ZONE_MESSAGE`).
* The starbase surcharge/discount applies to what a ship **pays** at a starbase (commodities, equipment,
  devices, upgrades). Selling is unaffected. Pirates are refused all starbase services.

## FedSpace, Wanted, bounties, police

* FedSpace = any sector whose zone has `is_federation = true`. Starbase sectors forbid all combat for everyone,
  including police. One decision table (`AlignmentRules::combatDecision`) covers ships and planets and is used by
  the web UI, REST API and NPCs alike (`CombatService`).
* Sector defences (mines, fighters) only act in FedSpace against Outlaws/Pirates and only if their owner is
  Neutral or better; Outlaws and Pirates cannot deploy in FedSpace.
* Wanted: set by a FedSpace offence (7 days from the last offence) or automatically while a Pirate. A Federation
  bounty (1,000 credits per 100 points below 0) is placed when Wanted is set, resized as alignment changes, and
  removed when Wanted ends. Galactic News announces it when first set.
* Fines: pay at a starbase (`/port`, `POST /api/v1/game/fine`). Restores alignment to -99 and clears Wanted. The
  amount scales with depth (100 credits per point below 0, minimum 10,000). Pirates cannot pay.
* Player bounties: `/alignment` page or `POST /api/v1/game/bounty`; paid from the IGB balance, minimum 10,000,
  non-refundable, never by NPCs. **There is one payout path:** `BountyService::claim()` (called by
  `Combat::collectBounty`). Only Neutral-or-better killers can claim, never on a team-mate; Paragons get 125%.
* Police: the `police_dispatch` task assigns idle police to Wanted ships (one per 500 points below 0, min 1, cap 5),
  nearest first. For human offenders it spawns temporary police if none are idle (they stand down in FedSpace once
  free). Pursuit follows `ships.last_known_sector`, refreshed by scans and whenever a Wanted ship and police share
  a sector. Police never pursue into a starbase sector and return home after 48 hours without contact.

## NPCs

* NPCs are ordinary `ships` rows with `is_npc = TRUE` plus an `npc_profiles` row. They act through the same
  service methods the API uses (`MovementService`, `TradeService`, `CombatService`), so they cannot bypass a rule.
* Accounts are created by `scripts/npc_spawn.php` or by the `npc_population` task. Email `<slug>@npc.invalid`,
  unusable password hash, no web or API login (`Ship::authenticate` rejects NPCs). Names carry the faction tag:
  `[Xenobe] Kraal Vesh`.
* **Respawn keeps the same ship row**, not a new one: a destroyed NPC is marked dead, waits the cooldown (6 h), then
  its ship is reset to the faction loadout. Persona, notebook and API token carry over, so an LLM NPC remembers who
  killed it and the worker's token file never changes.
* **Explicit NPC advantages** (all visible on the admin NPC page): faction `turn_multiplier` (default 1.0), free
  repair/restock in the faction's home zone (`npc.repair_at_home`), a Federation tax that caps NPC wealth
  (`npc.credit_cap_multiplier` x loadout credits, so successful traders don't compound without bound), and a trading skill for Guild (20) and Free
  Captains (10) - without a skill the default price model leaves no margin to trade on.
* NPCs never attack their own faction (the combat service refuses and raises an anomaly alert), and Raiders pace
  themselves with `npc.raider_attack_cooldown_min` (30). Raiders hunt ships, not planets.
* Scripted tick: `npc_scripted_tick` runs at most 25 NPCs and 200 ms per run (least recently run first) and never
  starts an action with less than `scripted_tick_margin_ms` left. Pathfinding is BFS over a cached graph rebuilt
  every 10 minutes, depth 20.
* Fallback: an LLM NPC runs scripted when `npc.llm_enabled` is off, its daily or the global budget is spent, three
  OpenRouter calls in a row failed, or the worker's heartbeat is older than 15 minutes. The worker retries a
  failing NPC after a 30-minute cool-down.

## The LLM worker

```bash
# 1. Create LLM-controlled NPCs and their tokens (tokens live only in the 0600 token file; the DB stores hashes)
php scripts/npc_spawn.php --faction=free --count=2 --controller=llm --model=provider/model-id --token-file=/etc/bnt/npc_tokens.json
# 2. Configure the worker (environment only, never the repository)
sudo install -m 600 docs/systemd/bnt-npc-agent.env.example /etc/bnt/npc-agent.env   # then edit
sudo cp docs/systemd/bnt-npc-agent.service /etc/systemd/system/ && sudo systemctl enable --now bnt-npc-agent
# 3. Turn it on: set npc.llm_enabled (config/env) or use "Enable LLM control" on /admin/npcs/global
```

Cron alternative (no systemd): `* * * * * php /path/bin/npc-agent.php --once` (a lock file prevents overlap).

* The worker reads wake queue, budgets and the audit log from PostgreSQL directly, but plays **only** through the
  public REST API as each NPC. The game server never calls an LLM.
* Wake conditions: regular interval (10 min, needs >= 10 turns), queued events (debounced to one per 5 min), or an
  admin "Wake now". One NPC per loop pass with a 1 s stagger.
* Per-wake limits: `npc.max_steps` (8), `npc.wake_budget_usd` ($0.25), plus per-NPC and global daily USD caps.
  OpenRouter's reported `usage.cost` is used; if absent, a conservative `npc.price_per_million` estimate applies.
* Tools (13, schema-validated before any call): `go_to`, `scan`, `find_trade`, `trade`, `attack_ship`,
  `deploy_defences`, `land`, `leave`, `planet_transfer`, `buy_upgrade`, `send_message`, `update_notebook`,
  `end_turn`. There is no tool that moves credits to another player, touches the IGB, or manages teams.
* Prompts live in `config/npc_prompts/` (rules digest, persona, untrusted-data rule, output contract).
* Everything is in `npc_action_log` (observation, every model call with tokens and cost, every tool call with
  arguments and result); admins can replay any wake from the NPC page. Retention: `npc.log_retention_days` (30).

### Security model

Injection is treated as certain; the controls make it harmless rather than rely on the model:

| Threat | Control in this implementation |
| --- | --- |
| Asset theft | No money-moving tools; the server validates every action |
| Behaviour hijack | Player text only inside `<<< >>>` (delimiter characters stripped, length-limited); persona goals restated every wake; same-faction attacks refused + alert |
| Prompt leak | `send_message` blocked by prompt-fragment filter in the worker and again on the server |
| Spam via NPC | 280 chars, 5/hour, recipients must be in sight or have messaged within 24 h, profanity filter, all stored |
| Cost exhaustion | Event wake debounce, per-wake / per-NPC / global caps, kill switch |
| Key leak | Key only in the worker environment; `.env` untracked and ignored; no token or key is ever logged |
| NPC token misuse | NPC tokens honoured only from `npc.worker_ips` (default loopback), 30-day expiry, `npc_spawn.php --rotate-tokens` |

**Repository history:** `.env` has been removed from the index and is in `.gitignore`. It only ever held default local
database settings, but if you ever stored real values in it, rewrite history (`git filter-repo --path .env
--invert-paths`) and rotate those credentials; that is intentionally not done automatically.

## Testing

```bash
export PATH=/opt/homebrew/opt/postgresql@15/bin:$PATH PGHOST=localhost PGPORT=5432 PGUSER=postgres   # as needed
php tests/run.php                 # unit + database + API integration + worker (mock OpenRouter) tests
php tests/run.php AlignmentRules  # run one class
php scripts/npc_simulate.php --days=7 --sectors=1000    # simulation harness (creates and drops its own database)
INJECTION_LIVE=1 OPENROUTER_API_KEY=... INJECTION_MODELS=a/b,c/d php tests/run.php InjectionLive   # costs money
```

Database tests create a throw-away database per class; they are skipped (not failed) without PostgreSQL tools.

## Monitoring and operations

* `/admin/npcs`, `/admin/npcs/global` (kill switch, budgets, heartbeat, error rate, alerts), `/admin/players/:id/alignment`.
* Alerts (admin page, plus email when `npc.alert_email` is set): stale heartbeat (> 15 min), OpenRouter error rate
  > 20% over an hour, global spend >= 80% of its cap, same-faction attack attempts, an NPC token the API rejects
  (expired, rotated or wrong source IP), and an LLM NPC with no model configured.
* Set a spending limit on the OpenRouter key itself as the backstop.

## Decisions on the spec's open questions

| Question | Implemented default |
| --- | --- |
| Existing bounty payout? | Yes (`Combat::collectBounty`). Federation and player bounties now share that one path. |
| Alignment per ship or account? | Per ship. |
| Reveal LLM NPCs? | Hidden. Profiles show "NPC" + faction only. |
| `go_to` NPC-only? | Yes in v1 (`/agent/go_to`). |
| NPC turn multipliers? | Supported per faction (`turn_multiplier`), default 1.0, shown to admins. |
| Models / spend? | Not chosen: `npc.default_model` is empty until an admin sets it; budgets default to $1/NPC/day, $10/day global. |
| Can Raiders capture player planets? | No - scripted Raiders only attack ships and lay mines. |

## Contraband (Void Relics)

A rare, very valuable, illegal trade good. Off by default (`contraband.enabled` / `CONTRABAND_ENABLED`).

* **Where:** only at black-market sectors (`universe.is_blackmarket`, about 7 per 1,000 sectors, never FedSpace or
  starbases). `create_universe.php` marks them; for an existing universe run `php scripts/mark_blackmarkets.php`.
  Apply `database/migrations/add_contraband.sql` first. Ordinary ports never deal in it.
* **Price:** about 1,000 credits a unit (roughly 40x goods), rising up to +50% as a market's stock runs low, with a
  10% spread. Stock caps at 200 per market and recovers 1% of the gap per port-production cycle, so buying where it
  is plentiful and selling where it is scarce is the profitable loop. A ship may carry at most 50, and it uses hold space.
* **Alignment:** every buy or sell action costs 200 (`alignment.deltas.contraband_trade`, logged as
  `contraband_buy` / `contraband_sell`). It never earns the +1 per 10,000 credits trading bonus.
* **Risk:** arriving in FedSpace carrying it costs 500 and sets Wanted (once per arrival); arriving at a starbase gets
  it confiscated plus a fine (`contraband.starbase_fine`, never more than the credits you have). If you are destroyed,
  the killer salvages what fits in their hold (up to the carry cap) and the rest is lost.
* **Players and NPCs:** same endpoint as other trade (`commodity: "contraband"`), the web port page shows a black-market
  panel with a warning. Xenobe Raiders smuggle between markets (never through FedSpace or starbases, one run about every
  two hours); Guild and Police never touch it. LLM NPCs see the market and their cargo in the observation, and the
  rules digest tells them it is illegal.
* **Admin:** the sector editor (`/admin/universe/sector/:id`) has a Black Market checkbox and a stock field. It refuses
  starbases and Federation zones, caps stock at `contraband.stock_limit`, and switches a port-less sector to the `special`
  port type so the port page opens.
* **Not included:** no planet storage, and no sale to ordinary ports.
