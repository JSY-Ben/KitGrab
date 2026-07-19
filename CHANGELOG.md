# Changelog

## v1.2.2 - User registration

- Added optional public registration for local user accounts.
- Added configurable administrator approval for newly registered accounts.
- Added pending-account visibility and approval controls to the Users page.
- Added approval emails and configurable administrator notifications when user accounts are created.
- Added customisable welcome, pending-approval, and account-approved user email templates.
- Grouped notification settings into separate user, reservation, equipment, and scheduled-alert sections.

Run the browser upgrade after installing this release to add account approval status storage.

## v1.2.1 - Password recovery

- Added a forgotten-password flow for local accounts.
- Added secure, expiring, single-use password reset links delivered through the configured SMTP service.
- Added request throttling and neutral responses to prevent account discovery.

Run the browser upgrade after installing this release to add secure password reset token storage.

## v1.2.0 - Reservation workflow and usability update

- Added the ability for users to add a note to the checkout user when submitting their reservation
- Reservations are now cancelled by default and not deleted, therefore retained in history; permanent deletion is restricted to administrators with additional completed-reservation confirmation.
- Added search, date filtering, pagination, quick column sorting, and remembered sort choices to My Reservations.
- Added item thumbnails to checked-out listings.
- Guest actions now resume after login and guest buttons explain that login is required.
- Added application-wide busy feedback, enhanced date/time pickers with an integrated Today action, and staff links from catalogue booking calendars to reservation details.
- Added non-blocking file locks to scheduled jobs to prevent overlapping runs.

Run the browser upgrade after installing this release to add the reservation note fields.

## v1.0.5 - Official Release + New Features

This is now our official first public non-beta release! Thank you to everyone who has downloaded the app so far. 

We have also added some new features:

### Groups and Group Permissions

1) New Groups and Permissions System! You can now create Groups for your users. You can then restrict users access to reserve certain items based on the Group the user is in. You can choose to hide the items from the catalogue entirely or have them appear but grayed out if they are not allowed to book said item.

2) You have the option to choose whether such restrictions are applied to the 'Quick Checkout' page or not.

### Checkout User Permissions

1) Added a new 'Checkout User Permissions' section on the Permissions settings tab. This includes an option to restrict checkout users to viewing and checking out reservations only for members of their own Groups.

When enabled, checkout users only see matching-group users' reservations on 'Today's Reservations (Checkout)', 'Checked Out Reservations', and 'All Reservations'. Admin users can still view and manage all reservations.

These new features require a database upgrade. After updating the files to v1.0.5, you will be warned when logging in that you will need to run the upgrade script. However, if you'd prefer to do this manually, please do the following:

Run the upgrade script at www.yourinstallation.com/install/upgrade through a browser.

Use the new SQL backup button on the upgrade page to download a database backup before applying the upgrades.

As always, please report any issues or feature requests to the appopriate section on GitHub.


## 0.12.0-Beta

This app is currently in a fully working state, but don't have much in the way of user feedback to determine if can be moved out of Beta, so am keeping it in Beta for now. Please do report any issues and feature requests on the 'Issues' tab in Github.

### Upgrade Notes

This release includes a database upgrade for new features.

The app should pop up a warning when an admin logs in directing you to the upgrade page. Howevever if you prefer to do this manually, an admin can run the browser upgrader at:

`www.yourinstall.com/public/install/upgrade/`

The upgrader now includes a SQL backup download button so admins can export the KitGrab booking database before applying updates. This is highly recommended!

### New Features

- Added user favourites on the catalogue. Signed-in users can mark models as favourites and filter the catalogue to show only their favourite models.
- Added a full-size image viewer on catalogue model images.
- Added quantity increase/decrease controls in the basket, so users can adjust requested quantities without returning to the catalogue.
- Added an admin login warning when pending database upgrades are detected.
- Added a “Show all upcoming reservations” toggle to the staff checkout reservation selector.
- Added a warning when staff select a future reservation whose early checkout may clash with other reservations before its scheduled start.

### Bug Fixes

- Fixed app issues caused by users who observe DST (Daylight Savings Time)
- Reservation policy controls now also apply when editing pending reservations, not only when creating them.
