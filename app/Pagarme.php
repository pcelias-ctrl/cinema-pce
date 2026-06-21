<?php

declare(strict_types=1);

namespace CinemaPce;

use RuntimeException;

final class Pagarme
{
    private const API = 'https://api.pagar.me/core/v5';
    private const SANDBOX_API = 'https://sdx-api.pagar.me/core/v5';

    public static function createOrder(array $settings, array $order, array $customer, array $items, string $method): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        $phone = PublicPortal::normalizeDigits($customer['whatsapp'] ?: $customer['phone']);
        $area = substr($phone, 0, 2);
        $number = substr($phone, 2);
        $payment = ['payment_method'=>'pix','pix'=>['expires_in'=>max(60, (int)($order['expires_epoch']??strtotime($order['expires_at'])) - time())]];
        $payload = [
            'code' => $order['order_code'],
            'closed' => true,
            'customer' => [
                'name'=>$customer['name'],'email'=>$customer['email'],'type'=>'individual','document'=>$customer['cpf'],
                'phones'=>['mobile_phone'=>['country_code'=>'55','area_code'=>$area,'number'=>$number]],
            ],
            'items' => $items,
            'payments' => [$payment],
        ];
        $response = self::request('POST', '/orders', $secret, $payload, $order['order_code']);
        if (($response['status'] ?? '') === 'failed') {
            $errors = $response['charges'][0]['last_transaction']['gateway_response']['errors'] ?? [];
            $messages = [];
            foreach ($errors as $error) {
                if (is_array($error) && !empty($error['message'])) $messages[] = (string) $error['message'];
                elseif (is_string($error)) $messages[] = $error;
            }
            $message = trim(implode(' ', $messages));
            if (str_contains($message, 'Sem ambiente configurado')) {
                throw new RuntimeException('O Pix ainda não está habilitado na conta Pagar.me. Ative o Pix no painel Pagar.me ou escolha InfinitePay.');
            }
            throw new RuntimeException($message !== '' ? 'Pagamento recusado pelo Pagar.me: ' . $message : 'O Pagar.me recusou a criação do pagamento.');
        }
        return $response;
    }

    public static function createPaymentLink(array $settings, array $order, array $customer, array $items): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        $totalCents = array_reduce($items, static fn(int $total, array $item): int => $total + ((int) $item['amount'] * (int) $item['quantity']), 0);
        $expiresIn = max(300, (int) ($order['expires_epoch'] ?? time() + 600) - time());
        $payload = [
            'is_building' => false,
            'type' => 'order',
            'name' => 'Cinema - pedido ' . $order['order_code'],
            'order_code' => $order['order_code'],
            'expires_in' => $expiresIn,
            'max_paid_sessions' => 1,
            'metadata' => ['local_order_id'=>(string)$order['id'],'order_code'=>$order['order_code']],
            'payment_settings' => [
                'accepted_payment_methods' => ['credit_card'],
                'credit_card_settings' => [
                    'operation_type' => 'auth_and_capture',
                    'installments_setup' => ['amount'=>$totalCents,'interest_rate'=>0,'max_installments'=>1,'interest_type'=>'simple'],
                ],
            ],
            'cart_settings' => ['items'=>array_map(static fn(array $item): array => [
                'name'=>(string)$item['description'],'amount'=>(int)$item['amount'],'default_quantity'=>(int)$item['quantity'],
            ],$items)],
            'customer_settings' => ['customer'=>[
                'name'=>$customer['name'],'email'=>$customer['email'],'type'=>'individual','document_type'=>'CPF','document'=>$customer['cpf'],
            ]],
        ];
        $response = self::request('POST', '/paymentlinks', $secret, $payload, $order['order_code']);
        if (empty($response['id']) || empty($response['url'])) throw new RuntimeException('O Pagar.me não retornou o link de pagamento.');
        return $response;
    }

    public static function getPaymentLink(array $settings, string $paymentLinkId): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        return self::request('GET', '/paymentlinks/' . rawurlencode($paymentLinkId), $secret);
    }

    public static function cancelPaymentLink(array $settings, string $paymentLinkId): void
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        self::request('PATCH', '/paymentlinks/' . rawurlencode($paymentLinkId) . '/cancel', $secret, []);
    }

    public static function getOrder(array $settings, string $providerOrderId): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        return self::request('GET', '/orders/' . rawurlencode($providerOrderId), $secret);
    }

    private static function request(string $method, string $path, string $secret, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $api = str_starts_with($secret, 'sk_test_') ? self::SANDBOX_API : self::API;
        $curl = curl_init($api . $path);
        $headers = ['Accept: application/json','Content-Type: application/json','Authorization: Basic ' . base64_encode($secret . ':')];
        if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>35]);
        if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = json_decode((string) $response, true);
        if ($status === 204) return [];
        if ($response === false || $status < 200 || $status >= 300 || !is_array($decoded)) {
            error_log('Pagar.me HTTP ' . $status . ': ' . substr((string)$response, 0, 2000));
            $messages = [];
            if (is_array($decoded)) {
                if (!empty($decoded['message']) && is_string($decoded['message'])) $messages[] = $decoded['message'];
                $walker = static function ($value) use (&$messages, &$walker): void {
                    if (is_string($value) && trim($value) !== '') $messages[] = trim($value);
                    elseif (is_array($value)) foreach ($value as $item) $walker($item);
                };
                if (isset($decoded['errors'])) $walker($decoded['errors']);
            }
            $message = implode(' ', array_values(array_unique($messages)));
            if ($message === '') $message = $error !== '' ? $error : 'O Pagar.me retornou HTTP ' . $status . ' sem uma mensagem válida.';
            if (stripos((string)$message, 'checkout is disabled') !== false) {
                $message = 'O checkout hospedado está desativado na conta Pagar.me. Ative Links de Pagamento/Checkout no portal Pagar.me.';
            }
            throw new RuntimeException((string) $message);
        }
        return $decoded;
    }
}
