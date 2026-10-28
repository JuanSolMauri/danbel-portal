<?php
// Página de diagnóstico temporal — borrar después de usar.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_user();

$pdo = db();

$cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
$hasOrdre = in_array('ordre', $cols);

$lastImport = $pdo->query('SELECT * FROM imports ORDER BY id DESC LIMIT 1')->fetch();

$ordreCount = $hasOrdre
    ? (int) $pdo->query('SELECT COUNT(*) FROM products WHERE ordre > 0')->fetchColumn()
    : null;

// Subfamilias de Birkholz con orden actual
$birkRows = [];
if ($hasOrdre) {
    $stmt = $pdo->query(
        'SELECT code, line_name, name, ordre
         FROM products
         WHERE family = \'BIRK\' AND obsolete = 0
         ORDER BY line_name, CASE WHEN ordre > 0 THEN ordre ELSE 9999 END, name'
    );
    $birkRows = $stmt->fetchAll();
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAGNÓSTICO PORTAL DANBEL — ORDRE ===\n\n";

echo "Columna 'ordre' en tabla products: " . ($hasOrdre ? "SÍ" : "NO — sube bootstrap.php y abre cualquier página del portal para crear la columna") . "\n";

echo "Última importación: ";
if ($lastImport) {
    echo "{$lastImport['imported_at']}  familias={$lastImport['families']}  productos={$lastImport['products']}\n";
} else {
    echo "ninguna registrada\n";
}

echo "Productos con ordre > 0: " . ($ordreCount !== null ? $ordreCount : 'N/A') . "\n";

if (!$hasOrdre) {
    echo "\n→ Paso 1: sube bootstrap.php al servidor y recarga una página del portal.\n";
    echo "  Eso crea la columna 'ordre' en la BD.\n";
    echo "→ Paso 2: rellena el campo ORDRE en el ERP para los artículos de Birkholz.\n";
    echo "→ Paso 3: exporta un nuevo tmatpr.DBF y reimporta desde Administración.\n";
} elseif ($ordreCount === 0) {
    echo "\n→ La columna existe pero ningún artículo tiene ORDRE asignado.\n";
    echo "  Rellena el campo ORDRE en el ERP (para los artículos de Birkholz u otros)\n";
    echo "  y vuelve a importar desde Administración con el nuevo tmatpr.DBF.\n";
}

if ($birkRows) {
    echo "\n--- Familia BIRK (Birkholz) — orden actual ---\n";
    echo str_pad('SUBFAMILIA', 38) . str_pad('ORDRE', 8) . "CÓDIGO / NOMBRE\n";
    echo str_repeat('-', 90) . "\n";
    $lastLine = null;
    foreach ($birkRows as $r) {
        $line = $r['line_name'] ?? '(sin subfamilia)';
        if ($line !== $lastLine) {
            echo "\n[$line]\n";
            $lastLine = $line;
        }
        $ordreVal = (int) $r['ordre'] > 0 ? $r['ordre'] : '—';
        echo '  ' . str_pad((string) $ordreVal, 6) . "  {$r['code']}  {$r['name']}\n";
    }
} elseif ($hasOrdre) {
    echo "\nNo se encontraron artículos activos de la familia BIRK en la BD.\n";
    echo "(¿Se ha reimportado con el nuevo import.php?)\n";
}
