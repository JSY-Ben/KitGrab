<img width="684" height="676" alt="kitgrab-logo" src="https://github.com/user-attachments/assets/184d83af-a15f-4e4e-a201-9a19eaa15610" />


# KitGrab - An Asset Reservation/Checkout System

[![Donate with PayPal to help me continue developing these apps!](https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif)](https://www.paypal.com/donate/?business=5TRANVZF49AN6&no_recurring=0&item_name=Thank+you+for+any+donations%21+It+will+help+me+put+money+into+the+tools+I+use+to+develop+my+apps+and+services.&currency_code=GBP)

Please note - this app is still in a beta stage of development as a product. Please do use it, report issues and request features, but consider it unsuitable for a high risk production environment until any bugs have been ironed out.

There is also a version of this app specifically designed to work with Snipe-IT's Inventory Database. It is called SnipeScheduler, and is available [here](https://github.com/JSY-Ben/SnipeScheduler)

KitGrab is a PHP/MySQL web app for equipment booking, checkout workflows, and asset tracking. It uses its own local asset model and asset inventory database.

Users can be created locally in the app, or you can make use of LDAP, Google OAuth, or Microsoft Entra OAuth Authentication. The installer creates an initial local admin account. When users sign in via external providers, they are added to the local user database automatically.

In the app, users can request equipment, and Checkout Users can manage reservations, checkouts, and checked-out assets from a unified "Reservations" hub.

## Features
- Catalogue and basket flow for users to request equipment.
- Checkout Users "Reservations" hub with tabs for Today's Reservations (checkout), Checked Out Reservations, Checking in Assets and Reservation History.
- Quick checkout/checkin flows for ad-hoc asset handling.
- Local inventory database for models and assets.
- LDAP/AD, Google OAuth and Microsoft Entra integration for authentication.

## System requirements
- PHP 8.0+ with extensions: pdo_mysql, curl, ldap, mbstring, openssl, json.
- MySQL/MariaDB database for the app tables.
- Web server: Apache or Nginx (PHP-FPM or mod_php).

## Installation
1. Clone or copy this repository to your web root.
2. Ensure the web server user can write to `config/` (for `config.php`).
3. Point your web server at the `public/` directory.
4. Visit https://www.yourinstallation.com/install/ in your browser:
   - Fill in database details and create the initial local admin account.
   - Generate `config/config.php` and optionally create the database from `public/install/schema.sql`.
   - Configure LDAP/Google/Entra later in the Admin Settings page if needed.
   - Remove or restrict access to `public/install` after successful setup.
5. If you prefer manual configuration, copy `config/config.example.php` to `config/config.php` and update values. Then import `public/install/schema.sql` into your database.

## Inventory setup
- Assets are split into Categories, Models and Assets. A model is a specific model of equipment that you may have several of in your inventory, such as a Canon EOS Camera or Manfrotto Tripod. An asset is an individual example of one of those models, with a unique Asset Tag ID that will be used when signing out to a user.  

## General usage
- Users:
  - Browse equipment via `Catalogue`, add to basket, and submit reservations.
  - View their reservations on `My Reservations`.
- Checkout Users:
  - Use `Reservations` page for:
    - Today's Reservations (checkout against bookings).
    - Checked Out Reservations (view/overdue assets).
    - Check in Assets (Checkin a specific user's reservations in bulk)
    - Reservation History (filter/search all reservations).
  - Quick checkout/checkin pages exist for ad-hoc asset handling.
- Settings (admin only):
  - Configure app, authentication, and SMTP options via `Settings`. Test buttons let you validate connections without saving.

## Setting up Admins/Checkout Users

This app supports local accounts plus LDAP, Google OAuth, or Microsoft Entra. During installation you create the first local admin. After install, define admins/checkout users via local users or external groups/emails in the settings page. Standard users only have access to reservations, whereas specified checkout user and admins can checkout/checkin equipment.

## CRON Scripts

In the scripts folder of this app, there are certain PHP scripts you should run as a cron or via PHP CLI at regular intervals.

- The `cron_mark_missed.php` script will automatically mark reservations not checked out after a specified time period (set on the settings page) as missed and release them to be booked again. By default, this is set to 1 hour.
- The `email_overdue_staff.php` and `email_overdue_users.php` scripts will automatically email users with overdue equipment and inform checkout users/admins specified on the settings page of currently overdue reservations. Run these daily if you want reminders.
