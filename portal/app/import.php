<?php
// Carga en MySQL las familias, artículos, descuentos y fichas de cliente de las tablas DBF del ERP.
declare(strict_types=1);

require_once __DIR__ . '/dbf.php';

const ERP_TABLES = ['families' => 'TFAMILIES.DBF', 'products' => 'TMATPR.DBF', 'discounts' => 'TTARIFCLI.DBF'];
/** Tablas opcionales: si no se suben, se conservan los datos ya cargados. */
const ERP_OPTIONAL_TABLES = ['customers' => 'TCLIENT.DBF', 'subfamilies' => 'TSUBFAM.DBF'];

/** Busca las tablas (sin distinguir mayúsculas) en una carpeta. */
function find_erp_tables(string $dir): array
{
    $found = [];
    foreach (scandir($dir) ?: [] as $entry) {
        foreach (ERP_TABLES + ERP_OPTIONAL_TABLES as $key => $name) {
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
        // Activas (ACTIU=1) y las inactivas (ACTIU=2) que pueden tener stock en liquidación.
        $code = trim((string) $r['CODI']);
        if ($code !== '' && in_array((int) $r['ACTIU'], [1, 2], true)) {
            $families[$code] = ['name' => trim((string) $r['DESCRICPIO']), 'active' => (int) $r['ACTIU'] === 1];
        }
    }

    // Mapa SFAMILIA → descripción de TSUBFAM. Si no se sube TSUBFAM, se usa NOMSFAM de tmatpr.
    $subfamilyNames = [];
    if (isset($tables['subfamilies'])) {
        foreach ((new DbfReader($tables['subfamilies']))->rows() as $r) {
            $sfCode = trim((string) $r['CODI']);
            $sfDesc = trim((string) $r['DESCRIPCIO']);
            if ($sfCode !== '' && $sfDesc !== '') {
                $subfamilyNames[$sfCode] = $sfDesc;
            }
        }
    }

    $photos = [];
    foreach (glob($imageDir . '/*') ?: [] as $file) {
        $photos[pathinfo($file, PATHINFO_FILENAME)] = 'img/products/' . basename($file);
    }

    $products = [];
    foreach ((new DbfReader($tables['products']))->rows() as $r) {
        // Artículo publicable: activo, en uso y visible. Los testers (marcados REGAL y sin precio de
        // tarifa) se venden a PVP y necesitan PVP; el resto necesita precio de tarifa.
        // Los artículos obsoletos (OBSOLET) se incluyen para la familia virtual "En liquidación".
        $tester = $r['REGAL'] === true && !$r['PREUTARIFA'];
        if (!$r['ACT'] || $r['NOUSAR'] || $r['NOVIS'] || !($tester ? $r['PVP'] : $r['PREUTARIFA'])) {
            continue;
        }
        $family = trim((string) $r['FAMILIA']);
        if (!isset($families[$family])) {
            continue;
        }
        $ean = trim((string) $r['BARRES']);
        $code = trim((string) $r['CODI']);
        // Productos de familia inactiva o con OBSOLET se marcan como obsolete=1 (van a EN LIQUIDACIÓN).
        $obsolete = (!$families[$family]['active'] || $r['OBSOLET']) ? 1 : 0;
        $ordre = (int) ($r['ORDRE'] ?? 0);
        // Nombre de subfamilia: TSUBFAM.DESCRIPCIO (si está cargado) o NOMSFAM de tmatpr como fallback.
        $sfam = trim((string) ($r['SFAMILIA'] ?? ''));
        $lineName = ($sfam !== '' && isset($subfamilyNames[$sfam]))
            ? $subfamilyNames[$sfam]
            : (trim((string) $r['NOMSFAM']) ?: null);
        $products[$code] = [
            $code,
            $family,
            $lineName,
            trim((string) $r['DESCWEB']) ?: trim((string) $r['DESCRIPCIO']),
            $ean ?: null,
            $r['PVP'] ? round($r['PVP'], 2) : null,
            round((float) $r['PREUTARIFA'], 2),
            $ean !== '' ? ($photos[$ean] ?? null) : null,
            $tester ? 1 : 0,
            $obsolete,
            $ordre,
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
        if (isset($tables['customers'])) {
            $db->exec('DELETE FROM customers');
            insert_rows($db, 'customers', ['code', 'fiscal_name', 'trade_name', 'address', 'postcode', 'city', 'province', 'nif', 'phone', 'email', 'account', 'vat', 'req', 'sales_rep', 'dto1', 'dto2'], read_customers($tables['customers']));
        }
        insert_rows($db, 'families', ['code', 'name'], array_map(fn($code, $f) => [$code, $f['name']], array_keys($families), array_values($families)));
        insert_rows($db, 'products', ['code', 'family', 'line_name', 'name', 'ean', 'pvp', 'base_price', 'image', 'tester', 'obsolete', 'ordre'], array_values($products));
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

/** Fichas de cliente de TCLIENT.DBF. La cuenta es IBAN + COMPTA1..4. */
function read_customers(string $path): array
{
    $text = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
    $rows = [];
    foreach ((new DbfReader($path))->rows() as $r) {
        if ($r['CODI'] === null) {
            continue;
        }
        $account = implode(' ', array_filter(array_map(fn ($k) => trim((string) $r[$k]), ['IBAN', 'COMPTA1', 'COMPTA2', 'COMPTA3', 'COMPTA4'])));
        $rows[(int) $r['CODI']] = [
            (int) $r['CODI'], $text($r['NOMFIS']), $text($r['NOMCOM']), $text($r['ADRESA']), $text($r['CODIPOS']),
            $text($r['POBLACIO']), $text($r['PROVINCIA']), $text($r['NIF']), $text($r['TELEF1']), $text($r['EMAIL']),
            $account ?: null, $r['IVA'], $r['REQ'], $text($r['NOMCOME']), (float) ($r['DTO1'] ?? 0), (float) ($r['DTO2'] ?? 0),
        ];
    }
    return array_values($rows);
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
