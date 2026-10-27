<?php
// Endpoint AJAX: recibe {items: {code: qty, ...}} y devuelve el resumen agrupado por familia.
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
$rawItems = (array) ($body['items'] ?? []);

$items = [];
foreach ($rawItems as $code => $qty) {
    $q = max(0, (int) $qty);
    if ($q > 0) {
        $items[(string) $code] = $q;
    }
}

if (!$items) {
    echo json_encode(['brands' => [], 'sums' => ['products' => 0, 'testers' => 0, 'free' => 0, 'd1' => 0, 'd2' => 0], 'dto1' => 0, 'dto2' => 0, 'total' => 0]);
    exit;
}

$access = resolve_access($user);
$customer = $access['customer'];
$discounts = $customer !== null ? customer_discounts($customer) : [];
$record = $customer !== null ? customer_record($customer) : null;
$dto1 = (float) ($record['dto1'] ?? 0);
$dto2 = (float) ($record['dto2'] ?? 0);

$codes = array_keys($items);
$ph = implode(',', array_fill(0, count($codes), '?'));
$stmt = db()->prepare(
    "SELECT p.code, p.name, p.ean, p.pvp, p.base_price, p.tester, p.family, f.name AS family_name
     FROM products p JOIN families f ON f.code = p.family
     WHERE p.code IN ($ph)"
);
$stmt->execute($codes);

$byFamily = [];
foreach ($stmt->fetchAll() as $p) {
    $family = $p['family'];
    $discount = isset($discounts[$family]) ? (float) $discounts[$family] : 0.0;
    $base = (float) $p['base_price'];
    $tester = (bool) $p['tester'];
    $net = $tester ? (float) $p['pvp'] : round($base * (1 - $discount / 100) + PHP_FLOAT_EPSILON, 2);
    $qty = $items[$p['code']];
    if (!isset($byFamily[$family])) {
        $byFamily[$family] = ['name' => $p['family_name'], 'lines' => []];
    }
    $byFamily[$family]['lines'][] = [
        'code' => $p['code'],
        'ean'  => $p['ean'] ?? '',
        'name' => $p['name'],
        'type' => $tester ? 'T' : 'P',
        'net'  => $net,
        'qty'  => $qty,
        'total' => round($net * $qty * 100) / 100,
    ];
}

uasort($byFamily, fn($a, $b) => strcmp($a['name'], $b['name']));

$allLines = array_merge(...array_column(array_values($byFamily), 'lines'));
$round = fn(float $v) => round($v * 100) / 100;
$products_sum = $round(array_sum(array_map(fn($l) => $l['type'] === 'P' ? $l['total'] : 0, $allLines)));
$testers_sum  = $round(array_sum(array_map(fn($l) => $l['type'] === 'T' ? $l['total'] : 0, $allLines)));
$free         = $round(min($testers_sum, $products_sum * 0.1));
$subtotal     = $round($products_sum + $testers_sum - $free);
$d1           = $round($subtotal * $dto1 / 100);
$d2           = $round(($subtotal - $d1) * $dto2 / 100);
$total        = $round($subtotal - $d1 - $d2);

echo json_encode([
    'brands' => array_values($byFamily),
    'sums'   => ['products' => $products_sum, 'testers' => $testers_sum, 'free' => $free, 'd1' => $d1, 'd2' => $d2],
    'dto1'   => $dto1,
    'dto2'   => $dto2,
    'total'  => $total,
]);
