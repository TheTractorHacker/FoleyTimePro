#!/bin/bash

# Check if Docker is installed and running
if ! [ -x "$(command -v docker)" ]; then
    echo "Error: Docker is not installed." >&2
    exit 1
fi

if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running." >&2
    exit 1
fi

# Ask for the company name
read -p "Enter the company name: " company_name

# Create a directory for the company
mkdir -p "Timeclock-$company_name"
cd "Timeclock-$company_name"

# Download necessary files from GitHub
echo "📥 Downloading files from GitHub..."
curl -L https://raw.githubusercontent.com/D-Best-App/Timesmart/main/Install/docker-compose.yml -o docker-compose.yml
curl -L https://raw.githubusercontent.com/D-Best-App/Timesmart/main/Install/timeclock-schema.sql -o timeclock-schema.sql

# Ask for database credentials
read -p "Enter database host: " db_host
read -p "Enter database user: " db_user
read -s -p "Enter database password: " db_pass
echo

# Ask to create Docker container
read -p "Create Docker container named 'Timeclock-$company_name'? (y/n): " create_docker
if [ "$create_docker" == "y" ]; then
    echo "🐳 Creating Docker container..."
    sed -i "s/COMPANY_NAME_PLACEHOLDER/Timeclock-$company_name/g" docker-compose.yml
    sed -i "s/DB_HOST_PLACEHOLDER/$db_host/g" docker-compose.yml
    sed -i "s/DB_NAME_PLACEHOLDER/timeclock-$company_name/g" docker-compose.yml
    sed -i "s/DB_USER_PLACEHOLDER/$db_user/g" docker-compose.yml
    sed -i "s/DB_PASS_PLACEHOLDER/$db_pass/g" docker-compose.yml
    docker compose -f docker-compose.yml up -d
fi

# Ask to create database
read -p "Create database named 'timeclock-$company_name'? (y/n): " create_database
if [ "$create_database" == "y" ]; then
    echo "📦 Creating database..."
    sed "s/DB_NAME_PLACEHOLDER/timeclock-$company_name/g" timeclock-schema.sql > timeclock-schema-temp.sql
    mysql -h "$db_host" -u "$db_user" -p"$db_pass" < timeclock-schema-temp.sql
    rm timeclock-schema-temp.sql
fi

# Ask to create backup script
read -p "Create backup.sh script and cron job for all companies? (y/n): " create_backup
if [ "$create_backup" == "y" ]; then
    echo "🛡 Creating backup.sh script..."

    cat << 'EOF' > /usr/local/bin/backup.sh
#!/bin/bash

DB_USER="timeclock"
DB_PASS="SecureNet25!"
DB_HOST="172.17.0.1"
BACKUP_BASE="/var/sql-data"
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
HOUR=$(date +"%H")

for dir in "$BACKUP_BASE"/*/; do
    COMPANY_NAME=$(basename "$dir")
    DB_NAME="timeclock-$COMPANY_NAME"
    BACKUP_DIR="$BACKUP_BASE/$COMPANY_NAME"
    DAILY_BACKUP_DIR="$BACKUP_DIR/daily-backup"

    mkdir -p "$DAILY_BACKUP_DIR"

    BACKUP_FILE="$BACKUP_DIR/${DB_NAME}-${DATE}.sql"
    echo "📤 Backing up $DB_NAME -> $BACKUP_FILE"
    mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"

    # Rotate hourly backups (keep last 8)
    ls -1t "$BACKUP_DIR"/*.sql 2>/dev/null | tail -n +9 | xargs -r rm --

    # Daily backup at 8 PM
    if [ "$HOUR" -eq 20 ]; then
        cp "$BACKUP_FILE" "$DAILY_BACKUP_DIR/"
    fi
done
EOF

    chmod +x /usr/local/bin/backup.sh

    # Add cron job (avoids duplicate entries)
    (crontab -l 2>/dev/null | grep -v 'backup.sh'; echo "0 * * * * /usr/local/bin/backup.sh") | crontab -

    echo "✅ /usr/local/bin/backup.sh created and scheduled."
fi

echo "✅ Timeclock install complete for $company_name"