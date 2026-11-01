<?php
// Importación automática de los DBF del ERP.
// Llámalo desde la tarea programada de Arsys:
//   https://danbelgroup.com/clientes/cron_import.php?token=TU_INSTALL_TOKEN
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/import.php';

// Protección: solo accesible con el install_token de config.php.
$token = (string) ($_REQUEST['token'] ?? '');
if ($token === '' || !hash_equals((string) config('install_token', ''), $token)) {
    http_response_code(403);
    exit('Token requerido.');
}

@set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');

$dataDir = __DIR__ . '/datos';
if (!is_dir($dataDir)) {
    echo 'ERROR: la carpeta /datos no existe en el servidor.' . "\n";
    exit;
}

$tables = find_erp_tables($dataDir);
$missing = array_diff_key(ERP_TABLES, $tables);
if ($missing) {
    echo 'ERROR: faltan los ficheros: ' . implode(', ', array_map('strtoupper', $missing)) . "\n";
    echo 'Revisa que están en /html/clientes/datos/' . "\n";
    exit;
}

try {
    $r = import_erp(db(), $tables, __DIR__ . '/img/products');
    $ts = date('Y-m-d H:i:s');
    echo "OK [$ts] {$r['families']} familias, {$r['products']} artículos, {$r['customers']} clientes con descuento.\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
