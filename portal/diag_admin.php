<?php
// Diagnóstico temporal — borrar del servidor cuando ya no se necesite.
ini_set('display_errors', '1');
error_reporting(E_ALL);
echo '<pre style="font:13px monospace;padding:1rem">';
echo 'PHP ' . PHP_VERSION . "\n\n";

$steps = [
    'bootstrap.php' => __DIR__ . '/app/bootstrap.php',
    'import.php'    => __DIR__ . '/app/import.php',
    'admin.php (parcial)' => null,
];

foreach ($steps as $label => $path) {
    if (!$path) break;
    if (!file_exists($path)) {
        echo "❌ $label — FICHERO NO ENCONTRADO en $path\n";
        continue;
    }
    try {
        require_once $path;
        echo "✓  $label OK\n";
    } catch (Throwable $e) {
        echo "❌ $label — " . $e->getMessage() . " (línea " . $e->getLine() . " de " . $e->getFile() . ")\n";
    }
}

echo "\n— Conexión ERP —\n";
if (!function_exists('db_erp')) {
    echo "❌ db_erp() no está definida (bootstrap.php no se cargó o es la versión antigua)\n";
} elseif (!config('erp_db')) {
    echo "⚠  erp_db no está en config.php\n";
} else {
    $erp = db_erp();
    if ($erp) {
        echo "✓  Conexión al ERP OK\n";
        try {
            $n = $erp->query('SELECT COUNT(*) FROM TFAMILIES')->fetchColumn();
            echo "✓  TFAMILIES: $n filas\n";
        } catch (Throwable $e) {
            echo "❌ TFAMILIES: " . $e->getMessage() . "\n";
        }
    } else {
        $err = $GLOBALS['_db_erp_error'] ?? 'sin detalle';
        echo "❌ No se pudo conectar al ERP: $err\n";
    }
}

echo '</pre>';
