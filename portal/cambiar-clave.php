<?php
// Cambio de contraseña con la sesión iniciada. Obligatorio tras una contraseña provisional.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/account.php';

$user = require_user();
$forced = (int) $user['must_change'] === 1;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $password = (string) ($_POST['password'] ?? '');
    if (!password_verify((string) ($_POST['current'] ?? ''), (string) $stmt->fetchColumn())) {
        $error = 'La contraseña actual no es correcta.';
    } else {
        $error = password_problem($password, (string) ($_POST['password2'] ?? ''));
        if ($error === null) {
            db()->prepare('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            redirect('index.php');
        }
    }
}

page_start('Cambiar contraseña · Danbel');
?>
<main class="access-state">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <h1>Cambiar contraseña</h1>
  <?php if ($forced): ?><p>Estás usando una contraseña provisional. Elige una tuya para continuar.</p><?php endif ?>
  <form method="post" class="login-form">
    <?= csrf_field() ?>
    <input type="hidden" name="username" autocomplete="username" value="<?= e($user['login']) ?>">
    <label for="current"><?= $forced ? 'Contraseña provisional' : 'Contraseña actual' ?></label>
    <input class="input" id="current" name="current" type="password" required autocomplete="current-password" autofocus>
    <label for="password">Nueva contraseña (mínimo 8 caracteres)</label>
    <input class="input" id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
    <label for="password2">Repite la contraseña</label>
    <input class="input" id="password2" name="password2" type="password" minlength="8" required autocomplete="new-password">
    <?php if ($error): ?><p class="login-error" role="alert"><?= e($error) ?></p><?php endif ?>
    <button type="submit" class="btn">Guardar contraseña</button>
  </form>
  <?php if (!$forced): ?><p class="login-help"><a href="index.php">Volver al catálogo</a></p><?php endif ?>
</main>
<?php
page_end();
