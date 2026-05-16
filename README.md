# FoleyTimePro

A modern, self-hosted time clock application built for small businesses. Employees clock in and out from any browser or the dedicated kiosk — admins get a full dashboard with reports, scheduling, and payroll exports.

**Current version: v1.2.2** · [Releases](https://github.com/TheTractorHacker/FoleyTimePro/releases) · [Docker Hub](https://hub.docker.com/r/foleyit/foleytimepro)

---

## Features

| Category | What's included |
|---|---|
| **Time tracking** | Clock in/out, lunch, GPS location logging |
| **Kiosk mode** | Full-screen immersive clock for shared devices |
| **Shift management** | Assign shifts, auto clock-out, lunch duration rules |
| **Admin dashboard** | Real-time activity, pending edits, time-off queue |
| **Reports & exports** | PDF summaries, Excel exports, attendance history |
| **Time-off requests** | Employee submission, admin approve/reject, leave balances |
| **Geofencing** | Block clock-ins outside defined GPS boundaries |
| **QuickBooks** | OAuth2 payroll export integration |
| **2FA** | TOTP authenticator app support for admins and employees |
| **Push notifications** | Web push for punch events |
| **Multi-office** | Separate locations under one installation |
| **Branding** | Custom logo, company name, and theme color |

---

## Quick Install

Requires Docker and a MySQL/MariaDB database already running.

```bash
curl -fsSL https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/Install/install.sh | bash
```

The script will ask for:
- **Company name** — used as the container and database name
- **Database host, user, password**
- Whether to import the schema into a new database
- Whether to set up an automatic hourly backup cron

---

## Manual Install (Docker Compose)

```bash
# Download files
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/docker-compose.yml -o docker-compose.yml
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/.env.example -o .env

# Fill in your database credentials
nano .env

# Start
docker compose up -d
```

---

## Production Deploy (SSL + Database + Auto-updates)

```bash
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/docker-compose.prod.yml -o docker-compose.prod.yml
curl -L https://raw.githubusercontent.com/TheTractorHacker/FoleyTimePro/main/docker/.env.example -o .env
nano .env   # set DOMAIN, CERTBOT_EMAIL, DB passwords
docker compose -f docker-compose.prod.yml up -d
```

Includes MariaDB, Nginx with Let's Encrypt SSL, and Watchtower for automatic Docker image updates.

---

## Environment Variables

| Variable | Description | Required |
|---|---|---|
| `DB_HOST` | Database server hostname or IP | Yes |
| `DB_NAME` | Database name | Yes |
| `DB_USER` | Database username | Yes |
| `DB_PASS` | Database password | Yes |
| `DB_TIMEZONE` | Timezone for PHP and MySQL (default: `America/Chicago`) | No |
| `DOMAIN` | Your domain name (production stack only) | Prod |
| `CERTBOT_EMAIL` | Email for Let's Encrypt (production stack only) | Prod |

---

## Database Setup

```sql
CREATE DATABASE timeclock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'timeclock'@'%' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON timeclock.* TO 'timeclock'@'%';
FLUSH PRIVILEGES;
```

Import schema:
```bash
mysql -h your_db_host -u timeclock -p timeclock < Install/timeclock-schema.sql
```

---

## First Login

| | URL | Default credentials |
|---|---|---|
| **Admin** | `http://your-server/admin/login.php` | Set on first run |
| **Employee** | `http://your-server/user/login.php` | Created by admin |
| **Kiosk** | `http://your-server/kiosk/` | PIN-based, no account needed |

---

## Updating

Watchtower handles updates automatically in the production stack. For manual updates:

```bash
docker pull foleyit/foleytimepro:latest
docker compose up -d
```

---

## Stack

- **PHP 8.5** with Nginx and PHP-FPM
- **Supervisor** managing both processes in one container
- **Composer** dependencies: PHPMailer, OTPHP, PhpSpreadsheet, TCPDF, phpdotenv
- **CI/CD**: GitHub Actions builds and pushes multi-arch images (amd64 + arm64) on every commit to `main`

---

## Development

Clone and build locally:

```bash
git clone https://github.com/TheTractorHacker/FoleyTimePro.git
cd FoleyTimePro
cp .env.example .env  # fill in local DB creds
```

Build and push Docker image:
```bash
./build.sh           # latest
./build.sh 1.3.0     # versioned
```

The GitHub Actions workflow in `.github/workflows/docker-publish.yml` runs automatically on push to `main` and on version tags.

---

## Troubleshooting

**Container won't start:**
```bash
docker logs foleytimepro
```

**Database connection failed:**
```bash
docker exec foleytimepro php -r "
\$c = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));
echo \$c->connect_error ? 'FAIL: '.\$c->connect_error : 'Connected OK';
"
```

**Common issues:**

| Problem | Fix |
|---|---|
| Port 80 already in use | Change `"8080:80"` in compose file |
| DB connection refused | Check `DB_HOST` — use host IP, not `localhost` inside Docker |
| Permission errors on uploads | `docker exec foleytimepro chown -R www-data:www-data /var/www/html` |

---

## Links

- [GitHub](https://github.com/TheTractorHacker/FoleyTimePro)
- [Docker Hub](https://hub.docker.com/r/foleyit/foleytimepro)
- [Issues](https://github.com/TheTractorHacker/FoleyTimePro/issues)
- [Releases](https://github.com/TheTractorHacker/FoleyTimePro/releases)
- [Deployment Guide](Install/DOCKER_DEPLOYMENT.md)
