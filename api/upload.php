<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$selectionId = $_POST['selection_id'] ?? null;
$selectionName = trim($_POST['selection_name'] ?? '');

if (!$selectionId && empty($selectionName)) {
    jsonResponse(['error' => 'Debes indicar un nombre de selección o elegir una existente'], 400);
}

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por PHP (upload_max_filesize)',
        UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo del formulario',
        UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
        UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
    ];
    $code = $_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(['error' => $errorMessages[$code] ?? 'Error al subir el archivo'], 400);
}

$file = $_FILES['pdf'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mime !== 'application/pdf') {
    jsonResponse(['error' => 'El archivo debe ser un PDF válido'], 400);
}

$db = getDB();

try {
    $db->beginTransaction();

    if (!$selectionId) {
        $stmt = $db->prepare("INSERT INTO selections (name) VALUES (?)");
        $stmt->execute([$selectionName]);
        $selectionId = $db->lastInsertId();
    }

    $storedName = generateUniqueFilename('pdf');
    $destPath = ORIGINALS_PATH . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new \Exception('Error al mover el archivo subido');
    }

    $stmt = $db->prepare("INSERT INTO pdf_uploads (selection_id, original_filename, stored_filename, status) VALUES (?, ?, ?, 'uploading')");
    $stmt->execute([$selectionId, $file['name'], $storedName]);
    $uploadId = $db->lastInsertId();

    $db->commit();

    Logger::log('info', "PDF subido: {$file['name']}", $selectionId, $uploadId);

    jsonResponse([
        'success' => true,
        'upload_id' => (int)$uploadId,
        'selection_id' => (int)$selectionId,
        'filename' => $file['name'],
        'message' => 'Archivo subido correctamente. Iniciando separación...'
    ]);

} catch (\Exception $e) {
    $db->rollBack();
    Logger::log('error', "Error al subir PDF: " . $e->getMessage());
    jsonResponse(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
}
