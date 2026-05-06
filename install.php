<?php
/**
 * Instalador de Talent Filter.
 *
 * Crea (si tiene permisos) la base de datos definida en config/database.php
 * y aplica el esquema de tablas. Es idempotente: usa CREATE TABLE IF NOT EXISTS
 * e INSERT IGNORE, por lo que se puede ejecutar varias veces sin romper datos.
 *
 * En producción el usuario MySQL puede no tener permiso para CREATE DATABASE;
 * en ese caso, crea la BD manualmente y vuelve a cargar este script para que
 * solo aplique el esquema.
 */

declare(strict_types=1);

require __DIR__ . '/config/database.php';

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

function render_message(string $title, string $body, string $color = '#1a2332', ?string $cta = null): void {
    $ctaHtml = $cta !== null ? $cta : '';
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>" . htmlspecialchars($title) . " - Talent Filter</title>";
    echo "<style>body{font-family:system-ui,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0;padding:20px}";
    echo ".card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:560px}";
    echo "h1{color:{$color};margin:0 0 12px}p{color:#555;line-height:1.5}.icon{font-size:48px;margin-bottom:8px}";
    echo "code{background:#f4f4f5;padding:2px 6px;border-radius:4px;font-size:.92em}";
    echo "a.btn{display:inline-block;margin-top:24px;padding:12px 28px;background:#1a2332;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}</style></head>";
    echo "<body><div class='card'><h1>" . htmlspecialchars($title) . "</h1><div>{$body}</div>{$ctaHtml}</div></body></html>";
}

try {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
    } catch (PDOException $e) {
        $isUnknownDb = strpos($e->getMessage(), '1049') !== false
            || stripos($e->getMessage(), 'Unknown database') !== false;

        if (!$isUnknownDb) {
            throw $e;
        }

        $dsnNoDb = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $pdoOptions);

        $dbNameQuoted = '`' . str_replace('`', '``', DB_NAME) . '`';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbNameQuoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE {$dbNameQuoted}");
    }

    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS selections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS pdf_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selection_id INT NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        total_pages INT DEFAULT 0,
        status ENUM('uploading','splitting','split','analyzing','done','error') DEFAULT 'uploading',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (selection_id) REFERENCES selections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS pdf_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        page_number INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        status ENUM('pending','analyzing','analyzed','unified','error') DEFAULT 'pending',
        is_cv_start TINYINT(1) DEFAULT NULL,
        cv_group_id INT DEFAULT NULL,
        candidate_name VARCHAR(255) DEFAULT NULL,
        analysis_result TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_upload_page (upload_id, page_number),
        INDEX idx_status (status),
        INDEX idx_cv_group (cv_group_id),
        FOREIGN KEY (upload_id) REFERENCES pdf_uploads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS cv_unified (
        id INT AUTO_INCREMENT PRIMARY KEY,
        selection_id INT NOT NULL,
        upload_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        page_range VARCHAR(100) NOT NULL,
        num_pages INT NOT NULL,
        candidate_name VARCHAR(255) DEFAULT NULL,
        status ENUM('active','deleted') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_selection (selection_id),
        INDEX idx_status (status),
        FOREIGN KEY (selection_id) REFERENCES selections(id) ON DELETE CASCADE,
        FOREIGN KEY (upload_id) REFERENCES pdf_uploads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_key VARCHAR(100) NOT NULL UNIQUE,
        config_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB;

    CREATE TABLE IF NOT EXISTS logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        level ENUM('info','warning','error','success') DEFAULT 'info',
        message TEXT NOT NULL,
        context TEXT DEFAULT NULL,
        selection_id INT DEFAULT NULL,
        upload_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_level (level),
        INDEX idx_created (created_at),
        INDEX idx_selection (selection_id)
    ) ENGINE=InnoDB;
    SQL;

    $pdo->exec($sql);

    $defaultPrompt = <<<'PROMPT'
Eres un analizador experto en curriculums vitae (CVs) en español.

Se te proporciona el texto extraído de una página de un PDF que contiene múltiples CVs concatenados uno tras otro.

Tu misión es determinar si esta página es el INICIO/COMIENZO de un nuevo curriculum vitae.

INDICADORES CLAROS DE INICIO DE CV (muy habituales en CVs españoles de portales de empleo):
- Encabezado con nombre completo de una persona (nombre y apellidos), generalmente en letra grande o destacada
- Porcentaje de coincidencia o match (ej: "41%", "85%") junto al nombre
- Título o puesto profesional debajo del nombre (ej: "Técnico de planificación", "Desarrollador Web", "Administrativo")
- Datos de contacto al inicio: email, teléfono (con prefijos como "677", "612", etc.), dirección con código postal
- Sección "Datos del candidato" con campos como: Carnet de conducir, Autónomo, Vehículo propio
- Sección "Perfil profesional" o "Sobre mí" al comienzo
- Palabras clave: "Curriculum Vitae", "CV", "Resume", "Perfil", "Datos personales"
- Foto de perfil o referencia a imagen al inicio

INDICADORES DE QUE NO ES INICIO (es continuación de un CV anterior):
- Empieza directamente con experiencia laboral (empresas, fechas, cargos) sin encabezado personal
- Empieza con formación académica (titulaciones, universidades) sin presentación personal
- Empieza con idiomas, habilidades o competencias sin encabezado
- El texto comienza con una frase que claramente continúa de la página anterior
- Secciones finales de un CV: referencias, firmas, "disponibilidad inmediata" suelto

IMPORTANTE: En los CVs exportados de portales de empleo españoles (InfoJobs, LinkedIn, etc.) es muy común ver al inicio: nombre + porcentaje de match + cargo + ubicación + contacto + "Datos del candidato". Estos son inicios de CV con alta certeza.

Responde ÚNICAMENTE con un JSON válido sin texto adicional:
{
    "is_cv_start": true o false,
    "confidence": número entre 0.0 y 1.0,
    "candidate_name": "nombre completo detectado o null",
    "reasoning": "explicación breve en español de por qué es o no es inicio"
}
PROMPT;

    $stmt = $pdo->prepare('INSERT IGNORE INTO config (config_key, config_value) VALUES (?, ?)');
    $stmt->execute(['openai_api_key', '']);
    $stmt->execute(['openai_model', 'gpt-4o-mini']);
    $stmt->execute(['max_tokens', '500']);
    $stmt->execute(['cv_detect_prompt', $defaultPrompt]);

    $homeUrl = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL . '/index.php' : 'index.php';

    render_message(
        'Instalación completada',
        '<div class="icon" style="color:#28a745">&#10003;</div>'
        . '<p>La base de datos <strong>' . htmlspecialchars(DB_NAME) . '</strong> está lista en '
        . '<code>' . htmlspecialchars(DB_HOST) . '</code> con todas las tablas necesarias.</p>',
        '#1a2332',
        '<a class="btn" href="' . htmlspecialchars($homeUrl) . '">Ir a Talent Filter</a>'
    );

} catch (PDOException $e) {
    $msg     = $e->getMessage();
    $isAuth  = strpos($msg, '1045') !== false || stripos($msg, 'Access denied') !== false;
    $isConn  = strpos($msg, '2002') !== false || stripos($msg, 'Connection refused') !== false
        || strpos($msg, '2005') !== false || stripos($msg, 'Unknown MySQL server') !== false;
    $isPerm  = strpos($msg, '1044') !== false || stripos($msg, 'denied for user') !== false;

    $hint = '';
    if ($isAuth) {
        $hint = '<p>Credenciales rechazadas. Revisa <code>DB_USER</code> y <code>DB_PASS</code> en <code>config/database.php</code>.</p>';
    } elseif ($isConn) {
        $hint = '<p>No se puede contactar con MySQL en <code>' . htmlspecialchars(DB_HOST) . '</code>. '
            . 'Verifica que el servidor está activo y que el firewall permite la conexión.</p>';
    } elseif ($isPerm) {
        $hint = '<p>El usuario <code>' . htmlspecialchars(DB_USER) . '</code> no tiene permiso para crear la base de datos. '
            . 'Crea <code>' . htmlspecialchars(DB_NAME) . '</code> manualmente y vuelve a abrir este instalador para aplicar solo el esquema.</p>';
    }

    render_message(
        'Error en la instalación',
        $hint . '<details style="margin-top:16px;text-align:left"><summary style="cursor:pointer;color:#666">Detalle técnico</summary>'
        . '<pre style="background:#f4f4f5;padding:12px;border-radius:6px;overflow:auto;font-size:.85em">'
        . htmlspecialchars($msg) . '</pre></details>',
        '#dc3545'
    );
}
