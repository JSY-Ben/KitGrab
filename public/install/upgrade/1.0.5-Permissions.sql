-- Upgrade: add local group catalogue permissions for KitGrab 1.0.5

CREATE TABLE IF NOT EXISTS catalogue_group_restrictions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(255) NOT NULL DEFAULT '',
    item_type VARCHAR(32) NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    item_name_cache VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_catalogue_group_item (group_id, item_type, item_id),
    KEY idx_catalogue_group_restrictions_group (group_id),
    KEY idx_catalogue_group_restrictions_item (item_type, item_id),
    CONSTRAINT fk_catalogue_group_restrictions_group
        FOREIGN KEY (group_id)
        REFERENCES user_groups (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_version (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(32) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_version_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version)
VALUES ('1.0.5')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
