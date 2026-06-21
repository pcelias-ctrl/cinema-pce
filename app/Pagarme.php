<?php

declare(strict_types=1);

namespace CinemaPce;

use RuntimeException;

final class Pagarme
{
    private const API = 'https://api.pagar.me/core/v5';

    public static function createOrder(array $settings, array $order, array $customer, array $items, string $method): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        $phone = PublicPortal::normalizeDigits($customer['whatsapp'] ?: $customer['phone']);
        $area = substr($phone, 0, 2);
        $number = substr($phone, 2);
        $totalCents = array_reduce($items, static fn(int $total, array $item): int => $total + ((int) $item['amount'] * (int) $item['quantity']), 0);
        $payment = $method === 'cartao'
            ? ['payment_method'=>'checkout','checkout'=>[
                'customer_editable'=>true,
                'accepted_payment_methods'=>['credit_card'],
                'success_url'=>PublicPortal::publicUrl(['action'=>'payment','order'=>$order['order_code'],'provider_return'=>'1']),
                'expires_in'=>max(5, (int) ceil(((int)($order['expires_epoch'] ?? time() + 600) - time()) / 60)),
                'credit_card'=>[
                    'installments'=>[['number'=>1,'total'=>$totalCents]],
                    'statement_descriptor'=>'CINESYS',
                    'capture'=>true,
                ],
            ]]
            : ['payment_method'=>'pix','pix'=>['expires_in'=>max(60, (int)($order['expires_epoch']??strtotime($order['expires_at'])) - time())]];
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

    public static function checkoutUrl(array $order): string
    {
        return (string) ($order['checkouts'][0]['payment_url'] ?? '');
    }

    public static function getOrder(array $settings, string $providerOrderId): array
    {
        $secret = SettingCrypto::decrypt($settings['pagarme_secret_encrypted'] ?? '');
        if ($secret === '') throw new RuntimeException('Pagar.me não configurado.');
        return self::request('GET', '/orders/' . rawurlencode($providerOrderId), $secret);
    }

    private static function request(string $method, string $path, string $secret, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $curl = curl_init(self::API . $path);
        $headers = ['Accept: application/json','Content-Type: application/json','Authorization: Basic ' . base64_encode($secret . ':')];
        if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>35]);
        if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = json_decode((string) $response, true);
        if ($response === false || $status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = $decoded['message'] ?? $decoded['errors'][0]['message'] ?? $error ?: 'Falha de comunicação com o pagamento.';
            throw new RuntimeException((string) $message);
        }
        return $decoded;
    }
}
