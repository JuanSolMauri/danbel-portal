<?php
// Arranque común: configuración, base de datos, sesión y utilidades.
declare(strict_types=1);

const APP_DIR = __DIR__;

$configFile = APP_DIR . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Falta app/config.php. Copia app/config.sample.php como app/config.php y rellena los datos.');
}
$CONFIG = require $configFile;

function config(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Migración: columna obsolete añadida para la familia "En liquidación".
        try { $pdo->exec('ALTER TABLE products ADD COLUMN obsolete TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    return $pdo;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

session_name('danbel_portal');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/** Escapa texto para HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function euro(?float $value): string
{
    return $value === null ? '—' : number_format($value, 2, ',', '.') . ' €';
}

function percent(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('La sesión ha caducado. Vuelve atrás y recarga la página.');
    }
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT id, login, role, customer_code FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Solo para administradores.');
    }
    return $user;
}

/** Devuelve true si el usuario puede ver la columna Stock. */
function can_see_stock(array $access): bool
{
    if ($access['admin']) {
        return true;
    }
    $allowed = array_map('strval', config('stock_visible_clients', []));
    return $access['customer'] !== null && in_array($access['customer'], $allowed, true);
}

/**
 * Cliente cuyo catálogo se muestra. El administrador puede ver el de cualquiera con ?cliente=CODIGO;
 * sin código ve todas las familias a precio de tarifa.
 */
function resolve_access(array $user): array
{
    $admin = $user['role'] === 'admin';
    $requested = (string) ($_GET['cliente'] ?? '');
    $preview = $admin && preg_match('/^\d{1,6}$/', $requested) ? $requested : null;
    $customer = $admin ? $preview : ($user['customer_code'] !== null ? (string) $user['customer_code'] : null);
    return ['admin' => $admin, 'preview' => $preview, 'customer' => $customer];
}
