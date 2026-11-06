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
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $db = db();
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY')->execute();
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= 10) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } else {
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect($next);
        }
        $db->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        $error = 'Usuario o contraseña incorrectos.';
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
  <p class="login-help">¿No tienes acceso o has olvidado la contraseña? Contacta con Danbel.</p>
</main>
<?php
page_end();
