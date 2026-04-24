<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'talent_filter';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    $sql = "
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
    ";

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

    $stmt = $pdo->prepare("INSERT IGNORE INTO config (config_key, config_value) VALUES (?, ?)");
    $stmt->execute(['openai_api_key', '']);
    $stmt->execute(['openai_model', 'gpt-4o-mini']);
    $stmt->execute(['max_tokens', '500']);
    $stmt->execute(['cv_detect_prompt', $defaultPrompt]);

    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Instalación - Talent Filter</title>";
    echo "<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0}";
    echo ".card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:500px}";
    echo "h1{color:#1a2332}p{color:#666}.success{color:#28a745;font-size:48px}";
    echo "a{display:inline-block;margin-top:20px;padding:12px 30px;background:#1a2332;color:#fff;text-decoration:none;border-radius:8px}</style></head>";
    echo "<body><div class='card'><div class='success'>&#10003;</div><h1>Instalación completada</h1>";
    echo "<p>La base de datos <strong>talent_filter</strong> ha sido creada correctamente con todas las tablas necesarias.</p>";
    echo "<a href='index.php'>Ir a Talent Filter</a></div></body></html>";

} catch (PDOException $e) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Error - Talent Filter</title>";
    echo "<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0}";
    echo ".card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:500px}";
    echo "h1{color:#dc3545}p{color:#666;word-break:break-all}</style></head>";
    echo "<body><div class='card'><h1>Error en la instalación</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p></div></body></html>";
}
