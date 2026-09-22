CREATE TABLE IF NOT EXISTS change_item_evidence (
    evidence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    change_item_id BIGINT UNSIGNED NOT NULL,
    media_kind VARCHAR(10) NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    original_name VARCHAR(255) NULL DEFAULT NULL,
    file_size INT UNSIGNED NULL DEFAULT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evidence_id),
    KEY idx_change_item_evidence (change_item_id, sort_order, media_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
