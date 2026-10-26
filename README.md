# Lekke Lokal

**Lekke Lokal** is a community-focused consumer-to-consumer (C2C) e-commerce marketplace for local buyers and sellers. It was developed as a web development and e-commerce project using PHP and MySQL.

**Live website:** [lekkelokal.infinityfree.io](https://lekkelokal.infinityfree.io)  
**Source code:** [GitHub repository](https://github.com/Coding-afk/LekkeLokal)

## Key features

- **Accounts and security:** registration, CAPTCHA, login, email OTP verification and password reset.
- **Marketplace:** browse listings, search by keyword and filter by category or location.
- **Product management:** create listings with photos, edit listings, and remove or restore them.
- **Buying and selling:** place orders, view order history, manage sales and mark sales as completed.
- **Communication:** buyer–seller messaging, reviews and complaints.
- **Trust and moderation:** seller verification, trust status and administrator management features.
- **Support:** downloadable PDF user guide from the marketplace support section.

## Technology stack

| Technology | Purpose |
| --- | --- |
| HTML | Page structure and templates |
| CSS | Styling and mobile-responsive layouts |
| JavaScript | Interactive forms, image previews and client-side validation |
| PHP | Server-side application logic, authentication and database access |
| MySQL | Stores account, listing, order and other marketplace records |
| Apache / XAMPP | Local development and testing |
| InfinityFree | Live PHP/MySQL hosting |

## Running locally

1. Install **XAMPP** or another environment with PHP, Apache and MySQL.
2. Copy the project folder into your web server's document root (for example, `C:\xampp\htdocs\LekkeLokal`).
3. Start **Apache** and **MySQL**.
4. In phpMyAdmin, create a database named `lekke_lokal` and import `database/database.sql` into the **empty** database.
5. Copy `includes/db_example.php` to `includes/db.php`, then adjust the database connection values for your environment.
6. Copy `includes/email_config_example.php` to `includes/email_config.php`. To test email OTP and password reset, configure a valid SMTP service using credentials kept out of version control. The example configuration may have SMTP disabled.
7. Open `http://localhost/LekkeLokal/` in a browser.

**Important:** Some flows require working email delivery and appropriately configured test accounts. Do not bypass account verification in a public deployment. If the SQL file contains demo users or an administrator seed, replace any reusable default credentials before deploying.

## Security and privacy

This repository is intended to contain **source code only**. Private files such as `includes/db.php`, `includes/email_config.php`, OTP logs, uploaded verification documents, and live database exports must not be committed. Use the provided example configuration files when setting up a new environment.

If a secret is ever committed, remove it from repository history as appropriate and **rotate the credential**; adding the file to `.gitignore` afterwards is not sufficient.

## User guide

The website includes a downloadable user manual at `downloads/Lekke-Lokal-User-Guide.pdf` and a download link in the marketplace support section.

## Showcase

The live demonstration can cover registration/login, marketplace search and filters, listing creation and management, purchasing, messaging, order/sale management, seller verification and administration.
