# BlackNova vs BNT Feature Comparison

This document compares the features of the original [BlackNova Traders](https://github.com/photogabble/blacknova) with the current BNT (BlackNova Traders) implementation at [upperdarkness/bnt](https://github.com/upperdarkness/bnt).

## Status Legend
- ✅ **Fully Implemented** - Feature is complete and functional
- 🚧 **Partially Implemented** - Feature exists but incomplete
- ❌ **Not Implemented** - Feature missing or only has database schema
- 📋 **Planned** - Feature documented in schema/TODOs

---

## Core Game Systems

### Authentication & User Management
| Feature | Status | Notes |
|---------|--------|-------|
| User registration with password hashing | ✅ | Using bcrypt |
| Login/logout functionality | ✅ | With CSRF protection |
| Session management | ✅ | Secure sessions |
| Character creation | ✅ | Ship naming and customization |

### Ship Types & Progression
| Feature | Status | Notes |
|---------|--------|-------|
| Scout ship class | ✅ | 70% cargo, 50% turn costs, speed bonus |
| Merchant ship class | ✅ | 200% cargo, 120% turn costs |
| Warship ship class | ✅ | 150% combat, 140% defense |
| Balanced ship class | ✅ | 100% all stats |
| Ship type bonuses | ✅ | Cargo, combat, defense, speed multipliers |

### Character Skill System
| Feature | Status | Notes |
|---------|--------|-------|
| Trading skill (0-100) | ✅ | 0.5% price improvement per level |
| Combat skill (0-100) | ✅ | 1% damage increase per level |
| Engineering skill (0-100) | ✅ | 0.4% upgrade cost reduction per level |
| Leadership skill (0-100) | ✅ | 0.25% general bonus per level |
| Skill point allocation | ✅ | Exponential cost scaling |
| Skill points from gameplay | ✅ | Trading, combat, upgrades award points |

---

## Navigation & Exploration

### Sector Navigation
| Feature | Status | Notes |
|---------|--------|-------|
| Move between linked sectors | ✅ | Turn-based movement |
| Turn costs based on ship type | ✅ | Scout uses fewer turns |
| Sector information display | ✅ | Shows ports, planets, ships |
| Safe zone (Sector 1 - Starbase) | ✅ | No combat allowed |
| Movement logging | ✅ | Track player movements |

### Advanced Navigation (Missing)
| Feature | Status | Notes |
|---------|--------|-------|
| Navigation computer | ❌ | **MISSING** - Auto-pathfinding feature |
| Long-range scan | ❌ | **MISSING** - Scan multiple sectors ahead |
| Realspace navigation | ❌ | **MISSING** - Alternative movement mode |

### Sector Hazards & Defenses
| Feature | Status | Notes |
|---------|--------|-------|
| Mine deployment | ✅ | Players can deploy mines |
| Mine detection on entry | ✅ | Automatic damage |
| Fighter deployment | ✅ | Sector defense fighters |
| Fighter attacks on entry | ✅ | Automatic combat |
| Defense degradation | ✅ | 1% per cycle |
| Retrieve defenses | ✅ | When in same sector |
| Defense vs defense combat | ✅ | Automatic resolution |

---

## Economy & Trading

### Port Trading System
| Feature | Status | Notes |
|---------|--------|-------|
| Five port types | ✅ | Ore, organics, goods, energy, special |
| Dynamic supply & demand pricing | ✅ | Price adjustments based on stock |
| Port production cycles | ✅ | Every 2 minutes |
| Trading skill bonus | ✅ | Reduces buy prices, increases sell |
| Four commodities | ✅ | Ore, organics, goods, energy |
| Cargo management | ✅ | Based on ship type |

### Trade Routes (Missing - HIGH PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Automated trade routes | 📋 | **Table exists**, no implementation in `src/Controllers/TradeRouteController.php` |
| Create trade routes (start/end) | ❌ | **MISSING** - No UI or logic |
| Circuit/loop routes | ❌ | **MISSING** |
| Move type selection | ❌ | **MISSING** |
| Route execution | ❌ | **MISSING** |

**Work Required:**
1. Create `TradeRouteController.php` with CRUD operations
2. Implement route pathfinding using sector links
3. Add route execution logic to scheduler
4. Create UI views for route management
5. Add turn consumption for automated trading
6. Implement profit tracking and reporting

### Intergalactic Bank (IGB)
| Feature | Status | Notes |
|---------|--------|-------|
| Deposit/withdraw credits | ✅ | Full banking system |
| Player-to-player transfers | ✅ | With configurable fees |
| Loan system | ✅ | Interest tracking |
| Interest on balances | ✅ | 0.1% per cycle |
| Net worth calculation | ✅ | Total assets |

---

## Combat System

### Ship-to-Ship Combat
| Feature | Status | Notes |
|---------|--------|-------|
| Attack other ships | ✅ | Full combat system |
| Beam weapons | ✅ | Damage calculation |
| Torpedo weapons | ✅ | Consumption tracking |
| Combat skill multipliers | ✅ | 1% per level |
| Ship type multipliers | ✅ | Warship gets 150% damage |
| Escape mechanics | ✅ | Based on ship stats |
| Fighter vs fighter combat | ✅ | Automatic resolution |
| Combat logging | ✅ | All outcomes tracked |

### Planet Attacks
| Feature | Status | Notes |
|---------|--------|-------|
| Attack unowned planets | ✅ | Capture mechanics |
| Attack enemy planets | ✅ | 5 turns required |
| Planetary base destruction | ✅ | Bases can be destroyed |
| Planet defense (fighters/torps) | ✅ | Defensive capabilities |
| Planet capture | ✅ | Transfer ownership |

### Planet Bombing (Missing)
| Feature | Status | Notes |
|---------|--------|-------|
| SOFA (fighter bombing) | ❌ | **MISSING** - Soften defenses with fighters before ship attack |

**Work Required:**
1. Add SOFA attack option in `CombatController.php`
2. Implement fighter-based planet damage
3. Add fighter consumption during bombing
4. Create UI for bombing interface
5. Add combat logs for bombing runs

---

## Planet System

### Planet Ownership & Management
| Feature | Status | Notes |
|---------|--------|-------|
| View planets in sector | ✅ | Complete planet display |
| Colonize unowned planets | ✅ | With 100+ colonists |
| Claim ownership | ✅ | Transfer resources |
| Planet information display | ✅ | Detailed stats |

### Planetary Production
| Feature | Status | Notes |
|---------|--------|-------|
| Six production types | ✅ | Ore, organics, goods, energy, fighters, torpedoes |
| Adjustable production allocation | ✅ | Must total 100% |
| Production cycles | ✅ | Every 2 minutes |
| Colonist-based production | ✅ | More colonists = more production |
| Resource accumulation | ✅ | Stored on planet |

### Planetary Bases
| Feature | Status | Notes |
|---------|--------|-------|
| Build bases on planets | ✅ | Resource costs |
| Enhanced defense with bases | ✅ | Better protection |
| Base destruction | ✅ | On successful attack |
| Sector ownership tracking | ✅ | Based on bases |

### Genesis Torpedoes & Terraforming (Missing - MEDIUM PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Genesis torpedo device | 📋 | **Column exists** (`dev_genesis` in ships table) |
| Create new planets | ❌ | **MISSING** - Function to generate planet in empty sector |
| Terraforming mechanics | ❌ | **MISSING** - Modify planet types |
| Genesis device limits | 📋 | Schema supports max 10 devices |
| Starting genesis torps | ❌ | **MISSING** - Players should start with 1 |

**Work Required:**
1. Implement genesis torpedo usage in `PlanetController.php`
2. Add planet generation logic for empty sectors
3. Implement device consumption (use 1 torpedo)
4. Add purchase/trade mechanics for genesis devices
5. Create UI for genesis torpedo usage
6. Add validation (sector must be empty)
7. Update score calculation to include genesis devices

---

## Ship Upgrades & Equipment

### Ship Component Upgrades
| Feature | Status | Notes |
|---------|--------|-------|
| Hull upgrades | ✅ | Increases armor |
| Engine upgrades | ✅ | Speed and turn efficiency |
| Power upgrades | ✅ | Overall performance |
| Computer upgrades | ✅ | Targeting |
| Sensor upgrades | ✅ | Detection |
| Beam upgrades | ✅ | Weapon damage |
| Torpedo launcher upgrades | ✅ | Capacity/damage |
| Shield upgrades | ✅ | Defense |
| Armor upgrades | ✅ | Hull protection |
| Cloak upgrades | ✅ | Stealth |
| Exponential cost scaling | ✅ | Gets expensive |
| Engineering skill discount | ✅ | Up to 40% |
| Upgrade/downgrade | ✅ | Refunds on downgrade |

### Special Devices (Missing - HIGH PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Warp editor | 📋 | **Column exists** (`dev_warpedit`) - Not functional |
| Space beacon | 📋 | **Column exists** (`dev_beacon`) - Not functional |
| Emergency warp | 📋 | **Column exists** (`dev_emerwarp`) - Not functional |
| Mine deflector | 📋 | **Column exists** (`dev_minedeflector`) - Not functional |
| Escape pod | 📋 | **Column exists** (`dev_escapepod`) - Not functional |
| Fuel scoop | 📋 | **Column exists** (`dev_fuelscoop`) - Not functional |
| LSSD device | 📋 | **Column exists** (`dev_lssd`) - Not functional |

**Work Required for Each Device:**

#### 1. Warp Editor
- Add functionality to modify sector links
- Create UI for warp editing
- Add device consumption logic
- Implement in special ports purchase system
- Add max limit (10 devices)

#### 2. Space Beacon
- Add beacon deployment in sectors
- Create beacon tracking table or extend sector_defence
- Display beacons to team members
- Add navigation aid functionality
- Purchase system in special ports

#### 3. Emergency Warp
- Implement instant warp to safe zone (Sector 1)
- Add device consumption (one-time use)
- Add cooldown or limitations
- Purchase system in special ports
- UI button in navigation

#### 4. Mine Deflector
- Add mine deflection logic in sector entry
- Prevent mine damage when device active
- Add device degradation/consumption
- Purchase system
- Status display on ship info

#### 5. Escape Pod
- Implement ship survival on destruction
- Save percentage of resources
- Respawn at Sector 1
- One-time use, then must repurchase
- Toggle on/off

#### 6. Fuel Scoop
- Implement fuel/energy collection mechanic
- Add energy regeneration in specific sectors
- Passive device (always active when owned)
- Purchase system

#### 7. LSSD (Long-range Sensor Suite Device)
- Add long-range scanning capability
- Display multiple sectors ahead
- Show detailed sector info remotely
- Passive device
- Purchase system

---

## Social Features

### Teams/Alliances
| Feature | Status | Notes |
|---------|--------|-------|
| Create and join teams | ✅ | Full team system |
| Team invitations | ✅ | Accept/decline |
| Team membership management | ✅ | Kick members |
| Leave team | ✅ | Voluntary departure |
| Team descriptions | ✅ | Customizable |
| Prevent same-team combat | ✅ | Safety check |
| Team dissolution | ✅ | Founder can delete |

### Team Communication
| Feature | Status | Notes |
|---------|--------|-------|
| Team chat/messaging | ✅ | Post messages |
| View message history | ✅ | 20 recent messages |
| Timestamps | ✅ | All messages |
| Member-only posting | ✅ | Security |

### Team Treasury (Missing - MEDIUM PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Team credit storage | 🚧 | **Methods stubbed** - TODOs in `Team.php:219,233` |
| Deposit to team bank | 🚧 | Code exists but not functional |
| Withdraw from team bank | 🚧 | Requires implementation |
| Team financial tracking | ❌ | **MISSING** |

**Work Required:**
1. Add `team_credits` column to teams table
2. Complete `addCredits()` method in `Team.php`
3. Complete `withdrawCredits()` method in `Team.php`
4. Add permission system (founder vs members)
5. Create UI for team banking
6. Add transaction logging
7. Update team statistics to include treasury

### Player Messaging
| Feature | Status | Notes |
|---------|--------|-------|
| Send private messages | ✅ | Player to player |
| Inbox/sent folders | ✅ | Message organization |
| Read/unread tracking | ✅ | Status tracking |
| Reply to messages | ✅ | Full functionality |
| Delete messages | ✅ | Soft delete |
| Message length limits | ✅ | 5000 chars max |
| Pagination | ✅ | 25 per page |

### Player Profiles & Search
| Feature | Status | Notes |
|---------|--------|-------|
| View player profiles | ✅ | Detailed info |
| Search players by name | ✅ | Search function |
| View team affiliation | ✅ | Team display |
| Combat statistics | ✅ | Win/loss tracking |
| Online status | ✅ | Activity indicator |

---

## Rankings & Statistics

### Player Rankings
| Feature | Status | Notes |
|---------|--------|-------|
| Score rankings | ✅ | Top 100 leaderboard |
| Multiple sort options | ✅ | 7 different sorts |
| Turn count ranking | ✅ | Activity tracking |
| Efficiency rating | ✅ | Performance metric |
| Rank position display | ✅ | Current rank |

### Team Rankings
| Feature | Status | Notes |
|---------|--------|-------|
| Team rankings | ✅ | Top 20 teams |
| Aggregate statistics | ✅ | Team totals |
| Member count | ✅ | Team size |

### Combat Statistics & Logging
| Feature | Status | Notes |
|---------|--------|-------|
| Attack logs (all types) | ✅ | Ship, planet, defense |
| Combat outcomes | ✅ | Success, failure, escaped |
| Damage tracking | ✅ | Full statistics |
| Attack history | ✅ | Paginated display |

---

## News & Events

### News System
| Feature | Status | Notes |
|---------|--------|-------|
| Basic news generation | ✅ | Combat events |
| News from combat | ✅ | Automated |
| News display | ✅ | Recent events |

### Enhanced News System (Missing - LOW PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Planet capture news | ❌ | **MISSING** |
| Trade milestone news | ❌ | **MISSING** |
| Team achievement news | ❌ | **MISSING** |
| Xenobe attack news | ❌ | **MISSING** - Requires Xenobe implementation |
| Player milestone news | ❌ | **MISSING** |

**Work Required:**
1. Extend news generation in scheduler
2. Add news triggers for planet captures
3. Add news for major trades
4. Add team achievement tracking
5. Create news categories/types
6. Add news filtering by type
7. Implement news importance levels

---

## Background Systems

### Scheduler System
| Feature | Status | Notes |
|---------|--------|-------|
| Turn generation | ✅ | Every 2 minutes |
| Missed cycle support | ✅ | Server downtime handling |
| Port production | ✅ | Every 2 minutes |
| Planet production | ✅ | Every 2 minutes |
| IGB interest | ✅ | Every 2 minutes |
| Rankings update | ✅ | Every 30 minutes |
| News generation | ✅ | Every 15 minutes |
| Fighter degradation | ✅ | Every 6 minutes (1% decay) |
| Cleanup tasks | ✅ | Every 60 minutes |
| No external cron needed | ✅ | Runs on page load |

### Scheduled Events (Missing - MEDIUM PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Xenobe attacks | ❌ | **MISSING** - NPC enemy ships |
| Random events | ❌ | **MISSING** |
| Server-wide events | ❌ | **MISSING** |
| Timed tournaments | ❌ | **MISSING** |

**Work Required for Xenobe (NPC Enemies):**
1. Create `Xenobe` model
2. Add xenobe spawn logic to scheduler
3. Implement xenobe AI (attack nearby players)
4. Add xenobe combat mechanics
5. Implement xenobe rewards (credits, items)
6. Create xenobe difficulty scaling
7. Add xenobe kill tracking
8. Add xenobe news events

---

## Bounty System (Missing - LOW PRIORITY)

| Feature | Status | Notes |
|---------|--------|-------|
| Place bounties on players | 📋 | **Table exists** - No implementation |
| Bounty collection | ❌ | **MISSING** |
| Bounty board display | ❌ | **MISSING** |
| Anonymous bounties | ❌ | **MISSING** |
| Bounty expiration | ❌ | **MISSING** |

**Work Required:**
1. Create `BountyController.php`
2. Implement place bounty functionality
3. Add bounty collection on kill
4. Create bounty board UI
5. Add bounty notifications
6. Implement bounty expiration logic
7. Add bounty statistics to player profiles

---

## Admin Functionality

### Admin Panel (Basic)
| Feature | Status | Notes |
|---------|--------|-------|
| Admin login | ✅ | Password authentication |
| Player management | ✅ | View, edit, delete |
| Team management | ✅ | View, delete |
| View game statistics | ✅ | Dashboard |
| System logs | ✅ | Event tracking |
| Universe regeneration | ✅ | Reset universe |

### Advanced Admin Panel (Missing - LOW PRIORITY)
| Feature | Status | Notes |
|---------|--------|-------|
| Game settings editor | ❌ | **MISSING** - Currently read-only |
| Ban management | 📋 | **Table exists** (`ip_bans`) - No UI |
| Announcement system | ❌ | **MISSING** |
| Maintenance mode | ❌ | **MISSING** |
| Server performance metrics | ❌ | **MISSING** |
| Backup/restore tools | ❌ | **MISSING** |

**Work Required:**
1. Create settings editor UI and backend
2. Implement ban management CRUD
3. Add global announcements system
4. Create maintenance mode toggle
5. Add performance monitoring
6. Implement backup/restore functionality

---

## Tournament & Special Modes (Missing - LOW PRIORITY)

| Feature | Status | Notes |
|---------|--------|-------|
| Tournament mode | ❌ | **MISSING** |
| Ladder/season system | ❌ | **MISSING** |
| Special game modes | ❌ | **MISSING** |
| Tournament brackets | ❌ | **MISSING** |
| Prize/reward system | ❌ | **MISSING** |

**Work Required:**
1. Design tournament structure
2. Create tournament registration system
3. Implement tournament brackets
4. Add special rule sets
5. Create separate tournament universe
6. Implement prize distribution
7. Add tournament history/archives

---

## Security & Infrastructure

### Security Features (Implemented)
| Feature | Status | Notes |
|---------|--------|-------|
| Password hashing (bcrypt) | ✅ | Secure passwords |
| SQL injection prevention | ✅ | PDO prepared statements |
| XSS protection | ✅ | Output escaping |
| CSRF protection | ✅ | Token validation |
| Session management | ✅ | Secure sessions |
| Admin authentication | ✅ | Separate system |

---

## Summary: Priority Work Items

### 🔴 HIGH PRIORITY (Core Gameplay)
1. **Trade Routes System** - Major feature, table exists
2. **Special Devices** - 7 devices with database support
   - Warp editor
   - Space beacon
   - Emergency warp
   - Mine deflector
   - Escape pod
   - Fuel scoop
   - LSSD

### 🟡 MEDIUM PRIORITY (Enhanced Gameplay)
3. **Genesis Torpedoes** - Planet creation functionality
4. **Team Treasury** - Stubbed code exists
5. **Xenobe/NPC Enemies** - Scheduled events system
6. **SOFA Planet Bombing** - Enhanced combat
7. **Navigation Computer** - Quality of life

### 🟢 LOW PRIORITY (Nice to Have)
8. **Bounty System** - Table exists
9. **Enhanced News System** - More event types
10. **Advanced Admin Panel** - Settings editor, ban management
11. **Tournament Modes** - Special game modes
12. **Long-range Scan** - Extended scanning

---

## Estimated Development Effort

| Feature | Complexity | Estimated Hours | Files to Create/Modify |
|---------|-----------|-----------------|------------------------|
| Trade Routes | High | 20-30h | `TradeRouteController.php`, views, scheduler |
| All Special Devices | High | 40-50h | Device logic in multiple controllers, purchase system |
| Genesis Torpedoes | Medium | 8-12h | `PlanetController.php`, device purchase |
| Team Treasury | Low | 4-6h | `TeamController.php`, views |
| Xenobe System | High | 24-32h | `Xenobe.php`, AI logic, scheduler |
| SOFA Bombing | Medium | 6-8h | `CombatController.php`, views |
| Bounty System | Medium | 12-16h | `BountyController.php`, views |
| Enhanced News | Low | 6-8h | Scheduler tasks |
| Navigation Computer | Medium | 10-15h | Pathfinding algorithm, UI |
| Long-range Scan | Low | 4-6h | Scan logic, UI |
| Admin Enhancements | Medium | 10-15h | Admin controllers, views |
| Tournament System | Very High | 40-60h | Complete new system |

**Total Estimated Effort:** 184-258 hours

---

## References

This comparison was compiled from:

### BlackNova Traders (Original)
- [BlackNova Traders on SourceForge](https://sourceforge.net/projects/blacknova/)
- [BlackNova Git Repository](https://github.com/photogabble/blacknova)
- [BlackNova Traders FAQ](https://bnt.wikidot.com/faq)
- [NewRPG BlackNova Overview](https://newrpg.com/browser-games/blacknova-traders/)

### BNT (Current Implementation)
- [BNT GitHub Repository](https://github.com/upperdarkness/bnt)
- BNT README.md, MIGRATION.md, SCHEDULER.md
- Database schema analysis (`/database/schema.sql`)
- Codebase exploration of controllers and models

---

*Document generated: 2025-12-02*
