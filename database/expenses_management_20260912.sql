-- Expense Management (Phase 1)
-- Safe to run: creates table only when missing.

CREATE TABLE IF NOT EXISTS expenses (
    expense_id INT NOT NULL AUTO_INCREMENT,
    expense_name VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    branch_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    status ENUM('Paid','To Be Paid','Archived') NOT NULL DEFAULT 'To Be Paid',
    payment_method VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    archived_at DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (expense_id),
    KEY idx_expenses_branch_date (branch_id, expense_date),
    KEY idx_expenses_status (status),
    KEY idx_expenses_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
