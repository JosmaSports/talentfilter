<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireApiAuth();

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $db->exec("DELETE FROM logs");
    Logger::log('info', 'Logs limpiados');
    jsonResponse(['success' => true]);
}

$level = trim($_GET['level'] ?? '');
$selectionId = (int)($_GET['selection_id'] ?? 0);
$date = trim($_GET['date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($level) {
    $where[] = "l.level = ?";
    $params[] = $level;
}
if ($selectionId) {
    $where[] = "l.selection_id = ?";
    $params[] = $selectionId;
}
if ($date) {
    $where[] = "DATE(l.created_at) = ?";
    $params[] = $date;
}

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) as total FROM logs l WHERE $whereStr");
$countStmt->execute($params);
$total = $countStmt->fetch()['total'];

$stmt = $db->prepare("
    SELECT l.*, s.name as selection_name
    FROM logs l
    LEFT JOIN selections s ON l.selection_id = s.id
    WHERE $whereStr
    ORDER BY l.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

jsonResponse([
    'success' => true,
    'logs' => $logs,
    'total' => (int)$total,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => ceil($total / $perPage)
]);
