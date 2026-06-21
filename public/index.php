<?php

declare(strict_types=1);

use CinemaPce\Database;
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
    unset($_SESSION['public_customer_id']);
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
    $secret=SettingCrypto::decrypt($portalSettings['pagarme_webhook_secret_encrypted']??'');
    $valid=$secret!==''&&hash_equals(hash('sha256',$secret),(string)($_GET['token']??''));
    if(!$valid){http_response_code(401);exit;}
    $payload=json_decode((string)file_get_contents('php://input'),true)?:[];$data=$payload['data']??[];
    $providerId=(string)($data['id']??'');$providerOrderId=(string)($data['order']['id']??'');
    $stmt=$db->prepare('SELECT id,pagarme_order_id FROM public_orders WHERE pagarme_order_id=? OR pagarme_charge_id=? LIMIT 1');$stmt->execute([$providerOrderId?:$providerId,$providerId]);$local=$stmt->fetch();
    if($local&&$local['pagarme_order_id']){try{$remote=Pagarme::getOrder($portalSettings,$local['pagarme_order_id']);if(($remote['status']??'')==='paid')PublicPortal::finalizePaidOrder($db,(int)$local['id'],$remote);}catch(Throwable $exception){error_log('Webhook Pagar.me: '.$exception->getMessage());http_response_code(500);exit;}}
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
        $expiresAt = (new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->modify('+' . $holdMinutes . ' minutes')->format('Y-m-d H:i:s');
        $orderCode = PublicPortal::orderCode();
        $db->prepare("INSERT INTO public_orders(order_code,customer_id,showtime_id,payment_method,status,expires_at) VALUES(?,?,?,'pix','rascunho',?)")->execute([$orderCode,(int)$customer['id'],$showtimeId,$expiresAt]);
        $orderId = (int)$db->lastInsertId();
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
        $db->beginTransaction();
        $stmt=$db->prepare("SELECT * FROM public_orders WHERE order_code=? AND customer_id=? AND status IN ('rascunho','aguardando_pagamento') AND expires_at>NOW() FOR UPDATE");$stmt->execute([$orderCode,(int)$customer['id']]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('A reserva expirou.');
        $db->prepare('DELETE FROM public_order_products WHERE order_id=? AND status=\'aguardando_pagamento\'')->execute([(int)$order['id']]);
        $quantities=[];foreach((array)($_POST['product_qty']??[]) as $productId=>$qty){$qty=max(0,min(10,(int)$qty));if($qty)$quantities[(int)$productId]=$qty;}
        $productTotal=0.0;$productItems=[];
        if($quantities){$ids=array_keys($quantities);$ph=implode(',',array_fill(0,count($ids),'?'));$productsStmt=$db->prepare("SELECT id,name,price,stock_quantity FROM products WHERE active=1 AND id IN ($ph) FOR UPDATE");$productsStmt->execute($ids);$rows=[];foreach($productsStmt->fetchAll() as $row)$rows[(int)$row['id']]=$row;if(count($rows)!==count($ids))throw new RuntimeException('Um produto não está mais disponível.');$insert=$db->prepare('INSERT INTO public_order_products(order_id,product_id,unit_price) VALUES(?,?,?)');foreach($quantities as $id=>$qty){$product=$rows[$id];if($product['stock_quantity']!==null&&(int)$product['stock_quantity']<$qty)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'.');for($i=0;$i<$qty;$i++)$insert->execute([(int)$order['id'],$id,$product['price']]);$productTotal+=(float)$product['price']*$qty;$productItems[]=['amount'=>(int)round((float)$product['price']*100),'description'=>$product['name'],'quantity'=>$qty,'code'=>'PROD-'.$id];}}
        $total=(float)$order['tickets_total']+$productTotal;$db->prepare("UPDATE public_orders SET payment_method=?,products_total=?,total_amount=?,status='aguardando_pagamento' WHERE id=?")->execute([$method,$productTotal,$total,(int)$order['id']]);
        $holds=$db->prepare('SELECT public_seat_holds.*,room_seats.seat_code FROM public_seat_holds INNER JOIN room_seats ON room_seats.id=public_seat_holds.room_seat_id WHERE order_id=?');$holds->execute([(int)$order['id']]);$ticketItems=[];foreach($holds->fetchAll() as $hold)$ticketItems[]=['amount'=>(int)round((float)$hold['unit_price']*100),'description'=>'Ingresso '.$hold['seat_code'].' '.ucfirst($hold['ticket_type']),'quantity'=>1,'code'=>'ING-'.$hold['room_seat_id']];
        $order['total_amount']=$total;$order['products_total']=$productTotal;$order['payment_method']=$method;$db->commit();
        $provider=Pagarme::createOrder($portalSettings,$order,$customer,array_merge($ticketItems,$productItems),$method,trim($_POST['card_token']??'')?:null);$charge=$provider['charges'][0]??[];$transaction=$charge['last_transaction']??[];
        $db->prepare('UPDATE public_orders SET pagarme_order_id=?,pagarme_charge_id=?,pix_qr_code=?,pix_qr_code_url=?,provider_payload=? WHERE id=?')->execute([$provider['id']??null,$charge['id']??null,$transaction['qr_code']??null,$transaction['qr_code_url']??null,json_encode($provider,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$order['id']]);
        if(($provider['status']??'')==='paid')PublicPortal::finalizePaidOrder($db,(int)$order['id'],$provider);
        header('Location: /?action=payment&order='.$orderCode);exit;
    }catch(Throwable $exception){if($db->inTransaction())$db->rollBack();$_SESSION['portal_error']=$exception->getMessage();header('Location: /?action=checkout&order='.urlencode($orderCode));exit;}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($action === 'google_complete') {
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
            $token = PublicPortal::createLoginToken($db, $customerId);
            $link = PublicPortal::publicUrl(['action' => 'token', 'token' => $token]);
            Mailer::send($db, $email, 'Confirme seu acesso - ' . $cinema['cinema_name'], '<h2>Olá, ' . e($name) . '</h2><p>Use o botão abaixo para confirmar seu e-mail e acessar seus ingressos.</p><p><a href="' . e($link) . '" style="display:inline-block;padding:12px 18px;background:#c2410c;color:#fff;text-decoration:none">Confirmar meu acesso</a></p><p>O link expira em 20 minutos.</p>');
            $message = 'Cadastro realizado. Enviamos um link de confirmação para seu e-mail.';
            $action = 'access';
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
                    $token = PublicPortal::createLoginToken($db, (int) $account['id']);
                    $link = PublicPortal::publicUrl(['action' => 'token', 'token' => $token]);
                    Mailer::send($db, $account['email'], 'Seu link de acesso - ' . $cinema['cinema_name'], '<h2>Olá, ' . e($account['name']) . '</h2><p><a href="' . e($link) . '" style="display:inline-block;padding:12px 18px;background:#c2410c;color:#fff;text-decoration:none">Acessar meus ingressos</a></p><p>Este link é de uso único e expira em 20 minutos.</p>');
                } catch (Throwable $exception) {
                    error_log('Falha no login por e-mail: ' . $exception->getMessage());
                    $error = 'O envio de e-mail está temporariamente indisponível. Tente novamente em alguns minutos.';
                }
            }
            if ($error === '') $message = 'Se o e-mail estiver cadastrado, você receberá um link de acesso válido por 20 minutos.';
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
    $stmt=$db->prepare("SELECT public_orders.*,showtimes.starts_at,showtimes.audio_type,showtimes.is_3d,movies.title,rooms.name room_name FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE public_orders.order_code=? AND public_orders.customer_id=? AND public_orders.status IN ('rascunho','aguardando_pagamento') AND public_orders.expires_at>NOW()");
    $stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);
    $order=$stmt->fetch();
    if($order){$holds=$db->prepare('SELECT public_seat_holds.*,room_seats.seat_code FROM public_seat_holds INNER JOIN room_seats ON room_seats.id=public_seat_holds.room_seat_id WHERE order_id=? ORDER BY room_seats.row_label,room_seats.seat_number');$holds->execute([(int)$order['id']]);$order['holds']=$holds->fetchAll();$orderContext=$order;$products=$db->query('SELECT products.id,products.name,products.price,products.image_data IS NOT NULL has_image,product_categories.name category_name FROM products INNER JOIN product_categories ON product_categories.id=products.category_id WHERE products.active=1 AND product_categories.active=1 AND (products.stock_quantity IS NULL OR products.stock_quantity>0) ORDER BY product_categories.sort_order,products.name')->fetchAll();}else{$error='Esta reserva expirou ou não está mais disponível.';}
} elseif ($action === 'payment' && $customer) {
    $stmt=$db->prepare("SELECT public_orders.*,showtimes.starts_at,movies.title,rooms.name room_name FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE public_orders.order_code=? AND customer_id=?");$stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);$paymentContext=$stmt->fetch()?:null;
    if($paymentContext&&$paymentContext['status']==='aguardando_pagamento'&&$paymentContext['pagarme_order_id']){try{$remote=Pagarme::getOrder($portalSettings,$paymentContext['pagarme_order_id']);if(($remote['status']??'')==='paid')PublicPortal::finalizePaidOrder($db,(int)$paymentContext['id'],$remote);$stmt->execute([trim($_GET['order']??''),(int)$customer['id']]);$paymentContext=$stmt->fetch()?:null;}catch(Throwable $exception){error_log('Consulta pagamento: '.$exception->getMessage());}}
} elseif ($action === 'account' && $customer) {
    $stmt=$db->prepare("SELECT public_orders.*,showtimes.starts_at,movies.title,rooms.name room_name,(SELECT COUNT(*) FROM tickets WHERE sale_code=public_orders.order_code AND status='vendido') ticket_count,(SELECT COUNT(*) FROM public_order_products WHERE order_id=public_orders.id AND status IN ('pendente','entregue')) product_count FROM public_orders INNER JOIN showtimes ON showtimes.id=public_orders.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE customer_id=? ORDER BY created_at DESC");$stmt->execute([(int)$customer['id']]);$accountOrders=$stmt->fetchAll();
}

function public_layout(string $title, array $cinema, ?array $customer, string $action, string $message, string $error, callable $content): void
{
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#17120f"><title><?= e($title) ?> - <?= e($cinema['cinema_name']) ?></title><link rel="stylesheet" href="/assets/css/portal.css"></head>
    <body><header class="portal-header"><a class="portal-brand" href="/"><?php if($cinema['has_logo']):?><img src="/?action=logo" alt=""><?php endif;?><strong><?=e($cinema['cinema_name'])?></strong></a><nav><a href="/">Sessões</a><?php if($customer):?><a href="/?action=account">Meus ingressos</a><a href="/?action=logout">Sair</a><?php else:?><a href="/?action=access">Entrar</a><a class="primary-link" href="/?action=register">Criar conta</a><?php endif;?></nav></header>
    <main class="portal-main"><?php if($message):?><p class="portal-notice success"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="portal-notice error"><?=e($error)?></p><?php endif;?><?php $content();?></main>
    <footer class="portal-footer"><div><strong><?=e($cinema['cinema_name'])?></strong><span><?=e($cinema['address'])?></span></div><nav><a href="/?action=privacy">Privacidade</a><a href="/?action=cookies">Cookies</a><a href="/admin/">Acesso administrativo</a></nav></footer>
    <section class="cookie-banner" id="cookie-banner" hidden><div><strong>Privacidade e cookies</strong><p>Usamos cookies essenciais para manter sua sessão, proteger a compra e guardar suas preferências.</p></div><div><a href="/?action=cookies">Saiba mais</a><button type="button" data-cookie-choice="essential">Somente essenciais</button><button type="button" class="primary" data-cookie-choice="all">Aceitar todos</button></div></section><script src="/assets/js/vendor/qrcode.min.js"></script><script src="/assets/js/portal.js"></script></body></html>
    <?php
}

public_layout($action === 'catalog' ? 'Ingressos' : 'Minha conta', $cinema, $customer, $action, $message, $error, function () use ($action,$cinema,$customer,$dates,$selectedDate,$parsedDate,$movies,$seatContext,$orderContext,$paymentContext,$accountOrders,$products,$portalSettings) {
    if ($action === 'seats') { if(!$seatContext){?><section class="empty-state"><strong>Sessão indisponível</strong><a class="portal-button primary" href="/">Voltar à programação</a></section><?php return;} $session=$seatContext['session'];$screen=$seatContext['screen'];?>
        <div class="page-heading"><div><a class="back-link" href="/?date=<?=e(date('Y-m-d',strtotime($session['starts_at'])))?>">← Voltar</a><span class="eyebrow">Escolha suas poltronas</span><h1><?=e($session['title'])?></h1><p><?=e($session['room_name'])?> · <?=e(date('d/m/Y H:i',strtotime($session['starts_at'])))?> · <?=e(ucfirst($session['audio_type']))?> · <?=$session['is_3d']?'3D':'2D'?></p></div></div>
        <?php if(($_GET['error']??'')==='conflict'):?><p class="portal-notice error">Uma poltrona foi escolhida em outro terminal. O mapa foi atualizado.</p><?php elseif(($_GET['error']??'')==='no_seats'):?><p class="portal-notice error">Selecione pelo menos uma poltrona.</p><?php endif;?>
        <form method="post" action="/?action=hold" class="public-seat-layout" id="public-seat-form" data-full-price="<?=e($session['price'])?>" data-half-price="<?=e($session['half_price']??$session['price']/2)?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="showtime_id" value="<?=(int)$session['id']?>"><section class="public-seat-map"><div class="public-screen" style="left:<?=e(((float)($screen['x']??270)/1040)*100)?>%;top:<?=e(((float)($screen['y']??28)/620)*100)?>%;width:<?=e(((float)($screen['w']??500)/1040)*100)?>%;height:<?=e(((float)($screen['h']??34)/620)*100)?>%;">TELA</div><?php foreach($seatContext['seats'] as $seat):$blocked=!empty($seat['sold_ticket_id'])||!empty($seat['held_id']);?><label class="public-seat <?=$seat['seat_type']==='grande'?'large':''?> <?=$blocked?'blocked':''?>" style="left:<?=e(((float)$seat['pos_x']/1040)*100)?>%;top:<?=e(((float)$seat['pos_y']/620)*100)?>%;width:<?=e(((float)$seat['width']/1040)*100)?>%;height:<?=e(((float)$seat['height']/620)*100)?>%;"><input type="checkbox" name="seat_ids[]" value="<?=(int)$seat['id']?>" data-code="<?=e($seat['seat_code'])?>" <?=$blocked?'disabled':''?>><span><?=e($seat['seat_code'])?></span></label><?php endforeach;?></section><aside class="seat-cart"><h2>Minha seleção</h2><div class="seat-legend"><span><i></i>Livre</span><span><i></i>Selecionada</span><span><i></i>Indisponível</span></div><div id="public-selected-seats" class="selected-seat-list"><p>Nenhuma poltrona selecionada.</p></div><div class="seat-cart-total"><span>Total</span><strong id="public-seat-total">R$ 0,00</strong></div><button class="portal-button primary" id="public-seat-continue" disabled>Reservar por 10 minutos</button></aside></form>
    <?php } elseif ($action === 'checkout') { if(!$orderContext){?><section class="empty-state"><strong>Reserva indisponível</strong><p>Escolha as poltronas novamente.</p><a class="portal-button primary" href="/">Ver sessões</a></section><?php return;}?>
        <div class="page-heading"><div><span class="eyebrow">Finalizar compra</span><h1><?=e($orderContext['title'])?></h1><p><?=e($orderContext['room_name'])?> · <?=e(date('d/m/Y H:i',strtotime($orderContext['starts_at'])))?> · <?=e(ucfirst($orderContext['audio_type']))?> · <?=$orderContext['is_3d']?'3D':'2D'?></p></div><div class="hold-timer" data-expires="<?=e($orderContext['expires_at'])?>"><span>Tempo da reserva</span><strong>10:00</strong></div></div>
        <form method="post" action="/?action=pay" class="public-checkout" id="public-checkout-form" data-ticket-total="<?=e($orderContext['tickets_total'])?>" data-pagarme-key="<?=e($portalSettings['pagarme_public_key'])?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="order_code" value="<?=e($orderContext['order_code'])?>"><input type="hidden" name="card_token" id="card-token"><section><h2>Ingressos</h2><div class="checkout-lines"><?php foreach($orderContext['holds'] as $hold):?><div><span>Poltrona <?=e($hold['seat_code'])?> · <?=e(ucfirst($hold['ticket_type']))?></span><strong>R$ <?=e(number_format((float)$hold['unit_price'],2,',','.'))?></strong></div><?php endforeach;?></div><h2>Adicionar produtos</h2><div class="public-product-grid"><?php foreach($products as $product):?><article><?php if($product['has_image']):?><img src="/?action=product_image&id=<?=(int)$product['id']?>" alt=""><?php endif;?><div><strong><?=e($product['name'])?></strong><span><?=e($product['category_name'])?></span><b>R$ <?=e(number_format((float)$product['price'],2,',','.'))?></b></div><input name="product_qty[<?=(int)$product['id']?>]" type="number" min="0" max="10" value="0" data-product-price="<?=e($product['price'])?>" aria-label="Quantidade de <?=e($product['name'])?>"></article><?php endforeach;?></div></section><aside class="checkout-summary"><h2>Resumo</h2><div><span>Ingressos</span><strong>R$ <?=e(number_format((float)$orderContext['tickets_total'],2,',','.'))?></strong></div><div><span>Produtos</span><strong id="checkout-products-total">R$ 0,00</strong></div><div class="grand"><span>Total</span><strong id="checkout-grand-total">R$ <?=e(number_format((float)$orderContext['total_amount'],2,',','.'))?></strong></div><label>Pagamento<select name="payment_method" id="public-payment-method"><option value="pix">Pix</option><option value="cartao">Cartão</option></select></label><div class="card-fields" id="public-card-fields" hidden><label>Número do cartão<input id="card-number" inputmode="numeric" autocomplete="cc-number"></label><label>Nome impresso<input id="card-holder" autocomplete="cc-name"></label><div><label>Validade<input id="card-expiry" placeholder="MM/AA" inputmode="numeric" autocomplete="cc-exp"></label><label>CVV<input id="card-cvv" inputmode="numeric" maxlength="4" autocomplete="cc-csc"></label></div></div><button class="portal-button primary" id="public-pay-button" <?=(!(int)$portalSettings['sales_enabled']||!$portalSettings['pagarme_public_key']||!$portalSettings['pagarme_secret_encrypted'])?'disabled':''?>>Continuar para pagamento</button><?php if(!(int)$portalSettings['sales_enabled']):?><small>Pagamento online temporariamente indisponível.</small><?php else:?><small>Pagamento processado com segurança pelo Pagar.me.</small><?php endif;?></aside></form>
    <?php } elseif ($action === 'google_complete') { $pending=$_SESSION['google_pending']??null;?>
        <section class="account-shell compact"><div><span class="eyebrow">Complete seu cadastro</span><h1><?=e($pending['name']??'Conta Google')?></h1><p><?=e($pending['email']??'')?></p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>CPF<input name="cpf" required inputmode="numeric" maxlength="14"></label><div class="form-columns"><label>WhatsApp<input name="whatsapp" required inputmode="tel"></label><label>Telefone<input name="phone" required inputmode="tel"></label></div><label>Endereço <small>Opcional</small><textarea name="address" rows="2"></textarea></label><label class="privacy-check"><input type="checkbox" name="privacy_accept" value="1" required><span>Li e aceito a <a href="/?action=privacy" target="_blank">Política de Privacidade</a>.</span></label><button class="portal-button primary">Concluir cadastro</button></form></section>
    <?php } elseif ($action === 'register') { ?>
        <section class="account-shell"><div><span class="eyebrow">Conta do cliente</span><h1>Crie sua conta</h1><p>Seus ingressos e produtos ficarão disponíveis pelo seu e-mail.</p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($portalSettings['google_client_id']&&$portalSettings['google_client_secret_encrypted']):?><a class="google-button" href="/?action=google">Continuar com Google</a><div class="form-divider"><span>ou</span></div><?php endif;?><label>Nome completo<input name="name" required autocomplete="name"></label><div class="form-columns"><label>CPF<input name="cpf" required inputmode="numeric" maxlength="14"></label><label>E-mail<input name="email" type="email" required autocomplete="email"></label><label>WhatsApp<input name="whatsapp" required inputmode="tel" autocomplete="tel"></label><label>Telefone<input name="phone" required inputmode="tel"></label></div><label>Endereço <small>Opcional</small><textarea name="address" rows="2" autocomplete="street-address"></textarea></label><label class="privacy-check"><input type="checkbox" name="privacy_accept" value="1" required><span>Li e aceito a <a href="/?action=privacy" target="_blank">Política de Privacidade</a>.</span></label><button class="portal-button primary">Criar conta e confirmar e-mail</button><a class="text-link" href="/?action=access">Já tenho cadastro</a></form></section>
    <?php } elseif ($action === 'access') { ?>
        <section class="account-shell compact"><div><span class="eyebrow">Acesso seguro</span><h1>Receba seu link</h1><p>Não usamos senha. Enviaremos um link de uso único para seu e-mail.</p></div><form method="post" class="portal-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($portalSettings['google_client_id']&&$portalSettings['google_client_secret_encrypted']):?><a class="google-button" href="/?action=google">Continuar com Google</a><div class="form-divider"><span>ou</span></div><?php endif;?><label>E-mail cadastrado<input name="email" type="email" required autofocus autocomplete="email"></label><button class="portal-button primary">Enviar link de acesso</button><a class="text-link" href="/?action=register">Criar uma conta</a></form></section>
    <?php } elseif ($action === 'payment') { if(!$paymentContext){?><section class="empty-state"><strong>Pedido não encontrado</strong><a class="portal-button primary" href="/?action=account">Meus ingressos</a></section><?php return;}?>
        <section class="payment-result <?=$paymentContext['status']==='pago'?'paid':'pending'?>"><span class="eyebrow">Pedido <?=e($paymentContext['order_code'])?></span><?php if($paymentContext['status']==='pago'):?><h1>Pagamento confirmado</h1><p>Seus documentos já estão disponíveis.</p><div class="payment-actions"><a class="portal-button primary" href="/?action=tickets_pdf&order=<?=e($paymentContext['order_code'])?>">Baixar ingressos em PDF</a><?php if((float)$paymentContext['products_total']>0):?><a class="portal-button" href="/?action=products_pdf&order=<?=e($paymentContext['order_code'])?>">Baixar produtos em PDF</a><?php endif;?></div><?php elseif($paymentContext['payment_method']==='pix'):?><h1>Aguardando pagamento Pix</h1><p>Escaneie o QR Code ou copie o código. A página confirma automaticamente.</p><div class="pix-box"><div data-pix-qr data-value="<?=e($paymentContext['pix_qr_code'])?>"></div><textarea readonly id="pix-code"><?=e($paymentContext['pix_qr_code'])?></textarea><button class="portal-button" type="button" data-copy-pix>Copiar código Pix</button></div><?php else:?><h1>Pagamento em processamento</h1><p>A confirmação pode levar alguns instantes.</p><?php endif;?></section>
    <?php } elseif ($action === 'account') { ?>
        <div class="page-heading"><div><span class="eyebrow">Área reservada</span><h1>Olá, <?=e(explode(' ',$customer['name'])[0])?></h1><p>Acompanhe seus pedidos e documentos.</p></div></div><?php if($accountOrders):?><div class="order-list"><?php foreach($accountOrders as $order):?><article><div><strong><?=e($order['title'])?></strong><span><?=e(date('d/m/Y H:i',strtotime($order['starts_at'])))?> · <?=e($order['room_name'])?></span><small>Pedido <?=e($order['order_code'])?></small></div><div><span class="order-status <?=$order['status']?>"><?=e(str_replace('_',' ',ucfirst($order['status'])))?></span><strong>R$ <?=e(number_format((float)$order['total_amount'],2,',','.'))?></strong></div><nav><?php if($order['status']==='pago'):?><a href="/?action=tickets_pdf&order=<?=e($order['order_code'])?>">Ingressos PDF</a><?php if((int)$order['product_count']>0):?><a href="/?action=products_pdf&order=<?=e($order['order_code'])?>">Produtos PDF</a><?php endif;?><?php else:?><a href="/?action=payment&order=<?=e($order['order_code'])?>">Ver pagamento</a><?php endif;?></nav></article><?php endforeach;?></div><?php else:?><section class="empty-state"><strong>Nenhuma compra online ainda</strong><p>Escolha uma sessão para comprar seu primeiro ingresso.</p><a class="portal-button primary" href="/">Ver sessões</a></section><?php endif;?>
    <?php } elseif ($action === 'privacy' || $action === 'cookies') { ?>
        <article class="policy"><span class="eyebrow">Transparência</span><h1><?=$action==='privacy'?'Política de Privacidade':'Política de Cookies'?></h1><?php if($action==='privacy'):?><p>Tratamos nome, CPF, e-mail, telefones, endereço opcional e dados das compras para identificar o cliente, processar pagamentos, prevenir fraudes, emitir ingressos e prestar atendimento.</p><h2>Base e finalidade</h2><p>O tratamento ocorre para execução da compra, cumprimento de obrigações legais e consentimento quando aplicável. Dados de cartão são enviados diretamente ao provedor de pagamento e não devem ser armazenados pelo cinema.</p><h2>Seus direitos</h2><p>Você pode solicitar confirmação, correção, portabilidade ou eliminação dos dados quando permitido pela legislação, usando os canais oficiais do cinema.</p><?php else:?><p>Cookies essenciais mantêm a sessão, o carrinho, a segurança e o consentimento. Eles são necessários para a compra funcionar.</p><p>Cookies opcionais de métricas ou publicidade somente poderão ser ativados após consentimento. Nesta versão, nenhum cookie opcional é utilizado.</p><?php endif;?></article>
    <?php } else { ?>
        <section class="catalog-heading"><div><span class="eyebrow">Programação</span><h1>Escolha sua sessão</h1><p><?=e($cinema['address'])?></p></div><?php if($customer):?><span class="customer-chip">Olá, <?=e(explode(' ',$customer['name'])[0])?></span><?php endif;?></section>
        <nav class="date-rail" aria-label="Datas da programação"><?php foreach($dates as $date):$active=$date->format('Y-m-d')===$selectedDate;?><a class="<?=$active?'active':''?>" href="/?date=<?=$date->format('Y-m-d')?>"><span><?=$date->format('D')?></span><strong><?=$date->format('d')?></strong><small><?=$date->format('m')?></small></a><?php endforeach;?></nav>
        <div class="movie-program"><?php foreach($movies as $movie):$info=$movie['info'];?><article class="program-card"><div class="poster"><?php if($info['has_cover']):?><img src="/?action=cover&id=<?=(int)$info['movie_id']?>" alt="Capa de <?=e($info['title'])?>"><?php else:?><div class="poster-placeholder">SEM CAPA</div><?php endif;?></div><div class="program-info"><div><h2><?=e($info['title'])?></h2><p><?=e($info['genre'])?> · <?=(int)$info['duration_minutes']?> min</p><p class="synopsis"><?=e($info['synopsis'])?></p></div><div class="session-groups"><?php foreach($movie['sessions'] as $session):$available=max(0,(int)$session['capacity']-(int)$session['sold']-(int)$session['held']);?><a class="session-button <?=$available<1?'sold-out':''?>" href="<?=$available>0?'/?action=seats&showtime_id='.(int)$session['id']:'#'?>"><strong><?=e(date('H:i',strtotime($session['starts_at'])))?></strong><span><?=e(ucfirst($session['audio_type']))?> · <?=$session['is_3d']?'3D':'2D'?></span><small><?=$available>0?$available.' lugares':'Esgotada'?></small></a><?php endforeach;?></div></div></article><?php endforeach;?><?php if(!$movies):?><section class="empty-state"><strong>Nenhuma sessão nesta data</strong><p>Escolha outro dia na régua acima.</p></section><?php endif;?></div>
    <?php }
});
