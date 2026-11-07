<?php
// Página del enlace del correo: el cliente elige su contraseña y entra en el portal.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/account.php';

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$reset = find_reset($token);
$error = null;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $error = password_problem($password, (string) ($_POST['password2'] ?? ''));
    if ($error === null) {
        $db = db();
        // Marca el enlace como usado (solo si nadie lo ha usado entretanto) antes de cambiar nada.
        $mark = $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL');
        $mark->execute([$reset['token_hash']]);
        if ($mark->rowCount() === 1) {
            $userId = set_customer_password((int) $reset['customer_code'], $password);
            // Los demás enlaces pendientes de este cliente dejan de servir.
            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE customer_code = ? AND used_at IS NULL')
                ->execute([(int) $reset['customer_code']]);
            log_in($userId);
            redirect('index.php');
        }
        $reset = null;
    }
}

page_start('Elige tu contraseña · Danbel');
?>
<main class="access-state">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <h1>Elige tu contraseña</h1>
  <?php if (!$reset): ?>
    <p>Este enlace no es válido, ya se ha usado o ha caducado.</p>
    <p class="login-help"><a href="acceso.php">Pedir un enlace nuevo</a></p>
  <?php else: ?>
    <p>Cliente <?= e($reset['customer_code']) ?>. Con esta contraseña y tu código de cliente podrás entrar en el portal.</p>
    <form method="post" class="login-form">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="hidden" name="username" autocomplete="username" value="<?= e($reset['customer_code']) ?>">
      <label for="password">Nueva contraseña (mínimo 8 caracteres)</label>
      <input class="input" id="password" name="password" type="password" minlength="8" required autocomplete="new-password" autofocus>
      <label for="password2">Repite la contraseña</label>
      <input class="input" id="password2" name="password2" type="password" minlength="8" required autocomplete="new-password">
      <?php if ($error): ?><p class="login-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <button type="submit" class="btn">Guardar y entrar</button>
    </form>
  <?php endif ?>
</main>
<?php
page_end();
