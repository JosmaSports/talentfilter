<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';

use setasign\Fpdi\Fpdi;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);
ini_set('memory_limit', '512M');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = (int)($input['upload_id'] ?? 0);
$batchStart = (int)($input['batch_start'] ?? 1);
$batchSize = (int)($input['batch_size'] ?? 50);

if (!$uploadId) {
    jsonResponse(['error' => 'upload_id requerido'], 400);
}

$db = getDB();

$upload = $db->prepare("SELECT * FROM pdf_uploads WHERE id = ?");
$upload->execute([$uploadId]);
$upload = $upload->fetch();

if (!$upload) {
    jsonResponse(['error' => 'Upload no encontrado'], 404);
}

$pdfPath = ORIGINALS_PATH . '/' . $upload['stored_filename'];
if (!file_exists($pdfPath)) {
    jsonResponse(['error' => 'Archivo PDF no encontrado en el servidor'], 404);
}

try {
    $pdf = new Fpdi();
    $totalPages = $pdf->setSourceFile($pdfPath);

    if ($batchStart === 1) {
        $db->prepare("UPDATE pdf_uploads SET total_pages = ?, status = 'splitting' WHERE id = ?")
           ->execute([$totalPages, $uploadId]);
        Logger::log('info', "Iniciando separación: $totalPages páginas", $upload['selection_id'], $uploadId);
    }

    $batchEnd = min($batchStart + $batchSize - 1, $totalPages);
    $pagesDir = PAGES_PATH . '/' . $uploadId;
    if (!is_dir($pagesDir)) {
        mkdir($pagesDir, 0755, true);
    }

    $processed = [];

    for ($pageNum = $batchStart; $pageNum <= $batchEnd; $pageNum++) {
        $newPdf = new Fpdi();
        $newPdf->setSourceFile($pdfPath);
        $tplId = $newPdf->importPage($pageNum);
        $size = $newPdf->getTemplateSize($tplId);

        $newPdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $newPdf->useTemplate($tplId);

        $fileName = $uploadId . '-' . $pageNum . '.pdf';
        $filePath = $pagesDir . '/' . $fileName;
        $newPdf->Output('F', $filePath);

        $relPath = 'uploads/pages/' . $uploadId . '/' . $fileName;

        $stmt = $db->prepare("INSERT INTO pdf_pages (upload_id, page_number, file_path, file_name, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$uploadId, $pageNum, $relPath, $fileName]);

        $processed[] = [
            'page' => $pageNum,
            'file' => $fileName,
            'id' => (int)$db->lastInsertId()
        ];
    }

    $isComplete = $batchEnd >= $totalPages;

    if ($isComplete) {
        $db->prepare("UPDATE pdf_uploads SET status = 'split' WHERE id = ?")
           ->execute([$uploadId]);
        Logger::log('success', "PDF separado completamente: $totalPages páginas", $upload['selection_id'], $uploadId);
    }

    jsonResponse([
        'success' => true,
        'total_pages' => $totalPages,
        'batch_start' => $batchStart,
        'batch_end' => $batchEnd,
        'pages_processed' => count($processed),
        'is_complete' => $isComplete,
        'next_batch_start' => $isComplete ? null : $batchEnd + 1,
        'processed' => $processed
    ]);

} catch (\Exception $e) {
    $db->prepare("UPDATE pdf_uploads SET status = 'error' WHERE id = ?")
       ->execute([$uploadId]);
    Logger::log('error', "Error al separar PDF: " . $e->getMessage(), $upload['selection_id'], $uploadId);
    jsonResponse(['error' => 'Error al separar el PDF: ' . $e->getMessage()], 500);
}
