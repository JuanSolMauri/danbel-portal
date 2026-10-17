<?php
// Importación desde la línea de comandos: php app/import-cli.php /carpeta/con/los/dbf
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
$_SERVER['REQUEST_URI'] = '';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/import.php';

$dir = $argv[1] ?? '';
$tables = is_dir($dir) ? find_erp_tables($dir) : [];
if (count($tables) !== 3) {
    fwrite(STDERR, "Uso: php app/import-cli.php <carpeta con TFAMILIES.DBF, tmatpr.DBF y ttarifcli.DBF>\n");
    exit(1);
}
db()->exec(file_get_contents(__DIR__ . '/schema.sql'));
$result = import_erp(db(), $tables, dirname(__DIR__) . '/img/products');
echo "{$result['families']} familias, {$result['products']} artículos, {$result['customers']} clientes con descuento\n";
