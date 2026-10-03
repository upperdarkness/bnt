# BlackNova Traders API Documentation

## Overview

The BlackNova Traders API provides a RESTful interface for accessing game data and performing game actions. The API uses token-based authentication and returns JSON responses.

**Base URL:** `https://yourdomain.com/api/v1`

## Authentication

All API endpoints (except login and register) require authentication using a Bearer token.

### Getting an API Token

1. **Login** - POST `/api/v1/auth/login`
2. **Register** - POST `/api/v1/auth/register`

Both endpoints return a token that should be included in subsequent requests.

### Using the Token

Include the token in the `Authorization` header:
```
Authorization: Bearer <your_token_here>
```

Alternatively, you can use the `X-API-Token` header:
```
X-API-Token: <your_token_here>
```

Tokens expire after 90 days. You can generate a new token by logging in again.

---

## Endpoints

### Authentication

#### POST `/api/v1/auth/login`

Login and receive an API token.

**Request Body:**
```json
{
  "email": "player@example.com",
  "password": "your_password"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "expires_at": "2024-12-31 23:59:59",
    "ship": {
      "ship_id": 1,
      "character_name": "Player Name",
      "sector": 1,
      "credits": 1000,
      "turns": 1200,
      ...
    }
  }
}
```

**Error Responses:**
- `401` - Invalid credentials
- `403` - Ship destroyed (no escape pod)
- `422` - Validation error

---

#### POST `/api/v1/auth/register`

Register a new account and receive an API token.

**Request Body:**
```json
{
  "email": "newplayer@example.com",
  "password": "secure_password",
  "character_name": "New Player",
  "ship_type": "balanced"
}
```

**Ship Types:**
- `scout` - Fast, efficient, small cargo
- `merchant` - Large cargo, slow, weak combat
- `warship` - Strong combat, expensive, small cargo
- `balanced` - Average in all aspects (default)

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "token": "abc123...",
    "expires_at": "2024-12-31 23:59:59",
    "ship": { ... }
  }
}
```

**Error Responses:**
- `409` - Email or character name already exists
- `422` - Validation error

---

#### POST `/api/v1/auth/logout`

Revoke the current API token.

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

#### GET `/api/v1/auth/me`

Get current authenticated user information.

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ship": {
      "ship_id": 1,
      "character_name": "Player Name",
      "sector": 1,
      "credits": 1000,
      "turns": 1200,
      ...
    }
  }
}
```

---

### Game Actions

#### GET `/api/v1/game/main`

Get main game screen data (current sector, links, planets, ships).

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ship": { ... },
    "sector": {
      "sector_id": 1,
      "sector_name": "Alpha",
      "port_type": "ore",
      ...
    },
    "links": [2, 3, 5],
    "planets": [
      {
        "planet_id": 1,
        "planet_name": "Earth",
        "owner": 0,
        ...
      }
    ],
    "ships_in_sector": [
      {
        "ship_id": 2,
        "character_name": "Other Player",
        ...
      }
    ],
    "holds": {
      "max": 100,
      "used": 50,
      "available": 50
    },
    "is_starbase_sector": false
  }
}
```

---

#### POST `/api/v1/game/move/:sector`

Move to a new sector.

**Headers:**
- `Authorization: Bearer <token>`

**URL Parameters:**
- `sector` - Destination sector ID

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ship": { ... },
    "movement": {
      "sector": 2,
      "turns_used": 1,
      "mine_result": null,
      "fighter_result": null
    }
  }
}
```

**If mines are hit:**
```json
{
  "success": true,
  "data": {
    "ship": { ... },
    "movement": {
      "sector": 2,
      "turns_used": 1,
      "mine_result": {
        "hit": true,
        "damage": 50,
        "message": "You hit a mine!"
      },
      "fighter_result": null
    }
  }
}
```

**Error Responses:**
- `400` - Not enough turns, sectors not linked, or ship destroyed
- `401` - Authentication required

---

#### GET `/api/v1/game/scan`

Get detailed sector scan information.

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ship": { ... },
    "sector": { ... },
    "links": [ ... ],
    "planets": [ ... ],
    "ships_in_sector": [ ... ],
    "defenses": [
      {
        "defence_id": 1,
        "defence_type": "M",
        "quantity": 10,
        "character_name": "Defender Name"
      }
    ]
  }
}
```

---

#### GET `/api/v1/game/status`

Get ship status and statistics.

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ship": { ... },
    "planets": [ ... ],
    "capacities": {
      "holds": 100,
      "energy": 500,
      "fighters": 100,
      "torps": 100
    },
    "score": 5000
  }
}
```

---

#### GET `/api/v1/game/planet/:id`

Get planet information.

**Headers:**
- `Authorization: Bearer <token>`

**URL Parameters:**
- `id` - Planet ID

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "planet": {
      "planet_id": 1,
      "planet_name": "Earth",
      "sector_id": 1,
      "owner": 1,
      ...
    },
    "owner_name": "Player Name",
    "is_owner": true,
    "is_on_planet": false
  }
}
```

**Error Responses:**
- `400` - Wrong sector
- `404` - Planet not found

---

#### POST `/api/v1/game/land/:id`

Land on a planet.

**Headers:**
- `Authorization: Bearer <token>`

**URL Parameters:**
- `id` - Planet ID

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Landed on planet successfully",
  "data": {
    "ship": { ... },
    "planet": { ... }
  }
}
```

**Error Responses:**
- `400` - Invalid planet or wrong sector
- `403` - Planet owned by another player

---

#### POST `/api/v1/game/leave`

Leave current planet.

**Headers:**
- `Authorization: Bearer <token>`

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Left planet successfully",
  "data": {
    "ship": { ... }
  }
}
```

**Error Responses:**
- `400` - Not on a planet

---

## Error Responses

All error responses follow this format:

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable error message",
    "details": { ... }
  }
}
```

### Common Error Codes

- `UNAUTHORIZED` (401) - Authentication required or invalid token
- `FORBIDDEN` (403) - Access denied
- `NOT_FOUND` (404) - Resource not found
- `VALIDATION_ERROR` (422) - Input validation failed
- `INSUFFICIENT_TURNS` (400) - Not enough turns
- `SECTORS_NOT_LINKED` (400) - Sectors are not connected
- `SHIP_DESTROYED` (400/403) - Ship has been destroyed

---

---

## Trading, combat and alignment endpoints

These are available to every API client (humans and NPCs) and enforce exactly the same rules as the web UI.
All are authenticated and rate limited. Request bodies are JSON.

### POST `/api/v1/game/port/trade`
Buy or sell at the port in your sector. Body: `{"commodity": "ore|organics|goods|energy", "action": "buy|sell", "amount": 100}`.
Starbase prices reflect alignment (Paragon -5%, Outlaw +15% on purchases); Pirates are refused (`SERVICE_REFUSED`, 403).
`commodity` may also be `contraband` (black-market sectors only, when enabled): each deal costs alignment; errors `NOT_BLACKMARKET`, `CONTRABAND_CAP`, `MARKET_FULL`, `CONTRABAND_DISABLED`.
Errors: `NO_PORT`, `PORT_WONT_SELL`, `PORT_WONT_BUY`, `PORT_STOCK`, `INSUFFICIENT_CREDITS`, `NO_CARGO_SPACE`, `INVALID_TRADE`.

### POST `/api/v1/game/attack/ship/:id`  ·  POST `/api/v1/game/attack/planet/:id`
Attack a ship or planet in your sector. Returns the combat outcome (`destroyed`, `damaged`, `escaped`, `captured`).
Rejected with 403 `STARBASE_NO_COMBAT` in starbase sectors and `FEDSPACE_PROTECTED` when the target is Neutral or better
in FedSpace (message: "Combat is not allowed in starbase sectors"). Other errors: `TARGET_NOT_FOUND`,
`TARGET_NOT_IN_SECTOR`, `TEAM_MEMBER`, `INSUFFICIENT_TURNS`, `FACTION_LOYALTY` (NPCs never attack their own faction).

### POST `/api/v1/game/defences`
Deploy sector defences. Body: `{"fighters": 20, "mines": 5}` (either or both). Mines use your torpedo stock.
403 `FEDSPACE_NO_DEFENCES` for Outlaws/Pirates in FedSpace, 403 `STARBASE_NO_DEFENCES` in starbase sectors.

### POST `/api/v1/game/planet/:id/transfer`
Move cargo between your ship and a planet you own and are landed on. Body:
`{"commodity": "ore|organics|goods|energy|colonists|fighters|credits", "amount": 10, "direction": "to_planet|to_ship"}`.

### POST `/api/v1/game/upgrade/:component`
Buy one upgrade level (`hull`, `engines`, `power`, `computer`, `sensors`, `beams`, `torp_launchers`, `shields`, `armor`,
`cloak`). Starbase sectors only (`NOT_STARBASE`, 403).

### GET `/api/v1/game/messages`  ·  POST `/api/v1/game/messages`
Read your inbox (`?limit=20`) or send `{"ship_id": 12, "text": "...", "subject": "optional"}`. Players: 30/hour, profanity
filtered. NPC accounts: 280 characters, 5/hour, recipient must be in sight or have messaged within 24 hours.

### GET `/api/v1/game/alignment`
Your exact alignment, tier, Wanted status, open bounty on you, fine quote and the last 20 changes.

```json
{ "success": true, "data": { "alignment": -320, "tier": "Outlaw", "wanted": true, "wanted_until": "2026-10-08 12:00:00+00",
  "open_bounty_on_you": 3000, "fine": { "fine": 32000, "restores_to": -99, "pirate_blocked": false, "applicable": true },
  "recent_changes": [ { "delta": -100, "new_value": -320, "reason": "attacked_lawful", "related_ship_id": 77, "created_at": "..." } ] } }
```

### POST `/api/v1/game/bounty`  ·  POST `/api/v1/game/fine`
`{"target_id": 12, "amount": 20000}` funds a bounty from your IGB balance (min 10,000, non-refundable).
`/fine` pays the Federation fine at a starbase (clears Wanted, alignment restored to -99; Pirates cannot pay).

Other players appear in `ships_in_sector` with `alignment_tier` and `wanted` only - never the number. NPCs are
flagged `npc: true` with their faction; whether an LLM drives them is never exposed.

### Protection, news and rumours

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/game/protection` | Protection state, progress text, thresholds, grace/respawn shield times |
| `POST /api/v1/game/protection/opt-out` | Give up protection permanently |
| `GET /api/v1/game/news?source=journalist` | Courier stories (`source` omitted = all; `limit`) |
| `POST /api/v1/game/settings/interviews` | `{"opt_out": true|false}` interview requests |
| `GET /api/v1/game/rumours` | Offers at the current port (tiers, prices, bought today) |
| `POST /api/v1/game/rumours/buy` | `{"tier": "tavern"|"informant"}`; 429 `RUMOUR_LIMIT` at the daily port limit |
| `GET /api/v1/game/rumours/log` | Your purchases, with truth revealed once expired |

New error codes: `PROTECTED_TARGET` (403), `DEFENCES_BLOCK_PROTECTED` (400), `RUMOUR_LIMIT` (429). Attack and defence endpoints accept
`confirm_end_protection: true` when the caller is protected.

## Agent endpoints (NPC accounts only)

Used by the LLM worker (`bin/npc-agent.php`). Human tokens receive 403. NPC tokens are accepted only from the
addresses in `npc.worker_ips`.

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/agent/observation` | Compact text observation (<= ~1,500 tokens) plus `last_event_id`. Player-written strings appear only inside `<<< >>>`. |
| `POST /api/v1/agent/go_to/:sector` | Server-side pathfinding (max depth 20); stops early on mines, fighters or a threatening ship. |
| `GET /api/v1/agent/trades?max_hops=n` | Best buy/sell pairs among ports this ship has discovered (`ship_known_ports`). `max_hops` 1-10. |
| `GET /api/v1/agent/news/candidates` · `POST /api/v1/agent/news/stories` | Courier (press NPC token only): open story candidates; submit `{candidate_id, headline, body}` or `{candidate_id, fallback: true}` |
| `GET /api/v1/agent/rumours/pool-status` · `POST /api/v1/agent/rumours/lines` | Courier only: flavour-line pool counts; submit `{lines: [{type, text}]}` for validation |
| `POST /api/v1/agent/notebook` | `{"text": "..."}` replaces the private notebook (<= 2,000 characters). |

See `docs/ALIGNMENT_AND_NPCS.md` for the full feature guide.

---

## CORS

The API supports CORS for cross-origin requests. Preflight OPTIONS requests are automatically handled.

---

## Rate Limiting

Every authenticated request spends one token from a bucket kept per API token (token bucket, refilled continuously).
Defaults: **60 requests per minute for players, 30 for NPC accounts** (`api.rate_limit_player_per_min`,
`api.rate_limit_npc_per_min`). Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. When the
bucket is empty the API returns `429` with a `Retry-After` header (seconds):

```json
{ "success": false, "error": { "message": "Rate limit exceeded. Retry in 2 seconds.", "code": "RATE_LIMITED", "details": { "retry_after": 2 } } }
```

---

## Example Usage

### Swift/iOS Example

```swift
import Foundation

class APIService {
    private let baseURL = "https://yourdomain.com/api/v1"
    private var token: String?
    
    func login(email: String, password: String) async throws -> AuthResponse {
        let url = URL(string: "\(baseURL)/auth/login")!
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        
        let body = ["email": email, "password": password]
        request.httpBody = try JSONSerialization.data(withJSONObject: body)
        
        let (data, _) = try await URLSession.shared.data(for: request)
        let response = try JSONDecoder().decode(AuthResponse.self, from: data)
        
        self.token = response.data.token
        return response
    }
    
    func getMain() async throws -> MainResponse {
        guard let token = token else {
            throw APIError.unauthorized
        }
        
        let url = URL(string: "\(baseURL)/game/main")!
        var request = URLRequest(url: url)
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        
        let (data, _) = try await URLSession.shared.data(for: request)
        return try JSONDecoder().decode(MainResponse.self, from: data)
    }
}
```

### cURL Examples

**Login:**
```bash
curl -X POST https://yourdomain.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"player@example.com","password":"password123"}'
```

**Get Main Screen:**
```bash
curl -X GET https://yourdomain.com/api/v1/game/main \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

**Move to Sector 2:**
```bash
curl -X POST https://yourdomain.com/api/v1/game/move/2 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## Notes

- All timestamps are in ISO 8601 format (YYYY-MM-DD HH:MM:SS)
- All numeric IDs are integers
- Boolean values are true/false (not 1/0)
- The API is versioned (v1) - future versions may introduce breaking changes
- Always check the `success` field in responses before accessing `data`
- Store tokens securely (iOS Keychain recommended)

---

## Future Endpoints

Additional endpoints for:
- Port trading
- Combat actions
- Planet management
- Messages
- Teams
- Rankings
- Upgrades
- IBank operations

These will be added in future updates.

