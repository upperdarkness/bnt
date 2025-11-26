# BlackNova Traders - Modern PHP Edition

A complete rewrite of BlackNova Traders using modern PHP 8.1+, PostgreSQL, and minimal dependencies.

## What's New in This Version

This is a ground-up rewrite of the classic BlackNova Traders game with modern technologies:

### Modern PHP Features
- **PHP 8.1+** with strict types and modern syntax
- **PSR-4 autoloading** with namespaces
- **Type declarations** throughout the codebase
- **No dependencies** - uses only built-in PHP features (PDO)

### Security Improvements
- **Password hashing** using `password_hash()` (bcrypt)
- **Prepared statements** for all database queries (SQL injection prevention)
- **CSRF protection** on all forms
- **XSS protection** with proper output escaping
- **Secure session handling** with regeneration
- **Security headers** (X-Frame-Options, X-Content-Type-Options, etc.)

### PostgreSQL Database
- **Native PostgreSQL** support with proper types
- **Foreign key constraints** for data integrity
- **Indexes** for performance
- **Transactions** where appropriate
- **Modern SQL** features (RETURNING, ON CONFLICT, etc.)

### Architecture
- **MVC-like structure** with separation of concerns
- **Clean routing** with RESTful URLs
- **Reusable components** (Database, Session, Router)
- **Modern views** with template inheritance

### Code Quality
- **No register_globals** workarounds
- **No SQL injection** vulnerabilities
- **No XSS** vulnerabilities
- **Proper error handling**
- **Clean, readable code**

## Requirements

- PHP 8.1 or higher
- PostgreSQL 12 or higher
- Apache/Nginx web server
- Composer (for autoloading)

## Installation

### 1. Clone or Download

```bash
git clone <repository-url> blacknova
cd blacknova
```

### 2. Install Dependencies

```bash
composer install
```

If you don't have composer, download it from https://getcomposer.org/

### 3. Configure Database

Copy the environment example file:

```bash
cp .env.example .env
```

Edit `.env` with your PostgreSQL credentials:

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=blacknova
DB_USER=bnt
DB_PASS=your_secure_password
```

### 4. Create PostgreSQL Database

Create a PostgreSQL user and database:

```bash
sudo -u postgres psql
CREATE USER bnt WITH PASSWORD 'your_secure_password';
CREATE DATABASE blacknova OWNER bnt;
GRANT ALL PRIVILEGES ON DATABASE blacknova TO bnt;
\q
```

### 5. Initialize Database

Run the initialization script:

```bash
./scripts/init_db.sh
```

Or manually:

```bash
psql -h localhost -U bnt -d blacknova -f database/schema.sql
```

### 6. Create Universe

Generate sectors and planets:

```bash
php scripts/create_universe.php 1000 200
```

This creates 1000 sectors and 200 planets. Adjust as needed.

### 7. Configure Web Server

#### Apache

Point your virtual host to the `public` directory:

```apache
<VirtualHost *:80>
    ServerName blacknova.local
    DocumentRoot /path/to/blacknova/public

    <Directory /path/to/blacknova/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable mod_rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx

```nginx
server {
    listen 80;
    server_name blacknova.local;
    root /path/to/blacknova/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 8. Set Permissions

```bash
chmod -R 755 public
chmod -R 750 config
```

### 9. Visit Your Game

Open your browser and visit:
- http://localhost (or your configured domain)
- Register a new account
- Start playing!

## Security Configuration

### Change Admin Password

Edit `config/config.php` and update the admin password hash:

```php
'admin_password' => password_hash('your_new_password', PASSWORD_DEFAULT),
```

### Generate Strong Passwords

```bash
php -r "echo password_hash('your_password', PASSWORD_DEFAULT) . PHP_EOL;"
```

## Directory Structure

```
blacknova/
├── config/              # Configuration files
│   └── config.php       # Main configuration
├── database/            # Database schemas
│   └── schema.sql       # PostgreSQL schema
├── public/              # Web root (point your web server here)
│   ├── index.php        # Application entry point
│   └── .htaccess        # Apache rewrite rules
├── scripts/             # Utility scripts
│   ├── create_universe.php  # Universe generator
│   └── init_db.sh       # Database initialization
├── src/                 # Application source code
│   ├── Controllers/     # Request handlers
│   ├── Core/            # Core framework components
│   ├── Models/          # Database models
│   └── Views/           # HTML templates
├── composer.json        # Composer configuration
└── README.md           # This file
```

## Key Features Implemented

### ✅ Completed Features

- User registration and authentication
- Session management with CSRF protection
- Ship/player management
- Sector navigation
- Port trading (buy/sell commodities)
- Planet viewing
- Ship status page
- Secure database layer with prepared statements
- Modern responsive UI
- Score calculation

### 🚧 Features to Implement

The original game had many features. Here are major ones not yet implemented:

- Combat system
- Planet colonization and management
- Sector defenses (mines, fighters)
- Teams/alliances
- Intergalactic Bank (IGB)
- Mail/messaging system
- Trade routes
- Genesis torpedoes and special devices
- Admin panel
- Rankings system
- News system
- Scheduler for automated tasks

## Migration from Old Version

If you're migrating from the old PHP/MySQL version:

1. **Do NOT** try to migrate the old database directly
2. **Export player data** if you want to preserve it
3. Start with a fresh database using the new schema
4. Manually recreate players with secure password hashes
5. The old passwords cannot be migrated (they weren't securely hashed)

## Development

### Adding New Routes

Edit `public/index.php`:

```php
$router->get('/myroute', fn() => $controller->myMethod());
$router->post('/myroute/:id', fn($id) => $controller->myMethod((int)$id));
```

### Creating New Models

Extend the `Model` base class:

```php
namespace BNT\Models;

class MyModel extends Model
{
    protected string $table = 'my_table';
    protected string $primaryKey = 'id';

    // Add your custom methods
}
```

### Creating New Controllers

```php
namespace BNT\Controllers;

class MyController
{
    public function __construct(
        private MyModel $model,
        private Session $session
    ) {}

    public function myAction(): void
    {
        // Your logic here
    }
}
```

### Creating Views

Create a new file in `src/Views/`:

```php
<?php
$title = 'My Page';
$showHeader = true;
ob_start();
?>

<h2>My Content</h2>
<!-- Your HTML here -->

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
```

## Configuration

All configuration is in `config/config.php`:

- Database settings
- Game parameters (starting credits, turns, etc.)
- Trading configuration
- Scheduler settings
- Security settings

## Performance Tips

1. **Enable opcache** in php.ini for production
2. **Use connection pooling** for PostgreSQL
3. **Add indexes** to frequently queried columns
4. **Enable gzip compression** in your web server
5. **Use CDN** for static assets if needed

## Security Best Practices

1. **Never commit** `.env` or sensitive config files
2. **Use HTTPS** in production
3. **Keep PHP updated** to the latest version
4. **Restrict database user** privileges
5. **Enable PostgreSQL SSL** connections
6. **Regular backups** of the database
7. **Monitor logs** for suspicious activity

## Troubleshooting

### Database Connection Errors

Check your `.env` file and ensure PostgreSQL is running:

```bash
sudo systemctl status postgresql
```

### 404 Errors on All Pages

Ensure mod_rewrite is enabled (Apache) or try_files is configured (Nginx).

### Permission Denied Errors

Check file permissions:

```bash
chmod -R 755 public
```

### Session Issues

Ensure PHP can write to the session directory:

```bash
sudo chmod 1777 /var/lib/php/sessions
```

## Credits

- **Original BlackNova Traders**: Created by the BlackNova development team
- **Modern Rewrite**: Completely rewritten with modern PHP and PostgreSQL
- **License**: Check original license terms

## Contributing

To contribute to this project:

1. Fork the repository
2. Create a feature branch
3. Follow PSR-12 coding standards
4. Add type declarations to all methods
5. Test your changes thoroughly
6. Submit a pull request

## Support

For issues, questions, or contributions:
- Check the documentation
- Review existing issues
- Create a new issue with details

## License

This is a modernized version of BlackNova Traders. Please respect the original license terms.

---

**Enjoy playing BlackNova Traders!** 🚀
