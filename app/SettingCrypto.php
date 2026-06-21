<?php

declare(strict_types=1);

namespace CinemaPce;

final class SettingCrypto
{
    public static function encrypt(string $value): string
    {
        if ($value === '') return '';
        $key = hash('sha256', (string) config_value('settings_key'), true);
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) return '';
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(?string $value): string
    {
        if (!$value) return '';
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < 29) return '';
        $key = hash('sha256', (string) config_value('settings_key'), true);
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
