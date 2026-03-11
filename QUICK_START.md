# OxySafe – Quick Start with XAMPP

Follow these steps to get the website running locally on your machine using XAMPP.

---

## Prerequisites

- [XAMPP](https://www.apachefriends.org/) installed (v7.4 or later recommended)
- PHP 7.4+, Apache, and MySQL included with XAMPP

---

## Step 1 – Start XAMPP Services

1. Open the **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

Both status indicators should turn green before continuing.

---

## Step 2 – Copy Project Files

Copy the `website/` folder into XAMPP's web root:

```
C:\xampp\htdocs\oxysafe\
```

Your final structure should look like:

```
C:\xampp\htdocs\oxysafe\
    config.php
    index.php
    dashboard.php
    ...
    db\
        schema.sql
        seed.php
```

---

## Step 3 – Create the Database

1. Open your browser and go to: [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. Click **New** in the left sidebar to create a new database, **or** let the schema script handle it automatically in the next step.
3. Click the **SQL** tab (in the top menu bar).
4. Click **Choose File**, select `website/db/schema.sql`, then click **Go**.

   Alternatively, run it from the XAMPP shell:

   ```bash
   mysql -u root < "C:\xampp\htdocs\oxysafe\db\schema.sql"
   ```

   This creates the `oxysafe_db` database, all tables, and the auto-purge event.

> **Note:** To enable the auto-purge scheduled event, make sure the MySQL event scheduler is on. Run this once in phpMyAdmin's SQL tab:
> ```sql
> SET GLOBAL event_scheduler = ON;
> ```

---

## Step 4 – Seed the Admin User

Run the seed script to create the default administrator account:

```bash
php "C:\xampp\htdocs\oxysafe\db\seed.php"
```

Or open it in your browser:

```
http://localhost/oxysafe/db/seed.php
```

Default admin credentials created by the seed:

| Field    | Value       |
|----------|-------------|
| Username | `admin`     |
| Password | `admin@123` |

> **Security:** Change this password immediately after your first login via the admin dashboard.

---

## Step 5 – Update `config.php`

Open `C:\xampp\htdocs\oxysafe\config.php` and update the `BASE_URL` constant to match your local URL:

```php
define('BASE_URL', 'http://localhost/oxysafe');
```

The database credentials default to XAMPP's defaults (`root` / no password) and should already work out of the box.

---

## Step 6 – Open the Website

Navigate to the login page in your browser:

```
http://localhost/oxysafe/index.php
```

Log in with the admin credentials from Step 4.

---

## Summary of URLs

| Page             | URL                                          |
|------------------|----------------------------------------------|
| Login            | `http://localhost/oxysafe/index.php`         |
| User Dashboard   | `http://localhost/oxysafe/user_dashboard.php`|
| Admin Dashboard  | `http://localhost/oxysafe/admin_dashboard.php`|
| phpMyAdmin       | `http://localhost/phpmyadmin`                |

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Apache/MySQL won't start | Check if port 80 or 3306 is already in use. Change ports in XAMPP config or stop the conflicting service. |
| "Database connection failed" | Ensure MySQL is running and `config.php` credentials match your XAMPP setup. |
| Blank page or PHP errors | Make sure you copied files to the correct `htdocs` subfolder and `BASE_URL` is set correctly. |
| Seed script says "Admin already exists" | The admin user was already created. You can log in directly. |
