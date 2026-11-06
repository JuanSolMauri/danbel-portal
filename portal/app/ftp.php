<?php
// Descarga los DBF del ERP vía FTP o FTPS (FTP sobre TLS).
// Configurar en config.php bajo la clave 'erp_ftp'.
declare(strict_types=1);

/**
 * Descarga los ficheros DBF del ERP al directorio local.
 *
 * Config (config.php, clave 'erp_ftp'):
 *   host       - servidor FTP/FTPS
 *   port       - puerto (21 por defecto)
 *   user       - usuario
 *   pass       - contraseña
 *   type       - 'ftps' (FTP sobre TLS, recomendado) o 'ftp' (sin cifrado)
 *   remote_dir - ruta remota donde están los DBF (p.ej. '/datos/dbf/')
 *
 * Los ficheros requeridos (TFAMILIES, TMATPR, TTARIFCLI) lanzan excepción si
 * no se pueden descargar. Los opcionales (TCLIENT, TSUBFAM, TSTOCKJOAN) se
 * ignoran si no están en el servidor.
 *
 * @return string[] nombres de ficheros descargados
 * @throws RuntimeException si la conexión o alguna descarga requerida falla
 */
function download_erp_dbfs(string $localDir): array
{
    $cfg = config('erp_ftp');
    if (!$cfg) {
        return [];
    }

    $host      = (string) ($cfg['host'] ?? '');
    $port      = (int)    ($cfg['port'] ?? 21);
    $user      = (string) ($cfg['user'] ?? '');
    $pass      = (string) ($cfg['pass'] ?? '');
    $type      = (string) ($cfg['type'] ?? 'ftps');
    $remoteDir = rtrim((string) ($cfg['remote_dir'] ?? '/'), '/');

    if ($type === 'ftps') {
        if (!function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('PHP no tiene soporte FTPS (ftp_ssl_connect). Usa type=ftp o activa la extensión FTP con SSL en Arsys.');
        }
        $conn = @ftp_ssl_connect($host, $port, 30);
        if (!$conn) {
            throw new RuntimeException("No se pudo conectar al servidor FTPS {$host}:{$port}");
        }
    } else {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('PHP no tiene la extensión FTP activada.');
        }
        $conn = @ftp_connect($host, $port, 30);
        if (!$conn) {
            throw new RuntimeException("No se pudo conectar al servidor FTP {$host}:{$port}");
        }
    }

    try {
        if (!@ftp_login($conn, $user, $pass)) {
            throw new RuntimeException("Error de autenticación FTP con usuario '{$user}'");
        }

        ftp_pasv($conn, true);

        $downloaded = [];

        // Tablas obligatorias: si no se descargan, falla la importación.
        foreach (array_values(ERP_TABLES) as $file) {
            $remotePath = $remoteDir . '/' . $file;
            $localPath  = $localDir . '/' . $file;
            if (!@ftp_get($conn, $localPath, $remotePath)) {
                throw new RuntimeException("No se pudo descargar {$file} del servidor ERP ({$host})");
            }
            $downloaded[] = $file;
        }

        // Tablas opcionales: si no están en el servidor se conservan los datos actuales.
        foreach (array_values(ERP_OPTIONAL_TABLES) as $file) {
            $remotePath = $remoteDir . '/' . $file;
            $localPath  = $localDir . '/' . $file;
            if (@ftp_get($conn, $localPath, $remotePath)) {
                $downloaded[] = $file;
            }
        }

        return $downloaded;
    } finally {
        @ftp_close($conn);
    }
}
