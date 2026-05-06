<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

$action = $_GET['action'] ?? 'single';

if ($action === 'single') {
    $cvId = (int)($_GET['id'] ?? 0);
    if (!$cvId) {
        http_response_code(400);
        die('ID requerido');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM cv_unified WHERE id = ? AND status = 'active'");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) {
        http_response_code(404);
        die('CV no encontrado');
    }

    $filePath = BASE_PATH . '/' . $cv['file_path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('Archivo no encontrado');
    }

    $downloadName = $cv['candidate_name']
        ? preg_replace('/[^a-zA-Z0-9áéíóúñÁÉÍÓÚÑ _-]/u', '', $cv['candidate_name']) . '_CV.pdf'
        : $cv['file_name'];

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache');
    readfile($filePath);
    exit;
}

if ($action === 'zip') {
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    $db = getDB();
    $selectionId = (int)($_GET['selection_id'] ?? 0);
    $candidateName = trim($_GET['candidate_name'] ?? '');
    $numPages = trim($_GET['num_pages'] ?? '');

    $where = ["cv.status = 'active'"];
    $params = [];

    if ($selectionId) {
        $where[] = "cv.selection_id = ?";
        $params[] = $selectionId;
    }
    if ($candidateName) {
        $where[] = "cv.candidate_name LIKE ?";
        $params[] = "%$candidateName%";
    }
    if ($numPages) {
        if ($numPages === '4+') {
            $where[] = "cv.num_pages >= 4";
        } else {
            $where[] = "cv.num_pages = ?";
            $params[] = (int)$numPages;
        }
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT cv.*, s.name as selection_name
        FROM cv_unified cv
        JOIN selections s ON cv.selection_id = s.id
        WHERE $whereStr
        ORDER BY cv.id ASC
    ");
    $stmt->execute($params);
    $cvs = $stmt->fetchAll();

    if (empty($cvs)) {
        http_response_code(404);
        die('No hay CVs para descargar');
    }

    $zipName = 'CVs_' . date('Y-m-d_His') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die('Error creando archivo ZIP');
    }

    $counter = 1;
    foreach ($cvs as $cv) {
        $filePath = BASE_PATH . '/' . $cv['file_path'];
        if (!file_exists($filePath)) continue;

        $name = $cv['candidate_name']
            ? preg_replace('/[^a-zA-Z0-9áéíóúñÁÉÍÓÚÑ _-]/u', '', $cv['candidate_name'])
            : 'CV_' . $counter;

        $folder = preg_replace('/[^a-zA-Z0-9áéíóúñÁÉÍÓÚÑ _-]/u', '', $cv['selection_name']);
        $zip->addFile($filePath, "$folder/{$name}_CV.pdf");
        $counter++;
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache');
    readfile($zipPath);
    unlink($zipPath);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
