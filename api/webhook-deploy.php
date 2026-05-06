<?php
/**
 * Webhook de deploy automático desde GitHub Actions.
 *
 * Flujo:
 *   1) Valida header X-Deploy-Token contra DEPLOY_TOKEN.
 *   2) Descarga el tarball de la rama configurada desde la API de GitHub
 *      (usando GITHUB_DEPLOY_TOKEN si el repo es privado).
 *   3) Extrae a un directorio temporal.
 *   4) Sincroniza con DEPLOY_ROOT preservando uploads/ y config/database.php.
 *   5) Ejecuta composer install --no-dev --optimize-autoloader.
 *   6) Ajusta permisos.
 *   7) Devuelve JSON {"success": true}.
 *
 * Configuración mediante variables de entorno (preferido) o sobrescribiendo
 * los valores por defecto al final del bloque "Configuración":
 *
 *   DEPLOY_TOKEN          → token compartido con GitHub Actions
 *   GITHUB_DEPLOY_TOKEN   → PAT con permiso Contents: Read (repos privados)
 *   DEPLOY_REPO           → owner/repo (default: JosmaSports/talentfilter)
 *   DEPLOY_BRANCH         → rama (default: main)
 *   DEPLOY_ROOT           → ruta absoluta de despliegue (default: /var/www/talent-filter)
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DEPLOY_LOG_FILE  = '/tmp/talentfilter-deploy.log';
const DEPLOY_LOCK_FILE = '/tmp/talentfilter-deploy.lock';

function deploy_log(string $line): void {
    @file_put_contents(
        DEPLOY_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
        FILE_APPEND
    );
}

function deploy_respond(int $httpCode, bool $success, string $message, array $extra = []): void {
    http_response_code($httpCode);
    echo json_encode(
        ['success' => $success, 'message' => $message] + $extra,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function deploy_env(string $key, string $default): string {
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

function deploy_run(string $cmd, ?array &$output = null): int {
    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    return $rc;
}

set_exception_handler(static function (Throwable $e): void {
    deploy_log('UNCAUGHT: ' . $e->getMessage());
    deploy_respond(500, false, 'Internal error', ['error' => $e->getMessage()]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Configuración
// ─────────────────────────────────────────────────────────────────────────────
$expectedToken = deploy_env('DEPLOY_TOKEN', 'tf-deploy-2026-secure-token');
$githubToken   = deploy_env('GITHUB_DEPLOY_TOKEN', '');
$repo          = deploy_env('DEPLOY_REPO', 'JosmaSports/talentfilter');
$branch        = deploy_env('DEPLOY_BRANCH', 'main');
$deployRoot    = rtrim(deploy_env('DEPLOY_ROOT', '/var/www/talent-filter'), '/');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Validación de método y token
// ─────────────────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    deploy_respond(405, false, 'Method not allowed');
}

$providedToken = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    deploy_log('Token inválido o ausente');
    deploy_respond(401, false, 'Invalid deploy token');
}

deploy_log('=== Inicio deploy (repo=' . $repo . ', branch=' . $branch . ', root=' . $deployRoot . ') ===');

// ─────────────────────────────────────────────────────────────────────────────
// 2. Lock anti-concurrencia
// ─────────────────────────────────────────────────────────────────────────────
$lock = @fopen(DEPLOY_LOCK_FILE, 'c');
if ($lock === false) {
    deploy_respond(500, false, 'Cannot open lock file');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    deploy_log('Otro deploy en curso, abortando');
    deploy_respond(409, false, 'Another deploy is already in progress');
}

$cleanup = function (?string $tmpDir = null) use ($lock): void {
    if ($tmpDir !== null && is_dir($tmpDir)) {
        deploy_run('rm -rf ' . escapeshellarg($tmpDir));
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
};

// ─────────────────────────────────────────────────────────────────────────────
// 3. Descarga del tarball
// ─────────────────────────────────────────────────────────────────────────────
$tmpDir = sys_get_temp_dir() . '/talentfilter-deploy-' . bin2hex(random_bytes(6));
if (!mkdir($tmpDir, 0755, true)) {
    $cleanup();
    deploy_respond(500, false, 'Cannot create temp directory');
}

$tarFile = $tmpDir . '/repo.tar.gz';
$apiUrl  = "https://api.github.com/repos/{$repo}/tarball/{$branch}";

deploy_log('Descargando ' . $apiUrl);

$ch = curl_init($apiUrl);
$fp = fopen($tarFile, 'wb');
if ($ch === false || $fp === false) {
    $cleanup($tmpDir);
    deploy_respond(500, false, 'Cannot init download');
}

$headers = [
    'Accept: application/vnd.github+json',
    'User-Agent: TF-Deploy-Webhook',
];
if ($githubToken !== '') {
    $headers[] = 'Authorization: Bearer ' . $githubToken;
}

curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_FAILONERROR    => true,
    CURLOPT_CONNECTTIMEOUT => 30,
]);

$ok       = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
fclose($fp);

if (!$ok || !is_file($tarFile) || filesize($tarFile) < 1024) {
    $cleanup($tmpDir);
    deploy_log("Descarga falló (HTTP {$httpCode}): {$curlErr}");
    deploy_respond(502, false, "GitHub download failed (HTTP {$httpCode})", [
        'curl_error' => $curlErr,
        'hint'       => 'Verifica GITHUB_DEPLOY_TOKEN en el servidor si el repo es privado.',
    ]);
}

deploy_log('Descarga OK (' . $httpCode . ', ' . filesize($tarFile) . ' bytes)');

// ─────────────────────────────────────────────────────────────────────────────
// 4. Extracción
// ─────────────────────────────────────────────────────────────────────────────
$extractDir = $tmpDir . '/extracted';
if (!mkdir($extractDir, 0755, true)) {
    $cleanup($tmpDir);
    deploy_respond(500, false, 'Cannot create extract dir');
}

$tarCmd = sprintf(
    'tar xzf %s --strip-components=1 -C %s',
    escapeshellarg($tarFile),
    escapeshellarg($extractDir)
);
$rc = deploy_run($tarCmd, $tarOut);
if ($rc !== 0) {
    $cleanup($tmpDir);
    deploy_log('tar falló: ' . implode("\n", $tarOut));
    deploy_respond(500, false, 'Tar extract failed', ['stderr' => $tarOut]);
}

deploy_log('Extracción OK');

// ─────────────────────────────────────────────────────────────────────────────
// 5. Sincronización a DEPLOY_ROOT (preserva uploads/ y la BD config)
// ─────────────────────────────────────────────────────────────────────────────
if (!is_dir($deployRoot) && !mkdir($deployRoot, 0755, true) && !is_dir($deployRoot)) {
    $cleanup($tmpDir);
    deploy_respond(500, false, 'Cannot create deploy root');
}

$excludes = [
    '--exclude=uploads/',
    '--exclude=.git/',
    '--exclude=.github/',
    '--exclude=composer.phar',
    '--exclude=*.log',
];

$rsyncCmd = sprintf(
    'rsync -a --delete-after %s %s/ %s/',
    implode(' ', $excludes),
    escapeshellarg($extractDir),
    escapeshellarg($deployRoot)
);

$rc = deploy_run($rsyncCmd, $rsyncOut);
if ($rc !== 0) {
    $cleanup($tmpDir);
    deploy_log('rsync falló: ' . implode("\n", $rsyncOut));
    deploy_respond(500, false, 'Rsync failed', ['stderr' => $rsyncOut]);
}

deploy_log('Sync OK');

// ─────────────────────────────────────────────────────────────────────────────
// 6. composer install --no-dev
// ─────────────────────────────────────────────────────────────────────────────
$composerBin = '';
foreach (['/usr/local/bin/composer', '/usr/bin/composer', 'composer'] as $candidate) {
    $check = [];
    $rcc = 0;
    exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null', $check, $rcc);
    if ($rcc === 0 && !empty($check)) {
        $composerBin = $candidate;
        break;
    }
}

if ($composerBin === '' && is_file($deployRoot . '/composer.phar')) {
    $composerBin = 'php ' . escapeshellarg($deployRoot . '/composer.phar');
}

if ($composerBin === '') {
    deploy_log('AVISO: composer no encontrado; saltando install');
} else {
    $composerCmd = sprintf(
        'cd %s && %s install --no-dev --optimize-autoloader --no-interaction --prefer-dist',
        escapeshellarg($deployRoot),
        $composerBin
    );
    $rc = deploy_run($composerCmd, $composerOut);
    if ($rc !== 0) {
        $cleanup($tmpDir);
        deploy_log('composer install falló: ' . implode("\n", $composerOut));
        deploy_respond(500, false, 'Composer install failed', ['stderr' => $composerOut]);
    }
    deploy_log('composer install OK');
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. Permisos y limpieza
// ─────────────────────────────────────────────────────────────────────────────
deploy_run('chmod -R 755 ' . escapeshellarg($deployRoot . '/api'));
if (is_dir($deployRoot . '/uploads')) {
    deploy_run('chmod -R 775 ' . escapeshellarg($deployRoot . '/uploads'));
} else {
    @mkdir($deployRoot . '/uploads', 0775, true);
}

$cleanup($tmpDir);
deploy_log('=== ✅ Deploy completado ===');

deploy_respond(200, true, 'Deploy completed', [
    'repo'   => $repo,
    'branch' => $branch,
    'root'   => $deployRoot,
]);
