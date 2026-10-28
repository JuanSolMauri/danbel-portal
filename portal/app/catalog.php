<?php
// Catálogo por cliente: solo las familias con descuento, precio neto = tarifa menos el % de la familia.
// Los testers no llevan descuento: se cobran a PVP.
declare(strict_types=1);

function brand_info(): array
{
    static $brands = null;
    if ($brands === null) {
        $brands = json_decode((string) file_get_contents(APP_DIR . '/brands.json'), true) ?: [];
    }
    return $brands;
}

/** Ficha del cliente cargada de TCLIENT.DBF, o null si no está. */
function customer_record(string $customer): ?array
{
    $stmt = db()->prepare('SELECT * FROM customers WHERE code = ?');
    $stmt->execute([(int) $customer]);
    return $stmt->fetch() ?: null;
}

/** @return array<string, float> familia => % de descuento */
function customer_discounts(string $customer): array
{
    $stmt = db()->prepare('SELECT family, dto FROM discounts WHERE customer_code = ?');
    $stmt->execute([(int) $customer]);
    return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Familias visibles con su número de artículos. Sin cliente (vista de administrador) se muestran todas.
 * @return array<int, array{code:string,name:string,count:int,discount:?float}>
 */
function visible_families(?string $customer): array
{
    $rows = db()->query('SELECT f.code, f.name, COUNT(*) AS count FROM families f JOIN products p ON p.family = f.code WHERE p.obsolete = 0 GROUP BY f.code, f.name ORDER BY f.name')->fetchAll();
    $discounts = $customer !== null ? customer_discounts($customer) : null;
    $out = [];
    foreach ($rows as $row) {
        $discount = $discounts === null ? null : ($discounts[$row['code']] ?? 0.0);
        if ($discounts !== null && $discount <= 0) {
            continue;
        }
        $out[] = ['code' => $row['code'], 'name' => $row['name'], 'count' => (int) $row['count'], 'discount' => $discount];
    }

    // Familia virtual: artículos obsoletos con stock disponible.
    $liqCount = (int) db()->query('SELECT COUNT(*) FROM products p JOIN stock s ON s.code = p.code WHERE p.obsolete = 1 AND s.qty > 0')->fetchColumn();
    if ($liqCount > 0) {
        $out[] = ['code' => '_LIQUIDACION', 'name' => 'EN LIQUIDACIÓN', 'count' => $liqCount, 'discount' => null];
    }

    return $out;
}

/** Artículos de la familia "En liquidación": obsoletos con stock, usando el descuento del cliente por familia. */
function liquidation_catalog(?string $customer): array
{
    $discounts = $customer !== null ? customer_discounts($customer) : [];
    $stmt = db()->query(
        'SELECT p.code, p.line_name, p.name, p.ean, p.pvp, p.base_price, p.tester, p.family,
                f.name AS family_name, COALESCE(s.qty, 0) AS stock
         FROM products p
         JOIN families f ON f.code = p.family
         JOIN stock s ON s.code = p.code
         WHERE p.obsolete = 1 AND s.qty > 0
         ORDER BY f.name, CASE WHEN p.ordre > 0 THEN p.ordre ELSE 9999 END, p.name'
    );
    $photos = [];
    foreach (glob(__DIR__ . '/../img/products/*') ?: [] as $file) {
        $photos[pathinfo($file, PATHINFO_FILENAME)] = 'img/products/' . basename($file);
    }
    $products = [];
    $byFamily = [];
    foreach ($stmt->fetchAll() as $p) {
        $base = (float) $p['base_price'];
        $tester = (bool) $p['tester'];
        $ean = $p['ean'] ?? '';
        $discount = $discounts[$p['family']] ?? 0.0;
        $products[$p['code']] = [
            'code'   => $p['code'],
            'line'   => $p['line_name'],
            'name'   => $p['name'],
            'ean'    => $p['ean'],
            'pvp'    => $p['pvp'] !== null ? (float) $p['pvp'] : null,
            'base'   => $base,
            'net'    => $tester ? (float) $p['pvp'] : round($base * (1 - $discount / 100) + PHP_FLOAT_EPSILON, 2),
            'image'  => $ean !== '' ? ($photos[$ean] ?? null) : null,
            'stock'  => (int) $p['stock'],
            'tester' => $tester,
        ];
        $byFamily[$p['family']] ??= ['name' => $p['family_name'], 'codes' => []];
        $byFamily[$p['family']]['codes'][] = $p['code'];
    }
    $sections = [];
    foreach ($byFamily as $fd) {
        $sections[] = ['name' => $fd['name'], 'images' => [], 'codes' => $fd['codes']];
    }
    return ['logo' => null, 'products' => $products, 'sections' => $sections];
}

/** Artículos de una familia con su precio neto, agrupados en secciones. */
function family_catalog(string $family, float $discount): array
{
    $stmt = db()->prepare('SELECT p.code, p.line_name, p.name, p.ean, p.pvp, p.base_price, p.tester, COALESCE(s.qty, 0) AS stock FROM products p LEFT JOIN stock s ON s.code = p.code WHERE p.family = ? AND p.obsolete = 0 ORDER BY p.line_name, CASE WHEN p.ordre > 0 THEN p.ordre ELSE 9999 END, p.name');
    $stmt->execute([$family]);

    // Escanea img/products/ una sola vez para mapear EAN → ruta relativa.
    $photos = [];
    foreach (glob(__DIR__ . '/../img/products/*') ?: [] as $file) {
        $photos[pathinfo($file, PATHINFO_FILENAME)] = 'img/products/' . basename($file);
    }

    $products = [];
    foreach ($stmt->fetchAll() as $p) {
        $base = (float) $p['base_price'];
        $tester = (bool) $p['tester'];
        $ean = $p['ean'] ?? '';
        $products[$p['code']] = [
            'code' => $p['code'],
            'line' => $p['line_name'],
            'name' => $p['name'],
            'ean' => $p['ean'],
            'pvp' => $p['pvp'] !== null ? (float) $p['pvp'] : null,
            'base' => $base,
            'net' => $tester ? (float) $p['pvp'] : round($base * (1 - $discount / 100) + PHP_FLOAT_EPSILON, 2),
            'image' => $ean !== '' ? ($photos[$ean] ?? null) : null,
            'stock' => (int) $p['stock'],
            'tester' => $tester,
        ];
    }

    $brand = brand_info()[$family] ?? ['logo' => null, 'sections' => []];
    $sections = [];
    $placed = [];
    $lineSection = [];
    // Primero las secciones preparadas para la marca (con sus fotos), luego el resto por subfamilia del ERP.
    foreach ($brand['sections'] as $section) {
        $codes = array_values(array_filter($section['codes'], fn ($c) => isset($products[$c])));
        if ($codes) {
            $sections[] = ['name' => $section['name'], 'images' => $section['images'], 'codes' => $codes];
            foreach ($codes as $c) {
                $placed[$c] = true;
                if ($products[$c]['line'] !== null) {
                    $lineSection[$products[$c]['line']] ??= count($sections) - 1;
                }
            }
        }
    }
    $byLine = [];
    foreach ($products as $code => $p) {
        if (isset($placed[$code])) {
            continue;
        }
        // Los artículos que no están en una sección preparada (p. ej. los testers) van con su subfamilia.
        if ($p['line'] !== null && isset($lineSection[$p['line']])) {
            $sections[$lineSection[$p['line']]]['codes'][] = $code;
        } else {
            $byLine[$p['line'] ?? ($sections ? 'Otras referencias' : 'Referencias')][] = $code;
        }
    }
    foreach ($byLine as $name => $codes) {
        $sections[] = ['name' => $name, 'images' => [], 'codes' => $codes];
    }
    // En cada subfamilia, primero los productos y al final los testers (sin cambiar el orden entre ellos).
    foreach ($sections as &$section) {
        $section['codes'] = array_merge(
            array_values(array_filter($section['codes'], fn ($c) => !$products[$c]['tester'])),
            array_values(array_filter($section['codes'], fn ($c) => $products[$c]['tester'])),
        );
    }
    unset($section);

    return ['logo' => $brand['logo'], 'products' => $products, 'sections' => $sections];
}
