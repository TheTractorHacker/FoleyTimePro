# TimeSmart Docker Deployment Guide

This guide explains how to deploy the TimeSmart time tracking system using Docker.

## Features

The Docker container includes:
- **PHP 8.3-FPM** - High-performance PHP processing
- **Nginx** - Web server
- **Cron** - Scheduled tasks (auto clock-out at 11 PM daily)
- **Supervisor** - Process management
- **All PHP extensions** - mysqli, PDO, GD (with FreeType/JPEG), ZIP
- **Composer dependencies** - QR codes, Excel export, PDF generation, push notifications

## Prerequisites

- Docker installed (version 20.10+)
- Docker Compose (optional, for easier deployment)
- MySQL/MariaDB database (can be external or in separate container)
- Git (if building from source)

## Quick Start

### Option 1: Using Pre-built Image

```bash
# Pull the image
docker pull foleyit/foleytimepro:latest

# Run with environment variables
docker run -d \
  --name timesmart \
  -p 8080:80 \
  -e DB_HOST=your_db_host \
  -e DB_NAME=timeclock \
  -e DB_USER=timeclock \
  -e DB_PASS=your_secure_password \
  -e DB_TIMEZONE=America/Chicago \
  foleyit/foleytimepro:latest
```

### Option 2: Using Docker Compose

1. Copy `docker-compose.yml` to your deployment location
2. Edit the placeholders:
   ```yaml
   container_name: COMPANY_NAME_PLACEHOLDER  # Change to your company name
   DB_HOST: DB_HOST_PLACEHOLDER             # Your database host
   DB_NAME: DB_NAME_PLACEHOLDER             # Database name
   DB_USER: DB_USER_PLACEHOLDER             # Database user
   DB_PASS: DB_PASS_PLACEHOLDER             # Database password
   ```

3. Start the container:
   ```bash
   docker-compose up -d
   ```

### Option 3: Build from Source

```bash
# Clone repository
git clone https://github.com/FoleyTimePro-App/Timesmart.git
cd Timesmart/Install

# Build image
docker build -t timesmart:custom .

# Run container
docker run -d \
  --name timesmart \
  -p 8080:80 \
  -e DB_HOST=localhost \
  -e DB_NAME=timeclock \
  -e DB_USER=timeclock \
  -e DB_PASS=SecurePassword123! \
  timesmart:custom
```

## Environment Variables

| Variable | Description | Default | Required |
|----------|-------------|---------|----------|
| `DB_HOST` | Database server hostname | `localhost` | Yes |
| `DB_NAME` | Database name | `timeclock` | Yes |
| `DB_USER` | Database username | `timeclock` | Yes |
| `DB_PASS` | Database password | - | Yes |
| `DB_TIMEZONE` | MySQL timezone | `America/Chicago` | No |

## Database Setup

### 1. Create Database and User

```sql
CREATE DATABASE timeclock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'timeclock'@'%' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON timeclock.* TO 'timeclock'@'%';
FLUSH PRIVILEGES;
```

### 2. Import Schema

The database schema will be automatically created on first run, or you can manually import:

```bash
# If using external MySQL
mysql -h your_db_host -u timeclock -p timeclock < timeclock-schema.sql

# Or from Docker container
docker exec -i timesmart mysql -h DB_HOST -u DB_USER -pDB_PASS DB_NAME < timeclock-schema.sql
```

## Container Management

### Start/Stop Container

```bash
# Start
docker start timesmart

# Stop
docker stop timesmart

# Restart
docker restart timesmart

# View logs
docker logs -f timesmart
```

### Access Container Shell

```bash
docker exec -it timesmart /bin/bash
```

### View Cron Logs

```bash
# View cron execution log
docker exec timesmart cat /var/www/html/logs/cron.log

# View auto clock-out log
docker exec timesmart cat /var/www/html/logs/auto_clockout.log

# Follow logs in real-time
docker exec timesmart tail -f /var/www/html/logs/auto_clockout.log
```

## Cron Schedule

The container runs the following automated tasks:

| Task | Schedule | Script | Purpose |
|------|----------|--------|---------|
| Auto Clock-Out | Daily at 11:00 PM | `functions/auto_clockout_cron.php` | Automatically clocks out users who forgot (1 hour after shift end) |

### Testing Cron Manually

```bash
# Run auto clock-out manually
docker exec timesmart /usr/local/bin/php /var/www/html/functions/auto_clockout_cron.php
```

### Modifying Cron Schedule

To change the cron schedule:

1. Access container shell
2. Edit `/etc/cron.d/timeclock-cron`
3. Restart cron: `supervisorctl restart cron`

Example schedules:
- `0 23 * * *` - Daily at 11:00 PM (default)
- `0 */4 * * *` - Every 4 hours
- `*/30 * * * *` - Every 30 minutes
- `0 0 * * 0` - Weekly on Sunday at midnight

## Ports

| Port | Service | Description |
|------|---------|-------------|
| 80 | HTTP | Web interface (map to host with `-p` flag) |

## Volumes (Optional)

For persistent logs and uploads:

```bash
docker run -d \
  --name timesmart \
  -p 8080:80 \
  -v /host/path/logs:/var/www/html/logs \
  -v /host/path/uploads:/var/www/html/uploads \
  -e DB_HOST=... \
  foleyit/foleytimepro:latest
```

## Networking

### Bridge Network (Default)

```bash
docker run -d \
  --name timesmart \
  -p 8080:80 \
  --network bridge \
  foleyit/foleytimepro:latest
```

### Custom Network

```bash
# Create network
docker network create timesmart-net

# Run database
docker run -d \
  --name timesmart-db \
  --network timesmart-net \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=timeclock \
  mariadb:latest

# Run app
docker run -d \
  --name timesmart \
  --network timesmart-net \
  -p 8080:80 \
  -e DB_HOST=timesmart-db \
  -e DB_NAME=timeclock \
  -e DB_USER=root \
  -e DB_PASS=root \
  foleyit/foleytimepro:latest
```

## Upgrading

### Pull Latest Image

```bash
# Stop current container
docker stop timesmart

# Pull latest image
docker pull foleyit/foleytimepro:latest

# Remove old container
docker rm timesmart

# Start with new image (use same docker run command as initial deployment)
docker run -d --name timesmart ...
```

### Database Migrations

Database migrations are included in the repo under `/migrations/`. Run them manually after upgrade:

```bash
# Access container
docker exec -it timesmart /bin/bash

# Run migrations
cd /var/www/html/migrations
php run_migrations.php
```

## Troubleshooting

### Container Won't Start

```bash
# View logs
docker logs timesmart

# Check if port is already in use
netstat -tulpn | grep :8080

# Verify environment variables
docker inspect timesmart | grep -A 10 "Env"
```

### Database Connection Issues

```bash
# Test database connection from container
docker exec timesmart /usr/local/bin/php -r "
\$conn = new mysqli('$DB_HOST', '$DB_USER', '$DB_PASS', '$DB_NAME');
if (\$conn->connect_error) {
    die('Connection failed: ' . \$conn->connect_error);
}
echo 'Connected successfully';
"
```

### Permission Issues

```bash
# Fix file permissions
docker exec timesmart chown -R www-data:www-data /var/www/html
docker exec timesmart chmod -R 755 /var/www/html
```

### Cron Not Running

```bash
# Check if cron is running
docker exec timesmart ps aux | grep cron

# Restart cron
docker exec timesmart supervisorctl restart cron

# Check cron logs
docker exec timesmart cat /var/log/cron.log
```

### View Supervisor Status

```bash
docker exec timesmart supervisorctl status
```

Expected output:
```
cron                             RUNNING   pid 15, uptime 0:05:00
nginx                            RUNNING   pid 12, uptime 0:05:00
php-fpm                          RUNNING   pid 10, uptime 0:05:00
```

## Security Best Practices

1. **Use strong database passwords**
   ```bash
   # Generate secure password
   openssl rand -base64 32
   ```

2. **Don't expose database publicly**
   - Use internal Docker network
   - Only expose web port (80/443)

3. **Use HTTPS in production**
   - Add reverse proxy (nginx/Caddy) with SSL
   - Or use Docker SSL termination

4. **Limit container resources**
   ```bash
   docker run -d \
     --name timesmart \
     --memory="512m" \
     --cpus="1.0" \
     foleyit/foleytimepro:latest
   ```

5. **Keep container updated**
   ```bash
   # Weekly/monthly update routine
   docker pull foleyit/foleytimepro:latest
   # Redeploy container
   ```

## Production Deployment

### With Nginx Reverse Proxy

1. Create `nginx.conf` on host:
   ```nginx
   server {
       listen 80;
       server_name timeclock.yourcompany.com;

       location / {
           proxy_pass http://localhost:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```

2. Enable SSL with Certbot:
   ```bash
   certbot --nginx -d timeclock.yourcompany.com
   ```

### With Docker Compose + SSL

See `docker-compose.prod.yml` for full production setup with:
- Let's Encrypt SSL
- Separate database container
- Persistent volumes
- Health checks
- Auto-restart policies

## Backup

### Database Backup

```bash
# Backup database
docker exec timesmart-db mysqldump \
  -u timeclock -p timeclock > backup-$(date +%Y%m%d).sql

# Restore database
docker exec -i timesmart-db mysql \
  -u timeclock -p timeclock < backup-20250124.sql
```

### Full Container Backup

```bash
# Backup container volumes
docker run --rm \
  --volumes-from timesmart \
  -v $(pwd):/backup \
  alpine tar czf /backup/timesmart-backup.tar.gz /var/www/html/logs /var/www/html/uploads
```

## Monitoring

### Health Check

```bash
# Add health check to docker run
docker run -d \
  --name timesmart \
  --health-cmd="curl -f http://localhost/ || exit 1" \
  --health-interval=30s \
  --health-timeout=3s \
  --health-retries=3 \
  foleyit/foleytimepro:latest

# View health status
docker inspect --format='{{.State.Health.Status}}' timesmart
```

## Support

- **GitHub Issues**: https://github.com/FoleyTimePro-App/Timesmart/issues
- **Documentation**: See `/docs` folder
- **API Docs**: See `/api/README.md`

## License

See LICENSE file in repository root.
