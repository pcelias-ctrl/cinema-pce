<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

$sessionPdo = null;
$sessionConnection = static function () use (&$sessionPdo, $config): PDO {
    if ($sessionPdo instanceof PDO) {
        return $sessionPdo;
    }

    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );
    $sessionPdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $sessionPdo;
};

session_set_save_handler(
    static fn(): bool => true,
    static fn(): bool => true,
    static function (string $id) use ($sessionConnection): string {
        $stmt = $sessionConnection()->prepare('SELECT data FROM app_sessions WHERE id = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$id]);
        $session = $stmt->fetch();
        return $session ? (string) $session['data'] : '';
    },
    static function (string $id, string $data) use ($sessionConnection): bool {
        $expiresAt = date('Y-m-d H:i:s', time() + (int) ini_get('session.gc_maxlifetime'));
        $stmt = $sessionConnection()->prepare('REPLACE INTO app_sessions (id, data, expires_at) VALUES (?, ?, ?)');
        return $stmt->execute([$id, $data, $expiresAt]);
    },
    static function (string $id) use ($sessionConnection): bool {
        return $sessionConnection()->prepare('DELETE FROM app_sessions WHERE id = ?')->execute([$id]);
    },
    static function () use ($sessionConnection): int|false {
        $sessionConnection()->exec('DELETE FROM app_sessions WHERE expires_at <= NOW()');
        return 1;
    }
);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'CinemaPce\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function config_value(string $key, $default = null)
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $route): void
{
    header('Location: index.php?route=' . urlencode($route));
    exit;
}
