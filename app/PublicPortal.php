<?php

declare(strict_types=1);

namespace CinemaPce;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PublicPortal
{
    public static function ensureSchema(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS public_portal_settings (
            id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1, sales_enabled TINYINT(1) NOT NULL DEFAULT 0,
            hold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            public_sale_cutoff_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
            payment_gateway ENUM('pagarme','infinitepay','mixed') NOT NULL DEFAULT 'pagarme',
            pagarme_public_key VARCHAR(190) NULL, pagarme_secret_encrypted TEXT NULL, pagarme_webhook_secret_encrypted TEXT NULL,
            pagarme_webhook_username VARCHAR(190) NULL, pagarme_webhook_password_encrypted TEXT NULL,
            infinitepay_handle VARCHAR(190) NULL,
            google_client_id VARCHAR(255) NULL, google_client_secret_encrypted TEXT NULL,
            privacy_contact_email VARCHAR(190) NULL, cookie_policy_version VARCHAR(30) NOT NULL DEFAULT '1.0',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("INSERT INTO public_portal_settings(id) VALUES(1) ON DUPLICATE KEY UPDATE id=id");
        $db->exec("CREATE TABLE IF NOT EXISTS public_customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL,
            cpf VARCHAR(11) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL UNIQUE,
            whatsapp VARCHAR(20) NOT NULL, phone VARCHAR(20) NOT NULL, address TEXT NULL,
            google_sub VARCHAR(190) NULL UNIQUE, email_verified_at DATETIME NULL,
            active TINYINT(1) NOT NULL DEFAULT 1, privacy_accepted_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS public_login_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL, used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_public_login_expiry (expires_at),
            CONSTRAINT fk_public_login_customer FOREIGN KEY (customer_id) REFERENCES public_customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS public_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_code VARCHAR(40) NOT NULL UNIQUE,
            customer_id INT UNSIGNED NOT NULL, showtime_id INT UNSIGNED NOT NULL,
            payment_method ENUM('pix','cartao') NOT NULL,
            status ENUM('rascunho','aguardando_pagamento','pago','cancelado','expirado','estornado') NOT NULL DEFAULT 'rascunho',
            tickets_total DECIMAL(10,2) NOT NULL DEFAULT 0, products_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0, pagarme_order_id VARCHAR(100) NULL,
            pagarme_charge_id VARCHAR(100) NULL, pix_qr_code TEXT NULL, pix_qr_code_url TEXT NULL,
            payment_gateway ENUM('pagarme','infinitepay') NOT NULL DEFAULT 'pagarme',
            provider_reference VARCHAR(190) NULL, provider_checkout_url TEXT NULL, provider_transaction_nsu VARCHAR(190) NULL,
            provider_payload JSON NULL, expires_at DATETIME NULL, paid_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_public_orders_customer (customer_id, created_at), INDEX idx_public_orders_status (status, expires_at),
            CONSTRAINT fk_public_orders_customer FOREIGN KEY (customer_id) REFERENCES public_customers(id),
            CONSTRAINT fk_public_orders_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureColumns($db, 'public_portal_settings', [
            'payment_gateway' => "ENUM('pagarme','infinitepay') NOT NULL DEFAULT 'pagarme' AFTER hold_minutes",
            'public_sale_cutoff_minutes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 45 AFTER hold_minutes',
            'pagarme_webhook_username' => 'VARCHAR(190) NULL AFTER pagarme_webhook_secret_encrypted',
            'pagarme_webhook_password_encrypted' => 'TEXT NULL AFTER pagarme_webhook_username',
            'infinitepay_handle' => 'VARCHAR(190) NULL AFTER pagarme_webhook_password_encrypted',
        ]);
        self::ensureColumns($db, 'public_orders', [
            'payment_gateway' => "ENUM('pagarme','infinitepay') NOT NULL DEFAULT 'pagarme' AFTER payment_method",
            'provider_reference' => 'VARCHAR(190) NULL AFTER pix_qr_code_url',
            'provider_checkout_url' => 'TEXT NULL AFTER provider_reference',
            'provider_transaction_nsu' => 'VARCHAR(190) NULL AFTER provider_checkout_url',
        ]);
        self::ensureColumns($db, 'public_login_tokens', [
            'attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER token_hash',
        ]);
        self::ensureColumns($db, 'room_seats', [
            'unavailable' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER seat_type',
        ]);
        $ticketCancelColumn = $db->query("SHOW COLUMNS FROM tickets LIKE 'canceled_at'")->fetch();
        if (!$ticketCancelColumn) {
            $db->exec('ALTER TABLE tickets ADD COLUMN canceled_at DATETIME NULL AFTER checked_in_by, ADD COLUMN canceled_by INT UNSIGNED NULL AFTER canceled_at, ADD COLUMN cancel_reason TEXT NULL AFTER canceled_by');
        }
        $legacyTicketIndex = $db->query("SHOW INDEX FROM tickets WHERE Key_name = 'uniq_ticket_showtime_seat'")->fetchAll();
        if ($legacyTicketIndex) {
            if (!$db->query("SHOW INDEX FROM tickets WHERE Key_name = 'idx_tickets_showtime'")->fetch()) {
                $db->exec('ALTER TABLE tickets ADD INDEX idx_tickets_showtime (showtime_id)');
            }
            if (!$db->query("SHOW INDEX FROM tickets WHERE Key_name = 'idx_tickets_room_seat'")->fetch()) {
                $db->exec('ALTER TABLE tickets ADD INDEX idx_tickets_room_seat (room_seat_id)');
            }
            $db->exec('ALTER TABLE tickets DROP INDEX uniq_ticket_showtime_seat');
        }
        $ticketStatusIndex = $db->query("SHOW INDEX FROM tickets WHERE Key_name = 'uniq_ticket_showtime_seat_status'")->fetchAll();
        if ($ticketStatusIndex) {
            $db->exec('ALTER TABLE tickets DROP INDEX uniq_ticket_showtime_seat_status');
        }
        self::ensureColumns($db, 'movies', [
            'age_rating' => "ENUM('L','10','12','14','16','18') NOT NULL DEFAULT 'L' AFTER genre",
        ]);
        $db->exec("UPDATE movies SET age_rating=CASE UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(technical_sheet,'$.classificacao')))) WHEN 'LIVRE' THEN 'L' WHEN 'L' THEN 'L' WHEN '10' THEN '10' WHEN '10 ANOS' THEN '10' WHEN '12' THEN '12' WHEN '12 ANOS' THEN '12' WHEN '14' THEN '14' WHEN '14 ANOS' THEN '14' WHEN '16' THEN '16' WHEN '16 ANOS' THEN '16' WHEN '18' THEN '18' WHEN '18 ANOS' THEN '18' ELSE age_rating END WHERE JSON_VALID(technical_sheet) AND JSON_EXTRACT(technical_sheet,'$.classificacao') IS NOT NULL");
        $gatewayColumn=$db->query("SHOW COLUMNS FROM public_portal_settings LIKE 'payment_gateway'")->fetch();
        if($gatewayColumn&&!str_contains((string)$gatewayColumn['Type'],"'mixed'"))$db->exec("ALTER TABLE public_portal_settings MODIFY payment_gateway ENUM('pagarme','infinitepay','mixed') NOT NULL DEFAULT 'pagarme'");
        $db->exec("UPDATE public_portal_settings SET pagarme_webhook_password_encrypted=pagarme_webhook_secret_encrypted WHERE pagarme_webhook_password_encrypted IS NULL AND pagarme_webhook_secret_encrypted IS NOT NULL");

        $db->exec("CREATE TABLE IF NOT EXISTS public_seat_holds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            showtime_id INT UNSIGNED NOT NULL, room_seat_id INT UNSIGNED NOT NULL,
            ticket_type ENUM('inteira','meia') NOT NULL DEFAULT 'inteira', unit_price DECIMAL(10,2) NOT NULL,
            expires_at DATETIME NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_public_hold_seat (showtime_id, room_seat_id),
            CONSTRAINT fk_public_hold_order FOREIGN KEY (order_id) REFERENCES public_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_public_hold_showtime FOREIGN KEY (showtime_id) REFERENCES showtimes(id),
            CONSTRAINT fk_public_hold_seat FOREIGN KEY (room_seat_id) REFERENCES room_seats(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS public_order_products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL, unit_price DECIMAL(10,2) NOT NULL,
            qr_token VARCHAR(80) NULL UNIQUE,
            status ENUM('aguardando_pagamento','pendente','entregue','cancelado') NOT NULL DEFAULT 'aguardando_pagamento',
            delivered_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_public_order_product_order FOREIGN KEY (order_id) REFERENCES public_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_public_order_product_product FOREIGN KEY (product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("DELETE public_seat_holds FROM public_seat_holds
            INNER JOIN public_orders ON public_orders.id = public_seat_holds.order_id
            WHERE public_seat_holds.expires_at <= NOW() AND public_orders.status IN ('rascunho','aguardando_pagamento','expirado','cancelado')");
        $db->exec("UPDATE public_orders SET status='expirado'
            WHERE status IN ('rascunho','aguardando_pagamento') AND expires_at IS NOT NULL AND expires_at <= NOW()");
        $db->exec("UPDATE public_order_products
            INNER JOIN public_orders ON public_orders.id = public_order_products.order_id
            SET public_order_products.status='cancelado'
            WHERE public_orders.status IN ('expirado','cancelado') AND public_order_products.status='aguardando_pagamento'");
    }

    public static function cinema(PDO $db): array
    {
        $defaults = ['cinema_name' => 'Cinema PCE', 'address' => '', 'phone' => '', 'whatsapp' => '', 'email' => '', 'has_logo' => false, 'public_products_enabled' => 1];
        try {
            self::ensureCinemaSettingsColumns($db);
            $row = $db->query('SELECT cinema_name,address,phone,whatsapp,email,logo_data IS NOT NULL has_logo,public_products_enabled FROM cinema_settings WHERE id=1')->fetch();
            return array_merge($defaults, $row ?: []);
        } catch (\Throwable $exception) {
            try {
                $row = $db->query('SELECT cinema_name,address,phone,whatsapp,email,logo_data IS NOT NULL has_logo FROM cinema_settings WHERE id=1')->fetch();
                return array_merge($defaults, $row ?: []);
            } catch (\Throwable $fallbackException) {
                return $defaults;
            }
        }
    }

    private static function ensureCinemaSettingsColumns(PDO $db): void
    {
        self::ensureColumns($db, 'cinema_settings', [
            'public_products_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'admin_products_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'qr_beep_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
        ]);
    }

    public static function settings(PDO $db): array
    {
        self::ensureSchema($db);
        $defaults = ['sales_enabled'=>0,'hold_minutes'=>10,'public_sale_cutoff_minutes'=>45,'payment_gateway'=>'pagarme','pagarme_public_key'=>'','pagarme_secret_encrypted'=>'','pagarme_webhook_secret_encrypted'=>'','pagarme_webhook_username'=>'','pagarme_webhook_password_encrypted'=>'','infinitepay_handle'=>'','google_client_id'=>'','google_client_secret_encrypted'=>'','privacy_contact_email'=>'','cookie_policy_version'=>'1.0'];
        $row = $db->query('SELECT * FROM public_portal_settings WHERE id=1')->fetch();
        return array_merge($defaults, $row ?: []);
    }

    public static function customer(PDO $db): ?array
    {
        $id = (int) ($_SESSION['public_customer_id'] ?? 0);
        if ($id < 1) return null;
        $stmt = $db->prepare('SELECT id,name,cpf,email,whatsapp,phone,address,email_verified_at FROM public_customers WHERE id=? AND active=1');
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
        if (!$customer) unset($_SESSION['public_customer_id']);
        return $customer ?: null;
    }

    public static function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }

    public static function validCpf(string $cpf): bool
    {
        $cpf = self::normalizeDigits($cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($digit = 9; $digit < 11; $digit++) {
            $sum = 0;
            for ($index = 0; $index < $digit; $index++) $sum += (int) $cpf[$index] * (($digit + 1) - $index);
            $check = (10 * $sum) % 11;
            if ($check === 10) $check = 0;
            if ($check !== (int) $cpf[$digit]) return false;
        }
        return true;
    }

    public static function createLoginToken(PDO $db, int $customerId): string
    {
        $token = bin2hex(random_bytes(32));
        $db->prepare('DELETE FROM public_login_tokens WHERE customer_id=? OR expires_at<=NOW()')->execute([$customerId]);
        $db->prepare('INSERT INTO public_login_tokens(customer_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 20 MINUTE))')->execute([$customerId, hash('sha256', $token)]);
        return $token;
    }

    public static function createLoginCode(PDO $db, int $customerId): string
    {
        $code = (string) random_int(100000, 999999);
        $db->prepare('DELETE FROM public_login_tokens WHERE customer_id=? OR expires_at<=NOW()')->execute([$customerId]);
        $db->prepare('INSERT INTO public_login_tokens(customer_id,token_hash,attempts,expires_at) VALUES(?,?,0,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$customerId, hash('sha256', $code)]);
        return $code;
    }

    public static function consumeLoginCode(PDO $db, string $email, string $code): bool
    {
        $email = strtolower(trim($email));
        $code = self::normalizeDigits($code);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) return false;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT public_login_tokens.* FROM public_login_tokens INNER JOIN public_customers ON public_customers.id=public_login_tokens.customer_id WHERE public_customers.email=? AND public_customers.active=1 AND public_login_tokens.used_at IS NULL AND public_login_tokens.expires_at>NOW() ORDER BY public_login_tokens.id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if (!$row || (int) $row['attempts'] >= 5 || !hash_equals((string) $row['token_hash'], hash('sha256', $code))) {
                if ($row) $db->prepare('UPDATE public_login_tokens SET attempts=attempts+1 WHERE id=?')->execute([(int) $row['id']]);
                $db->commit();
                return false;
            }
            $db->prepare('UPDATE public_login_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
            $db->prepare('UPDATE public_customers SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([(int) $row['customer_id']]);
            $_SESSION['public_customer_id'] = (int) $row['customer_id'];
            unset($_SESSION['public_pending_email']);
            session_regenerate_id(true);
            $db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function consumeLoginToken(PDO $db, string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM public_login_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
            $stmt->execute([hash('sha256', $token)]);
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                return false;
            }
            $db->prepare('UPDATE public_login_tokens SET used_at=NOW() WHERE id=?')->execute([(int) $row['id']]);
            $db->prepare('UPDATE public_customers SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([(int) $row['customer_id']]);
            $_SESSION['public_customer_id'] = (int) $row['customer_id'];
            session_regenerate_id(true);
            $db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function publicUrl(array $params = []): string
    {
        $base = rtrim((string) config_value('app_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . '/?' . http_build_query($params);
    }

    public static function orderCode(): string
    {
        return 'ON' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function ensureColumns(PDO $db, string $table, array $definitions): void
    {
        $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
        $existing = array_flip(array_column($columns, 'Field'));
        foreach ($definitions as $name => $definition) {
            if (!isset($existing[$name])) {
                $db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }

    public static function finalizePaidOrder(PDO $db, int $orderId, array $providerPayload): void
    {
        $db->beginTransaction();
        try {
            $stmt=$db->prepare('SELECT public_orders.*,public_customers.name customer_name,public_customers.email customer_email FROM public_orders INNER JOIN public_customers ON public_customers.id=public_orders.customer_id WHERE public_orders.id=? FOR UPDATE');
            $stmt->execute([$orderId]);$order=$stmt->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['status']==='pago'){$db->commit();return;}
            if(!in_array($order['status'],['rascunho','aguardando_pagamento'],true))throw new RuntimeException('Pedido não pode ser confirmado.');
            $email='online@cinesys.local';$user=$db->prepare('SELECT id FROM users WHERE email=?');$user->execute([$email]);$systemId=(int)$user->fetchColumn();
            if($systemId<1){$db->prepare("INSERT INTO users(name,email,password_hash,role,active) VALUES('Venda Online',?,?, 'vendedor',0)")->execute([$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);$systemId=(int)$db->lastInsertId();}
            $holds=$db->prepare('SELECT * FROM public_seat_holds WHERE order_id=? FOR UPDATE');$holds->execute([$orderId]);$holdRows=$holds->fetchAll();if(!$holdRows)throw new RuntimeException('Reserva de poltronas expirada.');
            $payment=$order['payment_method']==='cartao'?'cartao':'pix';
            $ticket=$db->prepare("INSERT INTO tickets(showtime_id,room_seat_id,seller_user_id,cash_register_id,sale_code,qr_token,buyer_name,payment_method,ticket_type,unit_price,total_amount,amount_paid,change_amount,status,sold_at) VALUES(?,?,?,NULL,?,?,?,?,?,?,?,?,0,'vendido',NOW())");
            foreach($holdRows as $hold){$ticket->execute([(int)$order['showtime_id'],(int)$hold['room_seat_id'],$systemId,$order['order_code'],bin2hex(random_bytes(24)),$order['customer_name'],$payment,$hold['ticket_type'],$hold['unit_price'],$order['total_amount'],$order['total_amount']]);}
            $products=$db->prepare('SELECT public_order_products.*,products.stock_quantity FROM public_order_products INNER JOIN products ON products.id=public_order_products.product_id WHERE order_id=? FOR UPDATE');$products->execute([$orderId]);$productRows=$products->fetchAll();
            if($productRows){$db->prepare('INSERT INTO product_sales(sale_code,seller_user_id,cash_register_id,payment_method,total_amount,sold_at) VALUES(?,?,NULL,?,?,NOW())')->execute([$order['order_code'],$systemId,$payment,$order['products_total']]);$saleId=(int)$db->lastInsertId();$insertProduct=$db->prepare("INSERT INTO product_sale_items(product_sale_id,product_id,unit_price,qr_token,status) VALUES(?,?,?,?,'pendente')");foreach($productRows as $product){$token=bin2hex(random_bytes(24));$insertProduct->execute([$saleId,$product['product_id'],$product['unit_price'],$token]);$db->prepare("UPDATE public_order_products SET status='pendente',qr_token=? WHERE id=?")->execute([$token,$product['id']]);if($product['stock_quantity']!==null)$db->prepare('UPDATE products SET stock_quantity=GREATEST(0,stock_quantity-1) WHERE id=?')->execute([$product['product_id']]);}}
            $db->prepare("UPDATE public_orders SET status='pago',paid_at=NOW(),provider_payload=? WHERE id=?")->execute([json_encode($providerPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$orderId]);
            $db->prepare('DELETE FROM public_seat_holds WHERE order_id=?')->execute([$orderId]);
            $db->commit();
            try {
                $ticketsUrl=self::publicUrl(['action'=>'tickets_pdf','order'=>$order['order_code']]);
                $productsUrl=(float)$order['products_total']>0?self::publicUrl(['action'=>'products_pdf','order'=>$order['order_code']]):'';
                $detailsStmt=$db->prepare('SELECT showtimes.starts_at,showtimes.audio_type,showtimes.is_3d,movies.title,movies.age_rating,rooms.name room_name FROM showtimes INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE showtimes.id=?');$detailsStmt->execute([(int)$order['showtime_id']]);$details=$detailsStmt->fetch()?:[];
                $ticketsStmt=$db->prepare("SELECT room_seats.seat_code,tickets.ticket_type,tickets.unit_price FROM tickets INNER JOIN room_seats ON room_seats.id=tickets.room_seat_id WHERE tickets.sale_code=? AND tickets.status='vendido' ORDER BY room_seats.row_label,room_seats.seat_number");$ticketsStmt->execute([$order['order_code']]);$emailTickets=$ticketsStmt->fetchAll();
                $productsStmt=$db->prepare("SELECT products.name,COUNT(*) quantity,public_order_products.unit_price FROM public_order_products INNER JOIN products ON products.id=public_order_products.product_id WHERE public_order_products.order_id=? AND public_order_products.status IN ('pendente','entregue') GROUP BY products.id,products.name,public_order_products.unit_price ORDER BY products.name");$productsStmt->execute([$orderId]);$emailProducts=$productsStmt->fetchAll();
                $cinema=self::cinema($db);$escape=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');$money=static fn($value):string=>'R$ '.number_format((float)$value,2,',','.');
                $ticketRows='';foreach($emailTickets as $emailTicket)$ticketRows.='<tr><td style="padding:10px 0;border-bottom:1px solid #ece7e3"><strong>Poltrona '.$escape($emailTicket['seat_code']).'</strong><br><span style="color:#756b65;font-size:13px">'.ucfirst($escape($emailTicket['ticket_type'])).'</span></td><td style="padding:10px 0;border-bottom:1px solid #ece7e3;text-align:right">'.$money($emailTicket['unit_price']).'</td></tr>';
                $productRows='';foreach($emailProducts as $emailProduct)$productRows.='<tr><td style="padding:8px 0">'.$escape($emailProduct['quantity']).'x '.$escape($emailProduct['name']).'</td><td style="padding:8px 0;text-align:right">'.$money((float)$emailProduct['unit_price']*(int)$emailProduct['quantity']).'</td></tr>';
                $schedule=!empty($details['starts_at'])?date('d/m/Y H:i',strtotime((string)$details['starts_at'])):'';$format=!empty($details['is_3d'])?'3D':'2D';$audio=ucfirst((string)($details['audio_type']??''));$rating=($details['age_rating']??'L')==='L'?'Livre':($details['age_rating'].' anos');$schedule.=' · Classificação '.$rating;
                $html='<!doctype html><html><body style="margin:0;background:#f4f1ee;font-family:Arial,sans-serif;color:#241f1c"><div style="max-width:620px;margin:0 auto;padding:24px 12px"><div style="background:#17120f;color:#fff;padding:22px 24px;border-radius:8px 8px 0 0"><div style="font-size:13px;color:#f5c451;text-transform:uppercase">Compra confirmada</div><h1 style="margin:7px 0 0;font-size:24px">'.$escape($cinema['cinema_name']).'</h1></div><div style="background:#fff;padding:26px 24px;border-radius:0 0 8px 8px"><p style="margin-top:0">Olá, <strong>'.$escape($order['customer_name']).'</strong>.</p><p style="color:#5f5651;line-height:1.5">Seu pagamento foi confirmado e os ingressos já estão prontos. Apresente o QR Code na entrada da sala.</p><div style="background:#f8f6f4;border-left:4px solid #f5c451;padding:16px;margin:22px 0"><h2 style="margin:0 0 8px;font-size:21px">'.$escape($details['title']??'Sessão de cinema').'</h2><div style="line-height:1.7;color:#5f5651"><strong>'.$escape($schedule).'</strong><br>'.$escape($details['room_name']??'').' · '.$escape($audio).' · '.$escape($format).'</div></div><h3 style="margin:24px 0 4px">Ingressos</h3><table style="width:100%;border-collapse:collapse">'.$ticketRows.'</table>'.($productRows!==''?'<h3 style="margin:24px 0 4px">Produtos</h3><table style="width:100%;border-collapse:collapse">'.$productRows.'</table>':'').'<div style="margin-top:20px;padding-top:16px;border-top:2px solid #241f1c"><table style="width:100%"><tr><td>Pagamento: <strong>'.($payment==='cartao'?'Cartão':'Pix').'</strong></td><td style="text-align:right;font-size:20px"><strong>'.$money($order['total_amount']).'</strong></td></tr></table></div><div style="margin:24px 0"><a href="'.$escape($ticketsUrl).'" style="display:inline-block;background:#18864b;color:#fff;text-decoration:none;padding:12px 18px;border-radius:5px;font-weight:bold">Baixar ingressos</a>'.($productsUrl?' <a href="'.$escape($productsUrl).'" style="display:inline-block;background:#eee9e5;color:#241f1c;text-decoration:none;padding:12px 18px;border-radius:5px;font-weight:bold">Baixar produtos</a>':'').'</div><p style="font-size:12px;color:#81766f">Pedido '.$escape($order['order_code']).' · Guarde este e-mail até o final da sessão.</p></div></div></body></html>';
                $attachments=[];try{$attachments[]=['name'=>'ingressos-'.$order['order_code'].'.pdf','mime'=>'application/pdf','data'=>PublicPdf::tickets($db,(string)$order['order_code'],(int)$order['customer_id'])];}catch(\Throwable $pdfException){error_log('Anexo de ingressos: '.$pdfException->getMessage());}
                if($productsUrl){try{$attachments[]=['name'=>'produtos-'.$order['order_code'].'.pdf','mime'=>'application/pdf','data'=>PublicPdf::products($db,(string)$order['order_code'],(int)$order['customer_id'])];}catch(\Throwable $pdfException){error_log('Anexo de produtos: '.$pdfException->getMessage());}}
                Mailer::send($db,(string)$order['customer_email'],'Compra confirmada - '.($details['title']??$order['order_code']),$html,$attachments);
            } catch (\Throwable $mailException) {
                error_log('E-mail da compra: '.$mailException->getMessage());
            }
        }catch(\Throwable $exception){if($db->inTransaction())$db->rollBack();throw $exception;}
    }

    public static function cancelPendingOrder(PDO $db, int $orderId): bool
    {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT status FROM public_orders WHERE id=? FOR UPDATE');
            $stmt->execute([$orderId]);
            $status = (string) $stmt->fetchColumn();
            if (!in_array($status, ['rascunho', 'aguardando_pagamento'], true)) {
                $db->commit();
                return false;
            }
            $db->prepare("UPDATE public_orders SET status='cancelado',expires_at=NOW() WHERE id=?")->execute([$orderId]);
            $db->prepare("UPDATE public_order_products SET status='cancelado' WHERE order_id=? AND status='aguardando_pagamento'")->execute([$orderId]);
            $db->prepare('DELETE FROM public_seat_holds WHERE order_id=?')->execute([$orderId]);
            $db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }
}
