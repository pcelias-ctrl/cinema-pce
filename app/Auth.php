<?php

declare(strict_types=1);

namespace CinemaPce;

final class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect_to('login');
        }
        static $validatedUserId = 0;
        $userId = (int) self::user()['id'];
        if ($validatedUserId === $userId) return;
        $stmt = Database::connection()->prepare('SELECT name, email, role, active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['active'] !== 1) {
            self::logout();
            redirect_to('login');
        }
        $_SESSION['user']['name'] = $user['name'];
        $_SESSION['user']['email'] = $user['email'];
        $_SESSION['user']['role'] = $user['role'];
        $validatedUserId = $userId;
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::user()['role'] !== 'administrador') {
            http_response_code(403);
            exit('Acesso restrito ao administrador.');
        }
    }
}
