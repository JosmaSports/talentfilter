<?php
/**
 * Herramienta de diagnóstico: muestra el texto extraído de una página
 * y la respuesta completa de OpenAI para poder detectar problemas.
 * Acceso: /talent-filter/api/debug_page.php?page_id=X
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

$pageId = (int)($_GET['page_id'] ?? 0);
if (!$pageId) {
    die('<p>Uso: ?page_id=X</p>');
}

$db = getDB();
$page = $db->prepare("
    SELECT pp.*, pu.selection_id, pu.original_filename, pu.stored_filename
    FROM pdf_pages pp
    JOIN pdf_uploads pu ON pp.upload_id = pu.id
    WHERE pp.id = ?
");
$page->execute([$pageId]);
$page = $page->fetch();

if (!$page) die('<p>Página no encontrada</p>');

$originalPdfPath = ORIGINALS_PATH . '/' . $page['stored_filename'];
$splitFilePath   = BASE_PATH . '/' . $page['file_path'];

$textFromOriginal = file_exists($originalPdfPath)
    ? extractPageTextFromOriginalPDF($originalPdfPath, (int)$page['page_number'])
    : '(archivo original no encontrado)';

$textFromSplit = file_exists($splitFilePath)
    ? extractTextFromPDF($splitFilePath)
    : '(archivo de página no encontrado)';

$prompt = getConfig('cv_detect_prompt');
$openaiRaw = null;
$openaiParsed = null;

if (!empty(trim($textFromOriginal)) && !empty(getConfig('openai_api_key'))) {
    $openaiRaw    = callOpenAIRaw(mb_substr($textFromOriginal, 0, 3000), $prompt);
    $openaiParsed = $openaiRaw ? parseOpenAIJson($openaiRaw) : null;
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Debug Página <?= $pageId ?> - Talent Filter</title>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; margin: 0; padding: 24px; color: #374151; }
.container { max-width: 960px; margin: 0 auto; }
h1 { color: #1a2332; font-size: 22px; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 20px; overflow: hidden; }
.card-header { background: #1a2332; color: #fff; padding: 12px 20px; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
.card-body { padding: 20px; }
pre { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 16px; white-space: pre-wrap; word-break: break-all; font-size: 13px; max-height: 400px; overflow-y: auto; margin: 0; }
.meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.meta-item { background: #f9fafb; border-radius: 6px; padding: 12px; }
.meta-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
.meta-value { font-size: 16px; font-weight: 700; color: #1a2332; margin-top: 4px; }
.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
.badge.start { background: #d1fae5; color: #065f46; }
.badge.cont { background: #fee2e2; color: #991b1b; }
.char-count { font-size: 12px; color: #9ca3af; margin-top: 6px; }
.warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; font-size: 14px; }
a.back { display: inline-block; margin-bottom: 16px; color: #3b82f6; text-decoration: none; font-size: 14px; }
</style>
</head>
<body>
<div class="container">
    <a class="back" href="/talent-filter/index.php?page=logs">← Volver a Logs</a>
    <h1>🔍 Diagnóstico — Página #<?= $pageId ?></h1>

    <div class="meta">
        <div class="meta-item">
            <div class="meta-label">Nº de página</div>
            <div class="meta-value"><?= $page['page_number'] ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Archivo origen</div>
            <div class="meta-value" style="font-size:13px"><?= sanitize($page['original_filename']) ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Estado actual</div>
            <div class="meta-value"><?= sanitize($page['status']) ?></div>
        </div>
        <div class="meta-item">
            <div class="meta-label">Marcada como</div>
            <div class="meta-value">
                <?php if ($page['is_cv_start'] === null): ?>
                    <span class="badge cont">Sin analizar</span>
                <?php elseif ($page['is_cv_start']): ?>
                    <span class="badge start">✅ INICIO de CV</span>
                <?php else: ?>
                    <span class="badge cont">➡️ Continuación</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Texto extraído del PDF ORIGINAL (pág. <?= $page['page_number'] ?>)</span>
            <span style="font-size:12px; opacity:.7"><?= mb_strlen(trim($textFromOriginal)) ?> caracteres</span>
        </div>
        <div class="card-body">
            <?php if (mb_strlen(trim($textFromOriginal)) < 20): ?>
                <div class="warn">⚠️ Texto muy corto o vacío. El PDF puede ser escaneado (imagen) y no contener texto digital.</div>
            <?php else: ?>
                <pre><?= sanitize($textFromOriginal) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span>Texto extraído del PDF recortado (FPDI)</span>
            <span style="font-size:12px; opacity:.7"><?= mb_strlen(trim($textFromSplit)) ?> caracteres</span>
        </div>
        <div class="card-body">
            <?php if (mb_strlen(trim($textFromSplit)) < 20): ?>
                <div class="warn">⚠️ FPDI no preserva el texto en el PDF recortado — confirmado. Por eso leemos del original.</div>
            <?php else: ?>
                <pre><?= sanitize($textFromSplit) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($openaiRaw !== null): ?>
    <div class="card">
        <div class="card-header">Respuesta cruda de OpenAI</div>
        <div class="card-body">
            <pre><?= sanitize($openaiRaw) ?></pre>
        </div>
    </div>
    <div class="card">
        <div class="card-header">JSON parseado</div>
        <div class="card-body">
            <pre><?= sanitize(json_encode($openaiParsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php if (!$openaiParsed): ?>
                <div class="warn" style="margin-top:12px">⚠️ No se pudo parsear el JSON de la respuesta de OpenAI.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif (empty(trim($textFromOriginal))): ?>
    <div class="warn">⚠️ No se llamó a OpenAI porque el texto extraído está vacío.</div>
    <?php elseif (empty(getConfig('openai_api_key'))): ?>
    <div class="warn">⚠️ No se llamó a OpenAI porque no hay API key configurada.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Resultado guardado en BD (analysis_result)</div>
        <div class="card-body">
            <pre><?= sanitize(json_encode(json_decode($page['analysis_result'] ?? 'null'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </div>
</div>
</body>
</html>
