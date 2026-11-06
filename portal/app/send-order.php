<?php
// Endpoint AJAX: recibe el pedido, genera un Excel y lo envía por correo.
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/catalog.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

if (!hash_equals(csrf_token(), (string) ($body['csrf'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['error' => 'csrf']);
    exit;
}

$rawItems = (array) ($body['items'] ?? []);
$notes    = trim((string) ($body['notes'] ?? ''));

$items = [];
foreach ($rawItems as $code => $qty) {
    $q = max(0, (int) $qty);
    if ($q > 0) {
        $items[(string) $code] = $q;
    }
}

if (!$items) {
    echo json_encode(['error' => 'empty']);
    exit;
}

$access    = resolve_access($user);
$customer  = $access['customer'];
$discounts = $customer !== null ? customer_discounts($customer) : [];
$record    = $customer !== null ? customer_record($customer) : null;
$dto1      = (float) ($record['dto1'] ?? 0);
$dto2      = (float) ($record['dto2'] ?? 0);

$codes = array_keys($items);
$ph    = implode(',', array_fill(0, count($codes), '?'));
$stmt  = db()->prepare(
    "SELECT p.code, p.name, p.ean, p.pvp, p.base_price, p.tester, p.family, f.name AS family_name
     FROM products p JOIN families f ON f.code = p.family
     WHERE p.code IN ($ph)"
);
$stmt->execute($codes);

$round = fn(float $v) => round($v * 100) / 100;

$lines = [];
foreach ($stmt->fetchAll() as $p) {
    $family   = $p['family'];
    $discount = isset($discounts[$family]) ? (float) $discounts[$family] : 0.0;
    $base     = (float) $p['base_price'];
    $tester   = (bool) $p['tester'];
    $net      = $tester ? (float) $p['pvp'] : $round($base * (1 - $discount / 100) + PHP_FLOAT_EPSILON);
    $qty      = $items[$p['code']];
    $lines[]  = [
        'family_name' => $p['family_name'],
        'code'        => $p['code'],
        'ean'         => $p['ean'] ?? '',
        'name'        => $p['name'],
        'type'        => $tester ? 'T' : 'P',
        'net'         => $net,
        'qty'         => $qty,
        'total'       => $round($net * $qty),
    ];
}

usort($lines, fn($a, $b) => strcmp($a['family_name'], $b['family_name']) ?: strcmp($a['name'], $b['name']));

$products_sum = $round(array_sum(array_map(fn($l) => $l['type'] === 'P' ? $l['total'] : 0, $lines)));
$testers_sum  = $round(array_sum(array_map(fn($l) => $l['type'] === 'T' ? $l['total'] : 0, $lines)));
$free         = $round(min($testers_sum, $products_sum * 0.1));
$subtotal     = $round($products_sum + $testers_sum - $free);
$d1           = $round($subtotal * $dto1 / 100);
$d2           = $round(($subtotal - $d1) * $dto2 / 100);
$total        = $round($subtotal - $d1 - $d2);

$sums = compact('products_sum', 'testers_sum', 'free', 'subtotal', 'd1', 'd2', 'dto1', 'dto2', 'total');

$to      = (string) config('order_email', '');
$label   = 'Cliente ' . ($customer ?? 'Admin');
$subject = '=?UTF-8?B?' . base64_encode("Pedido Danbel · {$label}") . '?=';
$date    = date('Y-m-d');
$fname   = 'pedido-' . ($customer ?? 'admin') . '-' . $date . '.xls';

$boundary = 'DBL' . md5(uniqid('', true));
$headers  = implode("\r\n", [
    'From: Portal Danbel <noreply@danbelgroup.com>',
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
]);

$message = "--{$boundary}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: 8bit\r\n\r\n"
    . order_text($lines, $sums, $notes, $label)
    . "\r\n\r\n--{$boundary}\r\n"
    . "Content-Type: application/vnd.ms-excel; name=\"{$fname}\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n"
    . chunk_split(base64_encode(order_excel($lines, $sums, $notes, $label)))
    . "\r\n--{$boundary}--";

$ok = @mail($to, $subject, $message, $headers);
echo json_encode($ok ? ['ok' => true] : ['error' => 'mail_failed']);

// ---- helpers ----

function order_text(array $lines, array $sums, string $notes, string $label): string
{
    $fmt = fn(float $v) => number_format($v, 2, ',', '.') . ' €';
    $out = "PEDIDO DANBEL · {$label}\n" . str_repeat('-', 60) . "\n";
    $curFamily = null;
    foreach ($lines as $l) {
        if ($l['family_name'] !== $curFamily) {
            $curFamily = $l['family_name'];
            $out .= "\n{$curFamily}\n";
        }
        $suffix = $l['type'] === 'T' ? ' [T]' : '';
        $out .= sprintf("  %-16s %-36s %3d ud × %s = %s\n",
            $l['code'] . $suffix,
            mb_substr($l['name'], 0, 36),
            $l['qty'],
            $fmt($l['net']),
            $fmt($l['total'])
        );
    }
    $out .= "\n" . str_repeat('-', 60) . "\n";
    $out .= "Productos:          " . $fmt($sums['products_sum']) . "\n";
    $out .= "Testers:            " . $fmt($sums['testers_sum']) . "\n";
    if ($sums['free'] > 0) {
        $out .= "Rappel gratuito:   -" . $fmt($sums['free']) . "\n";
    }
    if ($sums['dto1'] > 0) {
        $out .= "Dto. {$sums['dto1']}%:          -" . $fmt($sums['d1']) . "\n";
    }
    if ($sums['dto2'] > 0) {
        $out .= "Dto. {$sums['dto2']}%:          -" . $fmt($sums['d2']) . "\n";
    }
    $out .= "TOTAL (sin IVA):    " . $fmt($sums['total']) . "\n";
    if ($notes !== '') {
        $out .= "\nObservaciones: {$notes}\n";
    }
    $out .= "\nIVA y transporte según condiciones comerciales acordadas.";
    return $out;
}

function order_excel(array $lines, array $sums, string $notes, string $label): string
{
    $xe  = fn(string $s) => htmlspecialchars($s, ENT_XML1, 'UTF-8');
    $str = fn(string $v, string $st = '') => '<Cell' . ($st ? " ss:StyleID=\"{$st}\"" : '') . '><Data ss:Type="String">' . $xe($v) . '</Data></Cell>';
    $num = fn(float $v, string $st = '') => '<Cell' . ($st ? " ss:StyleID=\"{$st}\"" : '') . '><Data ss:Type="Number">' . $v . '</Data></Cell>';

    $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n";
    $xml .= "<Styles>\n";
    $xml .= "<Style ss:ID=\"h\"><Font ss:Bold=\"1\"/></Style>\n";
    $xml .= "<Style ss:ID=\"n\"><NumberFormat ss:Format=\"0.00\"/></Style>\n";
    $xml .= "<Style ss:ID=\"hn\"><Font ss:Bold=\"1\"/><NumberFormat ss:Format=\"0.00\"/></Style>\n";
    $xml .= "</Styles>\n";
    $xml .= "<Worksheet ss:Name=\"Pedido\">\n<Table>\n";

    // Título
    $xml .= "<Row><Cell ss:MergeAcross=\"7\"><Data ss:Type=\"String\">" . $xe("PEDIDO DANBEL · {$label}") . "</Data></Cell></Row>\n";
    $xml .= "<Row/>\n";

    // Cabecera columnas
    $xml .= "<Row>";
    foreach (['Marca', 'Código', 'EAN', 'Descripción', 'Tipo', 'Precio neto', 'Uds.', 'Total'] as $h) {
        $xml .= $str($h, 'h');
    }
    $xml .= "</Row>\n";

    // Líneas
    foreach ($lines as $l) {
        $xml .= "<Row>";
        $xml .= $str($l['family_name']);
        $xml .= $str($l['code']);
        $xml .= $str($l['ean']);
        $xml .= $str($l['name']);
        $xml .= $str($l['type'] === 'T' ? 'Tester' : 'Producto');
        $xml .= $num($l['net'], 'n');
        $xml .= $num($l['qty']);
        $xml .= $num($l['total'], 'n');
        $xml .= "</Row>\n";
    }

    // Totales
    $xml .= "<Row/>\n";
    $totals = [
        ['Productos', $sums['products_sum']],
        ['Testers', $sums['testers_sum']],
    ];
    if ($sums['free'] > 0) {
        $totals[] = ['Rappel gratuito testers', -$sums['free']];
    }
    if ($sums['dto1'] > 0) {
        $totals[] = ["Dto. {$sums['dto1']}%", -$sums['d1']];
    }
    if ($sums['dto2'] > 0) {
        $totals[] = ["Dto. {$sums['dto2']}%", -$sums['d2']];
    }
    $totals[] = ['TOTAL (sin IVA)', $sums['total']];
    foreach ($totals as [$tLabel, $tVal]) {
        $bold = $tLabel === 'TOTAL (sin IVA)';
        $xml .= "<Row><Cell ss:Index=\"7\"><Data ss:Type=\"String\" ss:StyleID=\"h\">" . $xe($tLabel) . "</Data></Cell>";
        $xml .= "<Cell ss:StyleID=\"" . ($bold ? 'hn' : 'n') . "\"><Data ss:Type=\"Number\">" . (float) $tVal . "</Data></Cell></Row>\n";
    }

    if ($notes !== '') {
        $xml .= "<Row/>\n";
        $xml .= "<Row><Cell><Data ss:Type=\"String\" ss:StyleID=\"h\">Observaciones</Data></Cell>"
              . "<Cell ss:MergeAcross=\"6\"><Data ss:Type=\"String\">" . $xe($notes) . "</Data></Cell></Row>\n";
    }

    $xml .= "</Table>\n</Worksheet>\n</Workbook>";
    return $xml;
}
