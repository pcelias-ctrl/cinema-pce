<?php

declare(strict_types=1);

namespace CinemaPce;

use RuntimeException;

final class InfinitePay
{
    private const API = 'https://api.checkout.infinitepay.io';

    public static function createCheckout(array $settings, array $order, array $customer, array $items): array
    {
        $handle = ltrim(trim((string) ($settings['infinitepay_handle'] ?? '')), '@');
        if ($handle === '') throw new RuntimeException('O handle da InfinitePay não foi configurado.');

        $payload = [
            'handle' => $handle,
            'order_nsu' => (string) $order['id'],
            'redirect_url' => PublicPortal::publicUrl(['action' => 'payment', 'order' => $order['order_code']]),
            'webhook_url' => PublicPortal::publicUrl(['action' => 'infinitepay_webhook', 'token' => self::webhookToken($handle)]),
            'items' => array_map(static fn(array $item): array => [
                'quantity' => (int) $item['quantity'],
                'price' => (int) $item['amount'],
                'description' => (string) $item['description'],
            ], $items),
            'customer' => [
                'name' => $customer['name'],
                'email' => $customer['email'],
                'phone_number' => PublicPortal::normalizeDigits($customer['whatsapp'] ?: $customer['phone']),
            ],
        ];

        $response = self::request('/links', $payload);
        $url = (string) ($response['url'] ?? $response['checkout_url'] ?? '');
        if ($url === '') throw new RuntimeException('A InfinitePay não retornou o endereço do checkout.');
        return $response;
    }

    public static function verifyPayment(array $settings, array $payload): array
    {
        $handle = ltrim(trim((string) ($settings['infinitepay_handle'] ?? '')), '@');
        $request = [
            'handle' => $handle,
            'order_nsu' => (string) ($payload['order_nsu'] ?? ''),
            'transaction_nsu' => (string) ($payload['transaction_nsu'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
        ];
        if ($handle === '' || in_array('', $request, true)) throw new RuntimeException('Notificação InfinitePay incompleta.');
        return self::request('/payment_check', $request);
    }

    public static function webhookToken(string $handle): string
    {
        return hash_hmac('sha256', $handle, (string) config_value('settings_key'));
    }

    private static function request(string $path, array $payload): array
    {
        $curl = curl_init(self::API . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = json_decode((string) $body, true);
        if ($body === false || $status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['error'] ?? $error ?: 'Falha de comunicação com a InfinitePay.';
            throw new RuntimeException((string) $message);
        }
        return $decoded;
    }
}
