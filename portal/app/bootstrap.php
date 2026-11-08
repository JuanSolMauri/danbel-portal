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
        // Migraciones de columnas añadidas tras la versión inicial.
        try { $pdo->exec('ALTER TABLE products ADD COLUMN obsolete TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE products ADD COLUMN prio TINYINT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE products ADD COLUMN ordre INT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE customers ADD COLUMN blocked TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE users ADD COLUMN must_change TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL'); } catch (Throwable $e) {}
        $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            customer_code INT NOT NULL,
            email VARCHAR(120) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_resets_customer (customer_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    return $pdo;
}

/** Conexión a la BD del ERP (erp_db en config.php). Devuelve null si no está configurada. */
function db_erp(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        $c = config('erp_db');
        if (!$c) {
            $pdo = null;
        } else {
            try {
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
            } catch (Throwable $e) {
                $GLOBALS['_db_erp_error'] = $e->getMessage();
                $pdo = null;
            }
        }
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
            $stmt = db()->prepare('SELECT id, login, role, customer_code, must_change FROM users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            // Un cliente que el ERP marca como inactivo o bloqueado pierde el acceso al momento.
            if ($user && $user['role'] === 'customer' && customer_blocked($user['customer_code'])) {
                $_SESSION = [];
                $user = null;
            }
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
    // Con contraseña provisional, lo primero es cambiarla.
    if ((int) $user['must_change'] === 1 && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'cambiar-clave.php') {
        redirect('cambiar-clave.php');
    }
    return $user;
}

/** true si la ficha del cliente (importada de TCLIENT) está inactiva o bloqueada en el ERP. */
function customer_blocked($code): bool
{
    if ($code === null) {
        return false;
    }
    $stmt = db()->prepare('SELECT blocked FROM customers WHERE code = ?');
    $stmt->execute([(int) $code]);
    return (int) $stmt->fetchColumn() === 1;
}

/** true si esta IP ha superado el límite de intentos en los últimos 15 minutos. */
function too_many_attempts(int $max = 10): bool
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $db = db();
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY')->execute();
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$ip]);
    return (int) $stmt->fetchColumn() >= $max;
}

function record_attempt(): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
}

/** Inicia la sesión de un usuario ya verificado. */
function log_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$userId]);
}

/** URL absoluta de una página del portal (para los enlaces de los correos). */
function portal_url(string $page): string
{
    $base = (string) config('base_url', '');
    if ($base === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'danbelgroup.com');
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $base = (is_https() ? 'https://' : 'http://') . $host . $dir;
    }
    return rtrim($base, '/') . '/' . $page;
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
