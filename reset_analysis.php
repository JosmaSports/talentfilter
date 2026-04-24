<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Contar qué se va a resetear
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'analyzed' THEN 1 ELSE 0 END) as analyzed,
        SUM(CASE WHEN status = 'unified' THEN 1 ELSE 0 END) as unified
    FROM pdf_pages
")->fetch();

$cvCount = $db->query("SELECT COUNT(*) as c FROM cv_unified")->fetch()['c'];

if (isset($_POST['confirm'])) {
    // Borrar CVs unificados y sus archivos
    $cvs = $db->query("SELECT file_path FROM cv_unified")->fetchAll();
    foreach ($cvs as $cv) {
        $path = BASE_PATH . '/' . $cv['file_path'];
        if (file_exists($path)) unlink($path);
    }
    $db->exec("DELETE FROM cv_unified");

    // Resetear páginas a 'pending'
    $db->exec("UPDATE pdf_pages SET status = 'pending', is_cv_start = NULL, candidate_name = NULL, analysis_result = NULL, cv_group_id = NULL WHERE status IN ('analyzed','unified','analyzing','error')");

    Logger::log('info', 'Análisis reseteado manualmente desde reset_analysis.php');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Reset - Talent Filter</title>';
    echo '<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0}.card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center;max-width:500px}h1{color:#1a2332}.success{color:#10b981;font-size:48px}a{display:inline-block;margin-top:20px;padding:12px 30px;background:#1a2332;color:#fff;text-decoration:none;border-radius:8px}</style></head>';
    echo '<body><div class="card"><div class="success">&#10003;</div><h1>Reset completado</h1>';
    echo '<p>Todas las páginas han sido marcadas como <strong>pendientes</strong> y los CVs unificados han sido eliminados.</p>';
    echo '<p style="margin-top:12px;color:#6b7280">Ahora ve a <strong>CVs Pendientes</strong> y vuelve a lanzar el análisis.</p>';
    echo '<a href="index.php?page=pendientes">Ir a CVs Pendientes</a></div></body></html>';
    exit;
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reset análisis - Talent Filter</title>
<style>
body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f2f5;margin:0}
.card{background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:520px;width:90%}
h1{color:#1a2332;margin-bottom:8px}
.warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:20px 0;font-size:14px}
table{width:100%;border-collapse:collapse;margin:16px 0}
td{padding:10px 0;border-bottom:1px solid #f3f4f6;font-size:15px}
td:first-child{color:#6b7280}
td:last-child{font-weight:700;text-align:right}
.btn-danger{background:#ef4444;color:#fff;border:none;padding:14px 28px;border-radius:8px;font-size:15px;cursor:pointer;width:100%;margin-top:8px}
.btn-secondary{display:block;text-align:center;margin-top:12px;color:#6b7280;text-decoration:none;font-size:14px}
</style>
</head>
<body>
<div class="card">
    <h1>⚠️ Reset del análisis</h1>
    <p style="color:#6b7280">Esto borrará todos los resultados de análisis y CVs unificados para poder procesar de nuevo.</p>

    <div class="warn">Esta operación <strong>no se puede deshacer</strong>. Los PDFs de páginas individuales se conservan.</div>

    <table>
        <tr><td>Páginas analizadas</td><td><?= $stats['analyzed'] ?></td></tr>
        <tr><td>Páginas unificadas</td><td><?= $stats['unified'] ?></td></tr>
        <tr><td>CVs unificados creados</td><td><?= $cvCount ?></td></tr>
    </table>

    <form method="post">
        <button type="submit" name="confirm" value="1" class="btn-danger">
            🗑️ Sí, resetear todo el análisis
        </button>
    </form>
    <a class="btn-secondary" href="index.php?page=pendientes">Cancelar</a>
</div>
</body>
</html>
