<?php

declare(strict_types=1);

namespace CinemaPce;

use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use PDO;
use RuntimeException;

final class PublicPdf
{
    public static function tickets(PDO $db, string $orderCode, int $customerId): string
    {
        $stmt=$db->prepare("SELECT tickets.*,room_seats.seat_code,movies.title,movies.age_rating,rooms.name room_name,showtimes.starts_at,showtimes.audio_type,showtimes.is_3d FROM public_orders INNER JOIN tickets ON tickets.sale_code=public_orders.order_code INNER JOIN room_seats ON room_seats.id=tickets.room_seat_id INNER JOIN showtimes ON showtimes.id=tickets.showtime_id INNER JOIN movies ON movies.id=showtimes.movie_id INNER JOIN rooms ON rooms.id=showtimes.room_id WHERE public_orders.order_code=? AND public_orders.customer_id=? AND public_orders.status='pago' ORDER BY room_seats.row_label,room_seats.seat_number");
        $stmt->execute([$orderCode,$customerId]);$rows=$stmt->fetchAll();if(!$rows)throw new RuntimeException('Ingressos não encontrados.');
        $cinema=self::cinema($db);$html=self::style();
        foreach($rows as $index=>$row){$validation=rtrim((string)config_value('app_url'),'/').'/admin/index.php?route=ticket_validate&token='.rawurlencode($row['qr_token']);$html.='<section class="ticket '.($index?'page':'').'">'.self::logo($cinema).'<div class="kind">INGRESSO</div><h1>'.self::e($row['title']).'</h1><div class="grid"><p><span>Data</span><strong>'.date('d/m/Y H:i',strtotime($row['starts_at'])).'</strong></p><p><span>Sala</span><strong>'.self::e($row['room_name']).'</strong></p><p><span>Poltrona</span><strong class="seat">'.self::e($row['seat_code']).'</strong></p><p><span>Tipo</span><strong>'.self::e(ucfirst($row['ticket_type'])).'</strong></p><p><span>Áudio</span><strong>'.self::e(ucfirst($row['audio_type'])).'</strong></p><p><span>Exibição</span><strong>'.($row['is_3d']?'3D':'2D').'</strong></p></div><div class="qr"><img src="'.self::qr($validation).'"><small>'.self::e($row['qr_token']).'</small></div><div class="code">Pedido '.self::e($row['sale_code']).'</div></section>';}
        $rating=($rows[0]['age_rating']??'L')==='L'?'Livre':($rows[0]['age_rating'].' anos');
        $html=str_replace('<div class="grid">','<p class="rating">Classificação indicativa: <strong>'.self::e($rating).'</strong></p><div class="grid">',$html);
        return self::render($html);
    }

    public static function products(PDO $db, string $orderCode, int $customerId): string
    {
        $stmt=$db->prepare("SELECT public_order_products.*,products.name product_name,product_categories.name category_name,public_orders.order_code FROM public_orders INNER JOIN public_order_products ON public_order_products.order_id=public_orders.id INNER JOIN products ON products.id=public_order_products.product_id INNER JOIN product_categories ON product_categories.id=products.category_id WHERE public_orders.order_code=? AND public_orders.customer_id=? AND public_orders.status='pago' AND public_order_products.status IN ('pendente','entregue') ORDER BY product_categories.sort_order,products.name,public_order_products.id");
        $stmt->execute([$orderCode,$customerId]);$rows=$stmt->fetchAll();if(!$rows)throw new RuntimeException('Produtos não encontrados.');
        $cinema=self::cinema($db);$html=self::style();
        foreach($rows as $index=>$row){$validation=rtrim((string)config_value('app_url'),'/').'/admin/index.php?route=product_pickup_lookup&token='.rawurlencode($row['qr_token']);$html.='<section class="ticket '.($index?'page':'').'">'.self::logo($cinema).'<div class="kind">RETIRADA DE PRODUTO</div><h1>'.self::e($row['product_name']).'</h1><div class="grid"><p><span>Categoria</span><strong>'.self::e($row['category_name']).'</strong></p><p><span>Valor</span><strong>R$ '.number_format((float)$row['unit_price'],2,',','.').'</strong></p></div><div class="qr"><img src="'.self::qr($validation).'"><small>'.self::e($row['qr_token']).'</small></div><div class="code">Pedido '.self::e($row['order_code']).'</div><p class="instruction">Apresente este QR Code no balcão de retirada.</p></section>';}
        return self::render($html);
    }

    private static function cinema(PDO $db): array
    {
        return $db->query('SELECT cinema_name,cnpj,address,logo_mime,logo_data FROM cinema_settings WHERE id=1')->fetch()?:[];
    }

    private static function logo(array $cinema): string
    {
        $image=!empty($cinema['logo_data'])?'<img class="logo" src="data:'.self::e($cinema['logo_mime']).';base64,'.base64_encode($cinema['logo_data']).'">':'';
        return '<header>'.$image.'<div><strong>'.self::e($cinema['cinema_name']??'Cinema').'</strong><small>'.self::e($cinema['cnpj']??'').'</small></div></header>';
    }

    private static function qr(string $value): string
    {
        return Builder::create()->writer(new PngWriter())->data($value)->size(240)->margin(4)->build()->getDataUri();
    }

    private static function render(string $html): string
    {
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');
        $dompdf=new Dompdf($options);$dompdf->loadHtml('<!doctype html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>','UTF-8');$dompdf->setPaper([0,0,297.64,419.53]);$dompdf->render();return $dompdf->output();
    }

    private static function style(): string
    {
        return '<style>@page{margin:18px}*{box-sizing:border-box}body{margin:0;font-family:"DejaVu Sans",sans-serif;color:#17120f}.ticket{height:378px;padding:13px;border:1px solid #bbb;border-top:5px solid #c2410c}.page{page-break-before:always}header{display:table;width:100%;padding-bottom:8px;border-bottom:1px solid #ddd}header>*{display:table-cell;vertical-align:middle}.logo{width:42px;height:42px;object-fit:contain}header div{padding-left:8px}header strong,header small{display:block}.kind{margin-top:10px;color:#c2410c;font-size:9px;font-weight:bold}h1{margin:4px 0 9px;font-size:17px;line-height:1.15}.grid{display:table;width:100%;table-layout:fixed}.grid p{display:table-cell;padding:4px;margin:0}.grid span,.grid strong{display:block}.grid span{color:#777;font-size:8px}.grid strong{font-size:10px}.grid .seat{font-size:18px}.qr{text-align:center;margin-top:8px}.qr img{width:135px;height:135px}.qr small{display:block;font-size:6px;word-break:break-all}.code{margin-top:6px;text-align:center;font-size:8px;font-weight:bold}.instruction{text-align:center;font-size:8px}</style>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES,'UTF-8');
    }
}
