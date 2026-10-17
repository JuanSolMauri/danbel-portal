<?php
// Catálogo por cliente: solo las familias con descuento, precio neto = tarifa menos el % de la familia.
declare(strict_types=1);

function brand_info(): array
{
    static $brands = null;
    if ($brands === null) {
        $brands = json_decode((string) file_get_contents(APP_DIR . '/brands.json'), true) ?: [];
    }
    return $brands;
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
    $rows = db()->query('SELECT f.code, f.name, COUNT(*) AS count FROM families f JOIN products p ON p.family = f.code GROUP BY f.code, f.name ORDER BY f.name')->fetchAll();
    $discounts = $customer !== null ? customer_discounts($customer) : null;
    $out = [];
    foreach ($rows as $row) {
        $discount = $discounts === null ? null : ($discounts[$row['code']] ?? 0.0);
        if ($discounts !== null && $discount <= 0) {
            continue;
        }
        $out[] = ['code' => $row['code'], 'name' => $row['name'], 'count' => (int) $row['count'], 'discount' => $discount];
    }
    return $out;
}

/** Artículos de una familia con su precio neto, agrupados en secciones. */
function family_catalog(string $family, float $discount): array
{
    $stmt = db()->prepare('SELECT code, line_name, name, ean, pvp, base_price, image FROM products WHERE family = ? ORDER BY line_name, name');
    $stmt->execute([$family]);
    $products = [];
    foreach ($stmt->fetchAll() as $p) {
        $base = (float) $p['base_price'];
        $products[$p['code']] = [
            'code' => $p['code'],
            'line' => $p['line_name'],
            'name' => $p['name'],
            'ean' => $p['ean'],
            'pvp' => $p['pvp'] !== null ? (float) $p['pvp'] : null,
            'base' => $base,
            'net' => round($base * (1 - $discount / 100) + PHP_FLOAT_EPSILON, 2),
            'image' => $p['image'],
        ];
    }

    $brand = brand_info()[$family] ?? ['logo' => null, 'sections' => []];
    $sections = [];
    $placed = [];
    // Primero las secciones preparadas para la marca (con sus fotos), luego el resto por subfamilia del ERP.
    foreach ($brand['sections'] as $section) {
        $codes = array_values(array_filter($section['codes'], fn ($c) => isset($products[$c])));
        if ($codes) {
            $sections[] = ['name' => $section['name'], 'images' => $section['images'], 'codes' => $codes];
            foreach ($codes as $c) {
                $placed[$c] = true;
            }
        }
    }
    $byLine = [];
    foreach ($products as $code => $p) {
        if (!isset($placed[$code])) {
            $byLine[$p['line'] ?? ($sections ? 'Otras referencias' : 'Referencias')][] = $code;
        }
    }
    foreach ($byLine as $name => $codes) {
        $sections[] = ['name' => $name, 'images' => [], 'codes' => $codes];
    }

    return ['logo' => $brand['logo'], 'products' => $products, 'sections' => $sections];
}
