# Changelog

All notable changes to BlackNova Traders will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Starbase System**
  - Configurable starbase percentage (default 5% of ports)
  - Starbases are safe zones where combat is prohibited
  - Ship upgrades available at starbases
  - Equipment purchases (fighters, torpedoes) at starbases
  - Emergency Warp Drive device purchase at starbases
  - Mine Deflector device purchase at starbases
  - Starbase indicators on navigation links
  - Automatic towing of large ships (hull > 5) from Sector 1

- **Emergency Warp Drive Device**
  - Automatically activates when attacked by another player
  - Warps player to random sector
  - Single-use device (consumed after activation)
  - Purchasable at starbases (default: 50,000 credits)
  - Log entries created when device activates

- **Mine Deflector Device**
  - Automatically activates when hitting mines
  - Prevents all mine damage when activated
  - Single-use device (consumed after activation)
  - Only works for ships with hull size 8+
  - Purchasable at starbases (default: 25,000 credits)

- **Port Colonists System**
  - Ports have colonists that can be loaded onto ships
  - Colonists can be transported to colonize planets
  - Colonists can be transferred between ports
  - Colonists regenerate at ports over time
  - Each colonist takes 1 cargo space

- **Port Economy System**
  - Dynamic pricing based on supply and demand
  - Port specialization (Ore ports sell ore, buy organics/goods, etc.)
  - Commodity regeneration at ports (export commodities)
  - Commodity consumption at ports (import commodities)
  - Asymptotic growth model for regeneration

- **Scheduler Improvements**
  - Missed cycles support for periods of inactivity
  - Automatic turn generation accounting for downtime
  - Safety cap of 24 hours worth of turns
  - Cycle-aware task execution

- **Utility Scripts**
  - `scripts/mark_starbases.php` - Randomly mark ports as starbases
  - `scripts/run_migration.php` - Run migrations via PHP

- **Admin Panel Enhancements**
  - Universe sector management with pagination and search
  - View and edit individual sectors (name, zone, port type, beacon, starbase status)
  - Edit port inventory (ore, organics, goods, energy, colonists)
  - Navigation link management (add/remove links between sectors)
  - Planet management (view, create, delete unowned planets)
  - Starbase status toggle for any sector
  - Protection against deleting player-owned planets

### Changed
- Navigation links now display starbase indicators
- Port view shows starbase services when at a starbase
- Combat screen displays both deployed fighters and mines
- Mine deployment shows deployed count

### Fixed
- Port access for starbases with no trading port (port_type = 'none')
- Undefined config variable in PortController
- Missing mine display on combat screen
- Players' own deployed mines now visible on combat screen

## [2.0.0] - 2025-11-27

### Added
- Complete rewrite using modern PHP 8.1+ and PostgreSQL
- MVC architecture with clean separation of concerns
- Secure authentication system with password hashing
- CSRF protection on all forms
- XSS protection with proper output escaping
- Scheduler system for automated game tasks
- Ship upgrade system
- Skill system (trading, combat, engineering)
- Team system
- Planet colonization and management
- Combat system (ship vs ship, ship vs planet)
- Sector defenses (fighters and mines)
- Trading system with dynamic pricing
- Navigation and movement system
- Admin panel for game management
- Attack logging system
- Ranking system
- Message system
- IGB (Intergalactic Bank) system

### Security
- All database queries use prepared statements
- Password hashing with bcrypt
- Session security with regeneration
- Security headers (X-Frame-Options, X-Content-Type-Options)
- CSRF token validation on all forms

### Technical
- PSR-4 autoloading with namespaces
- Type declarations throughout codebase
- PostgreSQL with proper types and constraints
- Foreign key constraints for data integrity
- Indexes for performance optimization
- Modern SQL features (RETURNING, ON CONFLICT)

---

## Version History

### Recent Updates (December 2025)

**December 3, 2025**
- Fixed missing mine display on combat screen
- Added starbase indicators to navigation links (main and scan views)

**December 2, 2025**
- Implemented Mine Deflector device functionality
- Added utility scripts for starbase management
- Fixed port access for starbases with no trading port
- Fixed undefined config variable in PortController
- Added Emergency Warp Drive device with automatic activation
- Added automatic towing of large ships from Sector 1
- Added configurable starbase system with upgrades and equipment purchases

**December 1, 2025**
- Added scheduler missed cycles support
- Fixed various admin panel issues

**November 30, 2025**
- Implemented port economy system with regeneration, consumption, and supply/demand pricing

**November 29, 2025**
- Fixed colonist pickup/dropoff functionality
- Updated scheduler documentation
- Added starbase sector protection
- Fixed planet view rendering

**November 28, 2025**
- Added fighter recall feature
- Fixed port cargo display
- Fixed navigation links and database access issues

---

## Notes

- All dates are in YYYY-MM-DD format
- Features marked as "Unreleased" are in development or recently added
- Breaking changes will be clearly marked
- Security fixes are prioritized and documented
