# D-BEST TimeSmart

D-BEST TimeSmart is a web-based time clock application designed to manage employee time attendance records efficiently. It provides functionalities for employees to clock in/out and for administrators to manage users, view reports, and export data.

## Features

*   **Employee Time Tracking:** Clock-in and clock-out functionality.
*   **User Management:** Add, edit, and manage employee accounts.
*   **Admin Dashboard:** Overview of time clock activities.
*   **Reporting:** Generate attendance reports, summaries, and export data (Excel, PDF).
*   **Two-Factor Authentication (2FA):** Enhanced security for user logins.
*   **Privacy Policy & Terms of Use:** Dedicated pages for legal information.

## Prerequisites

Before you begin, ensure you have the following installed on your system:

*   **Docker:** For running the application in a container.
*   **Docker Compose:** For managing the Docker containers.

## Installation

Follow these steps to set up D-BEST TimeSmart using Docker:

1.  **Clone the repository:**

    ```bash
    git clone https://github.com/D-Best-Apps/Timesmart.git
    cd Timesmart
    ```

2.  **Create a `.env` file for Docker Compose:**

    In the `Install` directory, create a `.env` file containing your database credentials. Docker Compose will read this file and inject the variables into the running container:

    ```
    DB_HOST=your_database_host
    DB_USER=your_database_user
    DB_PASS=your_database_password
    DB_NAME=your_database_name
    DB_TIMEZONE=America/Chicago
    ```

3.  **Database Setup:**

    This Docker setup does not include a database. You will need to provide your own MySQL or MariaDB database. Once you have created your database, you can import the database schema from the `Install/timeclock-schema.sql` file. This will create the necessary tables and seed them with some initial data.

4.  **Build and Run the Application:**

    From the `Install` directory, run the following command to build and start the application:

    ```bash
    docker-compose up -d
    ```

5.  **Access the Application:**

    *   Open your web browser and navigate to `http://localhost:8080`.

## Usage

*   **Admin Login:**
    *   Navigate to `http://localhost:8080/admin/login.php` to access the admin login page.
    *   The default admin credentials are:
        *   **Username:** admin
        *   **Password:** password
*   **Employee Login:**
    *   Navigate to `http://localhost:8080/user/login.php` to access the employee login page.
    *   Employees can log in with the credentials created by the administrator.

## Troubleshooting

*   **`docker-compose up` fails:** Ensure that you have Docker and Docker Compose installed correctly. Also, make sure that you are in the `Install` directory when you run the command.
*   **Database Connection Error:** Double-check your database credentials in the `.env` file used by Docker Compose.
*   **Page Not Found (404):** Ensure that the application is running correctly by checking the Docker logs:

    ```bash
    docker-compose logs -f
    ```

## Contributing

Contributions are welcome! Please feel free to fork the repository, make your changes, and submit a pull request.

## License

[Specify your license here, e.g., MIT License]