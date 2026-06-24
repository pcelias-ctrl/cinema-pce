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

CREATE TABLE cinema_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    cinema_name VARCHAR(180) NOT NULL,
    cnpj VARCHAR(20) NULL,
    address TEXT NULL,
    whatsapp VARCHAR(30) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(180) NULL,
    logo_mime VARCHAR(80) NULL,
    logo_data LONGBLOB NULL,
    checkin_advance_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    public_products_enabled TINYINT(1) NOT NULL DEFAULT 1,
    admin_products_enabled TINYINT(1) NOT NULL DEFAULT 1,
    qr_beep_enabled TINYINT(1) NOT NULL DEFAULT 1,
    smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    smtp_host VARCHAR(255) NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    smtp_encryption ENUM('none', 'tls', 'ssl') NOT NULL DEFAULT 'tls',
    smtp_auth TINYINT(1) NOT NULL DEFAULT 1,
    smtp_username VARCHAR(255) NULL,
    smtp_password_encrypted TEXT NULL,
    smtp_from_name VARCHAR(180) NULL,
    smtp_from_email VARCHAR(180) NULL,
    smtp_reply_to VARCHAR(180) NULL,
    smtp_timeout SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    original_title VARCHAR(180) NULL,
    synopsis TEXT NOT NULL,
    trailer_url VARCHAR(500) NULL,
    cover_mime VARCHAR(80) NULL,
    cover_data LONGBLOB NULL,
    promo_banner_mime VARCHAR(80) NULL,
    promo_banner_data LONGBLOB NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    genre VARCHAR(120) NOT NULL,
    age_rating ENUM('L','10','12','14','16','18') NOT NULL DEFAULT 'L',
    technical_sheet JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    is_coming_soon TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_movies_filter (genre, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(50) NOT NULL DEFAULT 'package',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_categories_parent FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(60) NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    image_mime VARCHAR(80) NULL,
    image_data LONGBLOB NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category_active (category_id, active),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    normal_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    large_seats SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    projection_laser TINYINT(1) NOT NULL DEFAULT 0,
    dolby_sound TINYINT(1) NOT NULL DEFAULT 0,
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
    unavailable TINYINT(1) NOT NULL DEFAULT 0,
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
    is_3d TINYINT(1) NOT NULL DEFAULT 0,
    is_presale TINYINT(1) NOT NULL DEFAULT 0,
    presale_starts_at DATETIME NULL,
    price DECIMAL(10,2) NOT NULL,
    half_price DECIMAL(10,2) NULL,
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
    ticket_type ENUM('inteira', 'meia') NOT NULL DEFAULT 'inteira',
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NULL,
    change_amount DECIMAL(10,2) NULL,
    status ENUM('reservado', 'vendido', 'cancelado') NOT NULL DEFAULT 'vendido',
    sold_at DATETIME NULL,
    checked_in_at DATETIME NULL,
    checked_in_by INT UNSIGNED NULL,
    canceled_at DATETIME NULL,
    canceled_by INT UNSIGNED NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tickets_showtime (showtime_id),
    INDEX idx_tickets_room_seat (room_seat_id),
    INDEX idx_tickets_sale_code (sale_code),
    INDEX idx_tickets_qr_token (qr_token),
    CONSTRAINT fk_tickets_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id),
    CONSTRAINT fk_tickets_room_seat FOREIGN KEY (room_seat_id) REFERENCES room_seats(id),
    CONSTRAINT fk_tickets_seller FOREIGN KEY (seller_user_id) REFERENCES users(id),
    CONSTRAINT fk_tickets_cash_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id),
    CONSTRAINT fk_tickets_checked_in_by FOREIGN KEY (checked_in_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_code VARCHAR(40) NOT NULL,
    seller_user_id INT UNSIGNED NOT NULL,
    cash_register_id INT UNSIGNED NULL,
    payment_method ENUM('dinheiro', 'cartao', 'pix') NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    sold_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_sales_code (sale_code),
    INDEX idx_product_sales_sold_at (sold_at),
    CONSTRAINT fk_product_sales_seller FOREIGN KEY (seller_user_id) REFERENCES users(id),
    CONSTRAINT fk_product_sales_cash FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_sale_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    qr_token VARCHAR(80) NOT NULL UNIQUE,
    status ENUM('pendente', 'entregue', 'cancelado') NOT NULL DEFAULT 'pendente',
    delivered_at DATETIME NULL,
    delivered_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_items_status (status),
    CONSTRAINT fk_product_items_sale FOREIGN KEY (product_sale_id) REFERENCES product_sales(id),
    CONSTRAINT fk_product_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_product_items_delivered_by FOREIGN KEY (delivered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    cpf VARCHAR(11) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    whatsapp VARCHAR(20) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT NULL,
    google_sub VARCHAR(190) NULL UNIQUE,
    email_verified_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    privacy_accepted_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_portal_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    sales_enabled TINYINT(1) NOT NULL DEFAULT 0,
    hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    public_sale_cutoff_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    banner_mime VARCHAR(80) NULL,
    banner_data LONGBLOB NULL,
    payment_gateway ENUM('pagarme','infinitepay','mixed') NOT NULL DEFAULT 'pagarme',
    pagarme_public_key VARCHAR(190) NULL,
    pagarme_secret_encrypted TEXT NULL,
    pagarme_webhook_secret_encrypted TEXT NULL,
    pagarme_webhook_username VARCHAR(190) NULL,
    pagarme_webhook_password_encrypted TEXT NULL,
    infinitepay_handle VARCHAR(190) NULL,
    google_client_id VARCHAR(255) NULL,
    google_client_secret_encrypted TEXT NULL,
    privacy_contact_email VARCHAR(190) NULL,
    cookie_policy_version VARCHAR(30) NOT NULL DEFAULT '1.0',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_login_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_login_expiry (expires_at),
    CONSTRAINT fk_public_login_customer FOREIGN KEY (customer_id) REFERENCES public_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(40) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    showtime_id INT UNSIGNED NOT NULL,
    payment_method ENUM('pix','cartao') NOT NULL,
    payment_gateway ENUM('pagarme','infinitepay') NOT NULL DEFAULT 'pagarme',
    status ENUM('rascunho','aguardando_pagamento','pago','cancelado','expirado','estornado') NOT NULL DEFAULT 'rascunho',
    tickets_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    products_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    pagarme_order_id VARCHAR(100) NULL,
    pagarme_charge_id VARCHAR(100) NULL,
    pix_qr_code TEXT NULL,
    pix_qr_code_url TEXT NULL,
    provider_reference VARCHAR(190) NULL,
    provider_checkout_url TEXT NULL,
    provider_transaction_nsu VARCHAR(190) NULL,
    provider_payload JSON NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_public_orders_customer (customer_id, created_at),
    INDEX idx_public_orders_status (status, expires_at),
    CONSTRAINT fk_public_orders_customer FOREIGN KEY (customer_id) REFERENCES public_customers(id),
    CONSTRAINT fk_public_orders_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_seat_holds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    showtime_id INT UNSIGNED NOT NULL,
    room_seat_id INT UNSIGNED NOT NULL,
    ticket_type ENUM('inteira','meia') NOT NULL DEFAULT 'inteira',
    unit_price DECIMAL(10,2) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_public_hold_seat (showtime_id, room_seat_id),
    CONSTRAINT fk_public_hold_order FOREIGN KEY (order_id) REFERENCES public_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_public_hold_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id),
    CONSTRAINT fk_public_hold_seat FOREIGN KEY (room_seat_id) REFERENCES room_seats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_seat_holds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    showtime_id INT UNSIGNED NOT NULL,
    room_seat_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    session_id VARCHAR(128) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_admin_hold_seat (showtime_id, room_seat_id),
    INDEX idx_admin_hold_session (session_id, expires_at),
    CONSTRAINT fk_admin_hold_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_hold_seat FOREIGN KEY (room_seat_id) REFERENCES room_seats(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_hold_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_order_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    qr_token VARCHAR(80) NULL UNIQUE,
    status ENUM('aguardando_pagamento','pendente','entregue','cancelado') NOT NULL DEFAULT 'aguardando_pagamento',
    delivered_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_public_order_product_order FOREIGN KEY (order_id) REFERENCES public_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_public_order_product_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
