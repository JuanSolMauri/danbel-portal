<?php
// Diagnóstico temporal: valores NOMSFAM (line_name) en la BD para familia BIRK.
// Borrar este fichero del servidor cuando ya no se necesite.
require __DIR__ . '/app/bootstrap.php';
$rows = db()->query("SELECT DISTINCT line_name FROM products WHERE family = 'BIRK' ORDER BY line_name")->fetchAll(PDO::FETCH_COLUMN);
echo "<pre>";
foreach ($rows as $r) echo htmlspecialchars((string)$r) . "\n";
echo "</pre>";
