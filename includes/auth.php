<?php
/**
 * Helper de autenticación para Talent Filter.
 *
 * Funciones públicas:
 *   - ensureSessionStarted(): inicia la sesión con cookies seguras.
 *   - isLoggedIn(): bool
 *   - getCurrentUser(): array|null
 *   - loginUser(email, password): bool
 *   - logoutUser(): void
 *   - requireLogin(): array — protege páginas (redirige a login.php si no hay sesión)
 *   - requireApiAuth(): array — protege APIs (responde 401 JSON si no hay sesión)
 *   - csrfToken() / verifyCsrf(): tokens anti-CSRF
 *   - userExists(): bool — hay al menos un usuario en BD
 *   - registerInitialUser(email, password, name): array — registro restringido
 *
 * Restricciones del registro:
 *   - Solo se permite el email definido en ALLOWED_REGISTRATION_EMAIL.
 *   - Solo funciona si la tabla users está vacía (one-shot).
 */

require_once __DIR__ . '/../config/database.php';

const ALLOWED_REGISTRATION_EMAIL = 'susana.rivero@sportsemotion.com';
const MIN_PASSWORD_LENGTH = 8;

function ensureSessionStarted(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('TFSESSID');
    session_start();
}

function isLoggedIn(): bool {
    ensureSessionStarted();
    return !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    ensureSessionStarted();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cache = null;
    $uid = (int)$_SESSION['user_id'];
    if ($cache !== null && (int)$cache['id'] === $uid) {
        return $cache;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, email, name, created_at, last_login_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();

    if (!$row) {
        unset($_SESSION['user_id']);
        $cache = null;
        return null;
    }

    $cache = $row;
    return $row;
}

function loginUser(string $email, string $password): bool {
    ensureSessionStarted();

    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, password_hash FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $u['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$u['id'];
    $_SESSION['login_at']  = time();

    $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$u['id']]);

    return true;
}

function logoutUser(): void {
    ensureSessionStarted();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path']     ?? '/',
            $params['domain']   ?? '',
            (bool)($params['secure']   ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function authBaseUrl(): string {
    return defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : '';
}

function requireLogin(): array {
    if (!isLoggedIn()) {
        $loginUrl = authBaseUrl() . '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
    $u = getCurrentUser();
    if ($u === null) {
        $loginUrl = authBaseUrl() . '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
    return $u;
}

/**
 * Asegura que las respuestas de la API siempre sean JSON, capturando
 * warnings y errores fatales que de otro modo emitirian HTML y romperian
 * el JSON.parse en el frontend.
 */
function setupApiErrorHandlers(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(E_ALL);

    if (ob_get_level() === 0) {
        ob_start();
    }

    set_exception_handler(static function (\Throwable $e): void {
        if (ob_get_level() > 0) {
            @ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Error interno: ' . $e->getMessage(),
            'code'    => 'INTERNAL_ERROR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) return;
        $fatalTypes = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (($err['type'] & $fatalTypes) === 0) return;

        if (ob_get_level() > 0) {
            @ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Error fatal: ' . ($err['message'] ?? 'desconocido'),
            'code'    => 'FATAL_ERROR',
            'where'   => isset($err['file']) ? basename($err['file']) . ':' . ($err['line'] ?? '?') : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

function requireApiAuth(): array {
    setupApiErrorHandlers();

    if (!isLoggedIn()) {
        if (ob_get_level() > 0) @ob_clean();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'No autenticado',
            'code'    => 'AUTH_REQUIRED',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $u = getCurrentUser();
    if ($u === null) {
        if (ob_get_level() > 0) @ob_clean();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Sesión no válida',
            'code'    => 'AUTH_REQUIRED',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

function csrfToken(): string {
    ensureSessionStarted();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(?string $token): bool {
    ensureSessionStarted();
    if (empty($token) || empty($_SESSION['csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf'], $token);
}

/**
 * Garantiza que la tabla users exista. Idempotente: usa CREATE TABLE IF NOT EXISTS.
 * Permite que el registro funcione aunque no se haya ejecutado install.php.
 */
function ensureUsersTable(): void {
    $db = getDB();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(190) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME DEFAULT NULL,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function userExists(): bool {
    try {
        $db = getDB();
        $r = $db->query('SELECT COUNT(*) AS c FROM users')->fetch();
        return ((int)($r['c'] ?? 0)) > 0;
    } catch (\PDOException $e) {
        // Si la tabla aún no existe, tratamos como sin usuarios.
        return false;
    }
}

function registerInitialUser(string $email, string $password, string $name = ''): array {
    $email = strtolower(trim($email));
    $name  = trim($name);

    if ($email !== ALLOWED_REGISTRATION_EMAIL) {
        return ['ok' => false, 'msg' => 'Email no autorizado para el registro.'];
    }

    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return [
            'ok'  => false,
            'msg' => 'La contraseña debe tener al menos ' . MIN_PASSWORD_LENGTH . ' caracteres.',
        ];
    }

    try {
        ensureUsersTable();
    } catch (\PDOException $e) {
        return [
            'ok'  => false,
            'msg' => 'No se pudo preparar la tabla users. Detalle: ' . $e->getMessage(),
        ];
    }

    if (userExists()) {
        return [
            'ok'  => false,
            'msg' => 'Ya existe un usuario registrado. El registro está cerrado.',
        ];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)'
    );
    $stmt->execute([$email, $hash, $name !== '' ? $name : null]);

    return ['ok' => true, 'msg' => 'Usuario creado correctamente.', 'id' => (int)$db->lastInsertId()];
}
