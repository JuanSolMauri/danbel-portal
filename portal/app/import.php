<?php
// Carga en MySQL las familias, artículos y descuentos de las tablas DBF del ERP.
declare(strict_types=1);

require_once __DIR__ . '/dbf.php';

const ERP_TABLES = ['families' => 'TFAMILIES.DBF', 'products' => 'TMATPR.DBF', 'discounts' => 'TTARIFCLI.DBF'];

/** Busca las tres tablas (sin distinguir mayúsculas) en una carpeta. */
function find_erp_tables(string $dir): array
{
    $found = [];
    foreach (scandir($dir) ?: [] as $entry) {
        foreach (ERP_TABLES as $key => $name) {
            if (strtoupper($entry) === $name) {
                $found[$key] = $dir . '/' . $entry;
            }
        }
    }
    return $found;
}

/**
 * Sustituye los datos del ERP por los de las tablas indicadas. Todo se hace en una transacción:
 * si algo falla, el portal sigue con los datos anteriores.
 *
 * @param array{families:string,products:string,discounts:string} $tables
 * @return array{families:int,products:int,customers:int}
 */
function import_erp(PDO $db, array $tables, string $imageDir): array
{
    @set_time_limit(600);

    $families = [];
    foreach ((new DbfReader($tables['families']))->rows() as $r) {
        // Solo las familias activas en el ERP (ACTIU = 1; 2 es inactiva).
        $code = trim((string) $r['CODI']);
        if ($code !== '' && (int) $r['ACTIU'] === 1) {
            $families[$code] = trim((string) $r['DESCRICPIO']);
        }
    }

    $photos = [];
    foreach (glob($imageDir . '/*') ?: [] as $file) {
        $photos[pathinfo($file, PATHINFO_FILENAME)] = 'img/products/' . basename($file);
    }

    $products = [];
    foreach ((new DbfReader($tables['products']))->rows() as $r) {
        // Artículo publicable: activo, no obsoleto, en uso y visible. Los testers (marcados REGAL y sin
        // precio de tarifa) se venden a PVP y necesitan PVP; el resto necesita precio de tarifa.
        $tester = $r['REGAL'] === true && !$r['PREUTARIFA'];
        if (!$r['ACT'] || $r['OBSOLET'] || $r['NOUSAR'] || $r['NOVIS'] || !($tester ? $r['PVP'] : $r['PREUTARIFA'])) {
            continue;
        }
        $family = trim((string) $r['FAMILIA']);
        if (!isset($families[$family])) {
            continue;
        }
        $ean = trim((string) $r['BARRES']);
        $code = trim((string) $r['CODI']);
        $products[$code] = [
            $code,
            $family,
            trim((string) $r['NOMSFAM']) ?: null,
            trim((string) $r['DESCWEB']) ?: trim((string) $r['DESCRIPCIO']),
            $ean ?: null,
            $r['PVP'] ? round($r['PVP'], 2) : null,
            round((float) $r['PREUTARIFA'], 2),
            $ean !== '' ? ($photos[$ean] ?? null) : null,
            $tester ? 1 : 0,
        ];
    }

    // Descuentos por familia. Las filas con MATERIAL son descuentos por artículo y de momento no se usan.
    // Si una pareja cliente-familia se repite, manda la última (mayor NUMERO).
    $discounts = [];
    $order = [];
    foreach ((new DbfReader($tables['discounts']))->rows() as $r) {
        $family = trim((string) $r['FAMILIA']);
        if ($family === '' || trim((string) $r['MATERIAL']) !== '' || $r['CLIENT'] === null) {
            continue;
        }
        $key = (int) $r['CLIENT'] . '|' . $family;
        $numero = (float) ($r['NUMERO'] ?? 0);
        if (isset($order[$key]) && $order[$key] > $numero) {
            continue;
        }
        $order[$key] = $numero;
        $discounts[$key] = (float) ($r['DTO'] ?? 0);
    }
    $discounts = array_filter($discounts, fn ($dto) => $dto > 0);
    unset($order);

    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM families');
        $db->exec('DELETE FROM products');
        $db->exec('DELETE FROM discounts');
        insert_rows($db, 'families', ['code', 'name'], array_map(null, array_keys($families), array_values($families)));
        insert_rows($db, 'products', ['code', 'family', 'line_name', 'name', 'ean', 'pvp', 'base_price', 'image', 'tester'], array_values($products));
        $rows = [];
        foreach ($discounts as $key => $dto) {
            [$customer, $family] = explode('|', $key, 2);
            $rows[] = [(int) $customer, $family, $dto];
        }
        insert_rows($db, 'discounts', ['customer_code', 'family', 'dto'], $rows);
        $customers = count(array_unique(array_column($rows, 0)));
        $db->prepare('INSERT INTO imports (families, products, customers) VALUES (?, ?, ?)')
            ->execute([count($families), count($products), $customers]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    return ['families' => count($families), 'products' => count($products), 'customers' => $customers];
}

function insert_rows(PDO $db, string $table, array $columns, array $rows, int $batch = 500): void
{
    $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    foreach (array_chunk($rows, $batch) as $chunk) {
        $sql = "INSERT INTO $table (" . implode(',', $columns) . ') VALUES '
            . implode(',', array_fill(0, count($chunk), $placeholders));
        $db->prepare($sql)->execute(array_merge(...$chunk));
    }
}
