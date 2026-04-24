<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'talent_filter');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

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
                header('Location: /talent-filter/install.php');
                exit;
            }
            if (strpos($e->getMessage(), '2002') !== false || strpos($e->getMessage(), 'Connection refused') !== false) {
                die('<div style="font-family:sans-serif;padding:40px;text-align:center"><h2 style="color:#dc2626">&#9888; MySQL no está disponible</h2><p>Asegúrate de que el servidor MySQL de XAMPP está en ejecución.</p></div>');
            }
            throw $e;
        }
    }
    return $pdo;
}

define('BASE_PATH', realpath(__DIR__ . '/..'));
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('ORIGINALS_PATH', UPLOADS_PATH . '/originals');
define('PAGES_PATH', UPLOADS_PATH . '/pages');
define('UNIFIED_PATH', UPLOADS_PATH . '/unified');
define('BASE_URL', '/talent-filter');
