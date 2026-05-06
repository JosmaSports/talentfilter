<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$db = getDB();

// ── Acción: eliminar todos los registros pendientes ───────────────────────────
if (($input['action'] ?? '') === 'delete_all_pending') {
    $selectionId = (int)($input['selection_id'] ?? 0);
    $uploadId    = (int)($input['upload_id'] ?? 0);

    try {
        // Obtener los uploads afectados según los filtros activos
        $where  = [];
        $params = [];

        if ($uploadId) {
            $where[]  = 'id = ?';
            $params[] = $uploadId;
        } elseif ($selectionId) {
            $where[]  = 'selection_id = ?';
            $params[] = $selectionId;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $uploads  = $db->prepare("SELECT * FROM pdf_uploads $whereStr");
        $uploads->execute($params);
        $uploads = $uploads->fetchAll();

        $deletedUploads = 0;
        $deletedPages   = 0;

        foreach ($uploads as $upload) {
            // Borrar archivos de páginas divididas
            $pagesDir = PAGES_PATH . '/' . $upload['id'];
            if (is_dir($pagesDir)) {
                foreach (glob("$pagesDir/*.pdf") ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($pagesDir);
            }

            // Borrar el PDF original
            $origFile = ORIGINALS_PATH . '/' . $upload['stored_filename'];
            if (file_exists($origFile)) {
                @unlink($origFile);
            }

            // Borrar registros de páginas y del upload
            $db->prepare("DELETE FROM pdf_pages WHERE upload_id = ?")->execute([$upload['id']]);
            $db->prepare("DELETE FROM pdf_uploads WHERE id = ?")->execute([$upload['id']]);

            $deletedPages += (int)$upload['total_pages'];
            $deletedUploads++;
        }

        $label = $selectionId ? "selección #$selectionId" : ($uploadId ? "archivo #$uploadId" : 'todos');
        Logger::log('info', "Eliminados $deletedUploads archivos y $deletedPages páginas pendientes ($label)");

        jsonResponse([
            'success'          => true,
            'deleted_uploads'  => $deletedUploads,
            'deleted_pages'    => $deletedPages,
            'message'          => "Se han eliminado $deletedUploads archivo(s) y $deletedPages página(s) pendientes.",
        ]);

    } catch (\Exception $e) {
        Logger::log('error', "Error eliminando pendientes: " . $e->getMessage());
        jsonResponse(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
    }
}

// ── Acción: eliminar un CV unificado individual ───────────────────────────────
$cvId = (int)($input['cv_id'] ?? 0);

if (!$cvId) {
    jsonResponse(['error' => 'cv_id requerido'], 400);
}

$stmt = $db->prepare("SELECT cv.*, s.name as selection_name FROM cv_unified cv JOIN selections s ON cv.selection_id = s.id WHERE cv.id = ?");
$stmt->execute([$cvId]);
$cv = $stmt->fetch();

if (!$cv) {
    jsonResponse(['error' => 'CV no encontrado'], 404);
}

try {
    $filePath = BASE_PATH . '/' . $cv['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    $db->prepare("UPDATE cv_unified SET status = 'deleted' WHERE id = ?")->execute([$cvId]);

    $nameLog = $cv['candidate_name'] ? " ({$cv['candidate_name']})" : "";
    Logger::log('info', "CV eliminado: {$cv['file_name']}$nameLog", $cv['selection_id'], $cv['upload_id']);

    jsonResponse(['success' => true, 'message' => 'CV eliminado correctamente']);

} catch (\Exception $e) {
    Logger::log('error', "Error eliminando CV: " . $e->getMessage(), $cv['selection_id']);
    jsonResponse(['error' => 'Error al eliminar: ' . $e->getMessage()], 500);
}
