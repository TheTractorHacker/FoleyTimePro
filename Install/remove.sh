#!/bin/bash

# --- CONFIG ---
DB_USER="timeclock"
DB_PASS="SecureNet25!"
DB_HOST="172.17.0.1"
BACKUP_BASE="/var/sql-data"

# --- Ensure Docker is running ---
if ! [ -x "$(command -v docker)" ]; then
    echo "❌ Docker is not installed."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "❌ Docker is not running."
    exit 1
fi

# --- Get list of companies ---
companies=()
while IFS= read -r -d '' dir; do
    companies+=("$(basename "$dir")")
done < <(find "$BACKUP_BASE" -mindepth 1 -maxdepth 1 -type d -print0)

# --- Check if any companies exist ---
if [ ${#companies[@]} -eq 0 ]; then
    echo "⚠️ No companies found in $BACKUP_BASE"
    exit 1
fi

# --- Let user select company ---
echo "🗃 Available Companies:"
select company_name in "${companies[@]}"; do
    if [[ -n "$company_name" ]]; then
        break
    else
        echo "❌ Invalid selection."
    fi
done

# --- Confirm ---
read -p "⚠️ Are you sure you want to remove $company_name (y/n)? " confirm
if [[ "$confirm" != "y" ]]; then
    echo "❎ Aborted."
    exit 0
fi

DB_NAME="timeclock-$company_name"
CONTAINER_NAME="Timeclock-$company_name"
FINAL_BACKUP_DIR="./$company_name"
FINAL_BACKUP_FILE="$FINAL_BACKUP_DIR/${DB_NAME}-final-backup.sql"

# --- Stop & remove Docker container ---
echo "🛑 Stopping and removing container: $CONTAINER_NAME"
docker compose -f "$CONTAINER_NAME/docker-compose.yml" down 2>/dev/null || docker rm -f "$CONTAINER_NAME" 2>/dev/null

# --- Create final backup ---
mkdir -p "$FINAL_BACKUP_DIR"
echo "📤 Dumping final database to $FINAL_BACKUP_FILE"
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$FINAL_BACKUP_FILE"

# --- Drop the database ---
echo "🔥 Dropping database: $DB_NAME"
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE \`$DB_NAME\`;"

# --- Delete data directories ---
echo "🗑 Removing Docker and backup directories"
rm -rf "Timeclock-$company_name"
rm -rf "$BACKUP_BASE/$company_name"

# --- Done ---
echo "✅ Company $company_name removed."
echo "🗃 Final backup saved at: $FINAL_BACKUP_FILE"
