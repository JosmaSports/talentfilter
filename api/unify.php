<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

use setasign\Fpdi\Fpdi;

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$uploadId = (int)($input['upload_id'] ?? 0);
$selectionId = (int)($input['selection_id'] ?? 0);

if (!$uploadId && !$selectionId) {
    jsonResponse(['error' => 'upload_id o selection_id requerido'], 400);
}

$db = getDB();

$where = '';
$params = [];

if ($uploadId) {
    $where = "pp.upload_id = ?";
    $params[] = $uploadId;
} else {
    $where = "pu.selection_id = ?";
    $params[] = $selectionId;
}

$pages = $db->prepare("
    SELECT pp.*, pu.selection_id
    FROM pdf_pages pp
    JOIN pdf_uploads pu ON pp.upload_id = pu.id
    WHERE $where AND pp.status = 'analyzed'
    ORDER BY pp.upload_id, pp.page_number ASC
");
$pages->execute($params);
$allPages = $pages->fetchAll();

if (empty($allPages)) {
    jsonResponse(['error' => 'No hay páginas analizadas para unificar'], 400);
}

try {
    $cvGroups = [];
    $currentGroup = [];

    foreach ($allPages as $i => $pg) {
        if ($pg['is_cv_start'] && !empty($currentGroup)) {
            $cvGroups[] = $currentGroup;
            $currentGroup = [];
        }
        $currentGroup[] = $pg;
    }

    if (!empty($currentGroup)) {
        $cvGroups[] = $currentGroup;
    }

    $unifiedDir = UNIFIED_PATH;
    if (!is_dir($unifiedDir)) {
        mkdir($unifiedDir, 0755, true);
    }

    $created = [];
    $groupId = 1;

    foreach ($cvGroups as $group) {
        $firstPage = $group[0];
        $lastPage = end($group);
        $upId = $firstPage['upload_id'];
        $selId = $firstPage['selection_id'];
        $candidateName = null;

        foreach ($group as $pg) {
            if (!empty($pg['candidate_name'])) {
                $candidateName = $pg['candidate_name'];
                break;
            }
        }

        $pageRange = $firstPage['page_number'] . '-' . $lastPage['page_number'];
        $slug      = candidateNameToFilename($candidateName);
        $fileName  = $slug . '_pag' . $pageRange . '.pdf';
        // Evitar colisiones de nombre
        $counter = 1;
        while (file_exists($unifiedDir . '/' . $fileName)) {
            $fileName = $slug . '_pag' . $pageRange . '_' . (++$counter) . '.pdf';
        }
        $filePath = $unifiedDir . '/' . $fileName;
        $relPath  = 'uploads/unified/' . $fileName;

        $mergedPdf = new Fpdi();

        foreach ($group as $pg) {
            $absPath = BASE_PATH . '/' . $pg['file_path'];
            if (!file_exists($absPath)) continue;

            $mergedPdf->setSourceFile($absPath);
            $tplId = $mergedPdf->importPage(1);
            $size = $mergedPdf->getTemplateSize($tplId);
            $mergedPdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $mergedPdf->useTemplate($tplId);
        }

        $mergedPdf->Output('F', $filePath);

        $stmt = $db->prepare("
            INSERT INTO cv_unified (selection_id, upload_id, file_path, file_name, page_range, num_pages, candidate_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $selId,
            $upId,
            $relPath,
            $fileName,
            $pageRange,
            count($group),
            $candidateName
        ]);
        $cvId = $db->lastInsertId();

        $pageIds = array_column($group, 'id');
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $db->prepare("UPDATE pdf_pages SET status = 'unified', cv_group_id = ? WHERE id IN ($placeholders)")
           ->execute(array_merge([$cvId], $pageIds));

        $created[] = [
            'cv_id' => (int)$cvId,
            'file_name' => $fileName,
            'page_range' => $pageRange,
            'num_pages' => count($group),
            'candidate_name' => $candidateName
        ];

        $nameLog = $candidateName ? " ($candidateName)" : "";
        Logger::log('success', "CV unificado: páginas $pageRange$nameLog - $fileName", $selId, $upId);

        $groupId++;
    }

    if ($uploadId) {
        $db->prepare("UPDATE pdf_uploads SET status = 'done' WHERE id = ?")->execute([$uploadId]);
    }

    Logger::log('success', "Unificación completada: " . count($created) . " CVs creados", $selectionId ?: $selId);

    jsonResponse([
        'success' => true,
        'cvs_created' => count($created),
        'cvs' => $created
    ]);

} catch (\Exception $e) {
    Logger::log('error', "Error en unificación: " . $e->getMessage(), $selectionId);
    jsonResponse(['error' => 'Error en la unificación: ' . $e->getMessage()], 500);
}
