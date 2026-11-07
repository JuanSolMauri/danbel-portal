<?php
// Primer acceso y "he olvidado mi contraseña": el cliente pide un enlace al email de su ficha del ERP.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/account.php';

$sent = false;
$error = null;
$code = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $code = trim((string) ($_POST['code'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    if (too_many_attempts()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } elseif ($code === '' || $email === '') {
        $error = 'Indica tu código de cliente y tu email.';
    } else {
        request_access_link($code, $email);
        $sent = true;
    }
}

page_start('Crear o recuperar contraseña · Danbel');
?>
<main class="access-state">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <h1>Crear o recuperar contraseña</h1>
  <?php if ($sent): ?>
    <p>Si el código y el email coinciden con nuestra ficha de cliente, en unos minutos recibirás un correo con un enlace para elegir tu contraseña. Revisa también la carpeta de spam.</p>
    <p class="login-help"><a href="login.php">Volver al acceso</a></p>
  <?php else: ?>
    <p>Es tu primera vez o no recuerdas la contraseña. Escribe tu código de cliente y el email que tienes registrado en Danbel y te enviaremos un enlace.</p>
    <form method="post" class="login-form">
      <?= csrf_field() ?>
      <label for="code">Código de cliente</label>
      <input class="input" id="code" name="code" inputmode="numeric" pattern="[0-9]{1,6}" required value="<?= e($code) ?>" autofocus>
      <label for="email">Email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e($email) ?>">
      <?php if ($error): ?><p class="login-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <button type="submit" class="btn">Enviar enlace</button>
    </form>
    <p class="login-help">Si no recibes el correo o tu email ha cambiado, contacta con Danbel. <a href="login.php">Volver al acceso</a></p>
  <?php endif ?>
</main>
<?php
page_end();
