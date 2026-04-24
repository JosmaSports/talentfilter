<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Logger.php';

function getConfig(string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT config_value FROM config WHERE config_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? ($row['config_value'] ?: $default) : $default;
}

function setConfig(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
    $stmt->execute([$key, $value]);
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function generateUniqueFilename(string $extension = 'pdf'): string {
    return uniqid('', true) . '.' . $extension;
}

function formatFileSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Llama a la API de OpenAI y devuelve el texto crudo de la respuesta.
 * Retorna null si la llamada HTTP falla.
 */
function callOpenAIRaw(string $text, string $prompt): ?string {
    $apiKey = getConfig('openai_api_key');
    if (empty($apiKey)) {
        Logger::log('error', 'API key de OpenAI no configurada');
        return null;
    }

    $model     = getConfig('openai_model', 'gpt-4o-mini');
    $maxTokens = (int)getConfig('max_tokens', '500');

    $data = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user',   'content' => $text],
        ],
        'max_tokens'  => $maxTokens,
        'temperature' => 0.0,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        Logger::log('error', "cURL error OpenAI: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        Logger::log('error', "OpenAI HTTP $httpCode: " . mb_substr($response, 0, 400));
        return null;
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['choices'][0]['message']['content'])) {
        Logger::log('error', "Respuesta inesperada OpenAI: " . mb_substr($response, 0, 400));
        return null;
    }

    return $decoded['choices'][0]['message']['content'];
}

/**
 * Parsea el JSON de la respuesta cruda de OpenAI.
 * Maneja: JSON directo, envuelto en ```json...```, o dentro de texto libre.
 */
function parseOpenAIJson(string $content): ?array {
    // 1) Parseo directo
    $trimmed = trim($content);
    $parsed  = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
        return $parsed;
    }

    // 2) Extraer de bloque markdown ```json ... ``` o ``` ... ```
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
        $parsed = json_decode($m[1], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }
    }

    // 3) Buscar primer objeto JSON con balance de llaves
    $start = strpos($content, '{');
    if ($start !== false) {
        $depth = 0;
        $end   = $start;
        $len   = strlen($content);
        for ($i = $start; $i < $len; $i++) {
            if ($content[$i] === '{') $depth++;
            elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        $jsonStr = substr($content, $start, $end - $start + 1);
        $parsed  = json_decode($jsonStr, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }
    }

    return null;
}

/** Wrapper de compatibilidad */
function callOpenAI(string $text, string $prompt): ?array {
    $raw = callOpenAIRaw($text, $prompt);
    if ($raw === null) return null;
    return parseOpenAIJson($raw) ?: ['raw' => $raw];
}

/**
 * Extrae texto de una página concreta del PDF ORIGINAL.
 * Es mucho más fiable que leer el PDF recortado por FPDI,
 * ya que FPDI incrusta el contenido como Form XObject y
 * smalot/pdfparser no extrae texto de XObjects.
 */
function extractPageTextFromOriginalPDF(string $originalFilePath, int $pageNumber): string {
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($originalFilePath);
        $pages  = $pdf->getPages();
        $idx    = $pageNumber - 1;
        if (isset($pages[$idx])) {
            return $pages[$idx]->getText();
        }
        return '';
    } catch (\Exception $e) {
        Logger::log('error', "Error extrayendo texto de pág. $pageNumber: " . $e->getMessage());
        return '';
    }
}

function extractTextFromPDF(string $filePath): string {
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($filePath);
        return $pdf->getText();
    } catch (\Exception $e) {
        Logger::log('error', "Error extrayendo texto de PDF: " . $e->getMessage());
        return '';
    }
}

function getSelections(): array {
    $db = getDB();
    return $db->query("SELECT * FROM selections ORDER BY created_at DESC")->fetchAll();
}

/**
 * Convierte el nombre de un candidato en un slug válido para nombre de archivo.
 * Ej: "María García López" → "maria-garcia-lopez"
 * Si el nombre está vacío devuelve $fallback.
 */
function candidateNameToFilename(?string $name, string $fallback = 'cv-sin-nombre'): string {
    if (empty(trim((string)$name))) return $fallback;

    $name = mb_strtolower(trim($name), 'UTF-8');

    $accents = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o',
    ];
    $name = str_replace(array_keys($accents), array_values($accents), $name);

    // Reemplazar cualquier carácter no alfanumérico por guion
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    $name = trim($name, '-');

    return $name ?: $fallback;
}

// -------------------------------------------------------
//  GhostScript / PDF → Imagen
// -------------------------------------------------------

/**
 * Encuentra el ejecutable de GhostScript en el sistema.
 * Primero busca la ruta guardada en config, luego rutas comunes.
 */
function findGhostScript(): ?string {
    // 1) Ruta guardada por el usuario en Configuración
    $configured = getConfig('gs_path');
    if ($configured && file_exists($configured)) {
        return $configured;
    }

    // 2) Rutas comunes en Windows
    $candidates = [];
    foreach (['C:\\Program Files', 'C:\\Program Files (x86)'] as $pf) {
        if (!is_dir("$pf\\gs")) continue;
        $dirs = glob("$pf\\gs\\gs*", GLOB_ONLYDIR) ?: [];
        rsort($dirs); // preferir versión más reciente
        foreach ($dirs as $dir) {
            foreach (['gswin64c.exe', 'gswin32c.exe'] as $exe) {
                if (file_exists("$dir\\bin\\$exe")) {
                    $candidates[] = "$dir\\bin\\$exe";
                }
            }
        }
    }

    // 3) Rutas hardcoded adicionales (XAMPP, herramientas comunes)
    $extra = [
        'C:\\xampp\\gs\\bin\\gswin64c.exe',
        'C:\\gs\\bin\\gswin64c.exe',
        'gswin64c',
        'gswin32c',
        'gs',
    ];
    foreach ($extra as $e) {
        $candidates[] = $e;
    }

    // 4) Comprobar cuál existe / está en PATH
    foreach ($candidates as $gs) {
        if (file_exists($gs)) return $gs;
        // Comprobar si está en PATH con where.exe
        exec("where \"$gs\" 2>NUL", $out, $ret);
        if ($ret === 0 && !empty($out[0]) && file_exists(trim($out[0]))) {
            return trim($out[0]);
        }
    }

    return null;
}

/**
 * Convierte UNA página de un PDF a JPEG en base64.
 * Usa GhostScript si está disponible; luego Imagick como fallback.
 * $pdfPath debe ser el PDF de esa única página (ya separado por FPDI).
 */
function convertPdfPageToJpegBase64(string $pdfPath): ?string {
    if (!file_exists($pdfPath)) return null;

    // --- Método 1: GhostScript ---
    $gs = findGhostScript();
    if ($gs) {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tf_img_' . uniqid() . '.jpg';
        // -r150: 150 DPI (suficiente para leer texto), -dJPEGQ=85: calidad
        $cmd = '"' . $gs . '" -dBATCH -dNOPAUSE -dSAFER'
             . ' -dFirstPage=1 -dLastPage=1'
             . ' -sDEVICE=jpeg -r150 -dJPEGQ=85'
             . ' -sOutputFile="' . $tmpFile . '"'
             . ' "' . $pdfPath . '"'
             . ' 2>&1';
        exec($cmd, $output, $retCode);
        if ($retCode === 0 && file_exists($tmpFile) && filesize($tmpFile) > 0) {
            $b64 = base64_encode(file_get_contents($tmpFile));
            unlink($tmpFile);
            return $b64;
        }
        if (file_exists($tmpFile)) unlink($tmpFile);
        Logger::log('warning', "GhostScript falló (código $retCode): " . implode(' ', array_slice($output, -3)));
    }

    // --- Método 2: Imagick (si está cargada la extensión) ---
    if (extension_loaded('imagick')) {
        try {
            $img = new Imagick();
            $img->setResolution(150, 150);
            $img->readImage($pdfPath . '[0]');
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(85);
            $b64 = base64_encode($img->getImageBlob());
            $img->destroy();
            return $b64;
        } catch (\Exception $e) {
            Logger::log('warning', 'Imagick falló: ' . $e->getMessage());
        }
    }

    return null;
}

// -------------------------------------------------------
//  OpenAI Vision API
// -------------------------------------------------------

/**
 * Envía una imagen JPEG (en base64) a la Vision API de OpenAI.
 * Devuelve el texto crudo de la respuesta, o null si falla.
 */
function callOpenAIVisionRaw(string $jpegBase64, string $prompt): ?string {
    $apiKey = getConfig('openai_api_key');
    if (empty($apiKey)) return null;

    // gpt-3.5-turbo no soporta visión; forzar gpt-4o-mini como mínimo
    $model = getConfig('openai_model', 'gpt-4o-mini');
    if ($model === 'gpt-3.5-turbo') $model = 'gpt-4o-mini';

    $maxTokens = max(300, (int)getConfig('max_tokens', '500'));

    $data = [
        'model'       => $model,
        'messages'    => [[
            'role'    => 'user',
            'content' => [
                [
                    'type'      => 'image_url',
                    'image_url' => [
                        'url'    => 'data:image/jpeg;base64,' . $jpegBase64,
                        'detail' => 'low',   // suficiente para detectar texto de CV
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ]],
        'max_tokens'  => $maxTokens,
        'temperature' => 0.0,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 90,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        Logger::log('error', "cURL Vision error: $curlError");
        return null;
    }
    if ($httpCode !== 200) {
        Logger::log('error', "OpenAI Vision HTTP $httpCode: " . mb_substr($response, 0, 400));
        return null;
    }

    $decoded = json_decode($response, true);
    return $decoded['choices'][0]['message']['content'] ?? null;
}

/**
 * Sube un PDF de una página a la Files API de OpenAI,
 * lo analiza con la Responses API usando el file_id,
 * y borra el archivo tras obtener la respuesta.
 *
 * Usa /v1/responses (no /v1/chat/completions) porque Chat Completions
 * solo acepta imágenes; la Responses API acepta PDFs de forma nativa.
 * Compatible con gpt-4o y gpt-4o-mini.
 */
function callOpenAIWithPdfFile(string $pdfPath, string $prompt): ?string {
    $apiKey = getConfig('openai_api_key');
    if (empty($apiKey) || !file_exists($pdfPath)) return null;

    $model = getConfig('openai_model', 'gpt-4o-mini');
    if ($model === 'gpt-3.5-turbo') $model = 'gpt-4o-mini';
    $maxTokens = max(300, (int)getConfig('max_tokens', '500'));

    // ── PASO 1: Subir el PDF a OpenAI Files API ───────────────────────────
    $fileId = null;
    try {
        $postFields = [
            'purpose' => 'user_data',
            'file'    => new CURLFile($pdfPath, 'application/pdf', basename($pdfPath)),
        ];

        $ch = curl_init('https://api.openai.com/v1/files');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            Logger::log('warning', "Files API upload HTTP $httpCode: " . mb_substr($resp, 0, 300));
            return null;
        }

        $decoded = json_decode($resp, true);
        $fileId  = $decoded['id'] ?? null;
        if (!$fileId) {
            Logger::log('warning', "Files API: no se obtuvo file_id. Resp: " . mb_substr($resp, 0, 300));
            return null;
        }
    } catch (\Exception $e) {
        Logger::log('error', "Files API upload excepción: " . $e->getMessage());
        return null;
    }

    // ── PASO 2: Analizar con Responses API usando el file_id ─────────────
    // Chat Completions (/v1/chat/completions) rechaza PDFs con HTTP 400
    // "Invalid MIME type. Only image types are supported."
    // La Responses API (/v1/responses) acepta PDFs de forma nativa con input_file.
    $rawContent = null;
    try {
        $data = [
            'model' => $model,
            'input' => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'    => 'input_file',
                        'file_id' => $fileId,
                    ],
                    [
                        'type' => 'input_text',
                        'text' => $prompt,
                    ],
                ],
            ]],
            'max_output_tokens' => $maxTokens,
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT    => 90,
        ]);

        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Logger::log('error', "Responses API cURL error: $curlErr");
        } elseif ($httpCode !== 200) {
            Logger::log('warning', "Responses API HTTP $httpCode: " . mb_substr($resp, 0, 400));
        } else {
            $decoded = json_decode($resp, true);
            // Responses API: output[0].content[0].text
            $rawContent = $decoded['output'][0]['content'][0]['text'] ?? null;
        }
    } catch (\Exception $e) {
        Logger::log('error', "Responses API excepción: " . $e->getMessage());
    }

    // ── PASO 3: Borrar el archivo de OpenAI (siempre, pase lo que pase) ──
    $delCh = curl_init("https://api.openai.com/v1/files/$fileId");
    curl_setopt_array($delCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($delCh);
    curl_close($delCh);

    return $rawContent;
}
