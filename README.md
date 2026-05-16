# 🕒 FoleyTimePro

**FoleyTimePro** is a modern, web-based time clock application built for small businesses, schools, and organizations needing efficient time tracking and user management. It supports employees clocking in/out and offers powerful tools for administrators to manage users, view reports, and export data — all in a secure and user-friendly interface.

---

## ✅ Key Features

- **🕘 Employee Time Tracking**  
  Clock In, Lunch, Break, and Clock Out with GPS and device logging.
  
- **👥 User Management**  
  Add, update, or remove employee accounts and manage permissions.
  
- **📊 Admin Dashboard**  
  Get real-time overviews of employee activity and time logs.
  
- **📁 Reports & Exports**  
  Generate and export attendance data (PDF, Excel, summary reports).
  
- **🔐 Two-Factor Authentication (2FA)**  
  Built-in support for optional 2FA via email or SMS.
  
- **📄 Legal Pages**  
  Includes Privacy Policy and Terms of Use pages.

---

## 🧰 Prerequisites

Ensure the following are installed:

- **Docker**  
  Required for containerized deployment.
  
- **MySQL Client**  
  Required to create the database during setup.

---

## 🚀 Quick Installation

Run the official installation script to deploy a new instance:

```bash
curl -o install.sh https://raw.githubusercontent.com/FoleyTimePro-App/Timesmart/main/Install/install.sh
chmod +x install.sh
sudo ./install.sh
```

### 🧩 What the script does:

- Prompts for **Company Name**, which sets the container/database name.
- Asks for **Database Host, User, and Password**.
- Downloads `docker-compose.yml` and SQL schema from GitHub.
- Creates the Docker container from the image: `foleyit/foleytimepro:latest`
- Initializes a new MySQL database (if requested).

---

## 🌐 Accessing the App

After installation, determine the container’s IP (if not using port mapping):

```bash
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' Timeclock-<YourCompanyName>
```

Replace `<YourCompanyName>` with your actual name used during setup.

Then open in your browser:

```
http://<container_ip>
```

---

## 👨‍💼 Admin Access

- URL: `http://<container_ip>/admin/login.php`
- **Default Credentials:**
  - Username: `admin`
  - Password: `admin`

You should change this password immediately after first login.

---

## 👷 Employee Access

- URL: `http://<container_ip>/user/login.php`
- Credentials must be created by the administrator.

---

## 🛠️ Troubleshooting

| Issue | Solution |
|-------|----------|
| `docker: command not found` | Install Docker and ensure it is in your PATH |
| `mysql: command not found` | Install the MySQL/MariaDB client |
| Database connection fails | Re-run the script and verify credentials are correct |
