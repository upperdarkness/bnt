#!/bin/bash

# BlackNova Traders - Database Initialization Script

set -e

# Default values
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-blacknova}"
DB_USER="${DB_USER:-bnt}"
DB_PASS="${DB_PASS:-bnt}"

echo "BlackNova Traders - Database Setup"
echo "=================================="
echo ""
echo "Database: $DB_NAME"
echo "Host: $DB_HOST:$DB_PORT"
echo "User: $DB_USER"
echo ""

# Check if PostgreSQL is available
if ! command -v psql &> /dev/null; then
    echo "Error: PostgreSQL client (psql) not found"
    exit 1
fi

# Create database if it doesn't exist
echo "Creating database..."
PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'" | grep -q 1 || \
PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "CREATE DATABASE $DB_NAME"

# Run schema
echo "Running schema..."
PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$(dirname "$0")/../database/schema.sql"

echo ""
echo "Database setup complete!"
echo ""
echo "Next steps:"
echo "1. Run: php scripts/create_universe.php 1000 200"
echo "   (Creates 1000 sectors and 200 planets)"
echo ""
echo "2. Start your web server pointing to the 'public' directory"
echo ""
echo "3. Visit http://your-server/ to start playing!"
