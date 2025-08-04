#!/bin/bash

# Script to restore a MySQL database from a gzipped SQL backup file.

BACKUP_FILE="$1"

# --- Input Validation ---
if [ -z "$BACKUP_FILE" ]; then
    echo "Usage: $0 <path_to_gzipped_sql_backup_file>"
    echo "Example: $0 /var/li/mysql/laravel_backup/backup_ab5_20250803_032424.sql.gz"
    exit 1
fi

if [ ! -f "$BACKUP_FILE" ]; then
    echo "Error: Backup file not found at '$BACKUP_FILE'"
    exit 1

fi

if [[ "$BACKUP_FILE" != *.sql.gz ]]; then
    echo "Error: Backup file must be a .sql.gz file."
    exit 1
fi

echo "--- Starting MySQL Database Restore ---"
echo "Backup file: $BACKUP_FILE"

# --- Extract MySQL Credentials from .env ---
ENV_FILE="/home/eric-shared/PhpstormProjects/librarian/.env"

if [ ! -f "$ENV_FILE" ]; then
    echo "Error: .env file not found at '$ENV_FILE'. Cannot retrieve database credentials."
    exit 1
fi

DB_DATABASE=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f 2-)
DB_USERNAME=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f 2-)
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d '=' -f 2-)
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f 2-)
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f 2-)

# Remove potential carriage returns from Windows-style .env files
DB_DATABASE=$(echo "$DB_DATABASE" | tr -d '\r')
DB_USERNAME=$(echo "$DB_USERNAME" | tr -d '\r')
DB_PASSWORD=$(echo "$DB_PASSWORD" | tr -d '\r')
DB_HOST=$(echo "$DB_HOST" | tr -d '\r')
DB_PORT=$(echo "$DB_PORT" | tr -d '\r')

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ] || [ -z "$DB_HOST" ]; then
    echo "Error: Missing one or more required database credentials (DB_DATABASE, DB_USERNAME, DB_HOST) in $ENV_FILE."
    exit 1
fi

echo "Database: $DB_DATABASE"
echo "User: $DB_USERNAME"
echo "Host: $DB_HOST"
echo "Port: ${DB_PORT:-3306} (defaulting to 3306 if not set)"

# --- Check if tables are empty to skip confirmation ---
echo "Checking if database tables are empty..."

# Check if users table exists and is empty
USERS_COUNT=$(mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -se "SELECT COUNT(*) FROM users" 2>/dev/null || echo "0")

# Check if books table exists and is empty
BOOKS_COUNT=$(mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -se "SELECT COUNT(*) FROM books" 2>/dev/null || echo "0")

echo "Users table count: $USERS_COUNT"
echo "Books table count: $BOOKS_COUNT"

# Skip confirmation if both tables are empty
if [ "$USERS_COUNT" -eq 0 ] && [ "$BOOKS_COUNT" -eq 0 ]; then
    echo "Both users and books tables are empty. Proceeding with restore without confirmation."
else
    # --- Confirm with User ---
    read -p "This will overwrite the '$DB_DATABASE' database. Are you sure you want to continue? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Restore cancelled by user."
        exit 0
    fi
fi

# --- Perform Restore ---
echo "Decompressing and importing database..."

# Use gunzip -c to decompress to stdout and pipe directly to mysql
# Note: Passing password directly with -pPASSWORD is insecure.
# For production, consider .my.cnf or prompting for password.
if gunzip -c "$BACKUP_FILE" | mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"; then
    echo "--- Database Restore Completed Successfully! ---"
else
    echo "--- Error: Database Restore Failed! ---"
    exit 1
fi

exit 0