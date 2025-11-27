# Migration Guide: From Legacy BNT to Modern Version

This document explains the differences between the old BlackNova Traders and this modernized version.

## Major Changes

### PHP Version
- **Old**: PHP 4/5 with register_globals
- **New**: PHP 8.1+ with modern features

### Database
- **Old**: MySQL with ADOdb abstraction layer
- **New**: PostgreSQL with native PDO

### Security
- **Old**: SQL injection vulnerabilities, plain-text passwords, no CSRF protection
- **New**: Prepared statements, bcrypt passwords, CSRF tokens, XSS prevention

### Architecture
- **Old**: Procedural code, mixed logic and presentation
- **New**: MVC pattern, separation of concerns, namespaces

## Code Comparison

### Database Queries

**Old (Unsafe):**
```php
$result = $db->Execute("SELECT * FROM ships WHERE email='$username'");
```

**New (Safe):**
```php
$ship = $shipModel->findByEmail($email);
// Uses prepared statement internally:
// SELECT * FROM ships WHERE email = :email
```

### Password Handling

**Old (Insecure):**
```php
if ($password == $playerinfo['password']) {
    // Login
}
```

**New (Secure):**
```php
if (password_verify($password, $ship['password_hash'])) {
    // Login
}
```

### Form Security

**Old (No Protection):**
```html
<form action="move.php" method="post">
    <input name="sector" value="123">
</form>
```

**New (CSRF Protected):**
```html
<form action="/move/123" method="post">
    <input type="hidden" name="csrf_token" value="<?= $session->getCsrfToken() ?>">
</form>
```

### Output Escaping

**Old (XSS Vulnerable):**
```php
echo $username;
echo "<p>$message</p>";
```

**New (XSS Safe):**
```php
echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
echo '<p>' . htmlspecialchars($message) . '</p>';
```

## Database Schema Changes

### Ships Table

**Old (MySQL):**
```sql
CREATE TABLE bnt_ships (
    ship_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100),
    password VARCHAR(16),
    ...
)
```

**New (PostgreSQL):**
```sql
CREATE TABLE ships (
    ship_id SERIAL PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    ...
    CONSTRAINT chk_turns CHECK (turns >= 0 AND turns <= 2500)
)
```

### Key Differences:
- `AUTO_INCREMENT` → `SERIAL`
- Plain text passwords → bcrypt hashes (255 chars)
- Added constraints and foreign keys
- Added indexes for performance
- Boolean types instead of 'Y'/'N' strings

## Configuration Changes

**Old:**
```php
// db_config.php
$dbhost = "localhost";
$dbuname = "bnt";
$dbpass = "bnt";
$db_type = "mysql";
```

**New:**
```php
// config/config.php
return [
    'database' => [
        'driver' => 'pgsql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        // ... structured configuration
    ]
];
```

## Session Management

**Old:**
```php
// Manual cookie handling
SetCookie("username", $username, time()+3600*24*365);
```

**New:**
```php
// Secure session class
$session->setUserId($shipId);
$session->regenerate(); // Prevents session fixation
```

## Routing

**Old:**
```php
// Direct file access
// http://example.com/main.php?sector=123
include("config.php");
include("header.php");
// ... mixed logic and HTML
```

**New:**
```php
// RESTful routing
// http://example.com/main
// http://example.com/move/123
$router->get('/main', fn() => $gameController->main());
$router->post('/move/:sector', fn($s) => $gameController->move((int)$s));
```

## Function to Method Conversion

**Old:**
```php
function checklogin() {
    global $username, $password, $db, $dbtables;
    // ... logic
}
```

**New:**
```php
class AuthController {
    public function __construct(
        private Ship $shipModel,
        private Session $session
    ) {}

    private function requireAuth(): ?array {
        // ... logic
    }
}
```

## Common Tasks in New Version

### Get Current Player
**Old:**
```php
$result = $db->Execute("SELECT * FROM ships WHERE email='$username'");
$playerinfo = $result->fields;
```

**New:**
```php
$shipId = $session->getUserId();
$ship = $shipModel->find($shipId);
```

### Update Player
**Old:**
```php
$db->Execute("UPDATE ships SET credits=credits+1000 WHERE ship_id=$ship_id");
```

**New:**
```php
$shipModel->update($shipId, [
    'credits' => $ship['credits'] + 1000
]);
```

### Player Log
**Old:**
```php
playerlog($sid, LOG_ATTACK_WIN, "You won!");
```

**New:**
```php
// Similar, but with model:
$logModel->create([
    'ship_id' => $shipId,
    'log_type' => LogType::ATTACK_WIN,
    'log_data' => 'You won!',
]);
```

## What's Not Migrated

Most core features from the original game have been successfully migrated and modernized. The following advanced features are not yet implemented:

1. **Trade Routes** - Automated trading routes
2. **Genesis Torpedoes** - Planet creation and terraforming
3. **Special Devices** - Beacons, warp editors, emergency warp devices
4. **News System** - Automated news generation from game events
5. **Scheduler** - Automated maintenance tasks (turn generation, production cycles, etc.)

All other major features including combat, planets, teams, banking, messaging, and rankings have been fully implemented with modern security and architecture.

### Successfully Migrated Features

The following features from the original game have been completely reimplemented:

- ✅ **Combat System** - Ship attacks, planet attacks, defense deployment with full damage calculations
- ✅ **Planet Management** - Colonization, production allocation, base construction, resource management
- ✅ **Sector Defenses** - Mine and fighter deployment, defense vs defense combat
- ✅ **Teams/Alliances** - Full team system with invitations, messaging, and management
- ✅ **IGB** - Complete Intergalactic Bank with deposits, withdrawals, transfers, and loans
- ✅ **Mail System** - Player-to-player messaging with inbox/sent folders
- ✅ **Rankings** - Player and team rankings with multiple sort options
- ✅ **Ship Upgrades** - 10 component upgrade system with exponential costs
- ✅ **Player Profiles** - Comprehensive player information and search
- ✅ **Attack Logs** - Complete combat history tracking

These can be extended or enhanced using the same patterns as existing features.

## Extending the Game

### Adding a New Feature

1. **Create the Model** (if needed)
```php
// src/Models/MyFeature.php
namespace BNT\Models;

class MyFeature extends Model {
    protected string $table = 'my_feature';
}
```

2. **Create the Controller**
```php
// src/Controllers/MyFeatureController.php
namespace BNT\Controllers;

class MyFeatureController {
    public function __construct(
        private MyFeature $model,
        private Session $session
    ) {}
}
```

3. **Add Routes**
```php
// public/index.php
$router->get('/myfeature', fn() => $myController->show());
```

4. **Create View**
```php
// src/Views/myfeature.php
<?php
$title = 'My Feature';
$showHeader = true;
ob_start();
?>
<h2>My Feature</h2>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
```

## Performance Comparison

The new version should be significantly faster:

1. **Native PDO** vs ADOdb abstraction layer
2. **Prepared statement caching** by PostgreSQL
3. **Efficient indexes** on foreign keys
4. **Modern PHP 8.1 JIT** compiler
5. **No register_globals** overhead
6. **Proper opcache** utilization

## Security Improvements Summary

| Issue | Old | New |
|-------|-----|-----|
| SQL Injection | ❌ Vulnerable | ✅ Protected |
| XSS | ❌ Vulnerable | ✅ Protected |
| CSRF | ❌ None | ✅ Protected |
| Passwords | ❌ Plain text | ✅ Bcrypt |
| Sessions | ❌ Insecure | ✅ Secure |
| Headers | ❌ None | ✅ Security headers |

## Questions?

If you have questions about migrating or need help with the new architecture, please open an issue in the repository.
