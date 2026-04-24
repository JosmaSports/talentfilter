<?php
$selections = getSelections();
?>

<div class="card mb-24">
    <div class="card-header">
        <h2><i class="fas fa-filter"></i> Filtros</h2>
        <button class="btn btn-success" id="btnDownloadAll" onclick="downloadAllCVs()">
            <i class="fas fa-download"></i> Descargar todos (ZIP)
        </button>
    </div>
    <div class="card-body">
        <div class="filters-bar">
            <div class="form-group">
                <label for="filterSelectionListos">Selección de CV</label>
                <select id="filterSelectionListos" class="form-control" onchange="loadListos()">
                    <option value="">Todas las selecciones</option>
                    <?php foreach ($selections as $sel): ?>
                        <option value="<?= $sel['id'] ?>"><?= sanitize($sel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="filterCandidateName">Nombre candidato</label>
                <input type="text" id="filterCandidateName" class="form-control" placeholder="Buscar por nombre..." oninput="debounceLoadListos()">
            </div>
            <div class="form-group">
                <label for="filterPages">Nº de páginas</label>
                <select id="filterPages" class="form-control" onchange="loadListos()">
                    <option value="">Todas</option>
                    <option value="1">1 página</option>
                    <option value="2">2 páginas</option>
                    <option value="3">3 páginas</option>
                    <option value="4+">4 o más</option>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-check-circle"></i> CVs Unificados</h2>
        <span class="badge badge-success" id="listosCount">0 CVs</span>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Candidato</th>
                        <th>Selección</th>
                        <th>Páginas</th>
                        <th>Rango</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="listosBody">
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
        <div class="pagination" id="listosPagination" style="padding: 16px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadListos();
});
</script>
