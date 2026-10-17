<?php
// Instalación inicial: crea las tablas y la primera cuenta de administrador.
// Solo funciona con el install_token de app/config.php y mientras no exista ningún administrador.
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

$token = (string) config('install_token', '');
$error = null;
$done = false;

db()->exec((string) file_get_contents(__DIR__ . '/app/schema.sql'));
$hasAdmin = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasAdmin && $token !== '') {
    require_post_csrf();
    $login = strtolower(trim((string) ($_POST['login'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        $error = 'El código de instalación no coincide con el de app/config.php.';
    } elseif ($login === '' || strlen($password) < 10) {
        $error = 'Indica un usuario y una contraseña de al menos 10 caracteres.';
    } else {
        db()->prepare("INSERT INTO users (login, role, password_hash) VALUES (?, 'admin', ?)")
            ->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);
        $done = true;
    }
}

page_start('Instalación · Danbel');
?>
<main class="access-state">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <h1>Instalación</h1>
  <?php if ($done): ?>
    <p>Administrador creado. Ahora deja <code>install_token</code> vacío en app/config.php y <a href="login.php">entra en el portal</a>.</p>
  <?php elseif ($hasAdmin || $token === ''): ?>
    <p>El portal ya está instalado. <a href="login.php">Ir al acceso</a>.</p>
  <?php else: ?>
    <p>Las tablas de la base de datos están listas. Crea la cuenta de administrador.</p>
    <form method="post" class="login-form">
      <?= csrf_field() ?>
      <label for="token">Código de instalación (install_token de app/config.php)</label>
      <input class="input" id="token" name="token" type="password" required>
      <label for="login">Usuario del administrador (email)</label>
      <input class="input" id="login" name="login" type="email" required>
      <label for="password">Contraseña (mínimo 10 caracteres)</label>
      <input class="input" id="password" name="password" type="password" minlength="10" required autocomplete="new-password">
      <?php if ($error): ?><p class="login-error" role="alert"><?= e($error) ?></p><?php endif ?>
      <button type="submit" class="btn">Crear administrador</button>
    </form>
  <?php endif ?>
</main>
<?php
page_end();
