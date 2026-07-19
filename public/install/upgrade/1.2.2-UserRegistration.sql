ALTER TABLE users
    ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER auth_source,
    ADD COLUMN profile_photo_url VARCHAR(1024) DEFAULT NULL AFTER is_approved;

INSERT IGNORE INTO schema_version (version) VALUES ('1.2.2');
