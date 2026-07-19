ALTER TABLE reservations
    ADD COLUMN reservation_note TEXT NULL AFTER asset_name_cache,
    ADD COLUMN checkout_note TEXT NULL AFTER reservation_note;
