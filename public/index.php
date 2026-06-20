<?php

declare(strict_types=1);

use CinemaPce\Auth;
use CinemaPce\Database;

require __DIR__ . '/../app/bootstrap.php';

$route = $_GET['route'] ?? 'dashboard';
$pdo = null;

function db()
{
    global $pdo;
    if ($pdo === null) {
        $pdo = Database::connection();
    }
    return $pdo;
}

function layout(string $title, callable $content): void
{
    $user = Auth::user();
    $cinema = cinema_settings();
    $currentRoute = $_GET['route'] ?? 'dashboard';
    if ($currentRoute === '') $currentRoute = 'dashboard';
    $navClass = static fn(string $route): string => $currentRoute === $route ? 'active' : '';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e($cinema['cinema_name']) ?></title>
        <link rel="stylesheet" href="assets/vendor/adminlte/css/adminlte.min.css">
        <link rel="stylesheet" href="assets/css/app.css">
    </head>
    <body class="layout-fixed sidebar-expand-lg bg-body-tertiary route-<?= e(str_replace('_', '-', $currentRoute)) ?> <?= $user ? 'app-shell' : 'public-shell' ?>">
      <div class="app-wrapper">
        <?php if ($user): ?>
            <nav class="app-header navbar navbar-expand bg-body">
                <div class="container-fluid">
                    <a class="nav-link sidebar-toggle" data-lte-toggle="sidebar" href="#" role="button" aria-label="Alternar menu" title="Alternar menu">☰</a>
                    <span class="navbar-text ms-auto"><?= e($user['name']) ?></span>
                </div>
            </nav>
            <aside class="app-sidebar sidebar bg-body-secondary shadow" data-bs-theme="dark">
                <div class="sidebar-brand">
                    <a class="brand" href="index.php">
                        <?php if ($cinema['has_logo']): ?><img src="index.php?route=cinema_logo" alt="<?= e($cinema['cinema_name']) ?>"><?php endif; ?>
                        <strong><?= e($cinema['cinema_name']) ?></strong>
                    </a>
                    <span>Gestão & Bilheteria</span>
                </div>
                <div class="sidebar-user">
                    <span><?= $user['role'] === 'administrador' ? 'Administrador' : 'Operador' ?></span>
                    <strong><?= e($user['name']) ?></strong>
                </div>
                <div class="sidebar-wrapper">
                    <nav class="mt-2" aria-label="Navegação principal">
                        <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">
                            <?php if ($user['role'] === 'administrador'): ?>
                                <li class="nav-header">GESTÃO</li>
                                <li class="nav-item"><a class="nav-link <?= $navClass('dashboard') ?>" href="index.php"><i></i><p>Painel</p></a></li>
                                <li class="nav-item"><a class="nav-link <?= $navClass('movies') ?>" href="index.php?route=movies"><i></i><p>Filmes</p></a></li>
                                <li class="nav-item"><a class="nav-link <?= $navClass('rooms') ?>" href="index.php?route=rooms"><i></i><p>Salas</p></a></li>
                                <li class="nav-item"><a class="nav-link <?= $navClass('showtimes') ?>" href="index.php?route=showtimes"><i></i><p>Sessões</p></a></li>
                                <li class="nav-item"><a class="nav-link <?= $navClass('cinema_settings') ?>" href="index.php?route=cinema_settings"><i></i><p>Cinema</p></a></li>
                            <?php endif; ?>
                            <li class="nav-header">OPERAÇÃO</li>
                            <li class="nav-item"><a class="nav-link <?= in_array($currentRoute, ['sales', 'sale_new', 'ticket_receipt', 'ticket_print'], true) ? 'active' : '' ?>" href="index.php?route=sales"><i></i><p>Venda</p></a></li>
                            <li class="nav-item"><a class="nav-link <?= in_array($currentRoute, ['cash_register', 'cash_receipt'], true) ? 'active' : '' ?>" href="index.php?route=cash_register"><i></i><p>Caixa</p></a></li>
                            <li class="nav-item"><a class="nav-link <?= in_array($currentRoute, ['qr_reader', 'ticket_validate'], true) ? 'active' : '' ?>" href="index.php?route=qr_reader"><i></i><p>Check-in QR</p></a></li>
                            <?php if ($user['role'] === 'administrador'): ?>
                                <li class="nav-item"><a class="nav-link <?= $navClass('users') ?>" href="index.php?route=users"><i></i><p>Usuários</p></a></li>
                            <?php endif; ?>
                            <li class="nav-item logout-link"><a class="nav-link" href="index.php?route=logout"><i></i><p>Sair</p></a></li>
                        </ul>
                    </nav>
                </div>
            </aside>
        <?php else: ?>
            <header class="public-topbar"><a class="brand" href="index.php"><?php if ($cinema['has_logo']): ?><img src="index.php?route=cinema_logo" alt=""><?php endif; ?><strong><?= e($cinema['cinema_name']) ?></strong></a></header>
        <?php endif; ?>
        <main class="<?= $user ? 'app-main' : '' ?>">
          <div class="<?= $user ? 'app-content' : '' ?>">
            <div class="container-fluid page">
                <?php $content(); ?>
            </div>
          </div>
        </main>
        <script src="assets/vendor/adminlte/js/adminlte.min.js"></script>
      </div>
    </body>
    </html>
    <?php
}

function input_value(string $key, array $source = []): string
{
    return e($source[$key] ?? '');
}

function save_movie_cover(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload da capa.');
    }

    $info = getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('A capa precisa ser JPG, PNG ou WEBP.');
    }

    $data = file_get_contents($file['tmp_name']);
    if ($data === false) {
        throw new RuntimeException('Não foi possível ler a capa enviada.');
    }

    return ['mime' => $info['mime'], 'data' => $data];
}

function save_cinema_logo(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload do logotipo.');
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('O logotipo deve ter no máximo 3 MB.');
    }
    $info = getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('O logotipo precisa ser JPG, PNG ou WEBP.');
    }
    $data = file_get_contents($file['tmp_name']);
    if ($data === false) throw new RuntimeException('Não foi possível ler o logotipo enviado.');
    return ['mime' => $info['mime'], 'data' => $data];
}

function rebuild_room_seats(int $roomId, array $seats): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM room_seats WHERE room_id = ?')->execute([$roomId]);
    $stmt = $pdo->prepare(
        'INSERT INTO room_seats (room_id, row_label, seat_number, seat_code, seat_type, pos_x, pos_y, width, height)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($seats as $seat) {
        $row = preg_replace('/[^A-Z0-9]/i', '', (string) ($seat['row'] ?? 'A')) ?: 'A';
        $number = max(1, (int) ($seat['number'] ?? 1));
        $type = ($seat['type'] ?? 'normal') === 'grande' ? 'grande' : 'normal';
        $stmt->execute([
            $roomId,
            strtoupper($row),
            $number,
            strtoupper($row) . $number,
            $type,
            (float) ($seat['x'] ?? 0),
            (float) ($seat['y'] ?? 0),
            (float) ($seat['w'] ?? ($type === 'grande' ? 62 : 44)),
            (float) ($seat['h'] ?? 44),
        ]);
    }
}

function technical_sheet_from_post(): ?string
{
    $sheet = [
        'direcao' => trim($_POST['technical_director'] ?? ''),
        'roteiro' => trim($_POST['technical_writer'] ?? ''),
        'elenco' => trim($_POST['technical_cast'] ?? ''),
        'pais' => trim($_POST['technical_country'] ?? ''),
        'ano' => trim($_POST['technical_year'] ?? ''),
        'classificacao' => trim($_POST['technical_rating'] ?? ''),
        'distribuidora' => trim($_POST['technical_distributor'] ?? ''),
    ];

    $sheet = array_filter($sheet, static fn($value) => $value !== '');

    return $sheet ? json_encode($sheet, JSON_UNESCAPED_UNICODE) : null;
}

function technical_sheet_fields(?string $value): array
{
    $defaults = [
        'direcao' => '',
        'roteiro' => '',
        'elenco' => '',
        'pais' => '',
        'ano' => '',
        'classificacao' => '',
        'distribuidora' => '',
    ];

    if (!$value) {
        return $defaults;
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $fields = array_merge($defaults, array_intersect_key($decoded, $defaults));
    foreach ($fields as $key => $fieldValue) {
        if (is_array($fieldValue)) {
            $fields[$key] = implode(', ', array_map('strval', $fieldValue));
        }
    }

    return $fields;
}

function money_to_decimal(string $value): float
{
    $value = trim($value);
    $value = strpos($value, ',') !== false
        ? str_replace(['.', ','], ['', '.'], $value)
        : $value;

    return max(0, (float) $value);
}

function datetime_local_value(string $value): string
{
    return str_replace(' ', 'T', substr($value, 0, 16));
}

function normalize_datetime_local(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$date) {
        $date = DateTime::createFromFormat('Y-m-d H:i', $value);
    }

    return $date ? $date->format('Y-m-d H:i:00') : null;
}

function sale_code(): string
{
    return 'V' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

function ticket_token(): string
{
    return bin2hex(random_bytes(24));
}

function app_url(string $route, array $params = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $query = http_build_query(array_merge(['route' => $route], $params));
    return $scheme . '://' . $host . '/index.php?' . $query;
}

function cinema_settings(): array
{
    static $settings = null;
    if ($settings !== null) return $settings;

    $defaults = [
        'id' => 1, 'cinema_name' => config_value('app_name'), 'cnpj' => '', 'address' => '',
        'whatsapp' => '', 'phone' => '', 'email' => '', 'has_logo' => false, 'smtp_enabled' => 0,
        'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_auth' => 1,
        'smtp_username' => '', 'smtp_password_encrypted' => '', 'smtp_from_name' => '',
        'smtp_from_email' => '', 'smtp_reply_to' => '', 'smtp_timeout' => 30,
    ];
    try {
        $row = db()->query('SELECT id, cinema_name, cnpj, address, whatsapp, phone, email, logo_data IS NOT NULL has_logo, smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_auth, smtp_username, smtp_password_encrypted, smtp_from_name, smtp_from_email, smtp_reply_to, smtp_timeout FROM cinema_settings WHERE id = 1')->fetch();
        $settings = array_merge($defaults, $row ?: []);
    } catch (Throwable $exception) {
        $settings = $defaults;
    }
    return $settings;
}

function ensure_cinema_settings_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS cinema_settings (
        id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
        cinema_name VARCHAR(180) NOT NULL,
        cnpj VARCHAR(20) NULL,
        address TEXT NULL,
        whatsapp VARCHAR(30) NULL,
        phone VARCHAR(30) NULL,
        email VARCHAR(180) NULL,
        logo_mime VARCHAR(80) NULL,
        logo_data LONGBLOB NULL,
        smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        smtp_host VARCHAR(255) NULL,
        smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
        smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
        smtp_auth TINYINT(1) NOT NULL DEFAULT 1,
        smtp_username VARCHAR(255) NULL,
        smtp_password_encrypted TEXT NULL,
        smtp_from_name VARCHAR(180) NULL,
        smtp_from_email VARCHAR(180) NULL,
        smtp_reply_to VARCHAR(180) NULL,
        smtp_timeout SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $logoColumn = db()->query("SHOW COLUMNS FROM cinema_settings LIKE 'logo_mime'")->fetch();
    if (!$logoColumn) {
        db()->exec('ALTER TABLE cinema_settings ADD COLUMN logo_mime VARCHAR(80) NULL AFTER email, ADD COLUMN logo_data LONGBLOB NULL AFTER logo_mime');
    }
    db()->exec("INSERT INTO cinema_settings (id, cinema_name) VALUES (1, 'Cinema PCE') ON DUPLICATE KEY UPDATE id=id");
}

function encrypt_setting(string $value): string
{
    if ($value === '') return '';
    $key = hash('sha256', (string) config_value('settings_key'), true);
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new RuntimeException('Não foi possível proteger a senha SMTP.');
    return base64_encode($iv . $tag . $encrypted);
}

function sale_tickets(string $saleCode): array
{
    ensure_ticket_pricing_columns();
    $stmt = db()->prepare(
        'SELECT tickets.*, room_seats.seat_code, movies.id movie_id, movies.title movie_title, movies.cover_data IS NOT NULL has_cover, rooms.name room_name, showtimes.starts_at, showtimes.audio_type
         FROM tickets
         INNER JOIN room_seats ON room_seats.id = tickets.room_seat_id
         INNER JOIN showtimes ON showtimes.id = tickets.showtime_id
         INNER JOIN movies ON movies.id = showtimes.movie_id
         INNER JOIN rooms ON rooms.id = showtimes.room_id
         WHERE tickets.sale_code = ?
         ORDER BY room_seats.row_label, room_seats.seat_number'
    );
    $stmt->execute([$saleCode]);
    return $stmt->fetchAll();
}

function ticket_seats_by_type(array $tickets): array
{
    $seats = ['inteira' => [], 'meia' => []];
    foreach ($tickets as $ticket) {
        $type = ($ticket['ticket_type'] ?? 'inteira') === 'meia' ? 'meia' : 'inteira';
        $seats[$type][] = $ticket['seat_code'];
    }
    return $seats;
}

function ensure_ticket_pricing_columns(): void
{
    static $ready = false;
    if ($ready) return;

    $halfPrice = db()->query("SHOW COLUMNS FROM showtimes LIKE 'half_price'")->fetch();
    if (!$halfPrice) {
        db()->exec('ALTER TABLE showtimes ADD COLUMN half_price DECIMAL(10,2) NULL AFTER price');
    }
    db()->exec('UPDATE showtimes SET half_price = ROUND(price / 2, 2) WHERE half_price IS NULL');
    $ticketType = db()->query("SHOW COLUMNS FROM tickets LIKE 'ticket_type'")->fetch();
    if (!$ticketType) {
        db()->exec("ALTER TABLE tickets ADD COLUMN ticket_type ENUM('inteira','meia') NOT NULL DEFAULT 'inteira' AFTER payment_method");
    }
    $ready = true;
}

function open_cash_register(?int $userId = null): ?array
{
    $userId = $userId ?: (int) Auth::user()['id'];
    $stmt = db()->prepare('SELECT * FROM cash_registers WHERE user_id = ? AND status = "aberto" ORDER BY opened_at DESC LIMIT 1');
    $stmt->execute([$userId]);
    $cash = $stmt->fetch();
    return $cash ?: null;
}

function cash_totals(int $cashRegisterId): array
{
    $stmt = db()->prepare(
        'SELECT payment_method, COUNT(*) tickets, COALESCE(SUM(unit_price), 0) total
         FROM tickets
         WHERE cash_register_id = ? AND status = "vendido"
         GROUP BY payment_method'
    );
    $stmt->execute([$cashRegisterId]);
    $totals = [
        'dinheiro' => ['tickets' => 0, 'total' => 0.0],
        'cartao' => ['tickets' => 0, 'total' => 0.0],
        'pix' => ['tickets' => 0, 'total' => 0.0],
    ];
    foreach ($stmt->fetchAll() as $row) {
        $totals[$row['payment_method']] = ['tickets' => (int) $row['tickets'], 'total' => (float) $row['total']];
    }
    $totals['total'] = array_sum(array_column($totals, 'total'));
    return $totals;
}

function current_query(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return http_build_query(array_filter($params, static fn($value) => $value !== '' && $value !== null));
}

try {
    if ($route === 'health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'service' => 'cinema-pce']);
        exit;
    }

    if ($route === 'cinema_logo') {
        try {
            $logo = db()->query('SELECT logo_mime, logo_data FROM cinema_settings WHERE id = 1 AND logo_data IS NOT NULL LIMIT 1')->fetch();
        } catch (Throwable $exception) {
            $logo = false;
        }
        if (!$logo) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $logo['logo_mime']);
        header('Cache-Control: private, max-age=3600');
        echo $logo['logo_data'];
        exit;
    }

    if ($route === 'login') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            if (Auth::attempt(trim($_POST['email'] ?? ''), $_POST['password'] ?? '')) {
                redirect_to('dashboard');
            }
            $error = 'Email ou senha invalidos.';
        }

        layout('Login', function () use (&$error) {
            ?>
            <section class="auth-panel">
                <h1>Entrar</h1>
                <?php if (!empty($error)): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
                <form method="post" class="form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Email<input name="email" type="email" required autofocus></label>
                    <label>Senha<input name="password" type="password" required></label>
                    <button class="button primary">Acessar</button>
                </form>
            </section>
            <?php
        });
        exit;
    }

    if ($route === 'logout') {
        Auth::logout();
        redirect_to('login');
    }

    Auth::requireLogin();

    if (in_array($route, ['showtimes', 'showtime_new', 'showtime_edit', 'sales', 'sale_new'], true)) {
        ensure_ticket_pricing_columns();
    }

    if (($route === 'dashboard' || $route === '') && Auth::user()['role'] !== 'administrador') {
        redirect_to('sales');
    }

    if ($route === 'cinema_settings') {
        Auth::requireAdmin();
        ensure_cinema_settings_table();
        $stmt = db()->query('SELECT id, cinema_name, cnpj, address, whatsapp, phone, email, logo_data IS NOT NULL has_logo, smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_auth, smtp_username, smtp_password_encrypted, smtp_from_name, smtp_from_email, smtp_reply_to, smtp_timeout FROM cinema_settings WHERE id = 1');
        $settings = $stmt->fetch() ?: cinema_settings();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $logo = save_cinema_logo($_FILES['logo'] ?? []);
            $password = trim($_POST['smtp_password'] ?? '');
            $encryptedPassword = $password !== '' ? encrypt_setting($password) : ($settings['smtp_password_encrypted'] ?? '');
            $encryption = in_array($_POST['smtp_encryption'] ?? '', ['none', 'tls', 'ssl'], true) ? $_POST['smtp_encryption'] : 'tls';
            $sql = 'INSERT INTO cinema_settings
                (id, cinema_name, cnpj, address, whatsapp, phone, email, smtp_enabled, smtp_host, smtp_port, smtp_encryption, smtp_auth, smtp_username, smtp_password_encrypted, smtp_from_name, smtp_from_email, smtp_reply_to, smtp_timeout)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE cinema_name=VALUES(cinema_name), cnpj=VALUES(cnpj), address=VALUES(address), whatsapp=VALUES(whatsapp), phone=VALUES(phone), email=VALUES(email), smtp_enabled=VALUES(smtp_enabled), smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_encryption=VALUES(smtp_encryption), smtp_auth=VALUES(smtp_auth), smtp_username=VALUES(smtp_username), smtp_password_encrypted=VALUES(smtp_password_encrypted), smtp_from_name=VALUES(smtp_from_name), smtp_from_email=VALUES(smtp_from_email), smtp_reply_to=VALUES(smtp_reply_to), smtp_timeout=VALUES(smtp_timeout)';
            $save = db()->prepare($sql);
            $save->execute([
                trim($_POST['cinema_name'] ?? '') ?: config_value('app_name'),
                trim($_POST['cnpj'] ?? '') ?: null,
                trim($_POST['address'] ?? '') ?: null,
                trim($_POST['whatsapp'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                trim($_POST['email'] ?? '') ?: null,
                isset($_POST['smtp_enabled']) ? 1 : 0,
                trim($_POST['smtp_host'] ?? '') ?: null,
                max(1, (int) ($_POST['smtp_port'] ?? 587)),
                $encryption,
                isset($_POST['smtp_auth']) ? 1 : 0,
                trim($_POST['smtp_username'] ?? '') ?: null,
                $encryptedPassword ?: null,
                trim($_POST['smtp_from_name'] ?? '') ?: null,
                trim($_POST['smtp_from_email'] ?? '') ?: null,
                trim($_POST['smtp_reply_to'] ?? '') ?: null,
                max(1, (int) ($_POST['smtp_timeout'] ?? 30)),
            ]);
            if (isset($_POST['remove_logo'])) {
                db()->exec('UPDATE cinema_settings SET logo_mime = NULL, logo_data = NULL WHERE id = 1');
            } elseif ($logo) {
                $saveLogo = db()->prepare('UPDATE cinema_settings SET logo_mime = ?, logo_data = ? WHERE id = 1');
                $saveLogo->execute([$logo['mime'], $logo['data']]);
            }
            redirect_to('cinema_settings');
        }

        layout('Configurações do Cinema', function () use ($settings) {
            ?>
            <div class="section-head"><div><h1>Configurações do Cinema</h1><p class="muted">Dados institucionais e envio de e-mails.</p></div></div>
            <form method="post" enctype="multipart/form-data" class="form wide">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <section class="panel settings-section">
                    <h2>Dados do cinema</h2>
                    <div class="columns">
                        <label>Nome do cinema<input name="cinema_name" value="<?= e($settings['cinema_name']) ?>" required></label>
                        <label>CNPJ<input name="cnpj" value="<?= e($settings['cnpj']) ?>" placeholder="00.000.000/0000-00"></label>
                        <label>E-mail<input name="email" type="email" value="<?= e($settings['email']) ?>"></label>
                        <label>WhatsApp<input name="whatsapp" value="<?= e($settings['whatsapp']) ?>" placeholder="(00) 00000-0000"></label>
                        <label>Telefone<input name="phone" value="<?= e($settings['phone']) ?>" placeholder="(00) 0000-0000"></label>
                    </div>
                    <label>Endereço<textarea name="address" rows="3"><?= e($settings['address']) ?></textarea></label>
                    <div class="logo-upload-row">
                        <?php if ($settings['has_logo']): ?><img class="cinema-logo-preview" src="index.php?route=cinema_logo" alt="Logotipo atual"><?php endif; ?>
                        <label>Logotipo do cinema<input name="logo" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG ou WEBP, até 3 MB. O arquivo será salvo no banco de dados.</small></label>
                        <?php if ($settings['has_logo']): ?><label class="check-label"><input type="checkbox" name="remove_logo" value="1"> Remover logotipo atual</label><?php endif; ?>
                    </div>
                </section>
                <section class="panel settings-section">
                    <div class="settings-title"><h2>Servidor SMTP</h2><label class="check-label"><input type="checkbox" name="smtp_enabled" value="1" <?= $settings['smtp_enabled'] ? 'checked' : '' ?>> Ativar envio de e-mails</label></div>
                    <div class="columns">
                        <label>Servidor SMTP<input name="smtp_host" value="<?= e($settings['smtp_host']) ?>" placeholder="smtp.exemplo.com"></label>
                        <label>Porta<input name="smtp_port" type="number" min="1" max="65535" value="<?= e($settings['smtp_port']) ?>"></label>
                        <label>Segurança<select name="smtp_encryption"><option value="none" <?= $settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Nenhuma</option><option value="tls" <?= $settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS</option><option value="ssl" <?= $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option></select></label>
                        <label>Usuário SMTP<input name="smtp_username" value="<?= e($settings['smtp_username']) ?>" autocomplete="off"></label>
                        <label>Senha SMTP<input name="smtp_password" type="password" placeholder="<?= $settings['smtp_password_encrypted'] ? 'Senha configurada; deixe vazio para manter' : 'Informe a senha' ?>" autocomplete="new-password"></label>
                        <label>Timeout (segundos)<input name="smtp_timeout" type="number" min="1" value="<?= e($settings['smtp_timeout']) ?>"></label>
                    </div>
                    <label class="check-label"><input type="checkbox" name="smtp_auth" value="1" <?= $settings['smtp_auth'] ? 'checked' : '' ?>> Servidor exige autenticação</label>
                    <div class="columns">
                        <label>Nome do remetente<input name="smtp_from_name" value="<?= e($settings['smtp_from_name']) ?>"></label>
                        <label>E-mail do remetente<input name="smtp_from_email" type="email" value="<?= e($settings['smtp_from_email']) ?>"></label>
                        <label>Responder para<input name="smtp_reply_to" type="email" value="<?= e($settings['smtp_reply_to']) ?>"></label>
                    </div>
                </section>
                <button class="button primary">Salvar configurações</button>
            </form>
            <?php
        });
        exit;
    }

    if ($route === 'movie_cover') {
        $stmt = db()->prepare('SELECT cover_mime, cover_data FROM movies WHERE id = ? AND cover_data IS NOT NULL LIMIT 1');
        $stmt->execute([(int) ($_GET['id'] ?? 0)]);
        $cover = $stmt->fetch();

        if (!$cover) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $cover['cover_mime']);
        header('Cache-Control: private, max-age=86400');
        echo $cover['cover_data'];
        exit;
    }

    if ($route === 'users') {
        Auth::requireAdmin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                trim($_POST['name']),
                trim($_POST['email']),
                password_hash($_POST['password'], PASSWORD_DEFAULT),
                $_POST['role'] === 'administrador' ? 'administrador' : 'vendedor',
            ]);
            redirect_to('users');
        }

        $users = db()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY name')->fetchAll();
        layout('Usuarios', function () use ($users) {
            ?>
            <div class="section-head"><h1>Usuarios</h1></div>
            <section class="split">
                <form method="post" class="panel form">
                    <h2>Novo usuario</h2>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Nome<input name="name" required></label>
                    <label>Email<input name="email" type="email" required></label>
                    <label>Senha<input name="password" type="password" minlength="6" required></label>
                    <label>Nivel
                        <select name="role">
                            <option value="vendedor">Vendedor de bilhetes</option>
                            <option value="administrador">Administrador</option>
                        </select>
                    </label>
                    <button class="button primary">Salvar</button>
                </form>
                <div class="panel">
                    <table>
                        <thead><tr><th>Nome</th><th>Email</th><th>Nivel</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr><td><?= e($user['name']) ?></td><td><?= e($user['email']) ?></td><td><?= e($user['role']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php
        });
        exit;
    }

    if ($route === 'movies') {
        Auth::requireAdmin();
        $movies = db()->query('SELECT id, title, genre, duration_minutes, cover_mime, cover_data IS NOT NULL has_cover FROM movies ORDER BY created_at DESC')->fetchAll();
        layout('Filmes', function () use ($movies) {
            ?>
            <div class="section-head">
                <h1>Filmes</h1>
                <a class="button primary" href="index.php?route=movie_new">Novo filme</a>
            </div>
            <div class="grid cards">
                <?php foreach ($movies as $movie): ?>
                    <article class="movie-card">
                        <?php if ($movie['has_cover']): ?><img src="index.php?route=movie_cover&id=<?= (int) $movie['id'] ?>" alt=""><?php endif; ?>
                        <div>
                            <h2><?= e($movie['title']) ?></h2>
                            <p><?= e($movie['genre']) ?> | <?= e($movie['duration_minutes']) ?> min</p>
                            <a href="index.php?route=movie_edit&id=<?= (int) $movie['id'] ?>">Editar</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
        });
        exit;
    }

    if ($route === 'movie_new' || $route === 'movie_edit') {
        Auth::requireAdmin();
        $movie = ['title' => '', 'original_title' => '', 'synopsis' => '', 'trailer_url' => '', 'duration_minutes' => '', 'genre' => '', 'technical_sheet' => ''];
        if ($route === 'movie_edit') {
            $stmt = db()->prepare('SELECT * FROM movies WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $movie = $stmt->fetch() ?: $movie;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $cover = save_movie_cover($_FILES['cover'] ?? []);
            $technicalSheet = technical_sheet_from_post();
            if ($route === 'movie_new') {
                $stmt = db()->prepare('INSERT INTO movies (title, original_title, synopsis, trailer_url, cover_mime, cover_data, duration_minutes, genre, technical_sheet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$_POST['title'], $_POST['original_title'], $_POST['synopsis'], $_POST['trailer_url'], $cover['mime'] ?? null, $cover['data'] ?? null, (int) $_POST['duration_minutes'], $_POST['genre'], $technicalSheet]);
            } else {
                $sql = 'UPDATE movies SET title=?, original_title=?, synopsis=?, trailer_url=?, duration_minutes=?, genre=?, technical_sheet=?';
                $params = [$_POST['title'], $_POST['original_title'], $_POST['synopsis'], $_POST['trailer_url'], (int) $_POST['duration_minutes'], $_POST['genre'], $technicalSheet];
                if ($cover) {
                    $sql .= ', cover_mime=?, cover_data=?';
                    $params[] = $cover['mime'];
                    $params[] = $cover['data'];
                }
                $sql .= ' WHERE id=?';
                $params[] = (int) $_GET['id'];
                db()->prepare($sql)->execute($params);
            }
            redirect_to('movies');
        }

        layout('Filme', function () use ($movie) {
            $technicalSheet = technical_sheet_fields($movie['technical_sheet'] ?? null);
            ?>
            <div class="section-head"><h1>Cadastro de Filme</h1></div>
            <form method="post" enctype="multipart/form-data" class="panel form wide">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="columns">
                    <label>Título<input name="title" value="<?= input_value('title', $movie) ?>" required></label>
                    <label>Nome original<input name="original_title" value="<?= input_value('original_title', $movie) ?>"></label>
                    <label>Duração em minutos<input name="duration_minutes" type="number" min="1" value="<?= input_value('duration_minutes', $movie) ?>" required></label>
                    <label>Gênero<input name="genre" value="<?= input_value('genre', $movie) ?>" required></label>
                    <label>Trailer<input name="trailer_url" type="url" value="<?= input_value('trailer_url', $movie) ?>"></label>
                </div>
                <label>Sinopse<textarea name="synopsis" rows="5" required><?= e($movie['synopsis']) ?></textarea></label>
                <fieldset class="fieldset">
                    <legend>Ficha técnica</legend>
                    <div class="columns">
                        <label>Direção<input name="technical_director" value="<?= e($technicalSheet['direcao']) ?>"></label>
                        <label>Roteiro<input name="technical_writer" value="<?= e($technicalSheet['roteiro']) ?>"></label>
                        <label>Elenco principal<input name="technical_cast" value="<?= e($technicalSheet['elenco']) ?>"></label>
                        <label>País<input name="technical_country" value="<?= e($technicalSheet['pais']) ?>"></label>
                        <label>Ano<input name="technical_year" type="number" min="1888" max="2100" value="<?= e($technicalSheet['ano']) ?>"></label>
                        <label>Classificação indicativa<input name="technical_rating" value="<?= e($technicalSheet['classificacao']) ?>"></label>
                        <label>Distribuidora<input name="technical_distributor" value="<?= e($technicalSheet['distribuidora']) ?>"></label>
                    </div>
                </fieldset>
                <label>Capa do filme<input name="cover" type="file" accept="image/jpeg,image/png,image/webp"></label>
                <button class="button primary">Salvar filme</button>
            </form>
            <?php
        });
        exit;
    }

    if ($route === 'rooms') {
        Auth::requireAdmin();
        $rooms = db()->query('SELECT * FROM rooms ORDER BY name')->fetchAll();
        layout('Salas', function () use ($rooms) {
            ?>
            <div class="section-head">
                <h1>Salas</h1>
                <a class="button primary" href="index.php?route=room_new">Nova sala</a>
            </div>
            <div class="panel">
                <table>
                    <thead><tr><th>Sala</th><th>Capacidade</th><th>Normais</th><th>Grandes</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><?= e($room['name']) ?></td>
                            <td><?= (int) $room['capacity'] ?></td>
                            <td><?= (int) $room['normal_seats'] ?></td>
                            <td><?= (int) $room['large_seats'] ?></td>
                            <td><a href="index.php?route=room_edit&id=<?= (int) $room['id'] ?>">Editar mapa</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        });
        exit;
    }

    if ($route === 'showtimes') {
        Auth::requireAdmin();
        $filters = [
            'q' => trim($_GET['q'] ?? ''),
            'date_from' => trim($_GET['date_from'] ?? ''),
            'date_to' => trim($_GET['date_to'] ?? ''),
            'room_id' => (int) ($_GET['room_id'] ?? 0),
            'audio_type' => $_GET['audio_type'] ?? '',
            'status' => $_GET['status'] ?? '',
            'sort' => $_GET['sort'] ?? 'starts_at_asc',
            'group_by' => $_GET['group_by'] ?? 'day',
        ];
        $roomsFilter = db()->query('SELECT id, name FROM rooms WHERE active = 1 ORDER BY name')->fetchAll();
        $where = [];
        $params = [];

        if ($filters['q'] !== '') {
            $where[] = '(movies.title LIKE ? OR rooms.name LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'DATE(showtimes.starts_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'DATE(showtimes.starts_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if ($filters['room_id'] > 0) {
            $where[] = 'showtimes.room_id = ?';
            $params[] = $filters['room_id'];
        }
        if (in_array($filters['audio_type'], ['dublado', 'legendado'], true)) {
            $where[] = 'showtimes.audio_type = ?';
            $params[] = $filters['audio_type'];
        }
        if (in_array($filters['status'], ['programada', 'cancelada', 'encerrada'], true)) {
            $where[] = 'showtimes.status = ?';
            $params[] = $filters['status'];
        }

        $orderOptions = [
            'starts_at_asc' => 'showtimes.starts_at ASC',
            'starts_at_desc' => 'showtimes.starts_at DESC',
            'movie_asc' => 'movies.title ASC, showtimes.starts_at ASC',
            'room_asc' => 'rooms.name ASC, showtimes.starts_at ASC',
        ];
        $orderBy = $orderOptions[$filters['sort']] ?? $orderOptions['starts_at_asc'];
        if ($filters['group_by'] === 'room') {
            $orderBy = 'rooms.name ASC, ' . $orderBy;
        } elseif ($filters['group_by'] === 'day') {
            $orderBy = 'DATE(showtimes.starts_at) ASC, ' . $orderBy;
        } elseif ($filters['group_by'] === 'room_day') {
            $orderBy = 'rooms.name ASC, DATE(showtimes.starts_at) ASC, showtimes.starts_at ASC';
        }

        $sql =
            'SELECT showtimes.*, movies.title movie_title, movies.cover_data IS NOT NULL has_cover, rooms.name room_name
             FROM showtimes
             INNER JOIN movies ON movies.id = showtimes.movie_id
             INNER JOIN rooms ON rooms.id = showtimes.room_id
             ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY ' . $orderBy;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $showtimes = $stmt->fetchAll();

        layout('Sessões', function () use ($showtimes, $filters, $roomsFilter) {
            ?>
            <div class="section-head">
                <h1>Sessões</h1>
                <a class="button primary" href="index.php?route=showtime_new">Nova sessão</a>
            </div>
            <form method="get" class="panel form filters">
                <input type="hidden" name="route" value="showtimes">
                <div class="columns compact">
                    <label>Busca<input name="q" placeholder="Filme ou sala" value="<?= e($filters['q']) ?>"></label>
                    <label>Data inicial<input name="date_from" type="date" value="<?= e($filters['date_from']) ?>"></label>
                    <label>Data final<input name="date_to" type="date" value="<?= e($filters['date_to']) ?>"></label>
                    <label>Sala
                        <select name="room_id">
                            <option value="0">Todas</option>
                            <?php foreach ($roomsFilter as $room): ?>
                                <option value="<?= (int) $room['id'] ?>" <?= $filters['room_id'] === (int) $room['id'] ? 'selected' : '' ?>><?= e($room['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Áudio
                        <select name="audio_type">
                            <option value="">Todos</option>
                            <option value="dublado" <?= $filters['audio_type'] === 'dublado' ? 'selected' : '' ?>>Dublado</option>
                            <option value="legendado" <?= $filters['audio_type'] === 'legendado' ? 'selected' : '' ?>>Legendado</option>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="">Todos</option>
                            <option value="programada" <?= $filters['status'] === 'programada' ? 'selected' : '' ?>>Programada</option>
                            <option value="cancelada" <?= $filters['status'] === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                            <option value="encerrada" <?= $filters['status'] === 'encerrada' ? 'selected' : '' ?>>Encerrada</option>
                        </select>
                    </label>
                    <label>Ordenar
                        <select name="sort">
                            <option value="starts_at_asc" <?= $filters['sort'] === 'starts_at_asc' ? 'selected' : '' ?>>Data mais próxima</option>
                            <option value="starts_at_desc" <?= $filters['sort'] === 'starts_at_desc' ? 'selected' : '' ?>>Data mais recente</option>
                            <option value="movie_asc" <?= $filters['sort'] === 'movie_asc' ? 'selected' : '' ?>>Filme A-Z</option>
                            <option value="room_asc" <?= $filters['sort'] === 'room_asc' ? 'selected' : '' ?>>Sala A-Z</option>
                        </select>
                    </label>
                    <label>Agrupar
                        <select name="group_by">
                            <option value="" <?= $filters['group_by'] === '' ? 'selected' : '' ?>>Sem agrupamento</option>
                            <option value="day" <?= $filters['group_by'] === 'day' ? 'selected' : '' ?>>Por dia</option>
                            <option value="room" <?= $filters['group_by'] === 'room' ? 'selected' : '' ?>>Por sala</option>
                            <option value="room_day" <?= $filters['group_by'] === 'room_day' ? 'selected' : '' ?>>Por sala e dia</option>
                        </select>
                    </label>
                </div>
                <div class="toolbar">
                    <button class="button primary">Filtrar</button>
                    <a class="button" href="index.php?route=showtimes">Limpar filtros</a>
                </div>
            </form>
            <div class="panel">
                <table>
                    <thead><tr><th>Filme</th><th>Sala</th><th>Áudio</th><th>Data e horário</th><th>Inteira</th><th>Meia</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php $currentGroup = null; ?>
                    <?php foreach ($showtimes as $showtime): ?>
                        <?php
                        $groupLabel = '';
                        if ($filters['group_by'] === 'day') {
                            $groupLabel = date('d/m/Y', strtotime($showtime['starts_at']));
                        } elseif ($filters['group_by'] === 'room') {
                            $groupLabel = $showtime['room_name'];
                        } elseif ($filters['group_by'] === 'room_day') {
                            $groupLabel = $showtime['room_name'] . ' - ' . date('d/m/Y', strtotime($showtime['starts_at']));
                        }
                        if ($groupLabel !== '' && $groupLabel !== $currentGroup):
                            $currentGroup = $groupLabel;
                        ?>
                            <tr class="group-row"><td colspan="8"><?= e($groupLabel) ?></td></tr>
                        <?php endif; ?>
                        <tr>
                            <td>
                                <div class="movie-cell">
                                    <?php if ($showtime['has_cover']): ?><img src="index.php?route=movie_cover&id=<?= (int) $showtime['movie_id'] ?>" alt=""><?php endif; ?>
                                    <span><?= e($showtime['movie_title']) ?></span>
                                </div>
                            </td>
                            <td><?= e($showtime['room_name']) ?></td>
                            <td><?= e(ucfirst($showtime['audio_type'])) ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($showtime['starts_at']))) ?></td>
                            <td>R$ <?= e(number_format((float) $showtime['price'], 2, ',', '.')) ?></td>
                            <td>R$ <?= e(number_format((float) ($showtime['half_price'] ?? $showtime['price'] / 2), 2, ',', '.')) ?></td>
                            <td><?= e($showtime['status']) ?></td>
                            <td><a href="index.php?route=showtime_edit&id=<?= (int) $showtime['id'] ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$showtimes): ?>
                        <tr><td colspan="8">Nenhuma sessão cadastrada.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        });
        exit;
    }

    if ($route === 'showtime_new' || $route === 'showtime_edit') {
        Auth::requireAdmin();
        $movies = db()->query('SELECT id, title FROM movies WHERE active = 1 ORDER BY title')->fetchAll();
        $rooms = db()->query('SELECT id, name FROM rooms WHERE active = 1 ORDER BY name')->fetchAll();
        $showtime = ['movie_id' => '', 'room_id' => '', 'audio_type' => 'dublado', 'starts_at' => '', 'price' => '', 'half_price' => '', 'status' => 'programada'];

        if ($route === 'showtime_edit') {
            $stmt = db()->prepare('SELECT * FROM showtimes WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $showtime = $stmt->fetch() ?: $showtime;
            $showtime['starts_at'] = $showtime['starts_at'] ? datetime_local_value($showtime['starts_at']) : '';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $movieId = (int) ($_POST['movie_id'] ?? 0);
            $roomId = (int) ($_POST['room_id'] ?? 0);
            $audioType = ($_POST['audio_type'] ?? 'dublado') === 'legendado' ? 'legendado' : 'dublado';
            $price = money_to_decimal($_POST['price'] ?? '0');
            $halfPrice = money_to_decimal($_POST['half_price'] ?? '0');
            if ($price <= 0 || $halfPrice <= 0) {
                throw new RuntimeException('Informe os valores da inteira e da meia-entrada.');
            }
            $status = in_array($_POST['status'] ?? 'programada', ['programada', 'cancelada', 'encerrada'], true) ? $_POST['status'] : 'programada';

            $starts = $_POST['starts_at'] ?? [];
            if (!is_array($starts)) {
                $starts = [$starts];
            }
            $starts = array_values(array_unique(array_filter(array_map('normalize_datetime_local', $starts))));

            if (!$starts) {
                throw new RuntimeException('Adicione pelo menos um horário de exibição válido.');
            }

            if ($route === 'showtime_new') {
                $stmt = db()->prepare('INSERT INTO showtimes (movie_id, room_id, starts_at, audio_type, price, half_price, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($starts as $startsAt) {
                    $stmt->execute([$movieId, $roomId, $startsAt, $audioType, $price, $halfPrice, $status]);
                }
            } else {
                $stmt = db()->prepare('UPDATE showtimes SET movie_id=?, room_id=?, starts_at=?, audio_type=?, price=?, half_price=?, status=? WHERE id=?');
                $stmt->execute([$movieId, $roomId, $starts[0], $audioType, $price, $halfPrice, $status, (int) $_GET['id']]);

                if (count($starts) > 1) {
                    $insert = db()->prepare('INSERT INTO showtimes (movie_id, room_id, starts_at, audio_type, price, half_price, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    foreach (array_slice($starts, 1) as $startsAt) {
                        $insert->execute([$movieId, $roomId, $startsAt, $audioType, $price, $halfPrice, $status]);
                    }
                }
            }

            redirect_to('showtimes');
        }

        layout('Sessão', function () use ($movies, $rooms, $showtime, $route) {
            $isEdit = $route === 'showtime_edit';
            ?>
            <div class="section-head"><h1><?= $isEdit ? 'Editar Sessão' : 'Nova Sessão' ?></h1></div>
            <?php if (!$movies || !$rooms): ?>
                <p class="alert">Cadastre pelo menos um filme e uma sala antes de criar sessões.</p>
            <?php endif; ?>
            <form method="post" class="panel form wide" id="showtime-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="columns">
                    <label>Filme
                        <select name="movie_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($movies as $movie): ?>
                                <option value="<?= (int) $movie['id'] ?>" <?= (int) $showtime['movie_id'] === (int) $movie['id'] ? 'selected' : '' ?>><?= e($movie['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Sala
                        <select name="room_id" required>
                            <option value="">Selecione</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= (int) $room['id'] ?>" <?= (int) $showtime['room_id'] === (int) $room['id'] ? 'selected' : '' ?>><?= e($room['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Áudio
                        <select name="audio_type" required>
                            <option value="dublado" <?= $showtime['audio_type'] === 'dublado' ? 'selected' : '' ?>>Dublado</option>
                            <option value="legendado" <?= $showtime['audio_type'] === 'legendado' ? 'selected' : '' ?>>Legendado</option>
                        </select>
                    </label>
                    <label>Valor inteira<input name="price" inputmode="decimal" placeholder="Ex: 30,00" value="<?= e($showtime['price'] !== '' ? number_format((float) $showtime['price'], 2, ',', '.') : '') ?>" required></label>
                    <label>Valor meia<input name="half_price" inputmode="decimal" placeholder="Ex: 15,00" value="<?= e($showtime['half_price'] !== '' ? number_format((float) $showtime['half_price'], 2, ',', '.') : '') ?>" required></label>
                    <label>Status
                        <select name="status">
                            <option value="programada" <?= $showtime['status'] === 'programada' ? 'selected' : '' ?>>Programada</option>
                            <option value="cancelada" <?= $showtime['status'] === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                            <option value="encerrada" <?= $showtime['status'] === 'encerrada' ? 'selected' : '' ?>>Encerrada</option>
                        </select>
                    </label>
                </div>

                <fieldset class="fieldset">
                    <legend>Horários de exibição</legend>
                    <div class="showtime-builder">
                        <label>Data<input id="showtime-date" type="date"></label>
                        <label>Horário<input id="showtime-time" type="time"></label>
                        <button type="button" class="button" id="add-showtime">Adicionar horário</button>
                    </div>
                    <div id="showtime-list" class="showtime-list" data-initial='<?= e(json_encode($showtime['starts_at'] ? [$showtime['starts_at']] : [])) ?>'></div>
                </fieldset>
                <script src="assets/js/showtime-form.js"></script>

                <button class="button primary" <?= !$movies || !$rooms ? 'disabled' : '' ?>>Salvar sessão</button>
            </form>
            <?php
        });
        exit;
    }

    if ($route === 'cash_register') {
        Auth::requireLogin();
        $cash = open_cash_register();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $action = $_POST['action'] ?? '';
            if ($action === 'open' && !$cash) {
                $stmt = db()->prepare('INSERT INTO cash_registers (user_id, opened_at, opening_amount, status, notes) VALUES (?, NOW(), ?, "aberto", ?)');
                $stmt->execute([(int) Auth::user()['id'], money_to_decimal($_POST['opening_amount'] ?? '0'), trim($_POST['notes'] ?? '') ?: null]);
                redirect_to('cash_register');
            }

            if ($action === 'close' && $cash) {
                $totals = cash_totals((int) $cash['id']);
                $expected = (float) $cash['opening_amount'] + (float) $totals['dinheiro']['total'];
                $stmt = db()->prepare('UPDATE cash_registers SET closed_at = NOW(), closing_amount = ?, expected_amount = ?, status = "fechado", notes = ? WHERE id = ?');
                $stmt->execute([
                    money_to_decimal($_POST['closing_amount'] ?? '0'),
                    $expected,
                    trim($_POST['notes'] ?? '') ?: $cash['notes'],
                    (int) $cash['id'],
                ]);
                header('Location: index.php?route=cash_receipt&id=' . (int) $cash['id']);
                exit;
            }
        }

        $totals = $cash ? cash_totals((int) $cash['id']) : null;
        layout('Caixa', function () use ($cash, $totals) {
            ?>
            <div class="section-head"><h1>Caixa do Operador</h1></div>
            <?php if (!$cash): ?>
                <form method="post" class="panel form">
                    <h2>Abrir caixa</h2>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="open">
                    <label>Valor inicial em dinheiro<input name="opening_amount" inputmode="decimal" placeholder="Ex: 100,00" required></label>
                    <label>Observação<textarea name="notes" rows="3"></textarea></label>
                    <button class="button primary">Abrir caixa</button>
                </form>
            <?php else: ?>
                <section class="panel">
                    <h2>Caixa aberto</h2>
                    <p>Aberto em: <strong><?= e(date('d/m/Y H:i', strtotime($cash['opened_at']))) ?></strong></p>
                    <p>Valor inicial: <strong>R$ <?= e(number_format((float) $cash['opening_amount'], 2, ',', '.')) ?></strong></p>
                    <div class="stats compact-stats">
                        <div class="stat"><strong>R$ <?= e(number_format((float) $totals['dinheiro']['total'], 2, ',', '.')) ?></strong><span>Dinheiro</span></div>
                        <div class="stat"><strong>R$ <?= e(number_format((float) $totals['cartao']['total'], 2, ',', '.')) ?></strong><span>Cartão</span></div>
                        <div class="stat"><strong>R$ <?= e(number_format((float) $totals['pix']['total'], 2, ',', '.')) ?></strong><span>Pix</span></div>
                    </div>
                    <p>Total vendido: <strong>R$ <?= e(number_format((float) $totals['total'], 2, ',', '.')) ?></strong></p>
                    <p>Dinheiro esperado no caixa: <strong>R$ <?= e(number_format((float) $cash['opening_amount'] + (float) $totals['dinheiro']['total'], 2, ',', '.')) ?></strong></p>
                </section>
                <form method="post" class="panel form">
                    <h2>Fechar caixa</h2>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="close">
                    <label>Valor contado no caixa<input name="closing_amount" inputmode="decimal" placeholder="Ex: 350,00" required></label>
                    <label>Observação<textarea name="notes" rows="3"><?= e($cash['notes']) ?></textarea></label>
                    <button class="button primary">Fechar e imprimir comprovante</button>
                </form>
            <?php endif; ?>
            <?php
        });
        exit;
    }

    if ($route === 'cash_receipt') {
        Auth::requireLogin();
        $stmt = db()->prepare('SELECT cash_registers.*, users.name user_name FROM cash_registers INNER JOIN users ON users.id = cash_registers.user_id WHERE cash_registers.id = ? AND cash_registers.user_id = ?');
        $stmt->execute([(int) ($_GET['id'] ?? 0), (int) Auth::user()['id']]);
        $cash = $stmt->fetch();
        if (!$cash) {
            throw new RuntimeException('Caixa não encontrado.');
        }
        $totals = cash_totals((int) $cash['id']);
        $sales = db()->prepare(
            'SELECT sale_code, MIN(sold_at) sold_at, payment_method, COUNT(*) seats, MAX(total_amount) total_amount
             FROM tickets
             WHERE cash_register_id = ? AND status = "vendido"
             GROUP BY sale_code, payment_method
             ORDER BY sold_at ASC'
        );
        $sales->execute([(int) $cash['id']]);
        $operations = $sales->fetchAll();
        layout('Comprovante de Caixa', function () use ($cash, $totals, $operations) {
            ?>
            <div class="section-head no-print">
                <h1>Comprovante de Caixa</h1>
                <div class="toolbar">
                    <button class="button primary" onclick="window.print()">Imprimir / salvar PDF</button>
                    <a class="button" href="index.php?route=cash_register">Voltar ao caixa</a>
                </div>
            </div>
            <section class="ticket-print">
                <h1>Fechamento de Caixa</h1>
                <p><strong>Operador:</strong> <?= e($cash['user_name']) ?></p>
                <p><strong>Abertura:</strong> <?= e(date('d/m/Y H:i', strtotime($cash['opened_at']))) ?></p>
                <p><strong>Fechamento:</strong> <?= e($cash['closed_at'] ? date('d/m/Y H:i', strtotime($cash['closed_at'])) : '-') ?></p>
                <p><strong>Valor inicial:</strong> R$ <?= e(number_format((float) $cash['opening_amount'], 2, ',', '.')) ?></p>
                <p><strong>Dinheiro vendido:</strong> R$ <?= e(number_format((float) $totals['dinheiro']['total'], 2, ',', '.')) ?></p>
                <p><strong>Cartão:</strong> R$ <?= e(number_format((float) $totals['cartao']['total'], 2, ',', '.')) ?></p>
                <p><strong>Pix:</strong> R$ <?= e(number_format((float) $totals['pix']['total'], 2, ',', '.')) ?></p>
                <p><strong>Total vendido:</strong> R$ <?= e(number_format((float) $totals['total'], 2, ',', '.')) ?></p>
                <p><strong>Esperado em dinheiro:</strong> R$ <?= e(number_format((float) $cash['expected_amount'], 2, ',', '.')) ?></p>
                <p><strong>Contado:</strong> R$ <?= e(number_format((float) $cash['closing_amount'], 2, ',', '.')) ?></p>
                <h2>Operações</h2>
                <?php foreach ($operations as $operation): ?>
                    <p><?= e(date('H:i', strtotime($operation['sold_at']))) ?> - <?= e($operation['sale_code']) ?> - <?= e($operation['seats']) ?> poltrona(s) - <?= e(ucfirst($operation['payment_method'])) ?> - R$ <?= e(number_format((float) $operation['total_amount'], 2, ',', '.')) ?></p>
                <?php endforeach; ?>
            </section>
            <?php
        });
        exit;
    }

    if ($route === 'sales') {
        Auth::requireLogin();
        $cash = open_cash_register();
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $stmt = db()->prepare(
            'SELECT showtimes.*, movies.title movie_title, movies.duration_minutes, movies.cover_data IS NOT NULL has_cover, rooms.name room_name
             FROM showtimes
             INNER JOIN movies ON movies.id = showtimes.movie_id
             INNER JOIN rooms ON rooms.id = showtimes.room_id
             WHERE DATE(showtimes.starts_at) = ? AND showtimes.status = "programada"
             ORDER BY showtimes.starts_at ASC, movies.title ASC'
        );
        $stmt->execute([$date]);
        $showtimes = $stmt->fetchAll();

        layout('Venda', function () use ($date, $showtimes, $cash) {
            ?>
            <div class="section-head"><h1>Venda de Ingressos</h1></div>
            <?php if (!$cash): ?>
                <p class="alert">Abra o caixa antes de fazer vendas. <a href="index.php?route=cash_register">Abrir caixa</a></p>
            <?php endif; ?>
            <form method="get" class="panel form filters">
                <input type="hidden" name="route" value="sales">
                <div class="columns compact">
                    <label>Data da sessão<input name="date" type="date" value="<?= e($date) ?>"></label>
                </div>
                <button class="button primary">Buscar sessões</button>
            </form>
            <div class="grid cards">
                <?php foreach ($showtimes as $showtime): ?>
                    <article class="movie-card">
                        <?php if ($showtime['has_cover']): ?><img src="index.php?route=movie_cover&id=<?= (int) $showtime['movie_id'] ?>" alt=""><?php endif; ?>
                        <div>
                            <h2><?= e($showtime['movie_title']) ?></h2>
                            <p><?= e($showtime['room_name']) ?> | <?= e(ucfirst($showtime['audio_type'])) ?> | <?= e(date('H:i', strtotime($showtime['starts_at']))) ?></p>
                            <p>Inteira R$ <?= e(number_format((float) $showtime['price'], 2, ',', '.')) ?> | Meia R$ <?= e(number_format((float) ($showtime['half_price'] ?? $showtime['price'] / 2), 2, ',', '.')) ?></p>
                            <a class="button primary" href="<?= $cash ? 'index.php?route=sale_new&showtime_id=' . (int) $showtime['id'] : 'index.php?route=cash_register' ?>">Vender</a>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$showtimes): ?>
                    <section class="panel"><p>Nenhuma sessão programada para esta data.</p></section>
                <?php endif; ?>
            </div>
            <?php
        });
        exit;
    }

    if ($route === 'sale_new') {
        Auth::requireLogin();
        $cash = open_cash_register();
        if (!$cash) {
            redirect_to('cash_register');
        }
        $showtimeId = (int) ($_GET['showtime_id'] ?? $_POST['showtime_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT showtimes.*, movies.id movie_id, movies.title movie_title, movies.duration_minutes, movies.cover_data IS NOT NULL has_cover, rooms.id room_id, rooms.name room_name, rooms.screen_config
             FROM showtimes
             INNER JOIN movies ON movies.id = showtimes.movie_id
             INNER JOIN rooms ON rooms.id = showtimes.room_id
             WHERE showtimes.id = ? AND showtimes.status = "programada"'
        );
        $stmt->execute([$showtimeId]);
        $showtime = $stmt->fetch();
        if (!$showtime) {
            throw new RuntimeException('Sessão não encontrada ou indisponível para venda.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $seatIds = $_POST['seat_ids'] ?? [];
            if (!is_array($seatIds)) {
                $seatIds = [$seatIds];
            }
            $seatIds = array_values(array_unique(array_map('intval', $seatIds)));
            if (!$seatIds) {
                throw new RuntimeException('Selecione pelo menos uma poltrona.');
            }

            $paymentMethod = $_POST['payment_method'] ?? 'dinheiro';
            if (!in_array($paymentMethod, ['dinheiro', 'cartao', 'pix'], true)) {
                $paymentMethod = 'dinheiro';
            }

            $buyerName = trim($_POST['buyer_name'] ?? '');
            $amountPaid = money_to_decimal($_POST['amount_paid'] ?? '0');
            $seatTypes = is_array($_POST['seat_types'] ?? null) ? $_POST['seat_types'] : [];
            $fullPrice = (float) $showtime['price'];
            $halfPrice = (float) ($showtime['half_price'] ?? ($fullPrice / 2));
            $ticketTypes = [];
            $totalAmount = 0.0;
            foreach ($seatIds as $seatId) {
                $ticketType = ($seatTypes[$seatId] ?? 'inteira') === 'meia' ? 'meia' : 'inteira';
                $ticketTypes[$seatId] = $ticketType;
                $totalAmount += $ticketType === 'meia' ? $halfPrice : $fullPrice;
            }
            $changeAmount = $paymentMethod === 'dinheiro' ? max(0, $amountPaid - $totalAmount) : 0;
            if ($paymentMethod === 'dinheiro' && $amountPaid < $totalAmount) {
                throw new RuntimeException('Valor recebido em dinheiro é menor que o total da venda.');
            }

            db()->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($seatIds), '?'));
            $params = array_merge([$showtimeId], $seatIds);
            $check = db()->prepare(
                "SELECT room_seats.id, room_seats.seat_code
                 FROM room_seats
                 LEFT JOIN tickets ON tickets.room_seat_id = room_seats.id
                    AND tickets.showtime_id = ?
                    AND tickets.status IN ('reservado', 'vendido')
                 WHERE room_seats.id IN ($placeholders)
                    AND room_seats.room_id = ?
                    AND tickets.id IS NULL"
            );
            $params[] = (int) $showtime['room_id'];
            $check->execute($params);
            $availableSeats = $check->fetchAll();
            if (count($availableSeats) !== count($seatIds)) {
                db()->rollBack();
                throw new RuntimeException('Uma ou mais poltronas já foram vendidas. Atualize a tela e escolha novamente.');
            }

            $code = sale_code();
            $insert = db()->prepare(
                'INSERT INTO tickets (showtime_id, room_seat_id, seller_user_id, cash_register_id, sale_code, qr_token, buyer_name, payment_method, ticket_type, unit_price, total_amount, amount_paid, change_amount, status, sold_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "vendido", NOW())'
            );
            foreach ($availableSeats as $seat) {
                $qrToken = ticket_token();
                $ticketType = $ticketTypes[(int) $seat['id']] ?? 'inteira';
                $unitPrice = $ticketType === 'meia' ? $halfPrice : $fullPrice;
                $insert->execute([
                    $showtimeId,
                    (int) $seat['id'],
                    (int) Auth::user()['id'],
                    (int) $cash['id'],
                    $code,
                    $qrToken,
                    $buyerName ?: null,
                    $paymentMethod,
                    $ticketType,
                    $unitPrice,
                    $totalAmount,
                    $paymentMethod === 'dinheiro' ? $amountPaid : $totalAmount,
                    $changeAmount,
                ]);
            }
            db()->commit();
            header('Location: index.php?route=ticket_receipt&sale_code=' . urlencode($code));
            exit;
        }

        $seatsStmt = db()->prepare(
            "SELECT room_seats.*, tickets.id sold_ticket_id
             FROM room_seats
             LEFT JOIN tickets ON tickets.room_seat_id = room_seats.id
                AND tickets.showtime_id = ?
                AND tickets.status IN ('reservado', 'vendido')
             WHERE room_seats.room_id = ?
             ORDER BY room_seats.row_label, room_seats.seat_number"
        );
        $seatsStmt->execute([$showtimeId, (int) $showtime['room_id']]);
        $seats = $seatsStmt->fetchAll();
        $screen = json_decode($showtime['screen_config'] ?: '{}', true) ?: ['x' => 270, 'y' => 28, 'w' => 500, 'h' => 34];

        layout('Venda', function () use ($showtime, $seats, $screen) {
            ?>
            <form method="post" class="sale-layout sale-workbench" id="sale-form" data-full-price="<?= e($showtime['price']) ?>" data-half-price="<?= e($showtime['half_price'] ?? $showtime['price'] / 2) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="showtime_id" value="<?= (int) $showtime['id'] ?>">
                <section class="panel sale-map-panel">
                    <div class="sale-map" aria-label="Mapa de poltronas">
                        <div class="sale-screen" style="left:<?= e(((float) ($screen['x'] ?? 270) / 1040) * 100) ?>%;top:<?= e(((float) ($screen['y'] ?? 28) / 620) * 100) ?>%;width:<?= e(((float) ($screen['w'] ?? 500) / 1040) * 100) ?>%;height:<?= e(((float) ($screen['h'] ?? 34) / 620) * 100) ?>%;">TELA</div>
                        <?php foreach ($seats as $seat): ?>
                            <?php $sold = !empty($seat['sold_ticket_id']); ?>
                            <label class="sale-seat <?= $seat['seat_type'] === 'grande' ? 'large' : '' ?> <?= $sold ? 'sold' : '' ?>"
                                style="left:<?= e(((float) $seat['pos_x'] / 1040) * 100) ?>%;top:<?= e(((float) $seat['pos_y'] / 620) * 100) ?>%;width:<?= e(((float) $seat['width'] / 1040) * 100) ?>%;height:<?= e(((float) $seat['height'] / 620) * 100) ?>%;"
                                title="<?= e($seat['seat_code']) ?>">
                                <input type="checkbox" name="seat_ids[]" value="<?= (int) $seat['id'] ?>" <?= $sold ? 'disabled' : '' ?>>
                                <span><?= e($seat['seat_code']) ?></span>
                                <input class="seat-ticket-type" type="hidden" name="seat_types[<?= (int) $seat['id'] ?>]" value="inteira" disabled>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
                <aside class="panel sale-side-panel">
                    <div class="sale-session-info">
                        <span class="eyebrow">Venda de ingressos</span>
                        <h1><?= e($showtime['movie_title']) ?></h1>
                        <strong><?= e($showtime['room_name']) ?></strong>
                        <span><?= e(date('d/m/Y H:i', strtotime($showtime['starts_at']))) ?> | <?= e(ucfirst($showtime['audio_type'])) ?></span>
                    </div>
                    <div class="legend">
                        <span><i class="free"></i>Disponível</span>
                        <span><i class="selected"></i>Selecionada</span>
                        <span><i class="sold"></i>Ocupada</span>
                    </div>
                    <div class="sale-summary">
                        <p class="summary-item">Poltronas <strong id="selected-seats">Nenhuma</strong></p>
                        <p class="summary-item total">Total <strong id="sale-total">R$ 0,00</strong></p>
                        <label>Forma de pagamento
                            <select name="payment_method" id="payment-method">
                                <option value="dinheiro">Dinheiro</option>
                                <option value="cartao">Cartão</option>
                                <option value="pix">Pix</option>
                            </select>
                        </label>
                        <label id="amount-paid-row">Valor recebido<input name="amount_paid" id="amount-paid" inputmode="decimal" placeholder="Ex: 50,00"></label>
                        <p class="summary-item" id="change-row">Troco <strong id="sale-change">R$ 0,00</strong></p>
                    </div>
                    <button class="button primary finish-sale" id="finish-sale" disabled>Finalizar venda</button>
                </aside>
                <footer class="panel sale-footer">
                    <div class="ticket-type-toolbar" role="group" aria-label="Tipo do ingresso para as próximas poltronas">
                        <span>Próximas poltronas</span>
                        <label><input type="radio" name="active_ticket_type" value="meia" checked><b>Meia</b><small>R$ <?= e(number_format((float) ($showtime['half_price'] ?? $showtime['price'] / 2), 2, ',', '.')) ?></small></label>
                        <label><input type="radio" name="active_ticket_type" value="inteira"><b>Inteira</b><small>R$ <?= e(number_format((float) $showtime['price'], 2, ',', '.')) ?></small></label>
                    </div>
                    <a class="button" href="index.php?route=sales">Trocar sessão</a>
                </footer>
            </form>
            <script src="assets/js/sale.js"></script>
            <?php
        });
        exit;
    }

    if ($route === 'ticket_receipt') {
        Auth::requireLogin();
        $saleCode = trim($_GET['sale_code'] ?? '');
        $tickets = sale_tickets($saleCode);
        if (!$tickets) {
            throw new RuntimeException('Venda não encontrada.');
        }
        $first = $tickets[0];
        $cinema = cinema_settings();
        layout('Ingressos', function () use ($tickets, $first, $cinema) {
            ?>
            <div class="section-head no-print">
                <h1><?= count($tickets) ?> ingresso(s) emitido(s)</h1>
                <div class="toolbar">
                    <a class="button primary" href="index.php?route=ticket_print&sale_code=<?= e(urlencode($first['sale_code'])) ?>" target="_blank" rel="noopener">Imprimir ingressos separados</a>
                    <a class="button primary" href="index.php?route=sale_new&showtime_id=<?= (int) $first['showtime_id'] ?>">Nova venda nesta sessão</a>
                    <a class="button" href="index.php?route=sales">Trocar sessão</a>
                </div>
            </div>
            <div class="ticket-stack">
                <?php foreach ($tickets as $ticket): ?>
                    <?php $validationUrl = app_url('ticket_validate', ['token' => $ticket['qr_token'] ?: $ticket['sale_code']]); ?>
                    <section class="ticket-print">
                        <h1><?= e($cinema['cinema_name']) ?></h1>
                        <p><strong>Filme:</strong> <?= e($ticket['movie_title']) ?></p>
                        <p><strong>Sala:</strong> <?= e($ticket['room_name']) ?></p>
                        <p><strong>Sessão:</strong> <?= e(date('d/m/Y H:i', strtotime($ticket['starts_at']))) ?> | <?= e(ucfirst($ticket['audio_type'])) ?></p>
                        <p><strong>Poltrona:</strong> <?= e($ticket['seat_code']) ?></p>
                        <p><strong>Ingresso:</strong> <?= e(ucfirst($ticket['ticket_type'] ?? 'inteira')) ?> | R$ <?= e(number_format((float) $ticket['unit_price'], 2, ',', '.')) ?></p>
                        <div class="qr-ticket">
                            <div data-ticket-qr data-url="<?= e($validationUrl) ?>"></div>
                            <p><strong>Código:</strong> <?= e($ticket['qr_token'] ?: $ticket['sale_code']) ?></p>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <script src="assets/js/vendor/qrcode.min.js"></script>
            <script src="assets/js/ticket-qr.js"></script>
            <?php
        });
        exit;
    }

    if ($route === 'ticket_print') {
        Auth::requireLogin();
        $saleCode = trim($_GET['sale_code'] ?? '');
        $tickets = sale_tickets($saleCode);
        if (!$tickets) {
            http_response_code(404);
            exit('Venda não encontrada.');
        }
        $first = $tickets[0];
        $cinema = cinema_settings();
        ?>
        <!doctype html>
        <html lang="pt-br">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Recibo <?= e($first['sale_code']) ?></title>
            <style>
                *{box-sizing:border-box} html,body{margin:0;background:#fff;color:#000} body{font:12px/1.25 "Courier New",Courier,monospace}
                .actions{display:flex;justify-content:center;gap:8px;padding:14px;background:#f3f3f3}
                .actions button{padding:9px 14px;border:0;border-radius:3px;background:#c2410c;color:#fff;font-weight:700;cursor:pointer}
                .receipt{width:40ch;max-width:calc(100% - 8mm);margin:0 auto;padding:4mm 0;text-align:left;overflow-wrap:anywhere}
                .receipt-logo{display:block;max-width:30mm;max-height:18mm;object-fit:contain;margin:0 auto 2mm;filter:grayscale(1)}
                h1{margin:0 0 3px;text-align:center;font:700 15px/1.2 "Courier New",Courier,monospace}.company{text-align:center;margin-bottom:8px}
                .line{height:1em;margin:5px 0;overflow:hidden}.line::before{content:"----------------------------------------"}p{margin:3px 0;line-height:1.25}
                [data-ticket-qr]{width:36mm;height:36mm;margin:8px auto 4px}[data-ticket-qr] canvas,[data-ticket-qr] img{width:36mm!important;height:36mm!important;display:block}
                .code{text-align:center;font-size:8px;word-break:break-all}.thanks{text-align:center;margin-top:8px;font-weight:700}
                .receipt+.receipt{break-before:page;page-break-before:always}
                @media print{.actions{display:none}.receipt{width:40ch;max-width:none;padding:0}.receipt+.receipt{break-before:page;page-break-before:always}@page{size:80mm auto;margin:3mm}}
            </style>
        </head>
        <body>
            <div class="actions"><button id="print-tickets" type="button">Imprimir / salvar PDF</button></div>
            <?php foreach ($tickets as $ticket): ?>
                <?php $validationUrl = app_url('ticket_validate', ['token' => $ticket['qr_token'] ?: $ticket['sale_code']]); ?>
                <main class="receipt">
                    <?php if ($cinema['has_logo']): ?><img class="receipt-logo" src="index.php?route=cinema_logo" alt=""><?php endif; ?>
                    <h1><?= e($cinema['cinema_name']) ?></h1>
                    <div class="company">
                        <?php if ($cinema['cnpj']): ?>CNPJ <?= e($cinema['cnpj']) ?><br><?php endif; ?>
                        <?php if ($cinema['address']): ?><?= nl2br(e($cinema['address'])) ?><br><?php endif; ?>
                        <?= e($cinema['phone'] ?: $cinema['whatsapp']) ?>
                    </div>
                    <div class="line"></div>
                    <p><strong>Venda:</strong> <?= e($ticket['sale_code']) ?></p>
                    <p><strong>Filme:</strong> <?= e($ticket['movie_title']) ?></p>
                    <p><strong>Sala:</strong> <?= e($ticket['room_name']) ?></p>
                    <p><strong>Sessão:</strong> <?= e(date('d/m/Y H:i', strtotime($ticket['starts_at']))) ?> | <?= e(ucfirst($ticket['audio_type'])) ?></p>
                    <p><strong>Poltrona:</strong> <?= e($ticket['seat_code']) ?></p>
                    <p><strong>Tipo:</strong> <?= e(ucfirst($ticket['ticket_type'] ?? 'inteira')) ?></p>
                    <p><strong>Valor:</strong> R$ <?= e(number_format((float) $ticket['unit_price'], 2, ',', '.')) ?></p>
                    <div class="line"></div>
                    <div data-ticket-qr data-url="<?= e($validationUrl) ?>"></div>
                    <div class="code"><?= e($ticket['qr_token'] ?: $ticket['sale_code']) ?></div>
                    <p class="thanks">Apresente este ingresso na entrada.</p>
                </main>
            <?php endforeach; ?>
            <script src="assets/js/vendor/qrcode.min.js"></script>
            <script src="assets/js/ticket-qr.js"></script>
            <script>
                document.getElementById('print-tickets').addEventListener('click', async function () {
                    this.disabled = true;
                    this.textContent = 'Preparando QR Codes...';
                    await (window.ticketQrReady || Promise.resolve());
                    this.disabled = false;
                    this.textContent = 'Imprimir / salvar PDF';
                    window.print();
                });
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    if ($route === 'qr_reader') {
        Auth::requireLogin();
        layout('Check-in QR', function () {
            ?>
            <div class="section-head">
                <div>
                    <h1>Check-in por QR Code</h1>
                    <p class="muted">Leia o ingresso e confirme a entrada na sessão correta.</p>
                </div>
            </div>
            <section class="panel qr-reader-panel">
                <div class="qr-camera-box">
                    <video id="qr-video" playsinline muted></video>
                    <canvas id="qr-canvas" hidden></canvas>
                </div>
                <div class="qr-reader-actions">
                    <button class="button primary" id="start-qr-reader" type="button">Abrir câmera</button>
                    <button class="button" id="stop-qr-reader" type="button" disabled>Parar</button>
                </div>
                <p class="muted" id="qr-reader-status">Use em HTTPS no celular para liberar a câmera.</p>
                <form method="get" class="form qr-manual-form">
                    <input type="hidden" name="route" value="ticket_validate">
                    <label>Código ou link do QR
                        <input name="token" placeholder="Cole o código lido ou a URL do ingresso">
                    </label>
                    <button class="button primary">Validar manualmente</button>
                </form>
            </section>
            <script src="assets/js/qr-reader.js"></script>
            <?php
        });
        exit;
    }

    if ($route === 'ticket_validate') {
        Auth::requireLogin();
        $rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
        if (str_contains($rawToken, 'ticket_validate')) {
            $parts = parse_url($rawToken);
            parse_str($parts['query'] ?? '', $query);
            $rawToken = trim($query['token'] ?? $rawToken);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $stmt = db()->prepare('UPDATE tickets SET checked_in_at = NOW(), checked_in_by = ? WHERE (qr_token = ? OR sale_code = ?) AND status = "vendido" AND checked_in_at IS NULL');
            $stmt->execute([(int) Auth::user()['id'], $rawToken, $rawToken]);
            header('Location: index.php?route=ticket_validate&token=' . urlencode($rawToken) . '&checked=' . $stmt->rowCount());
            exit;
        }

        $tickets = [];
        if ($rawToken !== '') {
            $stmt = db()->prepare(
                'SELECT tickets.*, room_seats.seat_code, movies.id movie_id, movies.title movie_title, movies.cover_data IS NOT NULL has_cover, rooms.name room_name, showtimes.starts_at, showtimes.audio_type
                 FROM tickets
                 INNER JOIN room_seats ON room_seats.id = tickets.room_seat_id
                 INNER JOIN showtimes ON showtimes.id = tickets.showtime_id
                 INNER JOIN movies ON movies.id = showtimes.movie_id
                 INNER JOIN rooms ON rooms.id = showtimes.room_id
                 WHERE (tickets.qr_token = ? OR tickets.sale_code = ?) AND tickets.status = "vendido"
                 ORDER BY room_seats.row_label, room_seats.seat_number'
            );
            $stmt->execute([$rawToken, $rawToken]);
            $tickets = $stmt->fetchAll();
        }

        $first = $tickets[0] ?? null;
        $alreadyUsed = $first && !empty($first['checked_in_at']);
        $checkedNow = max(0, (int) ($_GET['checked'] ?? 0));
        layout('Check-in do Ingresso', function () use ($rawToken, $tickets, $first, $alreadyUsed, $checkedNow) {
            ?>
            <div class="section-head">
                <div>
                    <h1>Check-in do Ingresso</h1>
                    <p class="muted">Controle de entrada por sessão, filme e sala.</p>
                </div>
                <a class="button" href="index.php?route=qr_reader">Ler outro QR</a>
            </div>
            <?php if (!$rawToken || !$first): ?>
                <section class="validation-card invalid">
                    <strong>QR Code inválido</strong>
                    <span>QR Code não encontrado ou venda inexistente.</span>
                </section>
            <?php elseif ($checkedNow > 0): ?>
                <section class="validation-card valid">
                    <strong>Check-in realizado</strong>
                    <span><?= $checkedNow ?> ingresso(s) registrado(s) para esta sessão.</span>
                </section>
            <?php elseif ($alreadyUsed): ?>
                <section class="validation-card used">
                    <strong>Check-in já realizado</strong>
                    <span>Entrada registrada em <?= e(date('d/m/Y H:i', strtotime($first['checked_in_at']))) ?>.</span>
                </section>
            <?php else: ?>
                <section class="validation-card valid">
                    <strong>Ingresso localizado</strong>
                    <span>Confira filme, sala e horário antes de confirmar o check-in.</span>
                </section>
            <?php endif; ?>

            <?php if ($first): ?>
                <section class="panel validation-detail">
                    <?php if ($first['has_cover']): ?>
                        <img src="index.php?route=movie_cover&id=<?= (int) $first['movie_id'] ?>" alt="">
                    <?php endif; ?>
                    <div>
                        <h2><?= e($first['movie_title']) ?></h2>
                        <p><strong>Sala:</strong> <?= e($first['room_name']) ?></p>
                        <p><strong>Sessão:</strong> <?= e(date('d/m/Y H:i', strtotime($first['starts_at']))) ?> | <?= e(ucfirst($first['audio_type'])) ?></p>
                        <p><strong>Poltronas:</strong> <?= e(implode(', ', array_column($tickets, 'seat_code'))) ?></p>
                        <p><strong>Código:</strong> <?= e($first['sale_code']) ?></p>
                        <?php if (!$alreadyUsed): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="token" value="<?= e($first['qr_token'] ?: $rawToken) ?>">
                                <button class="button primary">Confirmar check-in</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php
        });
        exit;
    }

    if ($route === 'room_new' || $route === 'room_edit') {
        Auth::requireAdmin();
        $room = ['name' => '', 'capacity' => '', 'normal_seats' => '', 'large_seats' => '', 'screen_config' => '{}', 'seat_layout' => '[]'];
        if ($route === 'room_edit') {
            $stmt = db()->prepare('SELECT * FROM rooms WHERE id = ?');
            $stmt->execute([(int) $_GET['id']]);
            $room = $stmt->fetch() ?: $room;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();
            $layout = json_decode($_POST['seat_layout'] ?: '[]', true);
            $screen = json_decode($_POST['screen_config'] ?: '{}', true);
            if (!is_array($layout) || !is_array($screen)) {
                throw new RuntimeException('Layout da sala invalido.');
            }

            db()->beginTransaction();
            if ($route === 'room_new') {
                $stmt = db()->prepare('INSERT INTO rooms (name, capacity, normal_seats, large_seats, screen_config, seat_layout) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$_POST['name'], (int) $_POST['capacity'], (int) $_POST['normal_seats'], (int) $_POST['large_seats'], json_encode($screen), json_encode($layout)]);
                $roomId = (int) db()->lastInsertId();
            } else {
                $roomId = (int) $_GET['id'];
                $stmt = db()->prepare('UPDATE rooms SET name=?, capacity=?, normal_seats=?, large_seats=?, screen_config=?, seat_layout=? WHERE id=?');
                $stmt->execute([$_POST['name'], (int) $_POST['capacity'], (int) $_POST['normal_seats'], (int) $_POST['large_seats'], json_encode($screen), json_encode($layout), $roomId]);
            }
            rebuild_room_seats($roomId, $layout);
            db()->commit();
            redirect_to('rooms');
        }

        layout('Sala', function () use ($room) {
            ?>
            <div class="section-head"><h1>Mapa da sala</h1></div>
            <form method="post" class="panel form wide" id="room-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="seat_layout" id="seat-layout" value="<?= e($room['seat_layout'] ?: '[]') ?>">
                <input type="hidden" name="screen_config" id="screen-config" value="<?= e($room['screen_config'] ?: '{}') ?>">
                <div class="columns compact">
                    <label>Nome da sala<input name="name" value="<?= input_value('name', $room) ?>" required></label>
                    <label>Capacidade<input id="capacity" name="capacity" type="number" min="1" value="<?= input_value('capacity', $room) ?>" required></label>
                    <label>Poltronas normais<input id="normal-seats" name="normal_seats" type="number" min="0" value="<?= input_value('normal_seats', $room) ?>" required></label>
                    <label>Poltronas grandes<input id="large-seats" name="large_seats" type="number" min="0" value="<?= input_value('large_seats', $room) ?>" required></label>
                    <label>Corredores<input id="rows" type="text" placeholder="Ex: A,B,C"></label>
                    <label>Por corredor<input id="seats-per-row" type="number" min="1" placeholder="Ex: 20"></label>
                </div>
                <div class="toolbar">
                    <button type="button" class="button" id="generate-layout">Gerar poltronas</button>
                    <button type="button" class="button" id="add-large-seat">Marcar grande</button>
                    <button type="button" class="button" id="fit-screen">Ajustar tela</button>
                    <button type="button" class="button danger" id="clear-layout">Limpar sala</button>
                    <span id="seat-summary"></span>
                </div>
                <div class="room-editor">
                    <div id="canvas" class="room-canvas">
                        <div id="screen" class="screen">TELA</div>
                    </div>
                </div>
                <button class="button primary">Salvar sala</button>
            </form>
            <script src="assets/js/seat-map-editor.js"></script>
            <?php
        });
        exit;
    }

    Auth::requireAdmin();
    $dashboardDate = trim($_GET['date'] ?? date('Y-m-d'));
    $statsStmt = db()->prepare(
        "SELECT
            COUNT(DISTINCT showtimes.id) showtimes,
            COUNT(DISTINCT showtimes.room_id) rooms,
            COALESCE(SUM(CASE WHEN tickets.status = 'vendido' THEN tickets.unit_price ELSE 0 END), 0) revenue,
            COUNT(CASE WHEN tickets.status = 'vendido' THEN tickets.id END) tickets,
            COUNT(CASE WHEN tickets.status = 'vendido' AND tickets.checked_in_at IS NOT NULL THEN tickets.id END) checked_in
         FROM showtimes
         LEFT JOIN tickets ON tickets.showtime_id = showtimes.id
         WHERE DATE(showtimes.starts_at) = ?"
    );
    $statsStmt->execute([$dashboardDate]);
    $stats = $statsStmt->fetch();

    $occupancyStmt = db()->prepare(
        "SELECT showtimes.id, showtimes.starts_at, showtimes.audio_type, showtimes.price,
            movies.id movie_id, movies.title movie_title, movies.cover_data IS NOT NULL has_cover,
            rooms.name room_name, rooms.capacity,
            COUNT(CASE WHEN tickets.status = 'vendido' THEN tickets.id END) sold,
            COUNT(CASE WHEN tickets.status = 'vendido' AND tickets.checked_in_at IS NOT NULL THEN tickets.id END) checked_in,
            COALESCE(SUM(CASE WHEN tickets.status = 'vendido' THEN tickets.unit_price ELSE 0 END), 0) revenue
         FROM showtimes
         INNER JOIN movies ON movies.id = showtimes.movie_id
         INNER JOIN rooms ON rooms.id = showtimes.room_id
         LEFT JOIN tickets ON tickets.showtime_id = showtimes.id
         WHERE DATE(showtimes.starts_at) = ?
         GROUP BY showtimes.id, movies.title, rooms.name, rooms.capacity
         ORDER BY showtimes.starts_at ASC, rooms.name ASC"
    );
    $occupancyStmt->execute([$dashboardDate]);
    $sessionRows = $occupancyStmt->fetchAll();

    $hourStmt = db()->prepare(
        "SELECT HOUR(showtimes.starts_at) hour_label,
            COUNT(CASE WHEN tickets.status = 'vendido' THEN tickets.id END) tickets
         FROM showtimes
         LEFT JOIN tickets ON tickets.showtime_id = showtimes.id
         WHERE DATE(showtimes.starts_at) = ?
         GROUP BY HOUR(showtimes.starts_at)
         ORDER BY hour_label ASC"
    );
    $hourStmt->execute([$dashboardDate]);
    $hourRows = $hourStmt->fetchAll();
    $maxHourTickets = max(1, ...array_map(static fn($row) => (int) $row['tickets'], $hourRows ?: [['tickets' => 1]]));

    $roomStmt = db()->prepare(
        "SELECT rooms.name room_name,
            COUNT(CASE WHEN tickets.status = 'vendido' THEN tickets.id END) tickets,
            COUNT(CASE WHEN tickets.status = 'vendido' AND tickets.checked_in_at IS NOT NULL THEN tickets.id END) checked_in,
            COALESCE(SUM(CASE WHEN tickets.status = 'vendido' THEN tickets.unit_price ELSE 0 END), 0) revenue
         FROM showtimes
         INNER JOIN rooms ON rooms.id = showtimes.room_id
         LEFT JOIN tickets ON tickets.showtime_id = showtimes.id
         WHERE DATE(showtimes.starts_at) = ?
         GROUP BY rooms.id, rooms.name
         ORDER BY rooms.name ASC"
    );
    $roomStmt->execute([$dashboardDate]);
    $roomRows = $roomStmt->fetchAll();
    $totalCapacity = array_sum(array_map(static fn($row) => (int) $row['capacity'], $sessionRows));
    $totalOccupied = array_sum(array_map(static fn($row) => (int) $row['sold'], $sessionRows));
    $overallOccupancy = $totalCapacity > 0 ? min(100, round(($totalOccupied / $totalCapacity) * 100)) : 0;
    $maxRoomRevenue = max(1, ...array_map(static fn($row) => (float) $row['revenue'], $roomRows ?: [['revenue' => 1]]));

    layout('Painel', function () use ($stats, $dashboardDate, $sessionRows, $hourRows, $maxHourTickets, $roomRows, $totalOccupied, $totalCapacity, $overallOccupancy, $maxRoomRevenue) {
        ?>
        <div class="section-head cockpit-head">
            <div>
                <h1>Cockpit de Vendas</h1>
                <p class="muted">Sessões, ocupação e vendas por horário de exibição</p>
            </div>
            <form method="get" class="date-switcher">
                <input type="hidden" name="route" value="dashboard">
                <input type="date" name="date" value="<?= e($dashboardDate) ?>">
                <button class="button primary">Atualizar</button>
            </form>
        </div>

        <div class="stats cockpit-stats">
            <div class="stat hero-stat"><strong>R$ <?= e(number_format((float) $stats['revenue'], 2, ',', '.')) ?></strong><span>Vendas do dia</span></div>
            <div class="stat"><strong><?= (int) $stats['tickets'] ?></strong><span>Ingressos vendidos</span></div>
            <div class="stat checkin-stat"><strong><?= (int) $stats['checked_in'] ?></strong><span>Check-ins realizados</span></div>
            <div class="stat remaining-stat"><strong><?= max(0, (int) $stats['tickets'] - (int) $stats['checked_in']) ?></strong><span>Ingressos restantes</span></div>
            <div class="stat"><strong><?= (int) $stats['showtimes'] ?></strong><span>Sessões no dia</span></div>
            <div class="stat"><strong><?= (int) $stats['rooms'] ?></strong><span>Salas em operação</span></div>
            <div class="stat occupancy-stat">
                <div class="occupancy-ring" style="--value: <?= e($overallOccupancy) ?>"><strong><?= e($overallOccupancy) ?>%</strong></div>
                <span>Ocupação geral</span>
                <small><?= $totalOccupied ?>/<?= $totalCapacity ?> lugares</small>
            </div>
        </div>

        <section class="cockpit-grid">
            <div class="panel chart-panel">
                <div class="panel-heading"><div><span class="eyebrow">Capacidade</span><h2>Ocupação das sessões</h2></div><span class="chart-legend">Ocupação por sessão</span></div>
                <div class="occupancy-list">
                    <?php foreach ($sessionRows as $session): ?>
                        <?php
                        $capacity = max(1, (int) $session['capacity']);
                        $sold = (int) $session['sold'];
                        $checkedIn = (int) $session['checked_in'];
                        $remaining = max(0, $sold - $checkedIn);
                        $percent = min(100, round(($sold / $capacity) * 100));
                        ?>
                        <div class="occupancy-item">
                            <div>
                                <strong><?= e(date('H:i', strtotime($session['starts_at']))) ?> - <?= e($session['movie_title']) ?></strong>
                                <span><?= e($session['room_name']) ?> | <?= e(ucfirst($session['audio_type'])) ?> | <?= $sold ?>/<?= $capacity ?> vendidos</span>
                                <span class="checkin-line"><?= $checkedIn ?> check-ins | <?= $remaining ?> aguardando entrada</span>
                            </div>
                            <div class="occupancy-meter" aria-label="<?= e($percent) ?>% ocupado">
                                <i style="width: <?= e($percent) ?>%"></i>
                            </div>
                            <b><?= e($percent) ?>%</b>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$sessionRows): ?><p class="muted">Nenhuma sessão cadastrada para esta data.</p><?php endif; ?>
                </div>
            </div>

            <div class="panel chart-panel">
                <div class="panel-heading"><div><span class="eyebrow">Fluxo</span><h2>Ingressos por horário</h2></div><span class="chart-legend">Quantidade vendida</span></div>
                <div class="bar-chart">
                    <?php foreach ($hourRows as $hour): ?>
                        <?php $height = 12 + (((int) $hour['tickets'] / $maxHourTickets) * 88); ?>
                        <div class="bar-column">
                            <span><?= (int) $hour['tickets'] ?> ing.</span>
                            <i style="height: <?= e($height) ?>%"></i>
                            <b><?= e(str_pad((string) $hour['hour_label'], 2, '0', STR_PAD_LEFT)) ?>h</b>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hourRows): ?><p class="muted">Sem ingressos por horário ainda.</p><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>Grade de sessões por sala e horário</h2>
            <div class="session-grid">
                <?php foreach ($sessionRows as $session): ?>
                    <?php
                    $capacity = max(1, (int) $session['capacity']);
                    $sold = (int) $session['sold'];
                    $checkedIn = (int) $session['checked_in'];
                    $remaining = max(0, $sold - $checkedIn);
                    $percent = min(100, round(($sold / $capacity) * 100));
                    ?>
                    <article class="session-tile">
                        <?php if ($session['has_cover']): ?>
                            <img class="session-poster" src="index.php?route=movie_cover&id=<?= (int) $session['movie_id'] ?>" alt="">
                        <?php else: ?>
                            <div class="session-poster placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                        <div class="session-time"><?= e(date('H:i', strtotime($session['starts_at']))) ?></div>
                        <div>
                            <strong><?= e($session['movie_title']) ?></strong>
                            <span><?= e($session['room_name']) ?> | <?= e(ucfirst($session['audio_type'])) ?></span>
                        </div>
                        <div class="mini-meter"><i style="width: <?= e($percent) ?>%"></i></div>
                        <small><?= $sold ?> vendidos | <?= $checkedIn ?> check-ins | <?= $remaining ?> aguardando</small>
                        <small>R$ <?= e(number_format((float) $session['revenue'], 2, ',', '.')) ?></small>
                    </article>
                <?php endforeach; ?>
                <?php if (!$sessionRows): ?><p class="muted">A grade aparece assim que houver sessões para a data selecionada.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <h2>Vendas por sala</h2>
            <div class="room-sales-grid">
                <?php foreach ($roomRows as $room): ?>
                    <div class="room-sale-card">
                        <strong><?= e($room['room_name']) ?></strong>
                        <span><?= (int) $room['tickets'] ?> ingresso(s)</span>
                        <span><?= (int) $room['checked_in'] ?> check-in(s) | <?= max(0, (int) $room['tickets'] - (int) $room['checked_in']) ?> restante(s)</span>
                        <b>R$ <?= e(number_format((float) $room['revenue'], 2, ',', '.')) ?></b>
                        <div class="room-revenue-meter"><i style="width: <?= e(min(100, ((float) $room['revenue'] / $maxRoomRevenue) * 100)) ?>%"></i></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$roomRows): ?><p class="muted">Sem vendas por sala nesta data.</p><?php endif; ?>
            </div>
        </section>
        <?php
    });
} catch (Throwable $exception) {
    http_response_code(500);
    layout('Erro', function () use ($exception) {
        ?>
        <section class="panel">
            <h1>Erro</h1>
            <p><?= e($exception->getMessage()) ?></p>
        </section>
        <?php
    });
}
