CREATE TABLE IF NOT EXISTS voucher_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quantity SMALLINT UNSIGNED NOT NULL,
    valid_until DATE NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_voucher_batches_valid_until (valid_until),
    CONSTRAINT fk_voucher_batches_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vouchers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    token VARCHAR(80) NOT NULL UNIQUE,
    status ENUM('disponivel','utilizado','cancelado') NOT NULL DEFAULT 'disponivel',
    used_ticket_id INT UNSIGNED NULL,
    used_sale_code VARCHAR(40) NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vouchers_batch_status (batch_id, status),
    INDEX idx_vouchers_used_sale (used_sale_code),
    CONSTRAINT fk_vouchers_batch FOREIGN KEY (batch_id) REFERENCES voucher_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @voucher_column_sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tickets ADD COLUMN voucher_id BIGINT UNSIGNED NULL AFTER ticket_type',
        'SELECT 1'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tickets'
      AND column_name = 'voucher_id'
);
PREPARE voucher_column_stmt FROM @voucher_column_sql;
EXECUTE voucher_column_stmt;
DEALLOCATE PREPARE voucher_column_stmt;

SET @voucher_index_sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE tickets ADD INDEX idx_tickets_voucher (voucher_id)',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tickets'
      AND index_name = 'idx_tickets_voucher'
);
PREPARE voucher_index_stmt FROM @voucher_index_sql;
EXECUTE voucher_index_stmt;
DEALLOCATE PREPARE voucher_index_stmt;
