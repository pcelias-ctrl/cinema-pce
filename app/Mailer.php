<?php

declare(strict_types=1);

namespace CinemaPce;

use PDO;
use RuntimeException;

final class Mailer
{
    public static function send(PDO $db, string $to, string $subject, string $html, array $attachments = []): void
    {
        $settings = $db->query('SELECT cinema_name,email,smtp_enabled,smtp_host,smtp_port,smtp_encryption,smtp_auth,smtp_username,smtp_password_encrypted,smtp_from_name,smtp_from_email,smtp_reply_to,smtp_timeout FROM cinema_settings WHERE id=1')->fetch();
        if (!$settings || !(int) $settings['smtp_enabled'] || !$settings['smtp_host']) {
            throw new RuntimeException('O envio de e-mail ainda não está configurado pelo cinema.');
        }

        $host = (string) $settings['smtp_host'];
        $port = (int) ($settings['smtp_port'] ?: 587);
        $encryption = (string) ($settings['smtp_encryption'] ?: 'tls');
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errorNumber, $errorMessage, (int) ($settings['smtp_timeout'] ?: 30));
        if (!$socket) throw new RuntimeException('Não foi possível conectar ao servidor de e-mail.');
        stream_set_timeout($socket, (int) ($settings['smtp_timeout'] ?: 30));

        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO cineshop.online', [250]);
            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Não foi possível ativar a conexão segura de e-mail.');
                }
                self::command($socket, 'EHLO cineshop.online', [250]);
            }
            if ((int) $settings['smtp_auth']) {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode((string) $settings['smtp_username']), [334]);
                self::command($socket, base64_encode(SettingCrypto::decrypt($settings['smtp_password_encrypted'])), [235]);
            }

            $fromEmail = (string) ($settings['smtp_from_email'] ?: $settings['email'] ?: $settings['smtp_username']);
            $fromName = (string) ($settings['smtp_from_name'] ?: $settings['cinema_name']);
            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>',
                'To: <' . $to . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
            ];
            if ($settings['smtp_reply_to']) $headers[] = 'Reply-To: ' . $settings['smtp_reply_to'];
            if ($attachments) {
                $boundary = 'cinesys_' . bin2hex(random_bytes(16));
                $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
                $body = '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
                foreach ($attachments as $attachment) {
                    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($attachment['name'] ?? 'arquivo.pdf')) ?: 'arquivo.pdf';
                    $mime = (string)($attachment['mime'] ?? 'application/octet-stream');
                    $body .= '--' . $boundary . "\r\nContent-Type: " . $mime . '; name="' . $filename . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $filename . "\"\r\n\r\n" . chunk_split(base64_encode((string)($attachment['data'] ?? '')), 76, "\r\n");
                }
                $body .= '--' . $boundary . "--\r\n";
            } else {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $body = chunk_split(base64_encode($html), 76, "\r\n");
            }
            $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n", "\r"], "\n", $body);
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function command($socket, string $command, array $codes): void
    {
        fwrite($socket, $command . "\r\n");
        self::expect($socket, $codes);
    }

    private static function expect($socket, array $codes): void
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) throw new RuntimeException('O servidor de e-mail encerrou a conexão.');
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) throw new RuntimeException('O servidor de e-mail recusou a mensagem (código ' . $code . ').');
    }
}
