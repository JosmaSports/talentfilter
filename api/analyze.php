<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);
ini_set('memory_limit', '1024M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$input  = json_decode(file_get_contents('php://input'), true);
$pageId = (int)($input['page_id'] ?? 0);

if (!$pageId) {
    jsonResponse(['error' => 'page_id requerido'], 400);
}

$apiKey = getConfig('openai_api_key');
if (empty($apiKey)) {
    jsonResponse(['error' => 'API key de OpenAI no configurada. Ve a Configuración para añadirla.'], 400);
}

$db = getDB();

$stmt = $db->prepare("
    SELECT pp.*, pu.selection_id, pu.original_filename, pu.stored_filename
    FROM pdf_pages pp
    JOIN pdf_uploads pu ON pp.upload_id = pu.id
    WHERE pp.id = ?
");
$stmt->execute([$pageId]);
$page = $stmt->fetch();

if (!$page) {
    jsonResponse(['error' => 'Página no encontrada'], 404);
}

$pageNum      = (int)$page['page_number'];
$splitPdfPath = BASE_PATH . '/' . $page['file_path'];
$origPdfPath  = ORIGINALS_PATH . '/' . $page['stored_filename'];

try {
    // ── Página 1: siempre inicio de CV, sin gastar llamada a la API ───────
    if ($pageNum === 1) {
        $db->prepare("UPDATE pdf_pages SET status='analyzed', is_cv_start=1, analysis_result=? WHERE id=?")
           ->execute([json_encode(['is_cv_start'=>true,'confidence'=>1.0,'reasoning'=>'Primera página, siempre inicio de CV'], JSON_UNESCAPED_UNICODE), $pageId]);
        Logger::log('info', "Pág. 1 → INICIO automático (sin llamada a API)", $page['selection_id'], $page['upload_id']);
        jsonResponse(['success'=>true,'page_id'=>$pageId,'page_number'=>1,'is_cv_start'=>true,'candidate_name'=>null,'confidence'=>1.0,'reasoning'=>'Primera página, siempre inicio de CV']);
    }

    $db->prepare("UPDATE pdf_pages SET status='analyzing' WHERE id=?")->execute([$pageId]);

    $prompt = getConfig('cv_detect_prompt');
    if (empty(trim($prompt))) {
        $prompt = 'Analiza si esta página de un PDF es el inicio de un nuevo curriculum vitae. Responde ÚNICAMENTE con JSON válido: {"is_cv_start": true/false, "confidence": 0.0-1.0, "candidate_name": "nombre completo o null", "reasoning": "razón breve en español"}';
    }

    $rawOpenAI  = null;
    $method     = 'none';
    $textLen    = 0;

    // ── PASO 1: Intentar extracción de texto del PDF original ─────────────
    if (file_exists($origPdfPath)) {
        $text = extractPageTextFromOriginalPDF($origPdfPath, $pageNum);
        $textLen = mb_strlen(trim($text));
        if ($textLen >= 30) {
            $method    = 'text';
            $rawOpenAI = callOpenAIRaw(mb_substr($text, 0, 3000), $prompt);
            Logger::log('info', "Pág. $pageNum — texto: {$textLen} chars → enviado a OpenAI (texto)", $page['selection_id'], $page['upload_id']);
        }
    }

    // ── PASO 2: Enviar PDF de página directamente a OpenAI (sin GhostScript) ─
    if ($rawOpenAI === null) {
        if (!file_exists($splitPdfPath)) {
            throw new \Exception("Archivo de página no encontrado: $splitPdfPath");
        }

        Logger::log('info', "Pág. $pageNum — sin texto ($textLen chars), enviando PDF a OpenAI Responses API...", $page['selection_id'], $page['upload_id']);
        $rawOpenAI = callOpenAIWithPdfFile($splitPdfPath, $prompt);

        if ($rawOpenAI !== null) {
            $method = 'responses_api';
            Logger::log('info', "Pág. $pageNum — analizado con Responses API (PDF nativo)", $page['selection_id'], $page['upload_id']);
        }
    }

    // ── PASO 3: Fallback a Vision con GhostScript (PDF → JPEG → OpenAI) ───
    if ($rawOpenAI === null) {
        $gsPath = findGhostScript();

        if ($gsPath) {
            Logger::log('info', "Pág. $pageNum — convirtiendo a imagen con GhostScript...", $page['selection_id'], $page['upload_id']);
            $jpegBase64 = convertPdfPageToJpegBase64($splitPdfPath);

            if ($jpegBase64) {
                $rawOpenAI = callOpenAIVisionRaw($jpegBase64, $prompt);
                if ($rawOpenAI !== null) {
                    $method = 'vision_gs';
                    Logger::log('info', "Pág. $pageNum — imagen enviada a OpenAI Vision (GhostScript)", $page['selection_id'], $page['upload_id']);
                }
            }
        }
    }

    // ── Sin ningún método disponible ─────────────────────────────────────
    if ($rawOpenAI === null) {
        $db->prepare("UPDATE pdf_pages SET status='error', analysis_result=? WHERE id=?")
           ->execute(['No se pudo analizar: sin texto extraíble y todos los métodos de envío a OpenAI fallaron.', $pageId]);
        Logger::log('error', "Pág. $pageNum → Todos los métodos fallaron (sin texto, Responses API fallida).", $page['selection_id'], $page['upload_id']);
        jsonResponse([
            'error'       => 'No se pudo analizar la página. El PDF es de imagen y la Responses API de OpenAI no está disponible.',
            'page_id'     => $pageId,
            'page_number' => $pageNum,
            'needs_gs'    => true,
        ], 422);
    }

    // ── Parsear respuesta ─────────────────────────────────────────────────
    if ($rawOpenAI === null) {
        throw new \Exception('Sin respuesta de OpenAI. Revisa la API key y los logs.');
    }

    Logger::log('info', "Pág. $pageNum — respuesta OpenAI ($method): " . mb_substr($rawOpenAI, 0, 300), $page['selection_id'], $page['upload_id']);

    $result = parseOpenAIJson($rawOpenAI);

    if (!$result || !isset($result['is_cv_start'])) {
        Logger::log('error', "Pág. $pageNum — JSON inválido de OpenAI: " . mb_substr($rawOpenAI, 0, 400), $page['selection_id'], $page['upload_id']);
        throw new \Exception('OpenAI no devolvió JSON válido: ' . mb_substr($rawOpenAI, 0, 200));
    }

    $isCvStart     = (bool)($result['is_cv_start'] ?? false);
    $candidateName = $result['candidate_name'] ?? null;
    $confidence    = round((float)($result['confidence'] ?? 0), 2);
    $reasoning     = $result['reasoning'] ?? '';

    $db->prepare("UPDATE pdf_pages SET status='analyzed', is_cv_start=?, candidate_name=?, analysis_result=? WHERE id=?")
       ->execute([
           $isCvStart ? 1 : 0,
           $candidateName,
           json_encode(array_merge($result, ['_method' => $method]), JSON_UNESCAPED_UNICODE),
           $pageId,
       ]);

    $icon    = $isCvStart ? '✅ INICIO' : '➡️ Continuación';
    $nameStr = $candidateName ? " — $candidateName" : '';
    Logger::log(
        $isCvStart ? 'success' : 'info',
        "Pág. $pageNum → $icon$nameStr (confianza: $confidence, método: $method) · $reasoning",
        $page['selection_id'], $page['upload_id']
    );

    jsonResponse([
        'success'        => true,
        'page_id'        => $pageId,
        'page_number'    => $pageNum,
        'is_cv_start'    => $isCvStart,
        'candidate_name' => $candidateName,
        'confidence'     => $confidence,
        'reasoning'      => $reasoning,
        'method'         => $method,
        'text_length'    => $textLen,
    ]);

} catch (\Exception $e) {
    $db->prepare("UPDATE pdf_pages SET status='error', analysis_result=? WHERE id=?")
       ->execute([$e->getMessage(), $pageId]);
    Logger::log('error', "Error pág. $pageNum: " . $e->getMessage(), $page['selection_id'], $page['upload_id']);
    jsonResponse(['error' => $e->getMessage()], 500);
}
