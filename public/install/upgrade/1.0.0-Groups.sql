-- Upgrade: add user groups for KitGrab 1.0.0

CREATE TABLE IF NOT EXISTS user_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
SELECT 'Administrators', 'Users in this group have admin access.', 1, 1, NOW()
WHERE EXISTS (SELECT 1 FROM users WHERE is_admin = 1)
ON DUPLICATE KEY UPDATE is_admin = 1, is_staff = 1;

INSERT IGNORE INTO user_group_members (user_id, group_id)
SELECT u.id, ug.id
  FROM users u
  JOIN user_groups ug ON ug.name = 'Administrators'
 WHERE u.is_admin = 1;

INSERT INTO user_groups (name, description, is_admin, is_staff, created_at)
SELECT 'Checkout Users', 'Users in this group can check equipment in and out.', 0, 1, NOW()
WHERE EXISTS (SELECT 1 FROM users WHERE is_staff = 1 AND is_admin = 0)
ON DUPLICATE KEY UPDATE is_staff = 1;

INSERT IGNORE INTO user_group_members (user_id, group_id)
SELECT u.id, ug.id
  FROM users u
  JOIN user_groups ug ON ug.name = 'Checkout Users'
 WHERE u.is_staff = 1
   AND u.is_admin = 0;

CREATE TABLE IF NOT EXISTS user_group_members (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, group_id),
    KEY idx_user_group_members_group (group_id),
    CONSTRAINT fk_user_group_members_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_group_members_group
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
VALUES ('1.0.0')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
