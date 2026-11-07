<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

$next = (string) ($_POST['next'] ?? $_GET['next'] ?? 'index.php');
// Solo se vuelve a páginas de este mismo portal.
if (!preg_match('~^/?[a-z0-9_./-]*\.php(\?[^\s]*)?$~i', $next) || str_contains($next, '//')) {
    $next = 'index.php';
}
if (current_user()) {
    redirect($next);
}

$error = null;
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $login = strtolower(trim((string) ($_POST['login'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (too_many_attempts()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } else {
        $stmt = db()->prepare('SELECT id, role, customer_code, must_change, password_hash FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] === 'customer' && customer_blocked($user['customer_code'])) {
                $error = 'Tu acceso está desactivado. Contacta con Danbel.';
            } else {
                log_in((int) $user['id']);
                redirect((int) $user['must_change'] === 1 ? 'cambiar-clave.php' : $next);
            }
        } else {
            record_attempt();
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

page_start('Acceso de clientes · Danbel');
?>
<main class="access-state">
  <div class="logo">DANBEL<span>DISTRIBUCIONES EXCLUSIVAS</span></div>
  <h1>Acceso de clientes</h1>
  <p>Identifícate para consultar tu catálogo y tus precios.</p>
  <form method="post" class="login-form">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="login">Email o código de cliente</label>
    <input class="input" id="login" name="login" autocomplete="username" required value="<?= e($login) ?>" <?= $login === '' ? 'autofocus' : '' ?>>
    <label for="password">Contraseña</label>
    <input class="input" id="password" name="password" type="password" autocomplete="current-password" required <?= $login !== '' ? 'autofocus' : '' ?>>
    <?php if ($error): ?><p class="login-error" role="alert"><?= e($error) ?></p><?php endif ?>
    <button type="submit" class="btn">Entrar</button>
  </form>
  <p class="login-help">¿Es tu primera vez o has olvidado la contraseña? <a href="acceso.php">Crea o recupera tu contraseña</a>.</p>
</main>
<?php
page_end();
