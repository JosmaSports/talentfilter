<?php
$selections = getSelections();
?>

<div class="card mb-24">
    <div class="card-header">
        <h2><i class="fas fa-filter"></i> Filtros</h2>
        <button class="btn btn-outline btn-sm" onclick="clearLogs()">
            <i class="fas fa-trash"></i> Limpiar logs
        </button>
    </div>
    <div class="card-body">
        <div class="filters-bar">
            <div class="form-group">
                <label for="filterLogLevel">Nivel</label>
                <select id="filterLogLevel" class="form-control" onchange="loadLogs()">
                    <option value="">Todos</option>
                    <option value="info">Info</option>
                    <option value="success">Success</option>
                    <option value="warning">Warning</option>
                    <option value="error">Error</option>
                </select>
            </div>
            <div class="form-group">
                <label for="filterLogSelection">Selección</label>
                <select id="filterLogSelection" class="form-control" onchange="loadLogs()">
                    <option value="">Todas</option>
                    <?php foreach ($selections as $sel): ?>
                        <option value="<?= $sel['id'] ?>"><?= sanitize($sel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="filterLogDate">Fecha</label>
                <input type="date" id="filterLogDate" class="form-control" onchange="loadLogs()">
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-list-alt"></i> Registro de actividad</h2>
        <span class="badge badge-info" id="logsCount">0 entradas</span>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:160px">Fecha/Hora</th>
                        <th style="width:80px">Nivel</th>
                        <th>Mensaje</th>
                        <th style="width:150px">Selección</th>
                    </tr>
                </thead>
                <tbody id="logsBody">
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>Cargando...</h3>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="logsPagination" style="padding: 16px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadLogs();
});
</script>
