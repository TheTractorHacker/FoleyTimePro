# FoleyTimePro

A modern, self-hosted time clock application built for small businesses. Employees clock in and out from any browser or the dedicated kiosk — admins get a full dashboard with reports, scheduling, and payroll exports.

**Current version: v1.5.0** · [Releases](https://github.com/TheTractorHacker/FoleyTimePro/releases) · [Docker Hub](https://hub.docker.com/r/bfoley23/foleytimepro)

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
| **Multi-company** | Run isolated instances per company on the same host |
| **Branding** | Custom logo, company name, and theme color |

---

## Quick Start (Single Company)

```bash
git clone https://github.com/TheTractorHacker/FoleyTimePro.git
cd FoleyTimePro/docker

./deploy.sh --company acme --port 8080
```

That's it. The script starts a MariaDB container and the app container, generates strong random passwords, and prints the access URL.

---

## Multi-Company Hosting

Run completely isolated instances for multiple companies on the same host — each gets its own database, containers, and network.

```bash
./deploy.sh --company acme  --port 8081
./deploy.sh --company beta  --port 8082
./deploy.sh --company gamma --port 8083
```

Each company gets:

| Resource | Pattern |
|---|---|
| App container | `acme_app` |
| DB container | `acme_db` |
| Database | `timeclock_acme` |
| Data volume | `acme_db_data` |
| Network | `acme_internal` |

Credentials are auto-generated on first deploy and saved to `docker/companies/<name>.env`. That file is gitignored — never committed.

**Stop a company** (keeps data):
```bash
./deploy.sh --company acme --port 8081 --down
```

---

## Manual Deploy (Docker Compose)

If you prefer to manage env vars yourself:

```bash
cd docker
cp .env.example .env
nano .env   # set COMPANY, APP_PORT, DB passwords
docker compose -p acme --env-file .env up -d
```

---

## Production Deploy (SSL)

```bash
cd docker
cp .env.example .env
nano .env   # set DOMAIN, CERTBOT_EMAIL, DB passwords
docker compose -f docker-compose.prod.yml up -d
```

Includes MariaDB, Nginx with Let's Encrypt SSL, and Watchtower for automatic Docker image updates.

---

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `COMPANY` | Company identifier — names containers and DB | `timeclock` |
| `APP_PORT` | Host port the web server listens on | `8080` |
| `DB_NAME` | Database name | `timeclock` |
| `DB_USER` | Database username | `timeclock` |
| `DB_PASS` | Database password | **Required** |
| `DB_ROOT_PASSWORD` | MariaDB root password | **Required** |
| `DB_TIMEZONE` | Timezone for PHP and MySQL | `America/Chicago` |
| `DOMAIN` | Domain name (production SSL stack only) | — |
| `CERTBOT_EMAIL` | Let's Encrypt email (production SSL stack only) | — |

---

## First Login

| | URL | Credentials |
|---|---|---|
| **Admin** | `http://your-server:<port>/admin/login.php` | Set on first run |
| **Employee** | `http://your-server:<port>/user/login.php` | Created by admin |
| **Kiosk** | `http://your-server:<port>/kiosk/` | PIN-based |

---

## Updating

```bash
# Pull latest image and restart
cd docker
./deploy.sh --company acme --port 8081
```

`deploy.sh` always pulls the latest image (`--pull always`) before starting. Watchtower handles automatic updates in the production SSL stack.

---

## Stack

- **PHP 8.5** with Nginx and PHP-FPM
- **MariaDB 11** — one container per company
- **Supervisor** managing nginx + php-fpm in one container
- **Composer** dependencies: PHPMailer, OTPHP, PhpSpreadsheet, TCPDF, phpdotenv
- **CI/CD**: GitHub Actions builds and pushes multi-arch images (amd64 + arm64) on every push to `main`

---

## Development

```bash
git clone https://github.com/TheTractorHacker/FoleyTimePro.git
cd FoleyTimePro
cp auth/.env.example auth/.env   # fill in local DB creds
```

Build and push Docker image manually:
```bash
./build.sh           # tag: latest
./build.sh 1.3.0     # tag: 1.3.0 + latest
```

The GitHub Actions workflow (`.github/workflows/docker-publish.yml`) runs automatically on every push to `main`.

---

## Troubleshooting

**Check logs:**
```bash
docker logs acme_app
docker logs acme_db
```

**Test DB connection from inside the app container:**
```bash
docker exec acme_app php -r "
\$c = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'));
echo \$c->connect_error ? 'FAIL: '.\$c->connect_error : 'Connected OK';
"
```

**Common issues:**

| Problem | Fix |
|---|---|
| Port already in use | Choose a different `--port` |
| DB not ready yet | Wait ~30s on first start; MariaDB initializes the schema |
| Permission errors on uploads | `docker exec acme_app chown -R www-data:www-data /var/www/html/uploads` |
| DB connection refused | `DB_HOST` is always `db` inside compose — don't change it |

---

## Links

- [GitHub](https://github.com/TheTractorHacker/FoleyTimePro)
- [Docker Hub](https://hub.docker.com/r/bfoley23/foleytimepro)
- [Issues](https://github.com/TheTractorHacker/FoleyTimePro/issues)
- [Releases](https://github.com/TheTractorHacker/FoleyTimePro/releases)
- [Deployment Guide](Install/DOCKER_DEPLOYMENT.md)
