<?php
// Diagnóstico temporal: columnas de las tablas ERP en una BD MySQL externa.
// Protegido por install_token de config.php. Borrar del servidor cuando ya no se necesite.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

// Protección: solo accesible con el install_token de config.php.
$token = (string) ($_REQUEST['token'] ?? '');
if ($token === '' || !hash_equals((string) config('install_token', ''), $token)) {
    http_response_code(403);
    exit('Token requerido. Añade ?token=TU_INSTALL_TOKEN a la URL.');
}

$host = (string) ($_POST['host'] ?? 'qarx575.danbelgroup.com');
$dbname = (string) ($_POST['dbname'] ?? 'qarx575');
$user = (string) ($_POST['user'] ?? 'qarx575');
$pass = (string) ($_POST['pass'] ?? '');

// Campos que el portal necesita de cada tabla ERP.
$needed = [
    'TFAMILIES' => ['CODI', 'ACTIU', 'DESCRICPIO'],
    'TMATPR'    => ['CODI', 'FAMILIA', 'SFAMILIA', 'NOMSFAM', 'DESCWEB', 'DESCRIPCIO', 'BARRES', 'PVP', 'PREUTARIFA', 'ACT', 'NOUSAR', 'NOVIS', 'REGAL', 'OBSOLET', 'ORDRE'],
    'TSUBFAM'   => ['CODI', 'DESCRIPCIO'],
    'TTARIFCLI' => ['FAMILIA', 'MATERIAL', 'CLIENT', 'NUMERO', 'DTO'],
    'TCLIENT'   => ['CODI', 'NOMFIS', 'NOMCOM', 'ADRESA', 'CODIPOS', 'POBLACIO', 'PROVINCIA', 'NIF', 'TELEF1', 'EMAIL', 'IBAN', 'COMPTA1', 'COMPTA2', 'COMPTA3', 'COMPTA4', 'IVA', 'REQ', 'NOMCOME', 'DTO1', 'DTO2'],
];

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pass !== '') {
    try {
        $erp = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $result = [];
        foreach ($needed as $table => $neededCols) {
            $stmt = $erp->query("SHOW COLUMNS FROM `$table`");
            $cols = $stmt ? $stmt->fetchAll() : [];
            $found = array_map('strtoupper', array_column($cols, 'Field'));

            $missing = [];
            $ok = [];
            foreach ($neededCols as $col) {
                if (in_array(strtoupper($col), $found, true)) {
                    $ok[] = $col;
                } else {
                    $missing[] = $col;
                }
            }
            $extra = array_filter($found, fn($c) => !in_array($c, array_map('strtoupper', $neededCols), true));
            $count = (int) $erp->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $result[$table] = compact('ok', 'missing', 'extra', 'count', 'cols');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<meta charset="utf-8">
<title>Diagnóstico ERP MySQL</title>
<style>
body{font-family:system-ui,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#222}
h1{font-size:1.3rem;margin-bottom:.3rem}
.sub{color:#666;font-size:.85rem;margin-bottom:1.5rem}
form{background:#f5f5f5;padding:1rem 1.2rem;border-radius:8px;margin-bottom:2rem;display:grid;gap:.6rem}
label{font-size:.85rem;color:#444}
input{width:100%;padding:.4rem .6rem;border:1px solid #ccc;border-radius:4px;font-size:.9rem;box-sizing:border-box}
button{padding:.5rem 1.2rem;background:#2a5a2a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:.9rem;width:fit-content}
.error{background:#fde;border-left:3px solid #c00;padding:.7rem 1rem;border-radius:4px;margin-bottom:1.5rem;font-size:.9rem}
.table-section{margin-bottom:1.5rem;border:1px solid #ddd;border-radius:8px;overflow:hidden}
.table-header{background:#eee;padding:.6rem 1rem;display:flex;align-items:center;gap:.8rem;border-bottom:1px solid #ddd}
.table-header h3{margin:0;font-size:1rem}
.count{font-size:.75rem;color:#555;background:#fff;padding:2px 8px;border-radius:10px;border:1px solid #ccc}
.cols{padding:.8rem 1rem;display:flex;flex-wrap:wrap;gap:.4rem}
.col-ok{background:#d8f5d8;color:#1a5a1a;padding:3px 10px;border-radius:12px;font-size:.8rem;font-family:monospace}
.col-missing{background:#fde;color:#8a0000;padding:3px 10px;border-radius:12px;font-size:.8rem;font-family:monospace;text-decoration:line-through}
.col-extra{background:#eee;color:#555;padding:3px 10px;border-radius:12px;font-size:.8rem;font-family:monospace}
.legend{font-size:.78rem;color:#666;margin-bottom:.5rem;padding:0 1rem}
.ok-badge{background:#d8f5d8;color:#1a5a1a;padding:1px 8px;border-radius:8px;font-size:.75rem}
.warn-badge{background:#fde;color:#8a0000;padding:1px 8px;border-radius:8px;font-size:.75rem}
</style>
<h1>Diagnóstico — tablas ERP en MySQL</h1>
<p class="sub">Comprueba que las columnas de la BD ERP coinciden con lo que necesita el import del portal.</p>

<form method="post">
<input type="hidden" name="token" value="<?= h($token) ?>">
<label>Host: <input name="host" value="<?= h($host) ?>"></label>
<label>Base de datos: <input name="dbname" value="<?= h($dbname) ?>"></label>
<label>Usuario: <input name="user" value="<?= h($user) ?>"></label>
<label>Contraseña: <input name="pass" type="password" placeholder="Contraseña de <?= h($user) ?>" autocomplete="new-password"></label>
<button type="submit">Conectar y ver columnas</button>
</form>

<?php if ($error): ?>
<div class="error"><strong>Error de conexión:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
<p class="legend">
  <span class="col-ok">columna</span> encontrada &nbsp;
  <span class="col-missing">columna</span> no encontrada (el portal la necesita) &nbsp;
  <span class="col-extra">columna</span> extra en ERP (no la usa el portal)
</p>
<?php foreach ($result as $table => $info): ?>
<div class="table-section">
  <div class="table-header">
    <h3><?= h($table) ?></h3>
    <span class="count"><?= number_format($info['count']) ?> filas</span>
    <?php if (empty($info['missing'])): ?>
    <span class="ok-badge">✓ todas las columnas OK</span>
    <?php else: ?>
    <span class="warn-badge">⚠ faltan <?= count($info['missing']) ?> columna(s)</span>
    <?php endif; ?>
  </div>
  <div class="cols">
  <?php foreach ($info['ok'] as $c): ?>
    <span class="col-ok"><?= h($c) ?></span>
  <?php endforeach; ?>
  <?php foreach ($info['missing'] as $c): ?>
    <span class="col-missing"><?= h($c) ?></span>
  <?php endforeach; ?>
  <?php foreach ($info['extra'] as $c): ?>
    <span class="col-extra"><?= h($c) ?></span>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
