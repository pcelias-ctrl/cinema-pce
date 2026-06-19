CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('administrador', 'vendedor') NOT NULL DEFAULT 'vendedor',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_sessions (
    id VARCHAR(128) PRIMARY KEY,
    data MEDIUMBLOB NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_app_sessions_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    original_title VARCHAR(180) NULL,
    synopsis TEXT NOT NULL,
    trailer_url VARCHAR(500) NULL,
    cover_mime VARCHAR(80) NULL,
    cover_data LONGBLOB NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    genre VARCHAR(120) NOT NULL,
    technical_sheet JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_movies_filter (genre, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    normal_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    large_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    screen_config JSON NULL,
    seat_layout JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_seats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    row_label VARCHAR(10) NOT NULL,
    seat_number SMALLINT UNSIGNED NOT NULL,
    seat_code VARCHAR(20) NOT NULL,
    seat_type ENUM('normal', 'grande') NOT NULL DEFAULT 'normal',
    pos_x DECIMAL(8,2) NOT NULL,
    pos_y DECIMAL(8,2) NOT NULL,
    width DECIMAL(8,2) NOT NULL DEFAULT 44,
    height DECIMAL(8,2) NOT NULL DEFAULT 44,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_room_seat_code (room_id, seat_code),
    CONSTRAINT fk_room_seats_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE showtimes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movie_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    audio_type ENUM('legendado', 'dublado') NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    status ENUM('programada', 'cancelada', 'encerrada') NOT NULL DEFAULT 'programada',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_showtimes_starts_at (starts_at),
    INDEX idx_showtimes_audio (audio_type),
    CONSTRAINT fk_showtimes_movie FOREIGN KEY (movie_id) REFERENCES movies(id),
    CONSTRAINT fk_showtimes_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_registers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    closing_amount DECIMAL(10,2) NULL,
    expected_amount DECIMAL(10,2) NULL,
    status ENUM('aberto', 'fechado') NOT NULL DEFAULT 'aberto',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_registers_user_status (user_id, status),
    CONSTRAINT fk_cash_registers_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    showtime_id INT UNSIGNED NOT NULL,
    room_seat_id INT UNSIGNED NOT NULL,
    seller_user_id INT UNSIGNED NOT NULL,
    cash_register_id INT UNSIGNED NULL,
    sale_code VARCHAR(40) NOT NULL,
    qr_token VARCHAR(80) NULL,
    buyer_name VARCHAR(160) NULL,
    payment_method ENUM('dinheiro', 'cartao', 'pix') NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NULL,
    change_amount DECIMAL(10,2) NULL,
    status ENUM('reservado', 'vendido', 'cancelado') NOT NULL DEFAULT 'vendido',
    sold_at DATETIME NULL,
    checked_in_at DATETIME NULL,
    checked_in_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ticket_showtime_seat (showtime_id, room_seat_id),
    INDEX idx_tickets_sale_code (sale_code),
    INDEX idx_tickets_qr_token (qr_token),
    CONSTRAINT fk_tickets_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id),
    CONSTRAINT fk_tickets_room_seat FOREIGN KEY (room_seat_id) REFERENCES room_seats(id),
    CONSTRAINT fk_tickets_seller FOREIGN KEY (seller_user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_cash_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id),
    CONSTRAINT fk_tickets_checked_in_by FOREIGN KEY (checked_in_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
