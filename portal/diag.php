<?php
// Página de diagnóstico temporal — borrar después de usar.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_user();

$pdo = db();

// Columnas de la tabla products
$cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);

// Valor obsolete del producto concreto
$ean = '8054609782661';
$stmt = $pdo->prepare('SELECT code, family, obsolete FROM products WHERE ean = ?');
$stmt->execute([$ean]);
$prod = $stmt->fetch();

// Última importación
$lastImport = $pdo->query('SELECT * FROM imports ORDER BY id DESC LIMIT 1')->fetch();

// Conteo por obsolete
$counts = $pdo->query('SELECT obsolete, COUNT(*) AS n FROM products GROUP BY obsolete')->fetchAll();

header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAGNÓSTICO PORTAL DANBEL ===\n\n";
echo "Columna 'obsolete' en tabla products: " . (in_array('obsolete', $cols) ? "SÍ" : "NO (falta)") . "\n\n";
echo "Producto EAN $ean:\n";
if ($prod) {
    echo "  code={$prod['code']}  family={$prod['family']}  obsolete={$prod['obsolete']}\n";
    echo "  (obsolete=1 → solo aparece en EN LIQUIDACIÓN; obsolete=0 → aparece en la familia normal)\n";
} else {
    echo "  No encontrado\n";
}
echo "\nDistribución obsolete en products:\n";
foreach ($counts as $c) {
    echo "  obsolete={$c['obsolete']}  count={$c['n']}\n";
}
echo "\nÚltima importación: ";
if ($lastImport) {
    echo "{$lastImport['imported_at']}  familias={$lastImport['families']}  productos={$lastImport['products']}\n";
} else {
    echo "ninguna registrada\n";
}
