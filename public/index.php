<?php

declare(strict_types=1);

use CinemaPce\Database;
use CinemaPce\InfinitePay;
use CinemaPce\Mailer;
use CinemaPce\Pagarme;
use CinemaPce\PublicPdf;
use CinemaPce\PublicPortal;
use CinemaPce\SettingCrypto;

require __DIR__ . '/../app/bootstrap.php';

$legacyAdminRoute = trim($_GET['route'] ?? '');
if ($legacyAdminRoute !== '') {
    header('Location: /admin/index.php?' . http_build_query($_GET));
    exit;
}

$db = Database::connection();
PublicPortal::ensureSchema($db);
$action = trim($_GET['action'] ?? 'catalog');
$cinema = PublicPortal::cinema($db);
$portalSettings = PublicPortal::settings($db);
$customer = PublicPortal::customer($db);
$message = '';
$error = '';
if(!empty($_SESSION['portal_error'])){$error=(string)$_SESSION['portal_error'];unset($_SESSION['portal_error']);}

function confirm_infinitepay_order(PDO $db, array $settings, array $order, array $payload): bool
{
    $verified=InfinitePay::verifyPayment($settings,$payload);
    $expectedAmount=(int)round((float)$order['total_amount']*100);
    $chargedAmount=(int)($verified['amount']??0);$paidAmount=(int)($verified['paid_amount']??0);
    if(empty($verified['paid'])||$chargedAmount!==$expectedAmount||$paidAmount<$expectedAmount)return false;
    $method=($verified['capture_method']??$payload['capture_method']??'pix')==='credit_card'?'cartao':'pix';
    $transaction=(string)($payload['transaction_nsu']??$payload['transaction_id']??'');
    $slug=(string)($payload['slug']??$payload['invoice_slug']??'');
    $db->prepare('UPDATE public_orders SET payment_method=?,provider_reference=?,provider_transaction_nsu=?,provider_payload=? WHERE id=?')->execute([$method,$slug,$transaction,json_encode($verified,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);
    PublicPortal::finalizePaidOrder($db,(int)$order['id'],$verified);
    return true;
}

function confirm_pagarme_order(PDO $db, array $settings, array $order, string $providerOrderId): bool
{
    $remote=Pagarme::getOrder($settings,$providerOrderId);
    $expectedAmount=(int)round((float)$order['total_amount']*100);
    if(($remote['status']??'')!=='paid'||(int)($remote['amount']??0)!==$expectedAmount)return false;
    $charge=$remote['charges'][0]??[];
    $db->prepare("UPDATE public_orders SET payment_method='cartao',pagarme_order_id=?,pagarme_charge_id=?,provider_payload=? WHERE id=?")->execute([$providerOrderId,$charge['id']??null,json_encode($remote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);
    PublicPortal::finalizePaidOrder($db,(int)$order['id'],$remote);
    return true;
}

if ($action === 'logo') {
    $logo = $db->query('SELECT logo_mime,logo_data FROM cinema_settings WHERE id=1 AND logo_data IS NOT NULL')->fetch();
    if (!$logo) { http_response_code(404); exit; }
    header('Content-Type: ' . $logo['logo_mime']);
    header('Cache-Control: public, max-age=3600');
    echo $logo['logo_data'];
    exit;
}

if ($action === 'cover') {
    $stmt = $db->prepare('SELECT cover_mime,cover_data FROM movies WHERE id=? AND cover_data IS NOT NULL');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $cover = $stmt->fetch();
    if (!$cover) { http_response_code(404); exit; }
    header('Content-Type: ' . $cover['cover_mime']);
    header('Cache-Control: public, max-age=86400');
    echo $cover['cover_data'];
    exit;
}

if ($action === 'product_image') {
    $stmt = $db->prepare('SELECT image_mime,image_data FROM products WHERE id=? AND image_data IS NOT NULL');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $image = $stmt->fetch();
    if (!$image) { http_response_code(404); exit; }
    header('Content-Type: ' . $image['image_mime']);
    header('Cache-Control: public, max-age=86400');
    echo $image['image_data'];
    exit;
}

if ($action === 'logout') {
    unset($_SESSION['public_customer_id'], $_SESSION['public_pending_email']);
    header('Location: /');
    exit;
}

if ($action === 'google') {
    if (!$portalSettings['google_client_id'] || !$portalSettings['google_client_secret_encrypted']) {
        $error = 'O acesso com Google ainda não foi configurado.';
        $action = 'access';
    } else {
        $state = bin2hex(random_bytes(24));
        $_SESSION['google_oauth_state'] = $state;
        $params = ['client_id'=>$portalSettings['google_client_id'],'redirect_uri'=>PublicPortal::publicUrl(['action'=>'google_callback']),'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'access_type'=>'online','prompt'=>'select_account'];
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
        exit;
    }
}

if ($action === 'google_callback') {
    try {
        if (!hash_equals($_SESSION['google_oauth_state'] ?? '', (string)($_GET['state']??''))) throw new RuntimeException('A validação do Google expirou.');
        unset($_SESSION['google_oauth_state']);
        $payload = ['code'=>(string)($_GET['code']??''),'client_id'=>$portalSettings['google_client_id'],'client_secret'=>SettingCrypto::decrypt($portalSettings['google_client_secret_encrypted']),'redirect_uri'=>PublicPortal::publicUrl(['action'=>'google_callback']),'grant_type'=>'authorization_code'];
        $curl=curl_init('https://oauth2.googleapis.com/token');curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);$response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $tokens=json_decode((string)$response,true);if($status<200||$status>=300||empty($tokens['id_token']))throw new RuntimeException('O Google não confirmou o acesso.');
        $verifyCurl=curl_init('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($tokens['id_token']));curl_setopt_array($verifyCurl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);$profile=json_decode((string)curl_exec($verifyCurl),true);$verifyStatus=(int)curl_getinfo($verifyCurl,CURLINFO_HTTP_CODE);curl_close($verifyCurl);
        if($verifyStatus!==200||($profile['aud']??'')!==$portalSettings['google_client_id']||($profile['email_verified']??'')!=='true')throw new RuntimeException('Não foi possível validar o e-mail Google.');
        $stmt=$db->prepare('SELECT id FROM public_customers WHERE google_sub=? OR email=? LIMIT 1');$stmt->execute([(string)$profile['sub'],strtolower((string)$profile['email'])]);$existing=$stmt->fetch();
        if($existing){$db->prepare('UPDATE public_customers SET google_sub=?,email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([(string)$profile['sub'],(int)$existing['id']]);$_SESSION['public_customer_id']=(int)$existing['id'];session_regenerate_id(true);header('Location: /?action=account');exit;}
        $_SESSION['google_pending']=['sub'=>(string)$profile['sub'],'email'=>strtolower((string)$profile['email']),'name'=>(string)($profile['name']??'')];
        header('Location: /?action=google_complete');exit;
    }catch(Throwable $exception){$error=$exception->getMessage();$action='access';}
}

if ($action === 'pagarme_webhook') {
    $expectedUser=(string)($portalSettings['pagarme_webhook_username']??'');
    $expectedPassword=SettingCrypto::decrypt($portalSettings['pagarme_webhook_password_encrypted']?:($portalSettings['pagarme_webhook_secret_encrypted']??''));
    $providedUser=(string)($_SERVER['PHP_AUTH_USER']??'');$providedPassword=(string)($_SERVER['PHP_AUTH_PW']??'');
    if($providedUser===''&&preg_match('/^Basic\s+(.+)$/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$match)){$credentials=base64_decode($match[1],true);if($credentials!==false&&str_contains($credentials,':'))[$providedUser,$providedPassword]=explode(':',$credentials,2);}
    $valid=$expectedUser!==''&&$expectedPassword!==''&&hash_equals($expectedUser,$providedUser)&&hash_equals($expectedPassword,$providedPassword);
    if(!$valid){header('WWW-Authenticate: Basic realm="CineSys Pagar.me"');http_response_code(401);exit;}
    $payload=json_decode((string)file_get_contents('php://input'),true)?:[];$data=$payload['data']??[];$eventType=(string)($payload['type']??'');
    $providerId=(string)($data['id']??'');$providerOrderId=(string)($data['order']['id']??(str_starts_with($eventType,'order.')?$providerId:''));
    $metadata=$data['metadata']??($data['order']['metadata']??[]);$localId=(int)($metadata['local_order_id']??0);$orderCode=(string)($data['code']??$data['order_code']??$metadata['order_code']??'');
    $paymentLinkId=(string)($data['payment_link']['id']??$data['payment_link_id']??$metadata['payment_link_id']??'');
    if($localId>0){$stmt=$db->prepare('SELECT * FROM public_orders WHERE id=? LIMIT 1');$stmt->execute([$localId]);}
    elseif($orderCode!==''){$stmt=$db->prepare('SELECT * FROM public_orders WHERE order_code=? LIMIT 1');$stmt->execute([$orderCode]);}
    else{$stmt=$db->prepare('SELECT * FROM public_orders WHERE pagarme_order_id=? OR pagarme_charge_id=? OR provider_reference=? LIMIT 1');$stmt->execute([$providerOrderId?:$providerId,$providerId,$paymentLinkId]);}
    $local=$stmt->fetch();
    if($local&&$providerOrderId!==''){try{confirm_pagarme_order($db,$portalSettings,$local,$providerOrderId);}catch(Throwable $exception){error_log('Webhook Pagar.me: '.$exception->getMessage());http_response_code(500);exit;}}
    http_response_code(204);exit;
}

if ($action === 'infinitepay_webhook') {
    $handle=(string)($portalSettings['infinitepay_handle']??'');
    $valid=$handle!==''&&hash_equals(InfinitePay::webhookToken($handle),(string)($_GET['token']??''));
    if(!$valid){http_response_code(401);exit;}
    $payload=json_decode((string)file_get_contents('php://input'),true)?:[];$orderId=(int)($payload['order_nsu']??0);
    $stmt=$db->prepare("SELECT * FROM public_orders WHERE id=? AND payment_gateway='infinitepay' LIMIT 1");$stmt->execute([$orderId]);$local=$stmt->fetch();
    if(!$local){http_response_code(204);exit;}
    try{
        confirm_infinitepay_order($db,$portalSettings,$local,$payload);
    }catch(Throwable $exception){error_log('Webhook InfinitePay: '.$exception->getMessage());http_response_code(500);exit;}
    http_response_code(204);exit;
}

if ($action === 'account' && !$customer) {
    header('Location: /?action=access');
    exit;
}
if (in_array($action,['payment','tickets_pdf','products_pdf'],true) && !$customer) {
    $_SESSION['public_return'] = '/?action=' . urlencode($action) . '&order=' . urlencode($_GET['order'] ?? '');
    header('Location: /?action=access');
    exit;
}

if ($action === 'cancel_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$customer) { header('Location: /?action=access'); exit; }
    verify_csrf();
    $orderCode = trim($_POST['order_code'] ?? '');
    $stmt = $db->prepare("SELECT * FROM public_orders WHERE order_code=? AND customer_id=? AND status IN ('rascunho','aguardando_pagamento') LIMIT 1");
    $stmt->execute([$orderCode, (int) $customer['id']]);
    $pendingOrder = $stmt->fetch();
    if ($pendingOrder) {
        try {
            if ($pendingOrder['payment_gateway'] === 'pagarme' && $pendingOrder['pagarme_order_id']) {
                $remote = Pagarme::getOrder($portalSettings, (string) $pendingOrder['pagarme_order_id']);
                if (($remote['status'] ?? '') === 'paid') {
                    PublicPortal::finalizePaidOrder($db, (int) $pendingOrder['id'], $remote);
                    header('Location: /?action=payment&order=' . urlencode($orderCode));
                    exit;
                }
            }
            if ($pendingOrder['payment_gateway'] === 'pagarme' && $pendingOrder['payment_method'] === 'cartao' && $pendingOrder['provider_reference']) {
                $paymentLink=Pagarme::getPaymentLink($portalSettings,(string)$pendingOrder['provider_reference']);
                if((int)($paymentLink['total_paid_sessions']??0)>0)throw new RuntimeException('Pagamento em confirmação.');
                Pagarme::cancelPaymentLink($portalSettings,(string)$pendingOrder['provider_reference']);
            }
            PublicPortal::cancelPendingOrder($db, (int) $pendingOrder['id']);
        } catch (Throwable $exception) {
            $_SESSION['portal_error'] = 'Não foi possível cancelar agora. Aguarde a confirmação do pagamento.';
            header('Location: /?action=payment&order=' . urlencode($orderCode));
            exit;
        }
    }
    header('Location: /');
    exit;
}

if ($action === 'tickets_pdf' || $action === 'products_pdf') {
    try {
        $orderCode=trim($_GET['order']??'');
        $pdf=$action==='tickets_pdf'?PublicPdf::tickets($db,$orderCode,(int)$customer['id']):PublicPdf::products($db,$orderCode,(int)$customer['id']);
        $name=($action==='tickets_pdf'?'ingressos-':'produtos-').preg_replace('/[^A-Za-z0-9_-]/','',$orderCode).'.pdf';
        header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
    }catch(Throwable $exception){$error=$exception->getMessage();$action='account';}
}

if ($action === 'token') {
    if (PublicPortal::consumeLoginToken($db, trim($_GET['token'] ?? ''))) {
        $returnTo = $_SESSION['public_return'] ?? '/?action=account';
        unset($_SESSION['public_return']);
        header('Location: ' . $returnTo);
        exit;
    }
    $error = 'Este link é inválido, já foi utilizado ou expirou. Solicite um novo acesso.';
    $action = 'access';
}

if ($action === 'seats' && !$customer) {
    $showtimeId = (int) ($_GET['showtime_id'] ?? 0);
    $_SESSION['public_return'] = '/?action=seats&showtime_id=' . $showtimeId;
    header('Location: /?action=access');
    exit;
}
if ($action === 'checkout' && !$customer) {
    $_SESSION['public_return'] = '/?action=checkout&order=' . urlencode($_GET['order'] ?? '');
    header('Location: /?action=access');
    exit;
}

if ($action === 'hold' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$customer) { header('Location: /?action=access'); exit; }
    verify_csrf();
    $showtimeId = (int) ($_POST['showtime_id'] ?? 0);
    $seatIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['seat_ids'] ?? [])))));
    $seatTypes = is_array($_POST['seat_types'] ?? null) ? $_POST['seat_types'] : [];
    if (!$seatIds) { header('Location: /?action=seats&showtime_id=' . $showtimeId . '&error=no_seats'); exit; }
    $db->beginTransaction();
    try {
        $showtimeStmt = $db->prepare("SELECT showtimes.*,rooms.id room_id FROM showtimes INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE showtimes.id=? AND showtimes.status='programada' AND showtimes.starts_at>NOW() FOR UPDATE");
        $showtimeStmt->execute([$showtimeId]);
        $showtime = $showtimeStmt->fetch();
        if (!$showtime) throw new RuntimeException('Sessão indisponível.');
        $db->prepare("DELETE public_seat_holds FROM public_seat_holds INNER JOIN public_orders ON public_orders.id=public_seat_holds.order_id WHERE public_seat_holds.expires_at<=NOW() AND public_orders.status IN ('rascunho','aguardando_pagamento','expirado','cancelado')")->execute();
        $placeholders = implode(',', array_fill(0, count($seatIds), '?'));
        $seatStmt = $db->prepare("SELECT room_seats.id FROM room_seats LEFT JOIN tickets ON tickets.room_seat_id=room_seats.id AND tickets.showtime_id=? AND tickets.status IN ('reservado','vendido') LEFT JOIN public_seat_holds ON public_seat_holds.room_seat_id=room_seats.id AND public_seat_holds.showtime_id=? AND public_seat_holds.expires_at>NOW() WHERE room_seats.room_id=? AND room_seats.id IN ($placeholders) AND tickets.id IS NULL AND public_seat_holds.id IS NULL FOR UPDATE");
        $seatStmt->execute(array_merge([$showtimeId,$showtimeId,(int)$showtime['room_id']],$seatIds));
        $available = array_map('intval', array_column($seatStmt->fetchAll(), 'id'));
        if (count($available) !== count($seatIds)) throw new RuntimeException('Uma das poltronas acabou de ser escolhida por outro cliente.');
        $holdMinutes=max(5,min(30,(int)$portalSettings['hold_minutes']));
        $orderCode = PublicPortal::orderCode();
        $db->prepare("INSERT INTO public_orders(order_code,customer_id,showtime_id,payment_method,status,expires_at) VALUES(?,?,?,'pix','rascunho',DATE_ADD(NOW(), INTERVAL {$holdMinutes} MINUTE))")->execute([$orderCode,(int)$customer['id'],$showtimeId]);
        $orderId = (int)$db->lastInsertId();
        $expiryStmt=$db->prepare('SELECT expires_at FROM public_orders WHERE id=?');$expiryStmt->execute([$orderId]);$expiresAt=(string)$expiryStmt->fetchColumn();
        $insertHold = $db->prepare('INSERT INTO public_seat_holds(order_id,showtime_id,room_seat_id,ticket_type,unit_price,expires_at) VALUES(?,?,?,?,?,?)');
        $ticketsTotal = 0.0;
        foreach($seatIds as $seatId){$type=($seatTypes[$seatId]??'inteira')==='meia'?'meia':'inteira';$price=$type==='meia'?(float)($showtime['half_price']??$showtime['price']/2):(float)$showtime['price'];$insertHold->execute([$orderId,$showtimeId,$seatId,$type,$price,$expiresAt]);$ticketsTotal+=$price;}
        $db->prepare('UPDATE public_orders SET tickets_total=?,total_amount=? WHERE id=?')->execute([$ticketsTotal,$ticketsTotal,$orderId]);
        $db->commit();
        header('Location: /?action=checkout&order=' . $orderCode);
        exit;
    } catch(Throwable $exception) {
        if($db->inTransaction())$db->rollBack();
        header('Location: /?action=seats&showtime_id=' . $showtimeId . '&error=conflict');
        exit;
    }
}

if ($action === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!$customer){header('Location: /?action=access');exit;}verify_csrf();
    $orderCode=trim($_POST['order_code']??'');$method=($_POST['payment_method']??'pix')==='cartao'?'cartao':'pix';
    try{
        if(!(int)$portalSettings['sales_enabled'])throw new RuntimeException('Os pagamentos online ainda não estão ativos.');
        $gateway=$portalSettings['payment_gateway']==='infinitepay'?'infinitepay':'pagarme';
        if($gateway==='infinitepay'&&trim((string)$portalSettings['infinitepay_handle'])==='')throw new RuntimeException('A InfinitePay ainda não foi configurada.');
        if($gateway==='pagarme'&&(!$portalSettings['pagarme_public_key']||!$portalSettings['pagarme_secret_encrypted']))throw new RuntimeException('O Pagar.me ainda não foi configurado.');
        $db->beginTransaction();
        $stmt=$db->prepare("SELECT public_orders.*,UNIX_TIMESTAMP(expires_at) expires_epoch FROM public_orders WHERE order_code=? AND customer_id=? AND status IN ('rascunho','aguardando_pagamento') AND expires_at>NOW() FOR UPDATE");$stmt->execute([$orderCode,(int)$customer['id']]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('A reserva expirou.');
        $db->prepare('DELETE FROM public_order_products WHERE order_id=? AND status=\'aguardando_pagamento\'')->execute([(int)$order['id']]);
        $quantities=[];foreach((array)($_POST['product_qty']??[]) as $productId=>$qty){$qty=max(0,min(10,(int)$qty));if($qty)$quantities[(int)$productId]=$qty;}
        $productTotal=0.0;$productItems=[];
        if($quantities){$ids=array_keys($quantities);$ph=implode(',',array_fill(0,count($ids),'?'));$productsStmt=$db->prepare("SELECT id,name,price,stock_quantity FROM products WHERE active=1 AND id IN ($ph) FOR UPDATE");$productsStmt->execute($ids);$rows=[];foreach($productsStmt->fetchAll() as $row)$rows[(int)$row['id']]=$row;if(count($rows)!==count($ids))throw new RuntimeException('Um produto não está mais disponível.');$insert=$db->prepare('INSERT INTO public_order_products(order_id,product_id,unit_price) VALUES(?,?,?)');foreach($quantities as $id=>$qty){$product=$rows[$id];if($product['stock_quantity']!==null&&(int)$product['stock_quantity']<$qty)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'.');for($i=0;$i<$qty;$i++)$insert->execute([(int)$order['id'],$id,$product['price']]);$productTotal+=(float)$product['price']*$qty;$productItems[]=['amount'=>(int)round((float)$product['price']*100),'description'=>$product['name'],'quantity'=>$qty,'code'=>'PROD-'.$id];}}
        $paymentMinutes=max(5,min(30,(int)$portalSettings['hold_minutes']));
        $total=(float)$order['tickets_total']+$productTotal;$db->prepare("UPDATE public_orders SET payment_method=?,payment_gateway=?,products_total=?,total_amount=?,status='aguardando_pagamento',expires_at=DATE_ADD(NOW(),INTERVAL {$paymentMinutes} MINUTE) WHERE id=?")->execute([$method,$gateway,$productTotal,$total,(int)$order['id']]);
        $db->prepare("UPDATE public_seat_holds SET expires_at=DATE_ADD(NOW(),INTERVAL {$paymentMinutes} MINUTE) WHERE order_id=?")->execute([(int)$order['id']]);
        $holds=$db->prepare('SELECT public_seat_holds.*,room_seats.seat_code FROM public_seat_holds INNER JOIN room_seats ON room_seats.id=public_seat_holds.room_seat_id WHERE order_id=?');$holds->execute([(int)$order['id']]);$ticketItems=[];foreach($holds->fetchAll() as $hold)$ticketItems[]=['amount'=>(int)round((float)$hold['unit_price']*100),'description'=>'Ingresso '.$hold['seat_code'].' '.ucfirst($hold['ticket_type']),'quantity'=>1,'code'=>'ING-'.$hold['room_seat_id']];
        $order['total_amount']=$total;$order['products_total']=$productTotal;$order['payment_method']=$method;$order['expires_epoch']=time()+($paymentMinutes*60);$db->commit();
        $items=array_merge($ticketItems,$productItems);
        if($gateway==='infinitepay'){$provider=InfinitePay::createCheckout($portalSettings,$order,$customer,$items);$checkoutUrl=(string)($provider['url']??$provider['checkout_url']??'');$db->prepare('UPDATE public_orders SET provider_reference=?,provider_checkout_url=?,provider_payload=? WHERE id=?')->execute([(string)($provider['slug']??''),$checkoutUrl,json_encode($provider,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);header('Location: '.$checkoutUrl);exit;}
        if($method==='cartao'){$provider=Pagarme::createPaymentLink($portalSettings,$order,$customer,$items);$checkoutUrl=(string)$provider['url'];$db->prepare('UPDATE public_orders SET provider_reference=?,provider_checkout_url=?,provider_payload=? WHERE id=?')->execute([(string)$provider['id'],$checkoutUrl,json_encode($provider,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);header('Location: '.$checkoutUrl);exit;}
        $provider=Pagarme::createOrder($portalSettings,$order,$customer,$items,'pix');$charge=$provider['charges'][0]??[];$transaction=$charge['last_transaction']??[];$checkoutUrl='';
        $db->prepare('UPDATE public_orders SET pagarme_order_id=?,pagarme_charge_id=?,pix_qr_code=?,pix_qr_code_url=?,provider_reference=?,provider_checkout_url=?,provider_payload=? WHERE id=?')->execute([$provider['id']??null,$charge['id']??null,$transaction['qr_code']??null,$transaction['qr_code_url']??null,$provider['id']??null,$checkoutUrl?:null,json_encode($provider,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);
        if(($provider['status']??'')==='paid')PublicPortal::finalizePaidOrder($db,(int)$order['id'],$provider);
        header('Location: /?action=payment&order='.$orderCode);exit;
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();$_SESSION['portal_error']=$exception->getMessage();header('Location: /?action=checkout&order='.urlencode($orderCode));exit;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($action === 'verify') {
        $email = strtolower(trim((string) ($_SESSION['public_pending_email'] ?? $_POST['email'] ?? '')));
        $code = PublicPortal::normalizeDigits($_POST['code'] ?? '');
        if (PublicPortal::consumeLoginCode($db, $email, $code)) {
            $returnTo = $_SESSION['public_return'] ?? '/?action=account';
            unset($_SESSION['public_return']);
            header('Location: ' . $returnTo);
            exit;
        }
        $error = 'Código inválido, expirado ou com muitas tentativas. Solicite um novo código.';
        $action = 'verify';
    } elseif ($action === 'google_complete') {
        try {
            $pending=$_SESSION['google_pending']??null;if(!$pending)throw new RuntimeException('O acesso Google expirou.');
            $cpf=PublicPortal::normalizeDigits($_POST['cpf']??'');$whatsapp=PublicPortal::normalizeDigits($_POST['whatsapp']??'');$phone=PublicPortal::normalizeDigits($_POST['phone']??'');$address=trim($_POST['address']??'')?:null;
            if(!PublicPortal::validCpf($cpf)||strlen($whatsapp)<10||strlen($phone)<10)throw new RuntimeException('Informe CPF, WhatsApp e telefone válidos.');
            if(empty($_POST['privacy_accept']))throw new RuntimeException('É necessário aceitar a Política de Privacidade.');
            $stmt=$db->prepare('INSERT INTO public_customers(name,cpf,email,whatsapp,phone,address,google_sub,email_verified_at,privacy_accepted_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$pending['name'],$cpf,$pending['email'],$whatsapp,$phone,$address,$pending['sub']]);
            $_SESSION['public_customer_id']=(int)$db->lastInsertId();unset($_SESSION['google_pending']);session_regenerate_id(true);header('Location: /?action=account');exit;
        }catch(PDOException $exception){$error=(string)$exception->getCode()==='23000'?'Este CPF já está vinculado a outra conta.':$exception->getMessage();}catch(Throwable $exception){$error=$exception->getMessage();}
    } elseif ($action === 'register') {
        try {
            $name = trim($_POST['name'] ?? '');
            $cpf = PublicPortal::normalizeDigits($_POST['cpf'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $whatsapp = PublicPortal::normalizeDigits($_POST['whatsapp'] ?? '');
            $phone = PublicPortal::normalizeDigits($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '') ?: null;
            if ($name === '' || !PublicPortal::validCpf($cpf) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($whatsapp) < 10 || strlen($phone) < 10) {
                throw new RuntimeException('Preencha nome, CPF válido, e-mail, WhatsApp e telefone.');
            }
            if (empty($_POST['privacy_accept'])) throw new RuntimeException('É necessário aceitar a Política de Privacidade para criar a conta.');
            $stmt = $db->prepare('INSERT INTO public_customers(name,cpf,email,whatsapp,phone,address,privacy_accepted_at) VALUES(?,?,?,?,?,?,NOW())');
            $stmt->execute([$name, $cpf, $email, $whatsapp, $phone, $address]);
            $customerId = (int) $db->lastInsertId();
            $code = PublicPortal::createLoginCode($db, $customerId);
            Mailer::send($db, $email, 'Código de acesso - ' . $cinema['cinema_name'], '<h2>Olá, ' . e($name) . '</h2><p>Seu código de confirmação é:</p><p style="font-size:32px;font-weight:bold;letter-spacing:6px">' . e($code) . '</p><p>Digite este código na loja. Ele expira em 10 minutos e pode ser usado uma única vez.</p>');
            $_SESSION['public_pending_email'] = $email;
            $message = 'Cadastro realizado. Enviamos um código de 6 dígitos para seu e-mail.';
            $action = 'verify';
        } catch (PDOException $exception) {
            $error = (string) $exception->getCode() === '23000' ? 'Já existe uma conta com este CPF ou e-mail. Use “Receber link de acesso”.' : 'Não foi possível concluir o cadastro.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'access') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido.';
        } else {
            $stmt = $db->prepare('SELECT id,name,email FROM public_customers WHERE email=? AND active=1');
            $stmt->execute([$email]);
            $account = $stmt->fetch();
            if ($account) {
                try {
                    $code = PublicPortal::createLoginCode($db, (int) $account['id']);
                    Mailer::send($db, $account['email'], 'Código de acesso - ' . $cinema['cinema_name'], '<h2>Olá, ' . e($account['name']) . '</h2><p>Seu código de acesso é:</p><p style="font-size:32px;font-weight:bold;letter-spacing:6px">' . e($code) . '</p><p>Digite este código na loja. Ele expira em 10 minutos e pode ser usado uma única vez.</p>');
                    $_SESSION['public_pending_email'] = $account['email'];
                    $message = 'Enviamos um código de 6 dígitos para seu e-mail.';
                    $action = 'verify';
                } catch (Throwable $exception) {
                    error_log('Falha no login por e-mail: ' . $exception->getMessage());
                    $error = 'O envio de e-mail está temporariamente indisponível. Tente novamente em alguns minutos.';
                }
            }
            if ($error === '' && !$account) $message = 'Se o e-mail estiver cadastrado, você receberá um código de acesso.';
        }
    }
}

$timezone = new DateTimeZone('America/Sao_Paulo');
$today = new DateTimeImmutable('today', $timezone);
$selectedDate = $_GET['date'] ?? $today->format('Y-m-d');
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate, $timezone);
if (!$parsedDate) { $parsedDate = $today; $selectedDate = $today->format('Y-m-d'); }
$dates = [];
for ($i = 0; $i < 7; $i++) $dates[] = $today->modify('+' . $i . ' days');

$movies = [];
$seatContext = null;
$orderContext = null;
$paymentContext = null;
$accountOrders = [];
$products = [];
if ($action === 'catalog') {
    $stmt = $db->prepare("SELECT showtimes.id,showtimes.starts_at,showtimes.audio_type,showtimes.is_3d,showtimes.price,showtimes.half_price,
        movies.id movie_id,movies.title,movies.genre,movies.duration_minutes,movies.synopsis,movies.cover_data IS NOT NULL has_cover,
        rooms.name room_name,
        (SELECT COUNT(*) FROM room_seats WHERE room_id=rooms.id) capacity,
        (SELECT COUNT(*) FROM tickets WHERE showtime_id=showtimes.id AND status IN ('reservado','vendido')) sold,
        (SELECT COUNT(*) FROM public_seat_holds WHERE showtime_id=showtimes.id AND expires_at>NOW()) held
        FROM showtimes INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id
        WHERE showtimes.status='programada' AND movies.active=1 AND rooms.active=1 AND DATE(showtimes.starts_at)=?
        AND showtimes.starts_at>NOW() ORDER BY movies.title,showtimes.starts_at");
    $stmt->execute([$selectedDate]);
    foreach ($stmt->fetchAll() as $session) {
        $movieId = (int) $session['movie_id'];
        if (!isset($movies[$movieId])) $movies[$movieId] = ['info' => $session, 'sessions' => []];
        $movies[$movieId]['sessions'][] = $session;
    }
} elseif ($action === 'seats') {
    $stmt = $db->prepare("SELECT showtimes.*,movies.id movie_id,movies.title,movies.cover_data IS NOT NULL has_cover,rooms.id room_id,rooms.name room_name,rooms.screen_config FROM showtimes INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE showtimes.id=? AND showtimes.status='programada' AND showtimes.starts_at>NOW()");
    $stmt->execute([(int)($_GET['showtime_id']??0)]);
    $session = $stmt->fetch();
    if ($session) {
        $seatsStmt = $db->prepare("SELECT room_seats.*,tickets.id sold_ticket_id,public_seat_holds.id held_id FROM room_seats LEFT JOIN tickets ON tickets.room_seat_id=room_seats.id AND tickets.showtime_id=? AND tickets.status IN ('reservado','vendido') LEFT JOIN public_seat_holds ON public_seat_holds.room_seat_id=room_seats.id AND public_seat_holds.showtime_id=? AND public_seat_holds.expires_at>NOW() WHERE room_seats.room_id=? ORDER BY room_seats.row_label,room_seats.seat_number");
        $seatsStmt->execute([(int)$session['id'],(int)$session['id'],(int)$session['room_id']]);
        $seatContext=['session'=>$session,'seats'=>$seatsStmt->fetchAll(),'screen'=>json_decode($session['screen_config']?:'{}',true)?:['x'=>270,'y'=>28,'w'=>500,'h'=>34]];
    } else {
        $error='Sessão indisponível ou já iniciada.';
    }
} elseif ($action === 'checkout' && $customer) {
    $stmt=$db->prepare("SELECT public_orders.*,UNIX_TIMESTAMP(public_orders.expires_at) expires_epoch,showtimes.starts_at,showtimes.audio_type,showtimes.is_3d,movies.title,rooms.name room_name FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE public_orders.order_code=? AND public_orders.customer_id=? AND public_orders.status IN ('rascunho','aguardando_pagamento') AND public_orders.expires_at>NOW()");
    $stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);
    $order=$stmt->fetch();
    if($order){$holds=$db->prepare('SELECT public_seat_holds.*,room_seats.seat_code FROM public_seat_holds INNER JOIN room_seats ON room_seats.id=public_seat_holds.room_seat_id WHERE order_id=? ORDER BY room_seats.row_label,room_seats.seat_number');$holds->execute([(int)$order['id']]);$order['holds']=$holds->fetchAll();$orderContext=$order;$products=$db->query('SELECT products.id,products.name,products.price,products.image_data IS NOT NULL has_image,product_categories.name category_name FROM products INNER JOIN product_categories ON product_categories.id=products.category_id WHERE products.active=1 AND product_categories.active=1 AND (products.stock_quantity IS NULL OR products.stock_quantity>0) ORDER BY product_categories.sort_order,products.name')->fetchAll();}else{$error='Esta reserva expirou ou não está mais disponível.';}
} elseif ($action === 'payment' && $customer) {
    $stmt=$db->prepare("SELECT public_orders.*,showtimes.starts_at,movies.title,rooms.name room_name FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE public_orders.order_code=? AND customer_id=?");$stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);$paymentContext=$stmt->fetch()?:null;
    if($paymentContext&&$paymentContext['status']==='aguardando_pagamento'&&$paymentContext['payment_gateway']==='infinitepay'&&(int)($_GET['order_nsu']??0)===(int)$paymentContext['id']&&!empty($_GET['transaction_nsu'])&&!empty($_GET['slug'])){try{confirm_infinitepay_order($db,$portalSettings,$paymentContext,$_GET);$stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);$paymentContext=$stmt->fetch()?:null;}catch(Throwable $exception){error_log('Retorno InfinitePay: '.$exception->getMessage());}}
    if($paymentContext&&$paymentContext['status']==='aguardando_pagamento'&&$paymentContext['pagarme_order_id']){try{$remote=Pagarme::getOrder($portalSettings,$paymentContext['pagarme_order_id']);if(($remote['status']??'')==='paid')PublicPortal::finalizePaidOrder($db,(int)$paymentContext['id'],$remote);$stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);$paymentContext=$stmt->fetch()?:null;}catch(Throwable $exception){error_log('Consulta pagamento: '.$exception->getMessage());}}
} elseif ($action === 'account' && $customer) {
    $stmt=$db->prepare("SELECT public_orders.*,showtimes.starts_at,movies.title,rooms.name room_name,(SELECT COUNT(*) FROM tickets WHERE sale_code=public_orders.order_code AND status='vendido') ticket_count,(SELECT COUNT(*) FROM public_order_products WHERE order_id=public_orders.id AND status IN ('pendente','entregue')) product_count FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE customer_id=? AND public_orders.status NOT IN ('cancelado','expirado') ORDER BY created_at DESC");$stmt->execute([(int)$customer['id']]);$accountOrders=$stmt->fetchAll();
}

function public_layout(string $title, array $cinema, ?array $customer, string $action, string $message, string $error, callable $content): void
{
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#17120f"><title><?= e($title) ?> - <?= e($cinema['cinema_name']) ?></title><link rel="stylesheet" href="/assets/css/portal.css"></head>
    <body><header class="portal-header"><a class="portal-brand" href="/"><?php if($cinema['has_logo']):?><img src="/?action=logo" alt=""><?php endif;?><strong><?=e($cinema['cinema_name'])?></strong></a><nav><a href="/">Sessões</a><?php if($customer):?><a href="/?action=account">Meus ingressos</a><a href="/?action=logout">Sair</a><?php else:?><a href="/?action=access">Entrar</a><a class="primary-link" href="/?action=register">Criar conta</a><?php endif;?></nav></header>
    <main class="portal-main"><?php if($message):?><p class="portal-notice success"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="portal-notice error"><?=e($error)?></p><?php endif;?><?php $content();?></main>
    <footer class="portal-footer"><div><strong><?=e($cinema['cinema_name'])?></strong><span><?=e($cinema['address'])?></span></div><nav><a href="/?action=privacy">Privacidade</a><a href="/?action=cookies">Cookies</a></nav></footer>
    <section class="cookie-banner" id="cookie-banner" hidden><div><strong>Privacidade e cookies</strong><p>Usamos cookies essenciais para manter sua sessão, proteger a compra e guardar suas preferências.</p></div><div><a href="/?action=cookies">Saiba mais</a><button type="button" data-cookie-choice="essential">Somente essenciais</button><button type="button" class="primary" data-cookie-choice="all">Aceitar todos</button></div></section><script src="/assets/js/vendor/qrcode.min.js"></script><script src="/assets/js/portal.js"></script></body></html>
    <?php
}

public_layout($action === 'catalog' ? 'Ingressos' : 'Minha conta', $cinema, $customer, $action, $message, $error, function () use ($action,$cinema,$customer,$dates,$selectedDate,$parsedDate,$movies,$seatContext,$orderContext,$paymentContext,$accountOrders,$products,$portalSettings) {
    if ($action === 'seats') { if(!$seatContext){?><section class="empty-state"><strong>Sessão indisponível</strong><a class="portal-button primary" href="/">Voltar à programação</a></section><?php return;} $session=$seatContext['session'];$screen=$seatContext['screen'];?>
        <div class="page-heading"><div><a class="back-link" href="/?date=<?=e(date('Y-m-d',strtotime($session['starts_at'])))?>">← Voltar</a><span class="eyebrow">Escolha suas poltronas</span><h1><?=e($session['title'])?></h1><p><?=e($session['room_name'])?> · <?=e(date('d/m/Y H:i',strtotime($session['starts_at'])))?> · <?=e(ucfirst($session['audio_type']))?> · <?=$session['is_3d']?'3D':'2D'?></p></div></div>
        <?php if(($_GET['error']??'')==='conflict'):?><p class="portal-notice error">Uma poltrona foi escolhida em outro terminal. O mapa foi atualizado.</p><?php elseif(($_GET['error']??'')==='no_seats'):?><p class="portal-notice error">Selecione pelo menos uma poltrona.</p><?php endif;?>
        <form method="post" action="/?action=hold" class="public-seat-layout" id="public-seat-form" data-full-price="<?=e($session['price'])?>" data-half-price="<?=e($session['half_price']??$session['price']/2)?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="showtime_id" value="<?=(int)$session['id']?>"><section class="public-seat-map"><div class="public-screen" style="left:<?=e(((float)($screen['x']??270)/1040)*100)?>%;top:<?=e(((float)($screen['y']??28)/620)*100)?>%;width:<?=e(((float)($screen['w']??500)/1040)*100)?>%;height:<?=e(((float)($screen['h']??34)/620)*100)?>%;">TELA</div><?php foreach($seatContext['seats'] as $seat):$blocked=!empty($seat['sold_ticket_id'])||!empty($seat['held_id']);?><label class="public-seat <?=$seat['seat_type']==='grande'?'large':''?> <?=$blocked?'blocked':''?>" style="left:<?=e(((float)$seat['pos_x']/1040)*100)?>%;top:<?=e(((float)$seat['pos_y']/620)*100)?>%;width:<?=e(((float)$seat['width']/1040)*100)?>%;height:<?=e(((float)$seat['height']/620)*100)?>%;"><input type="checkbox" name="seat_ids[]" value="<?=(int)$seat['id']?>" data-code="<?=e($seat['seat_code'])?>" <?=$blocked?'disabled':''?>><span><?=e($seat['seat_code'])?></span></label><?php endforeach;?></section><aside class="seat-cart"><h2>Minha seleção</h2><div class="seat-legend"><span><i></i>Livre</span><span><i></i>Selecionada</span><span><i></i>Indisponível</span></div><div id="public-selected-seats" class="selected-seat-list"><p>Nenhuma poltrona selecionada.</p></div><div class="seat-cart-total"><span>Total</span><strong id="public-seat-total">R$ 0,00</strong></div><button class="portal-button primary" id="public-seat-continue" disabled>Continuar compra</button></aside></form>
    <?php } elseif ($action === 'checkout') { if(!$orderContext){?><section class="empty-state"><strong>Reserva indisponível</strong><p>Escolha as poltronas novamente.</p><a class="portal-button primary" href="/">Ver sessões</a></section><?php return;}?>
        <div class="page-heading"><div><span class="eyebrow">Finalizar compra</span><h1><?=e($orderContext['title'])?></h1><p><?=e($orderContext['room_name'])?> · <?=e(date('d/m/Y H:i',strtotime($orderContext['starts_at'])))?> · <?=e(ucfirst($orderContext['audio_type']))?> · <?=$orderContext['is_3d']?'3D':'2D'?></p></div><div class="hold-timer" data-expires-epoch="<?=(int)$orderContext['expires_epoch']?>"><span>Tempo da reserva</span><strong>10:00</strong></div></div>
        <?php $pagarmeReady=!empty($portalSettings['pagarme_public_key'])&&!empty($portalSettings['pagarme_secret_encrypted']);$infiniteReady=!empty($portalSettings['infinitepay_handle']);$gateway=$portalSettings['payment_gateway']==='infinitepay'?'infinitepay':'pagarme';$gatewayReady=$gateway==='pagarme'?$pagarmeReady:$infiniteReady;?>
        <form method="post" action="/?action=pay" class="public-checkout" id="public-checkout-form" data-gateway="<?=e($gateway)?>" data-ticket-total="<?=e($orderContext['tickets_total'])?>">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="order_code" value="<?=e($orderContext['order_code'])?>">
            <section><h2>Ingressos</h2><div class="checkout-lines"><?php foreach($orderContext['holds'] as $hold):?><div><span>Poltrona <?=e($hold['seat_code'])?> · <?=e(ucfirst($hold['ticket_type']))?></span><strong>R$ <?=e(number_format((float)$hold['unit_price'],2,',','.'))?></strong></div><?php endforeach;?></div><h2>Adicionar produtos</h2><div class="public-product-grid"><?php foreach($products as $product):?><article><?php if($product['has_image']):?><img src="/?action=product_image&id=<?=(int)$product['id']?>" alt=""><?php endif;?><div class="product-details"><strong><?=e($product['name'])?></strong><span><?=e($product['category_name'])?></span><b>R$ <?=e(number_format((float)$product['price'],2,',','.'))?></b></div><div class="quantity-stepper"><button type="button" data-qty-minus aria-label="Diminuir <?=e($product['name'])?>">−</button><input name="product_qty[<?=(int)$product['id']?>]" type="number" min="0" max="10" value="0" readonly data-product-price="<?=e($product['price'])?>" aria-label="Quantidade de <?=e($product['name'])?>"><button type="button" data-qty-plus aria-label="Aumentar <?=e($product['name'])?>">+</button></div></article><?php endforeach;?></div></section>
            <aside class="checkout-summary"><h2>Resumo</h2><div><span>Ingressos</span><strong>R$ <?=e(number_format((float)$orderContext['tickets_total'],2,',','.'))?></strong></div><div><span>Produtos</span><strong id="checkout-products-total">R$ 0,00</strong></div><div class="grand"><span>Total</span><strong id="checkout-grand-total">R$ <?=e(number_format((float)$orderContext['total_amount'],2,',','.'))?></strong></div><?php if($gateway==='pagarme'):?><label>Pagamento<select name="payment_method" id="public-payment-method"><option value="pix">Pix</option><option value="cartao">Cartão</option></select></label><div class="gateway-choice"><strong>Pagamento seguro</strong><span>No cartão, os dados serão preenchidos no checkout do Pagar.me.</span></div><?php else:?><input type="hidden" name="payment_method" value="pix"><div class="gateway-choice"><strong>Pagamento seguro</strong><span>Escolha Pix ou cartão no checkout.</span></div><?php endif;?><button class="portal-button primary" id="public-pay-button" <?=(!(int)$portalSettings['sales_enabled']||!$gatewayReady)?'disabled':''?>>Continuar para pagamento</button><?php if(!(int)$portalSettings['sales_enabled']):?><small>Pagamento online temporariamente indisponível.</small><?php elseif(!$gatewayReady):?><small>Pagamento online aguardando configuração.</small><?php else:?><small>Você continuará no ambiente seguro do provedor.</small><?php endif;?></aside>
        </form>
    <?php } elseif ($action === 'google_complete') { $pending=$_SESSION['google_pending']??null;?>
        <section class="account-shell compact"><div><span class="eyebrow">Complete seu cadastro</span><h1><?=e($pending['name']??'Conta Google')?></h1><p><?=e($pending['email']??'')?></p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>CPF<input name="cpf" required inputmode="numeric" maxlength="14"></label><div class="form-columns"><label>WhatsApp<input name="whatsapp" required inputmode="tel"></label><label>Telefone<input name="phone" required inputmode="tel"></label></div><label>Endereço <small>Opcional</small><textarea name="address" rows="2"></textarea></label><label class="privacy-check"><input type="checkbox" name="privacy_accept" value="1" required><span>Li e aceito a <a href="/?action=privacy" target="_blank">Política de Privacidade</a>.</span></label><button class="portal-button primary">Concluir cadastro</button></form></section>
    <?php } elseif ($action === 'register') { ?>
        <section class="account-shell"><div><span class="eyebrow">Conta do cliente</span><h1>Crie sua conta</h1><p>Seus ingressos e produtos ficarão disponíveis pelo seu e-mail.</p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($portalSettings['google_client_id']&&$portalSettings['google_client_secret_encrypted']):?><a class="google-button" href="/?action=google"><img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="">Continuar com Google</a><div class="form-divider"><span>ou</span></div><?php endif;?><label>Nome completo<input name="name" required autocomplete="name"></label><div class="form-columns"><label>CPF<input name="cpf" required inputmode="numeric" maxlength="14"></label><label>E-mail<input name="email" type="email" required autocomplete="email"></label><label>WhatsApp<input name="whatsapp" required inputmode="tel" autocomplete="tel"></label><label>Telefone<input name="phone" required inputmode="tel"></label></div><label>Endereço <small>Opcional</small><textarea name="address" rows="2" autocomplete="street-address"></textarea></label><label class="privacy-check"><input type="checkbox" name="privacy_accept" value="1" required><span>Li e aceito a <a href="/?action=privacy" target="_blank">Política de Privacidade</a>.</span></label><button class="portal-button primary">Criar conta e confirmar e-mail</button><a class="text-link" href="/?action=access">Já tenho cadastro</a></form></section>
    <?php } elseif ($action === 'verify') { $pendingEmail=(string)($_SESSION['public_pending_email']??''); ?>
        <section class="account-shell compact"><div><span class="eyebrow">Confirmação por e-mail</span><h1>Digite o código</h1><p>Enviamos 6 números para <?=e($pendingEmail?preg_replace('/(^.).*(@.*$)/','$1***$2',$pendingEmail):'seu e-mail')?>.</p></div><form method="post" action="/?action=verify" class="portal-form code-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="email" value="<?=e($pendingEmail)?>"><label>Código de acesso<input name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000"></label><button class="portal-button primary">Confirmar e entrar</button><a class="text-link" href="/?action=access">Enviar um novo código</a></form></section>
    <?php } elseif ($action === 'access') { ?>
        <section class="account-shell compact"><div><span class="eyebrow">Acesso seguro</span><h1>Receba seu código</h1><p>Não usamos senha. Enviaremos um código numérico de uso único para seu e-mail.</p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($portalSettings['google_client_id']&&$portalSettings['google_client_secret_encrypted']):?><a class="google-button" href="/?action=google"><img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="">Continuar com Google</a><div class="form-divider"><span>ou</span></div><?php endif;?><label>E-mail cadastrado<input name="email" type="email" required autofocus autocomplete="email"></label><button class="portal-button primary">Enviar código de acesso</button><a class="text-link" href="/?action=register">Criar uma conta</a></form></section>
    <?php } elseif ($action === 'payment') { if(!$paymentContext){?><section class="empty-state"><strong>Pedido não encontrado</strong><a class="portal-button primary" href="/?action=account">Meus ingressos</a></section><?php return;}?>
        <section class="payment-result <?=$paymentContext['status']==='pago'?'paid':'pending'?>"><span class="eyebrow">Pedido <?=e($paymentContext['order_code'])?></span><?php if($paymentContext['status']==='pago'):?><h1>Pagamento confirmado</h1><p>Seus documentos já estão disponíveis.</p><div class="payment-actions"><a class="portal-button primary" href="/?action=tickets_pdf&order=<?=e($paymentContext['order_code'])?>">Baixar ingressos em PDF</a><?php if((float)$paymentContext['products_total']>0):?><a class="portal-button" href="/?action=products_pdf&order=<?=e($paymentContext['order_code'])?>">Baixar produtos em PDF</a><?php endif;?></div><?php elseif($paymentContext['payment_gateway']==='infinitepay'):?><h1>Aguardando confirmação da InfinitePay</h1><p>Após concluir o Pix ou cartão, a confirmação aparecerá automaticamente.</p><?php if($paymentContext['provider_checkout_url']):?><a class="portal-button primary" href="<?=e($paymentContext['provider_checkout_url'])?>">Continuar pagamento</a><?php endif;?><?php elseif($paymentContext['payment_method']==='pix'):?><h1>Aguardando pagamento Pix</h1><p>Escaneie o QR Code ou copie o código. A página confirma automaticamente.</p><div class="pix-box"><div data-pix-qr data-value="<?=e($paymentContext['pix_qr_code'])?>"></div><textarea readonly id="pix-code"><?=e($paymentContext['pix_qr_code'])?></textarea><button class="portal-button" type="button" data-copy-pix>Copiar código Pix</button></div><?php else:?><h1>Aguardando pagamento no Pagar.me</h1><p>Os dados do cartão são informados somente no ambiente seguro do Pagar.me.</p><?php if($paymentContext['provider_checkout_url']):?><a class="portal-button primary" href="<?=e($paymentContext['provider_checkout_url'])?>">Continuar pagamento</a><?php endif;?><?php endif;?><?php if(in_array($paymentContext['status'],['rascunho','aguardando_pagamento'],true)):?><form method="post" action="/?action=cancel_order" class="payment-cancel-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="order_code" value="<?=e($paymentContext['order_code'])?>"><button class="portal-button" type="submit">Cancelar compra e liberar poltronas</button></form><?php endif;?></section>
    <?php } elseif ($action === 'account') { ?>
        <div class="page-heading"><div><span class="eyebrow">Área reservada</span><h1>Olá, <?=e(explode(' ',$customer['name'])[0])?></h1><p>Acompanhe seus pedidos e documentos.</p></div></div><?php if($accountOrders):?><div class="order-list"><?php foreach($accountOrders as $order):?><article><div><strong><?=e($order['title'])?></strong><span><?=e(date('d/m/Y H:i',strtotime($order['starts_at'])))?> · <?=e($order['room_name'])?></span><small>Pedido <?=e($order['order_code'])?></small></div><div><span class="order-status <?=$order['status']?>"><?=e(str_replace('_',' ',ucfirst($order['status'])))?></span><strong>R$ <?=e(number_format((float)$order['total_amount'],2,',','.'))?></strong></div><nav><?php if($order['status']==='pago'):?><a href="/?action=tickets_pdf&order=<?=e($order['order_code'])?>">Ingressos PDF</a><?php if((int)$order['product_count']>0):?><a href="/?action=products_pdf&order=<?=e($order['order_code'])?>">Produtos PDF</a><?php endif;?><?php else:?><a href="/?action=payment&order=<?=e($order['order_code'])?>">Ver pagamento</a><?php endif;?></nav></article><?php endforeach;?></div><?php else:?><section class="empty-state"><strong>Nenhuma compra online ainda</strong><p>Escolha uma sessão para comprar seu primeiro ingresso.</p><a class="portal-button primary" href="/">Ver sessões</a></section><?php endif;?>
    <?php } elseif ($action === 'privacy' || $action === 'cookies') { ?>
        <article class="policy"><span class="eyebrow">Transparência</span><h1><?=$action==='privacy'?'Política de Privacidade':'Política de Cookies'?></h1><?php if($action==='privacy'):?><p>Tratamos nome, CPF, e-mail, telefones, endereço opcional e dados das compras para identificar o cliente, processar pagamentos, prevenir fraudes, emitir ingressos e prestar atendimento.</p><h2>Base e finalidade</h2><p>O tratamento ocorre para execução da compra, cumprimento de obrigações legais e consentimento quando aplicável. Dados de cartão são enviados diretamente ao provedor de pagamento e não devem ser armazenados pelo cinema.</p><h2>Seus direitos</h2><p>Você pode solicitar confirmação, correção, portabilidade ou eliminação dos dados quando permitido pela legislação, usando os canais oficiais do cinema.</p><?php else:?><p>Cookies essenciais mantêm a sessão, o carrinho, a segurança e o consentimento. Eles são necessários para a compra funcionar.</p><p>Cookies opcionais de métricas ou publicidade somente poderão ser ativados após consentimento. Nesta versão, nenhum cookie opcional é utilizado.</p><?php endif;?></article>
    <?php } else { ?>
        <section class="catalog-heading"><div><span class="eyebrow">Programação</span><h1>Escolha sua sessão</h1><p><?=e($cinema['address'])?></p></div><?php if($customer):?><span class="customer-chip">Olá, <?=e(explode(' ',$customer['name'])[0])?></span><?php endif;?></section>
        <?php $weekdays=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];$todayKey=$dates[0]->format('Y-m-d');?>
        <nav class="date-rail" aria-label="Datas da programação"><?php foreach($dates as $date):$dateKey=$date->format('Y-m-d');$active=$dateKey===$selectedDate;$dayLabel=$dateKey===$todayKey?'Hoje':$weekdays[(int)$date->format('w')];?><a class="<?=$active?'active':''?>" href="/?date=<?=$dateKey?>"><span><?=e($dayLabel)?></span><strong><?=$date->format('d')?></strong><small><?=$date->format('m')?></small></a><?php endforeach;?></nav>
        <div class="movie-program"><?php foreach($movies as $movie):$info=$movie['info'];?><article class="program-card"><div class="poster"><?php if($info['has_cover']):?><img src="/?action=cover&id=<?=(int)$info['movie_id']?>" alt="Capa de <?=e($info['title'])?>"><?php else:?><div class="poster-placeholder">SEM CAPA</div><?php endif;?></div><div class="program-info"><div><h2><?=e($info['title'])?></h2><p><?=e($info['genre'])?> · <?=(int)$info['duration_minutes']?> min</p><p class="synopsis"><?=e($info['synopsis'])?></p></div><div class="session-groups"><?php foreach($movie['sessions'] as $session):$available=max(0,(int)$session['capacity']-(int)$session['sold']-(int)$session['held']);?><a class="session-button <?=$available<1?'sold-out':''?>" href="<?=$available>0?'/?action=seats&showtime_id='.(int)$session['id']:'#'?>"><strong><?=e(date('H:i',strtotime($session['starts_at'])))?></strong><span><?=e(ucfirst($session['audio_type']))?> · <?=$session['is_3d']?'3D':'2D'?></span><small><?=$available>0?$available.' lugares':'Esgotada'?></small></a><?php endforeach;?></div></div></article><?php endforeach;?><?php if(!$movies):?><section class="empty-state"><strong>Nenhuma sessão nesta data</strong><p>Escolha outro dia na régua acima.</p></section><?php endif;?></div>
    <?php }
});
