# FoleyTimePro — Docker Deployment Guide

## How the build pipeline works

```
git push to main
      ↓
GitHub Actions builds linux/amd64 + linux/arm64 image
      ↓
Pushes to Docker Hub: bfoley23/foleytimepro:latest
      ↓
Run install.sh on the client's server to deploy
```

---

## Deploying on a client server (install.sh)

This is the one-command way to set up a new client:

```bash
curl -fsSL https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/Install/install.sh | bash
```

It will ask for:
- Company name
- Database host, user, password
- Whether to create the DB schema
- Whether to set up the hourly backup cron

---

## Manual deployment with Docker Compose

Use this if you want more control than `install.sh`.

### Simple deploy (external DB)

```bash
# 1. Get the compose file and env template
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/docker-compose.yml -o docker-compose.yml
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/.env.example -o .env

# 2. Fill in your database credentials
nano .env

# 3. Start
docker compose up -d
```

### Full production stack (DB + SSL + auto-updates)

```bash
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/docker-compose.prod.yml -o docker-compose.prod.yml
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/.env.example -o .env
nano .env   # set DOMAIN, CERTBOT_EMAIL, and passwords
docker compose -f docker-compose.prod.yml up -d
```

First-time SSL certificate:
```bash
docker compose -f docker-compose.prod.yml run --rm certbot certonly \
  --webroot --webroot-path=/var/www/certbot \
  -d yourdomain.com --email you@email.com --agree-tos --no-eff-email
```

---

## Environment variables

| Variable | Description | Required |
|---|---|---|
| `DB_HOST` | Database server hostname or IP | Yes |
| `DB_NAME` | Database name | Yes |
| `DB_USER` | Database username | Yes |
| `DB_PASS` | Database password | Yes |
| `DB_TIMEZONE` | PHP/MySQL timezone (default: `America/Chicago`) | No |
| `DOMAIN` | Domain name (prod stack only) | Prod only |
| `CERTBOT_EMAIL` | Email for Let's Encrypt (prod stack only) | Prod only |

---

## Database setup

```sql
CREATE DATABASE timeclock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'timeclock'@'%' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON timeclock.* TO 'timeclock'@'%';
FLUSH PRIVILEGES;
```

Import the schema:
```bash
mysql -h your_db_host -u timeclock -p timeclock < timeclock-schema.sql
```

---

## Managing a running container

```bash
docker logs -f foleytimepro          # live logs
docker exec -it foleytimepro bash    # shell access
docker restart foleytimepro          # restart
docker stop foleytimepro             # stop
docker pull bfoley23/foleytimepro:latest && docker compose up -d  # update
```

Supervisor status (nginx + php-fpm):
```bash
docker exec foleytimepro supervisorctl status
```

---

## Building the image (developers only)

The image is built automatically by GitHub Actions on every push to `main`.
No manual build step is needed for deployment.

To trigger a manual build: **GitHub repo → Actions → Build & Push Docker Image → Run workflow**

To build locally (requires Docker with buildx + QEMU):
```bash
./build.sh           # tags as latest
./build.sh 2.2.0     # tags as version + latest
```

---

## Backup

```bash
# Database backup
mysqldump -h DB_HOST -u timeclock -p timeclock > backup-$(date +%Y%m%d).sql

# Restore
mysql -h DB_HOST -u timeclock -p timeclock < backup-20260516.sql
```

The `install.sh` script can set up an automatic hourly backup cron when you deploy.

---

## Troubleshooting

**Container won't start:**
```bash
docker logs foleytimepro
```

**Database connection refused:**
```bash
docker exec foleytimepro php -r "
\$c = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));
echo \$c->connect_error ? 'FAIL: '.\$c->connect_error : 'OK';
"
```

**Permission errors:**
```bash
docker exec foleytimepro chown -R www-data:www-data /var/www/html
```

---

## Support

- GitHub: https://github.com/TheTractorHacker/FoleyTimePro
- Issues: https://github.com/TheTractorHacker/FoleyTimePro/issues
