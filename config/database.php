<?php
define('DB_HOST', '172.17.0.1');
define('DB_NAME', 'talent_filter_db');
define('DB_USER', 'talent_user');
define('DB_PASS', 'kjNzGn405FLRnI');
define('DB_CHARSET', 'utf8mb4');

define('BASE_PATH', realpath(__DIR__ . '/..'));
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('ORIGINALS_PATH', UPLOADS_PATH . '/originals');
define('PAGES_PATH', UPLOADS_PATH . '/pages');
define('UNIFIED_PATH', UPLOADS_PATH . '/unified');

// BASE_URL configurable por entorno: en producción el subdominio sirve la app
// desde la raíz; en XAMPP local se sirve bajo /talent-filter.
$envBaseUrl = getenv('APP_BASE_URL');
if ($envBaseUrl !== false) {
    define('BASE_URL', rtrim($envBaseUrl, '/'));
} else {
    define('BASE_URL', '/talent-filter');
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), '1049') !== false || strpos($e->getMessage(), 'Unknown database') !== false) {
                $installUrl = (BASE_URL === '' ? '' : BASE_URL) . '/install.php';
                header('Location: ' . $installUrl);
                exit;
            }
            if (strpos($e->getMessage(), '2002') !== false || strpos($e->getMessage(), 'Connection refused') !== false) {
                die('<div style="font-family:sans-serif;padding:40px;text-align:center"><h2 style="color:#dc2626">&#9888; MySQL no está disponible</h2><p>No se puede conectar al servidor MySQL en <code>' . htmlspecialchars(DB_HOST) . '</code>.</p></div>');
            }
            throw $e;
        }
    }
    return $pdo;
}
