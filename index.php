<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$currentUser = requireLogin();

$page = $_GET['page'] ?? 'extractor';
$validPages = ['extractor', 'pendientes', 'listos', 'configuracion', 'logs'];
if (!in_array($page, $validPages)) {
    $page = 'extractor';
}

$pageFile = __DIR__ . '/pages/' . $page . '.php';
if (!file_exists($pageFile)) {
    $page = 'extractor';
    $pageFile = __DIR__ . '/pages/extractor.php';
}

$pageTitles = [
    'extractor' => 'Extractor de CVs',
    'pendientes' => 'CVs Pendientes',
    'listos' => 'CVs Listos',
    'configuracion' => 'Configuración',
    'logs' => 'Registro de Actividad',
];
$pageTitle = $pageTitles[$page] ?? 'Talent Filter';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - Talent Filter</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-filter"></i>
                <span>Talent Filter</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="?page=extractor" class="nav-item <?= $page === 'extractor' ? 'active' : '' ?>">
                <i class="fas fa-file-import"></i>
                <span>Extractor</span>
            </a>
            <a href="?page=pendientes" class="nav-item <?= $page === 'pendientes' ? 'active' : '' ?>">
                <i class="fas fa-clock"></i>
                <span>CVs Pendientes</span>
            </a>
            <a href="?page=listos" class="nav-item <?= $page === 'listos' ? 'active' : '' ?>">
                <i class="fas fa-check-circle"></i>
                <span>CVs Listos</span>
            </a>
            <div class="nav-divider"></div>
            <a href="?page=configuracion" class="nav-item <?= $page === 'configuracion' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i>
                <span>Configuración</span>
            </a>
            <a href="?page=logs" class="nav-item <?= $page === 'logs' ? 'active' : '' ?>">
                <i class="fas fa-list-alt"></i>
                <span>Logs</span>
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-avatar">
                    <?php
                        $displayName = $currentUser['name'] ?: $currentUser['email'];
                        $initial = strtoupper(mb_substr($displayName, 0, 1, 'UTF-8'));
                    ?>
                    <?= sanitize($initial) ?>
                </div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name" title="<?= sanitize($displayName) ?>"><?= sanitize($displayName) ?></div>
                    <div class="sidebar-user-email" title="<?= sanitize($currentUser['email']) ?>"><?= sanitize($currentUser['email']) ?></div>
                </div>
                <a href="<?= sanitize(defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : '') ?>/logout.php" class="sidebar-user-logout" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
            <small>&copy; <?= date('Y') ?> Talent Filter</small>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-bar">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="page-title"><?= sanitize($pageTitle) ?></h1>
        </header>
        <div class="content-area">
            <?php include $pageFile; ?>
        </div>
    </main>

    <div class="modal-overlay" id="modalOverlay" style="display:none">
        <div class="modal">
            <div class="modal-header">
                <h3 id="modalTitle">Confirmar</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="modalCancel">Cancelar</button>
                <button class="btn btn-danger" id="modalConfirm">Confirmar</button>
            </div>
        </div>
    </div>

    <div id="toast-container"></div>

    <script src="assets/js/app.js"></script>
</body>
</html>
