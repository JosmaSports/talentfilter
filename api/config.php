<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'save_config';

    if ($action === 'save_gs_path') {
        $path = trim($input['gs_path'] ?? '');
        setConfig('gs_path', $path);
        Logger::log('info', 'Ruta GhostScript actualizada: ' . ($path ?: '(vacía)'));
        jsonResponse(['success' => true, 'message' => 'Ruta guardada correctamente']);
    }

    if ($action === 'save_prompt') {
        $prompt = trim($input['cv_detect_prompt'] ?? '');
        if (empty($prompt)) {
            jsonResponse(['error' => 'El prompt no puede estar vacío'], 400);
        }
        setConfig('cv_detect_prompt', $prompt);
        Logger::log('info', 'Prompt de detección actualizado');
        jsonResponse(['success' => true, 'message' => 'Prompt guardado correctamente']);
    }

    if ($action === 'reset_prompt') {
        $defaultPrompt = <<<'PROMPT'
Eres un sistema de clasificación de páginas de PDF que contiene curriculums exportados desde InfoJobs u otros portales de empleo españoles.

Se te pasa el texto extraído de UNA página. Tu única tarea es decidir si esa página es el INICIO de un nuevo curriculum o es una CONTINUACIÓN del curriculum anterior.

== CÓMO ES EL INICIO DE UN CURRICULUM EN ESTE FORMATO ==

La primera zona de la página contiene, en este orden aproximado:
1. Nombre y apellidos de la persona (ej: "Roberto Latre")
2. Un porcentaje seguido del símbolo % (ej: "41%") — es el % de coincidencia del portal
3. Título o puesto profesional (ej: "Técnico de planificación")
4. Código postal y ciudad (ej: "50002, Zaragoza, Zaragoza")
5. Correo electrónico (ej: "robertolatre92@gmail.com")
6. Número de teléfono español (ej: "677 059 330 (preferente)")
7. A continuación, la sección con el título exacto: "Datos del candidato"
8. Bajo esa sección, campos como: "Carnet de conducir:", "Autónomo:", "Vehículo propio:"

Si el texto contiene CUALQUIERA de estas combinaciones al principio, es un INICIO DE CURRICULUM.

== CÓMO ES UNA PÁGINA DE CONTINUACIÓN ==

No empieza con nombre + porcentaje + cargo. En su lugar empieza directamente con:
- Secciones intermedias o finales de un CV: "Experiencia profesional", "Formación académica", "Idiomas", "Habilidades", "Informática", "Otros datos", "Referencias"
- Listados de empresas, fechas de trabajo, titulaciones, o habilidades sin encabezado personal

== REGLA ESPECIAL ==

Si el texto está casi vacío, en blanco, o no es legible, trátala como CONTINUACIÓN (is_cv_start: false).

== FORMATO DE RESPUESTA ==

Responde ÚNICAMENTE con este JSON exacto, sin ningún texto antes ni después:

{
  "is_cv_start": true,
  "confidence": 0.97,
  "candidate_name": "Roberto Latre",
  "reasoning": "Contiene nombre + 41% + cargo + localización + email + teléfono + sección Datos del candidato"
}

Donde:
- "is_cv_start": true si es inicio de curriculum, false si es continuación
- "confidence": número entre 0.0 y 1.0 indicando tu nivel de certeza
- "candidate_name": el nombre completo detectado, o null si no aparece
- "reasoning": una frase corta en español explicando la decisión
PROMPT;
        setConfig('cv_detect_prompt', $defaultPrompt);
        Logger::log('info', 'Prompt restaurado a valores por defecto');
        jsonResponse(['success' => true, 'message' => 'Prompt restaurado', 'prompt' => $defaultPrompt]);
    }

    $apiKey = trim($input['api_key'] ?? '');
    $model = trim($input['model'] ?? 'gpt-4o-mini');
    $maxTokens = (int)($input['max_tokens'] ?? 500);

    if ($maxTokens < 100) $maxTokens = 100;
    if ($maxTokens > 4000) $maxTokens = 4000;

    setConfig('openai_api_key', $apiKey);
    setConfig('openai_model', $model);
    setConfig('max_tokens', (string)$maxTokens);

    Logger::log('info', 'Configuración actualizada');

    jsonResponse(['success' => true, 'message' => 'Configuración guardada correctamente']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'test') {
        $apiKey = getConfig('openai_api_key');
        if (empty($apiKey)) {
            jsonResponse(['error' => 'No hay API key configurada'], 400);
        }

        $result = callOpenAI(
            'Responde solo con: {"status":"ok","message":"Conexión exitosa"}',
            'Responde únicamente con el JSON solicitado, sin texto adicional.'
        );

        if ($result && isset($result['status'])) {
            jsonResponse(['success' => true, 'message' => 'Conexión con OpenAI exitosa']);
        } elseif ($result && isset($result['raw'])) {
            jsonResponse(['success' => true, 'message' => 'Conexión exitosa. Respuesta: ' . $result['raw']]);
        } else {
            jsonResponse(['error' => 'No se pudo conectar con OpenAI. Revisa la API key y los logs.'], 500);
        }
    }

    if ($action === 'test_gs') {
        $gs = findGhostScript();
        if (!$gs) {
            jsonResponse(['error' => 'GhostScript no encontrado. Instálalo o indica la ruta manualmente.'], 404);
        }
        // Probar ejecutando gs --version
        exec('"' . $gs . '" --version 2>&1', $out, $ret);
        $version = trim(implode(' ', $out));
        if ($ret === 0) {
            jsonResponse(['success' => true, 'message' => "GhostScript funciona correctamente. Versión: $version", 'path' => $gs]);
        } else {
            jsonResponse(['error' => "GhostScript encontrado pero falló al ejecutar: $version"], 500);
        }
    }

    jsonResponse(['error' => 'Acción no válida'], 400);
}

jsonResponse(['error' => 'Método no permitido'], 405);
