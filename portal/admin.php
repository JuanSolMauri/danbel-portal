<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/import.php';

$user = require_admin();
$access = resolve_access($user);
$message = null;
$error = null;

/** Guarda los ficheros subidos (DBF sueltos o un ZIP con ellos) en una carpeta temporal. */
function receive_uploads(string $dir): void
{
    $files = $_FILES['tablas'] ?? null;
    if (!$files || !is_array($files['name'])) {
        throw new RuntimeException('No se ha recibido ningún fichero.');
    }
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("No se pudo subir $name (código {$files['error'][$i]}). Puede que sea más grande de lo que permite el hosting: prueba a subirlo en un ZIP.");
        }
        $base = basename($name);
        if (preg_match('/\.zip$/i', $base)) {
            $zip = new ZipArchive();
            if ($zip->open($files['tmp_name'][$i]) !== true) {
                throw new RuntimeException("No se puede abrir $base");
            }
            for ($j = 0; $j < $zip->numFiles; $j++) {
                $entry = basename((string) $zip->getNameIndex($j));
                if (preg_match('/\.dbf$/i', $entry)) {
                    $in = $zip->getStream((string) $zip->getNameIndex($j));
                    $out = fopen("$dir/$entry", 'wb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                }
            }
            $zip->close();
        } elseif (preg_match('/\.dbf$/i', $base)) {
            move_uploaded_file($files['tmp_name'][$i], "$dir/$base");
        }
    }
}

/** Convierte la letra(s) de columna Excel en índice 0-based (A→0, B→1, Z→25, AA→26…). */
function col_to_index(string $col): int
{
    $idx = 0;
    foreach (str_split(strtoupper($col)) as $ch) {
        $idx = $idx * 26 + (ord($ch) - 64);
    }
    return $idx - 1;
}

/**
 * Lee un fichero de stock (.xlsx o .csv).
 * Devuelve [$key_type, $data] donde $key_type es 'code' (columna CODI) o 'ean' (columna EAN).
 * Si hay CODI tiene prioridad sobre EAN.
 */
function read_stock_file(string $path, string $filename): array
{
    if (preg_match('/\.csv$/i', $filename)) {
        return read_stock_csv($path);
    }
    if (preg_match('/\.xlsx$/i', $filename)) {
        return read_stock_xlsx($path);
    }
    throw new RuntimeException('El fichero debe ser .xlsx o .csv.');
}

/** @return array{0:'code'|'ean', 1:array<string,int>} */
function read_stock_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('No se puede abrir el CSV.');
    }
    $raw = fgets($handle);
    rewind($handle);
    $sep = substr_count((string) $raw, ';') >= substr_count((string) $raw, ',') ? ';' : ',';
    $codiCol = $stockCol = $eanCol = null;
    $result = [];
    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        if ($codiCol === null && $eanCol === null) {
            foreach ($row as $i => $v) {
                $u = strtoupper(trim((string) $v));
                if ($u === 'CODI') $codiCol = $i;
                if ($u === 'EAN' || $u === 'BARRES') $eanCol = $i;
                if ($u === 'STOCK') $stockCol = $i;
            }
            if ($stockCol === null || ($codiCol === null && $eanCol === null)) {
                throw new RuntimeException('El CSV necesita la columna STOCK y al menos CODI o EAN.');
            }
            continue;
        }
        $keyCol = $codiCol ?? $eanCol;
        $key = trim($row[$keyCol] ?? '');
        if ($key !== '') {
            $result[$key] = (int) ($row[$stockCol] ?? 0);
        }
    }
    fclose($handle);
    return [$codiCol !== null ? 'code' : 'ean', $result];
}

/** @return array{0:'code'|'ean', 1:array<string,int>} */
function read_stock_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se puede abrir el Excel.');
    }
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssXml = (string) preg_replace('/\s+\w+:\w+(?:="[^"]*")?/', '', $ssXml);
        $ssXml = (string) preg_replace('/\s+xmlns="[^"]*"/', '', $ssXml);
        $ss = new SimpleXMLElement($ssXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($ss->si as $si) {
            $text = '';
            foreach ($si->xpath('.//t') as $t) {
                $text .= (string) $t;
            }
            $strings[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('No se encuentra la hoja del Excel.');
    }
    // Elimina namespaces y atributos prefijados para que SimpleXMLElement pueda parsear el XML.
    $sheetXml = (string) preg_replace('/\s+\w+:\w+(?:="[^"]*")?/', '', $sheetXml);
    $sheetXml = (string) preg_replace('/\s+xmlns="[^"]*"/', '', $sheetXml);
    $sheet = new SimpleXMLElement($sheetXml, LIBXML_NOERROR | LIBXML_NOWARNING);
    $codiCol = $stockCol = $eanCol = null;
    $result = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            preg_match('/^([A-Z]+)/', (string) $cell['r'], $m);
            $idx = col_to_index($m[1]);
            $type = (string) $cell['t'];
            $val = (string) $cell->v;
            if ($type === 's') {
                $val = $strings[(int) $val] ?? '';
            }
            $cells[$idx] = $val;
        }
        if ($codiCol === null && $eanCol === null) {
            foreach ($cells as $idx => $v) {
                $u = strtoupper(trim($v));
                if ($u === 'CODI') $codiCol = $idx;
                if ($u === 'EAN' || $u === 'BARRES') $eanCol = $idx;
                if ($u === 'STOCK') $stockCol = $idx;
            }
            if ($stockCol !== null && ($codiCol !== null || $eanCol !== null)) {
                continue;
            }
            continue;
        }
        $keyCol = $codiCol ?? $eanCol;
        $key = trim($cells[$keyCol] ?? '');
        if ($key !== '') {
            $result[$key] = (int) ($cells[$stockCol] ?? 0);
        }
    }
    if ($codiCol === null && $eanCol === null) {
        throw new RuntimeException('No se encontraron las columnas CODI (o EAN) y STOCK en el Excel.');
    }
    return [$codiCol !== null ? 'code' : 'ean', $result];
}

function remove_dir(string $dir): void
{
    foreach (glob("$dir/*") ?: [] as $file) {
        unlink($file);
    }
    @rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'import') {
            $dir = sys_get_temp_dir() . '/danbel_' . bin2hex(random_bytes(8));
            mkdir($dir, 0700);
            try {
                receive_uploads($dir);
                $tables = find_erp_tables($dir);
                $missing = array_diff_key(ERP_TABLES, $tables);
                if ($missing) {
                    throw new RuntimeException('Faltan las tablas: ' . implode(', ', $missing) . '.');
                }
                $r = import_erp(db(), $tables, __DIR__ . '/img/products');
                $message = "Datos actualizados: {$r['families']} familias, {$r['products']} artículos y {$r['customers']} clientes con descuento.";
            } finally {
                remove_dir($dir);
            }
        } elseif ($action === 'save_user') {
            $login = strtolower(trim((string) ($_POST['login'] ?? '')));
            $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'customer';
            $code = trim((string) ($_POST['customer_code'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($login === '' || strlen($password) < 8) {
                throw new RuntimeException('Indica el usuario y una contraseña de al menos 8 caracteres.');
            }
            if ($role === 'customer' && !preg_match('/^\d{1,6}$/', $code)) {
                throw new RuntimeException('Indica el código de cliente (solo números).');
            }
            db()->prepare('INSERT INTO users (login, role, customer_code, password_hash) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = VALUES(role), customer_code = VALUES(customer_code), password_hash = VALUES(password_hash)')
                ->execute([$login, $role, $role === 'customer' ? (int) $code : null, password_hash($password, PASSWORD_DEFAULT)]);
            $message = "Cuenta $login guardada.";
        } elseif ($action === 'upload_stock') {
            $file = $_FILES['stock'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new RuntimeException("No se pudo recibir el fichero (código $code).");
            }
            [$keyType, $data] = read_stock_file($file['tmp_name'], (string) $file['name']);
            if (!$data) {
                throw new RuntimeException('No se encontraron artículos en el fichero.');
            }
            // Si las claves son EAN, resuelve el código de artículo correspondiente.
            if ($keyType === 'ean') {
                $eans = array_keys($data);
                $ph = implode(',', array_fill(0, count($eans), '?'));
                $s = db()->prepare("SELECT ean, code FROM products WHERE ean IN ($ph)");
                $s->execute($eans);
                $byEan = $s->fetchAll(PDO::FETCH_KEY_PAIR);
                $resolved = [];
                foreach ($data as $ean => $qty) {
                    if (isset($byEan[$ean])) {
                        $resolved[$byEan[$ean]] = $qty;
                    }
                }
                $data = $resolved;
            }
            if (!$data) {
                throw new RuntimeException('Ningún EAN del fichero coincide con artículos del catálogo.');
            }
            // Crea la tabla si todavía no existe (por si el portal se instaló antes de esta versión).
            db()->exec("CREATE TABLE IF NOT EXISTS stock (
                code VARCHAR(20) NOT NULL PRIMARY KEY,
                qty INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $stmt = db()->prepare('INSERT INTO stock (code, qty) VALUES (?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = CURRENT_TIMESTAMP');
            db()->beginTransaction();
            try {
                foreach ($data as $code => $qty) {
                    $stmt->execute([$code, $qty]);
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            $message = 'Stock actualizado: ' . count($data) . ' artículos.';
        } elseif ($action === 'upload_photos') {
            $file = $_FILES['fotos'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new RuntimeException("No se pudo recibir el fichero (código $code). Comprueba que no supera el límite de subida del hosting.");
            }
            if (!preg_match('/\.zip$/i', (string) $file['name'])) {
                throw new RuntimeException('El fichero debe ser un ZIP.');
            }
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                throw new RuntimeException('No se puede abrir el ZIP.');
            }
            $imageDir = __DIR__ . '/img/products';
            $eanSet = array_flip(db()->query('SELECT ean FROM products WHERE ean IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
            $useGd = extension_loaded('gd');
            $linked = [];
            $unknown = [];
            $errors = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zipName = (string) $zip->getNameIndex($i);
                $base = basename($zipName);
                if (!preg_match('/\.(jpe?g|png|webp)$/i', $base, $m)) {
                    continue;
                }
                $stem = pathinfo($base, PATHINFO_FILENAME);
                $ext = strtolower($m[1] === 'jpeg' ? 'jpg' : $m[1]);
                $data = $zip->getFromIndex($i);
                if ($data === false || strlen($data) === 0) {
                    $errors[] = $base;
                    continue;
                }
                if ($useGd) {
                    $img = @imagecreatefromstring($data);
                    if ($img !== false) {
                        $w = imagesx($img);
                        $h = imagesy($img);
                        if ($w > 1200 || $h > 1200) {
                            $ratio = min(1200 / $w, 1200 / $h);
                            $nw = (int) round($w * $ratio);
                            $nh = (int) round($h * $ratio);
                            $dst = imagecreatetruecolor($nw, $nh);
                            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                            ob_start();
                            imagejpeg($dst, null, 85);
                            $data = (string) ob_get_clean();
                            imagedestroy($img);
                            imagedestroy($dst);
                            $ext = 'jpg';
                        } else {
                            imagedestroy($img);
                        }
                    }
                }
                if (file_put_contents("$imageDir/$stem.$ext", $data) === false) {
                    $errors[] = $base;
                    continue;
                }
                if (isset($eanSet[$stem])) {
                    $linked[] = $stem;
                } else {
                    $unknown[] = $stem;
                }
            }
            $zip->close();

            $parts = [];
            if ($linked) {
                $parts[] = count($linked) . ' foto' . (count($linked) !== 1 ? 's' : '') . ' vinculada' . (count($linked) !== 1 ? 's' : '') . ' a productos';
            }
            if ($unknown) {
                $preview = implode(', ', array_slice($unknown, 0, 5)) . (count($unknown) > 5 ? '…' : '');
                $parts[] = count($unknown) . ' foto' . (count($unknown) !== 1 ? 's' : '') . ' sin artículo en el ERP (' . $preview . ')';
            }
            if ($errors) {
                $parts[] = count($errors) . ' error' . (count($errors) !== 1 ? 'es' : '');
            }
            $message = $parts ? implode('; ', $parts) . '.' : 'El ZIP no contenía imágenes (jpg, png o webp).';
        } elseif ($action === 'delete_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $user['id']) {
                throw new RuntimeException('No puedes borrar tu propia cuenta.');
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $message = 'Cuenta borrada.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Error: ' . $e->getMessage();
    }
}

$lastImport = db()->query('SELECT * FROM imports ORDER BY id DESC LIMIT 1')->fetch();
$users = db()->query('SELECT id, login, role, customer_code, created_at FROM users ORDER BY role, login')->fetchAll();

page_start('Administración · Danbel');
site_header('admin', $access, $user);
?>
<main class="main customer-page">
  <div class="intro"><div><p class="eyebrow">PORTAL PROFESIONAL · DANBEL</p><h1>Administración</h1></div></div>
  <?php if ($message): ?><p class="notice notice-ok"><?= e($message) ?></p><?php endif ?>
  <?php if ($error): ?><p class="notice notice-error"><?= e($error) ?></p><?php endif ?>

  <section class="customer-panel admin-panel">
    <h2>Actualizar datos del ERP</h2>
    <p>Sube <strong>TFAMILIES.DBF</strong>, <strong>tmatpr.DBF</strong> y <strong>ttarifcli.DBF</strong>, y si quieres actualizar las fichas de cliente también <strong>tclient.DBF</strong>, sueltos o juntos en un ZIP. Se sustituyen familias, artículos, descuentos y fichas; las cuentas de acceso no se tocan.</p>
    <p class="muted">Última actualización: <?= $lastImport ? e($lastImport['imported_at']) . " · {$lastImport['families']} familias, {$lastImport['products']} artículos, {$lastImport['customers']} clientes" : 'todavía no se han cargado datos' ?>.
      Tamaño máximo de subida en este hosting: <?= e(ini_get('upload_max_filesize')) ?>.</p>
    <form method="post" enctype="multipart/form-data" class="admin-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <input type="file" name="tablas[]" multiple accept=".dbf,.DBF,.zip" required>
      <button type="submit" class="btn">Actualizar datos</button>
    </form>
  </section>

  <section class="customer-panel admin-panel">
    <h2>Actualizar stock</h2>
    <p>Sube el fichero de stock en <strong>.xlsx</strong> o <strong>.csv</strong>. Debe tener la columna <code>STOCK</code> y al menos una columna de referencia: <code>CODI</code> (código de artículo) o <code>EAN</code> (código de barras). Si hay ambas, se usa CODI. El stock se actualiza artículo a artículo; los no incluidos conservan su stock anterior.</p>
    <form method="post" enctype="multipart/form-data" class="admin-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_stock">
      <input type="file" name="stock" accept=".xlsx,.csv" required>
      <button type="submit" class="btn">Actualizar stock</button>
    </form>
  </section>

  <section class="customer-panel admin-panel">
    <h2>Subir fotos de productos</h2>
    <p>Sube un ZIP con las fotos en JPG, PNG o WebP. El nombre de cada fichero debe ser el <strong>código EAN</strong> del artículo (por ejemplo <code>4250588324807.jpg</code>). Las fotos se vinculan automáticamente al catálogo sin necesidad de reimportar los DBF. Si GD está disponible en el hosting, las imágenes de más de 1200 px se reducen al guardarlas.</p>
    <p class="muted">Tamaño máximo de subida: <?= e(ini_get('upload_max_filesize')) ?>.</p>
    <form method="post" enctype="multipart/form-data" class="admin-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="upload_photos">
      <input type="file" name="fotos" accept=".zip" required>
      <button type="submit" class="btn">Subir fotos</button>
    </form>
  </section>

  <section class="customer-panel admin-panel">
    <h2>Cuentas de acceso</h2>
    <p>Para cambiar la contraseña de una cuenta, vuelve a guardarla con el mismo usuario.</p>
    <form method="post" class="admin-form admin-user-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_user">
      <label>Usuario (email o código)<input class="input" name="login" required></label>
      <label>Tipo<select class="input" name="role"><option value="customer">Cliente</option><option value="admin">Administrador</option></select></label>
      <label>Código de cliente<input class="input" name="customer_code" inputmode="numeric" pattern="[0-9]{1,6}"></label>
      <label>Contraseña<input class="input" name="password" type="text" minlength="8" required autocomplete="new-password"></label>
      <button type="submit" class="btn">Guardar cuenta</button>
    </form>
    <div class="customer-tariff-table"><table class="admin-users">
      <thead><tr><th>Usuario</th><th>Tipo</th><th>Cliente</th><th>Alta</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr><td><?= e($u['login']) ?></td><td><?= $u['role'] === 'admin' ? 'Administrador' : 'Cliente' ?></td><td><?= e($u['customer_code'] ?? '—') ?></td><td><?= e(substr((string) $u['created_at'], 0, 10)) ?></td>
          <td><?php if ((int) $u['id'] !== (int) $user['id']): ?><form method="post" onsubmit="return confirm('¿Borrar esta cuenta?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="btn btn-outline btn-sm" type="submit">Borrar</button></form><?php endif ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  </section>
</main>
<?php
page_end();
