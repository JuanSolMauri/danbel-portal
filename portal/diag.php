<?php
// Página de diagnóstico temporal — borrar después de usar.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_user();

$pdo = db();

// Columnas de la tabla products
$cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
$hasOrdre = in_array('ordre', $cols);
$hasObsolete = in_array('obsolete', $cols);

// Última importación
$lastImport = $pdo->query('SELECT * FROM imports ORDER BY id DESC LIMIT 1')->fetch();

// Conteo productos con ordre > 0
$ordreCount = $hasOrdre
    ? (int) $pdo->query('SELECT COUNT(*) FROM products WHERE ordre > 0')->fetchColumn()
    : null;

// Subfamilia de muestra: la primera con ordre > 0, o si no hay, la primera subfamilia de la primera familia
$sampleSubfam = null;
$sampleRows   = [];
if ($hasOrdre && $ordreCount > 0) {
    $row = $pdo->query('SELECT line_name, family FROM products WHERE ordre > 0 AND line_name IS NOT NULL LIMIT 1')->fetch();
    if ($row) {
        $sampleSubfam = $row['line_name'] . ' (familia ' . $row['family'] . ')';
        $stmt = $pdo->prepare('SELECT code, name, ordre FROM products WHERE line_name = ? AND family = ? ORDER BY CASE WHEN ordre > 0 THEN ordre ELSE 9999 END, name');
        $stmt->execute([$row['line_name'], $row['family']]);
        $sampleRows = $stmt->fetchAll();
    }
} else {
    // Mostrar igualmente la primera subfamilia para verificar el orden sin ORDRE
    $row = $pdo->query('SELECT line_name, family FROM products WHERE line_name IS NOT NULL LIMIT 1')->fetch();
    if ($row) {
        $sampleSubfam = $row['line_name'] . ' (familia ' . $row['family'] . ') — ningún producto tiene ordre > 0 en esta subfamilia';
        $stmt = $pdo->prepare('SELECT code, name' . ($hasOrdre ? ', ordre' : '') . ' FROM products WHERE line_name = ? AND family = ? ORDER BY ' . ($hasOrdre ? 'CASE WHEN ordre > 0 THEN ordre ELSE 9999 END, ' : '') . 'name LIMIT 20');
        $stmt->execute([$row['line_name'], $row['family']]);
        $sampleRows = $stmt->fetchAll();
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAGNÓSTICO PORTAL DANBEL — ORDRE ===\n\n";

echo "Columna 'ordre' en tabla products: " . ($hasOrdre ? "SÍ" : "NO — falta subir bootstrap.php y recargar cualquier página del portal") . "\n";
echo "Columna 'obsolete' en tabla products: " . ($hasObsolete ? "SÍ" : "NO") . "\n\n";

echo "Última importación: ";
if ($lastImport) {
    echo "{$lastImport['imported_at']}  familias={$lastImport['families']}  productos={$lastImport['products']}\n";
} else {
    echo "ninguna registrada\n";
}

echo "\nProductos con ordre > 0: " . ($ordreCount !== null ? $ordreCount : 'N/A (columna no existe)') . "\n";

if ($sampleSubfam) {
    echo "\nSubfamilia de muestra: $sampleSubfam\n";
    echo str_pad('CÓDIGO', 22) . str_pad('ORDRE', 8) . "NOMBRE\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($sampleRows as $r) {
        $ordreVal = isset($r['ordre']) ? $r['ordre'] : '—';
        echo str_pad($r['code'], 22) . str_pad((string) $ordreVal, 8) . $r['name'] . "\n";
    }
}
