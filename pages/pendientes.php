<?php
$db = getDB();
$selections = getSelections();
?>

<div class="card mb-24">
    <div class="card-header">
        <h2><i class="fas fa-filter"></i> Filtros</h2>
    </div>
    <div class="card-body">
        <div class="filters-bar">
            <div class="form-group">
                <label for="filterSelection">Selección de CV</label>
                <select id="filterSelection" class="form-control" onchange="loadPendientes()">
                    <option value="">Todas las selecciones</option>
                    <?php foreach ($selections as $sel): ?>
                        <option value="<?= $sel['id'] ?>"><?= sanitize($sel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="filterUpload">Archivo PDF</label>
                <select id="filterUpload" class="form-control" onchange="loadPendientes()">
                    <option value="">Todos los archivos</option>
                </select>
            </div>
            <div class="form-group" style="flex:0">
                <label>&nbsp;</label>
                <button class="btn btn-primary" id="btnUnify" onclick="startUnification()">
                    <i class="fas fa-object-group"></i> Unificar CVs
                </button>
            </div>
        </div>
    </div>
</div>

<div id="unifyProgress" style="display:none">
    <div class="processing-status">
        <h4><i class="fas fa-spinner fa-spin"></i> Analizando y unificando CVs...</h4>
        <div class="progress-bar" style="margin-top:12px">
            <div class="progress-bar-fill" id="unifyProgressFill" style="width:0%"></div>
        </div>
        <div class="progress-info">
            <span id="unifyProgressText">Iniciando análisis...</span>
            <span id="unifyProgressPercent">0%</span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-clock"></i> Páginas Pendientes</h2>
        <div style="display:flex;align-items:center;gap:12px">
            <span class="badge badge-pending" id="pendingCount">0 pendientes</span>
            <button id="btnDeleteAll" class="btn btn-danger btn-sm" onclick="deleteAllPendientes()">
                <i class="fas fa-trash-alt"></i> Eliminar todos
            </button>
        </div>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Archivo origen</th>
                        <th>Página</th>
                        <th>Nombre archivo</th>
                        <th>Estado</th>
                        <th>Detección IA</th>
                        <th>Selección</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="pendientesBody">
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>Cargando...</h3>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="pendientesPagination" style="padding: 16px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadPendientes();
    loadUploadFilter();
});
</script>
