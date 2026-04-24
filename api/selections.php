<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $selections = $db->query("
        SELECT s.*,
            (SELECT COUNT(*) FROM pdf_pages pp JOIN pdf_uploads pu ON pp.upload_id = pu.id WHERE pu.selection_id = s.id) as total_pages,
            (SELECT COUNT(*) FROM pdf_pages pp JOIN pdf_uploads pu ON pp.upload_id = pu.id WHERE pu.selection_id = s.id AND pp.status = 'pending') as pending_pages,
            (SELECT COUNT(*) FROM cv_unified cv WHERE cv.selection_id = s.id AND cv.status = 'active') as unified_cvs
        FROM selections s
        ORDER BY s.created_at DESC
    ")->fetchAll();
    jsonResponse(['success' => true, 'selections' => $selections]);
}

if ($action === 'uploads') {
    $selectionId = (int)($_GET['selection_id'] ?? 0);
    $where = $selectionId ? "WHERE selection_id = $selectionId" : "";
    $uploads = $db->query("SELECT * FROM pdf_uploads $where ORDER BY created_at DESC")->fetchAll();
    jsonResponse(['success' => true, 'uploads' => $uploads]);
}

// Devuelve solo los IDs de todas las páginas pendientes (sin paginación) para el pool paralelo
if ($action === 'all_pending_ids') {
    $selectionId = (int)($_GET['selection_id'] ?? 0);
    $uploadId    = (int)($_GET['upload_id'] ?? 0);

    $where  = ["pp.status IN ('pending', 'analyzing')"];
    $params = [];

    if ($selectionId) { $where[] = "pu.selection_id = ?"; $params[] = $selectionId; }
    if ($uploadId)    { $where[] = "pp.upload_id = ?";    $params[] = $uploadId; }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT pp.id, pp.page_number, pp.upload_id
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        WHERE $whereStr
        ORDER BY pp.upload_id ASC, pp.page_number ASC
    ");
    $stmt->execute($params);
    $ids = $stmt->fetchAll();

    jsonResponse(['success' => true, 'pages' => $ids, 'total' => count($ids)]);
}

if ($action === 'pending_pages') {
    $selectionId = (int)($_GET['selection_id'] ?? 0);
    $uploadId = (int)($_GET['upload_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    $where = ["pp.status IN ('pending', 'analyzing', 'analyzed')"];
    $params = [];

    if ($selectionId) {
        $where[] = "pu.selection_id = ?";
        $params[] = $selectionId;
    }
    if ($uploadId) {
        $where[] = "pp.upload_id = ?";
        $params[] = $uploadId;
    }

    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        WHERE $whereStr
    ");
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT pp.*, pu.original_filename, s.name as selection_name
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        JOIN selections s ON pu.selection_id = s.id
        WHERE $whereStr
        ORDER BY pp.upload_id ASC, pp.page_number ASC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $pages = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'pages' => $pages,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ]);
}

if ($action === 'unified_cvs') {
    $selectionId = (int)($_GET['selection_id'] ?? 0);
    $candidateName = trim($_GET['candidate_name'] ?? '');
    $numPages = trim($_GET['num_pages'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;

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

    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM cv_unified cv WHERE $whereStr");
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT cv.*, s.name as selection_name
        FROM cv_unified cv
        JOIN selections s ON cv.selection_id = s.id
        WHERE $whereStr
        ORDER BY cv.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $cvs = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'cvs' => $cvs,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ]);
}

if ($action === 'next_pending') {
    $uploadId = (int)($_GET['upload_id'] ?? 0);
    $selectionId = (int)($_GET['selection_id'] ?? 0);

    $where = ["pp.status = 'pending'"];
    $params = [];

    if ($uploadId) {
        $where[] = "pp.upload_id = ?";
        $params[] = $uploadId;
    } elseif ($selectionId) {
        $where[] = "pu.selection_id = ?";
        $params[] = $selectionId;
    }

    $whereStr = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT pp.id, pp.page_number, pp.upload_id, pp.file_name
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        WHERE $whereStr
        ORDER BY pp.upload_id ASC, pp.page_number ASC
        LIMIT 1
    ");
    $stmt->execute($params);
    $next = $stmt->fetch();

    $totalPending = $db->prepare("
        SELECT COUNT(*) as c
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        WHERE $whereStr
    ");
    $totalPending->execute($params);
    $pending = $totalPending->fetch()['c'];

    $totalAnalyzed = $db->prepare("
        SELECT COUNT(*) as c
        FROM pdf_pages pp
        JOIN pdf_uploads pu ON pp.upload_id = pu.id
        WHERE pp.status = 'analyzed'" . ($uploadId ? " AND pp.upload_id = ?" : ($selectionId ? " AND pu.selection_id = ?" : ""))
    );
    $analyzeParams = [];
    if ($uploadId) $analyzeParams[] = $uploadId;
    elseif ($selectionId) $analyzeParams[] = $selectionId;
    $totalAnalyzed->execute($analyzeParams);
    $analyzed = $totalAnalyzed->fetch()['c'];

    jsonResponse([
        'success' => true,
        'next_page' => $next,
        'pending_count' => (int)$pending,
        'analyzed_count' => (int)$analyzed
    ]);
}

jsonResponse(['error' => 'Acción no válida'], 400);
