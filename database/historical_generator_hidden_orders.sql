CREATE TABLE IF NOT EXISTS historical_generator_hidden_orders (
    order_id INT NOT NULL PRIMARY KEY,
    hidden_by INT NULL,
    hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NULL,
    CONSTRAINT fk_hgh_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    KEY idx_hgh_hidden_at (hidden_at),
    KEY idx_hgh_hidden_by (hidden_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
