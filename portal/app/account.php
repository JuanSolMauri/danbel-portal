<?php
// Alta y recuperación de contraseñas de clientes con enlace por correo.
declare(strict_types=1);

const RESET_LINK_HOURS = 2;

/** Emails de la ficha del cliente. En el ERP a veces hay varios separados por ; , / o espacios. */
function customer_emails(int $code): array
{
    $stmt = db()->prepare('SELECT email FROM customers WHERE code = ? AND blocked = 0');
    $stmt->execute([$code]);
    $raw = (string) $stmt->fetchColumn();
    $emails = [];
    foreach (preg_split('~[;,/\s]+~', strtolower($raw)) ?: [] as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    return array_values(array_unique($emails));
}

/**
 * Si el código y el email coinciden con la ficha del ERP, envía un enlace de un solo uso para crear la contraseña.
 * No dice nunca si el cliente existe: la respuesta al usuario es siempre la misma.
 */
function request_access_link(string $code, string $email): void
{
    $email = strtolower(trim($email));
    if (!preg_match('/^\d{1,6}$/', $code) || !in_array($email, customer_emails((int) $code), true)) {
        record_attempt();
        return;
    }
    $db = db();
    $db->prepare('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY')->execute();
    $stmt = $db->prepare('SELECT COUNT(*) FROM password_resets WHERE customer_code = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->execute([(int) $code]);
    if ((int) $stmt->fetchColumn() >= 3) {
        return; // Ya se han enviado varios enlaces en la última hora.
    }
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO password_resets (token_hash, customer_code, email, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . RESET_LINK_HOURS . ' HOUR)')
        ->execute([hash('sha256', $token), (int) $code, $email]);

    $link = portal_url('nueva-clave.php?t=' . $token);
    $body = "Hola,\n\n"
        . "Hemos recibido una petición para crear o cambiar la contraseña del portal de clientes de Danbel (cliente $code).\n\n"
        . "Para elegir tu contraseña, abre este enlace:\n$link\n\n"
        . 'El enlace caduca en ' . RESET_LINK_HOURS . " horas y solo se puede usar una vez.\n"
        . "Si no lo has pedido tú, ignora este correo: tu contraseña no cambia.\n\n"
        . "Danbel Distribuciones Exclusivas\n";
    $headers = implode("\r\n", [
        'From: Portal Danbel <noreply@danbelgroup.com>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    @mail($email, mb_encode_mimeheader('Tu contraseña del portal de clientes Danbel', 'UTF-8'), $body, $headers);
}

/** Devuelve el enlace pendiente (código de cliente y email) si el token es válido, o null. */
function find_reset(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT token_hash, customer_code, email FROM password_resets
        WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch() ?: null;
    if ($reset && customer_blocked($reset['customer_code'])) {
        return null;
    }
    return $reset;
}

/** Guarda la contraseña del cliente (crea la cuenta si no existe) y devuelve el id del usuario. */
function set_customer_password(int $code, string $password): int
{
    $db = db();
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'customer' AND customer_code = ? ORDER BY id LIMIT 1");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($id) {
        $db->prepare('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?')->execute([$hash, $id]);
        return (int) $id;
    }
    // El usuario es el código de cliente (en el login se puede entrar con el código).
    $db->prepare("INSERT INTO users (login, role, customer_code, password_hash) VALUES (?, 'customer', ?, ?)")
        ->execute([(string) $code, $code, $hash]);
    return (int) $db->lastInsertId();
}

/** Comprueba la nueva contraseña. Devuelve el error o null si es válida. */
function password_problem(string $password, string $repeat): ?string
{
    if (strlen($password) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($password !== $repeat) {
        return 'Las dos contraseñas no coinciden.';
    }
    return null;
}

/** Contraseña provisional legible (sin 0/O ni 1/l para que no haya confusiones al dictarla). */
function generate_temp_password(int $length = 10): string
{
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
