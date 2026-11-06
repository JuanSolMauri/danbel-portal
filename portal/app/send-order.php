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

$to    = (string) config('order_email', '');
$label = 'Cliente ' . ($customer ?? 'Admin');
$date  = date('Y-m-d');

if (class_exists('ZipArchive')) {
    $attachData = order_xlsx($lines, $sums, $notes, $label);
    $attachMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $fname      = 'pedido-' . ($customer ?? 'admin') . '-' . $date . '.xlsx';
} else {
    $attachData = order_csv($lines, $sums, $notes, $label);
    $attachMime = 'text/csv; charset=UTF-8';
    $fname      = 'pedido-' . ($customer ?? 'admin') . '-' . $date . '.csv';
}

$subject  = '=?UTF-8?B?' . base64_encode("Pedido Danbel · {$label}") . '?=';
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
    . "Content-Type: {$attachMime}; name=\"{$fname}\"\r\n"
    . "Content-Transfer-Encoding: base64\r\n"
    . "Content-Disposition: attachment; filename=\"{$fname}\"\r\n\r\n"
    . chunk_split(base64_encode($attachData))
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

function order_xlsx(array $lines, array $sums, string $notes, string $label): string
{
    $xe = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    // Styles: 0=normal, 1=bold text, 2=decimal, 3=bold decimal
    $cs = fn(string $col, int $row, string $val, int $s = 0): string =>
        '<c r="' . $col . $row . '" t="inlineStr"' . ($s ? " s=\"{$s}\"" : '') . '><is><t>' . $xe($val) . '</t></is></c>';
    $cn = fn(string $col, int $row, float $val, int $s = 0): string =>
        '<c r="' . $col . $row . '"' . ($s ? " s=\"{$s}\"" : '') . '><v>' . $val . '</v></c>';

    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $rows = '';
    $r    = 1;

    // Título
    $rows .= "<row r=\"{$r}\">" . $cs('A', $r, "PEDIDO DANBEL · {$label}", 1) . "</row>\n";
    $r += 2; // fila en blanco

    // Cabecera
    $rows .= "<row r=\"{$r}\">";
    foreach (['Marca', 'Código', 'EAN', 'Descripción', 'Tipo', 'Precio neto', 'Uds.', 'Total'] as $i => $h) {
        $rows .= $cs($cols[$i], $r, $h, 1);
    }
    $rows .= "</row>\n";
    $r++;

    // Líneas de producto
    foreach ($lines as $l) {
        $rows .= "<row r=\"{$r}\">"
            . $cs('A', $r, $l['family_name'])
            . $cs('B', $r, $l['code'])
            . $cs('C', $r, $l['ean'])
            . $cs('D', $r, $l['name'])
            . $cs('E', $r, $l['type'] === 'T' ? 'Tester' : 'Producto')
            . $cn('F', $r, (float) $l['net'], 2)
            . $cn('G', $r, (float) $l['qty'])
            . $cn('H', $r, (float) $l['total'], 2)
            . "</row>\n";
        $r++;
    }

    // Totales
    $r++;
    $totals = [
        ['Productos', (float) $sums['products_sum'], false],
        ['Testers', (float) $sums['testers_sum'], false],
    ];
    if ($sums['free'] > 0)  $totals[] = ['Rappel gratuito testers', -(float) $sums['free'], false];
    if ($sums['dto1'] > 0)  $totals[] = ["Dto. {$sums['dto1']}%", -(float) $sums['d1'], false];
    if ($sums['dto2'] > 0)  $totals[] = ["Dto. {$sums['dto2']}%", -(float) $sums['d2'], false];
    $totals[] = ['TOTAL (sin IVA)', (float) $sums['total'], true];

    foreach ($totals as [$tLabel, $tVal, $bold]) {
        $rows .= "<row r=\"{$r}\">"
            . $cs('G', $r, $tLabel, 1)
            . $cn('H', $r, $tVal, $bold ? 3 : 2)
            . "</row>\n";
        $r++;
    }

    if ($notes !== '') {
        $r++;
        $rows .= "<row r=\"{$r}\">"
            . $cs('A', $r, 'Observaciones', 1)
            . $cs('B', $r, $notes)
            . "</row>\n";
    }

    // Construir el ZIP
    $tmp = tempnam(sys_get_temp_dir(), 'dbl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Pedido" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>'
    );

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>' .
        '<fonts count="2">' .
        '<font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
        '</fonts>' .
        '<fills count="2">' .
        '<fill><patternFill patternType="none"/></fill>' .
        '<fill><patternFill patternType="gray125"/></fill>' .
        '</fills>' .
        '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="4">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' .
        '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>' .
        '</cellXfs>' .
        '</styleSheet>'
    );

    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>' . $rows . '</sheetData>' .
        '</worksheet>'
    );

    $zip->close();
    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

function order_csv(array $lines, array $sums, string $notes, string $label): string
{
    $row = fn(array $f): string => implode(';', array_map(
        fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $f
    )) . "\r\n";
    $fmt = fn(float $v): string => number_format($v, 2, ',', '.');

    $out = "\xEF\xBB\xBF" // UTF-8 BOM para Excel
        . $row(["PEDIDO DANBEL · {$label}"])
        . $row([])
        . $row(['Marca', 'Código', 'EAN', 'Descripción', 'Tipo', 'Precio neto', 'Uds.', 'Total']);

    foreach ($lines as $l) {
        $out .= $row([
            $l['family_name'], $l['code'], $l['ean'], $l['name'],
            $l['type'] === 'T' ? 'Tester' : 'Producto',
            $fmt($l['net']), $l['qty'], $fmt($l['total']),
        ]);
    }

    $out .= $row([]);
    $totals = [['Productos', $fmt($sums['products_sum'])], ['Testers', $fmt($sums['testers_sum'])]];
    if ($sums['free'] > 0)  $totals[] = ['Rappel gratuito testers', '-' . $fmt($sums['free'])];
    if ($sums['dto1'] > 0)  $totals[] = ["Dto. {$sums['dto1']}%", '-' . $fmt($sums['d1'])];
    if ($sums['dto2'] > 0)  $totals[] = ["Dto. {$sums['dto2']}%", '-' . $fmt($sums['d2'])];
    $totals[] = ['TOTAL (sin IVA)', $fmt($sums['total'])];
    foreach ($totals as [$tLabel, $tVal]) {
        $out .= $row(['', '', '', '', '', '', $tLabel, $tVal]);
    }

    if ($notes !== '') {
        $out .= $row([]) . $row(['Observaciones', $notes]);
    }
    return $out;
}
