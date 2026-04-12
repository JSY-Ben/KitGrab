# Changelog

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
