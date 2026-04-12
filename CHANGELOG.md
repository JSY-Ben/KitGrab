# Changelog

## 0.12.0-Beta

### Upgrade Notes

This release includes a database upgrade for the new catalogue favourites feature.

Existing installations should run the browser upgrader at:

`public/install/upgrade/`

Alternatively, the SQL upgrade can be run manually:

`public/install/upgrade/0.12.0-Beta-Favourites.sql`

The upgrader now includes a SQL backup download button so administrators can export the KitGrab booking database before applying updates.

### New Features

- Added user favourites on the catalogue. Signed-in users can mark models as favourites and filter the catalogue to show only their favourite models.
- Added a full-size image viewer on catalogue model images.
- Added quantity increase/decrease controls in the basket, so users can adjust requested quantities without returning to the catalogue.
- Added an admin login warning when pending database upgrades are detected.
- Added a standalone SQL upgrade file for version `0.12.0-Beta`.

### Bug Fixes

- Fixed missed-reservation cutoff checks to use the configured app timezone instead of database server time. This avoids daylight saving and DB/PHP timezone drift issues.
- Fixed datetime parsing so displayed dates are interpreted using the configured app timezone.
- Reservation policy controls now also apply when editing pending reservations, not only when creating them.

### Database Changes

- Added `user_favourite_models` to store per-user favourite catalogue models.
- Fresh installs now record schema version `0.12.0-Beta`.
- Existing installs can apply the same schema change through the upgrader or the standalone SQL file.

### Verification

- `git diff --check` passes.
- PHP syntax linting was not run in this environment because no `php` binary was available on the shell path.
