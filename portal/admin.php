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
