-- Upgrade: add per-user model favourites for KitGrab 0.12.0-Beta

CREATE TABLE IF NOT EXISTS user_favourite_models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_email VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_favourite_models_user_model (user_email, model_id),
    KEY idx_user_favourite_models_user (user_email),
    KEY idx_user_favourite_models_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_version (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(32) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_version_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_version (version)
VALUES ('0.12.0-Beta')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
