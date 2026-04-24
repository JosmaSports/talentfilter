<?php
$selections = getSelections();
?>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-file-pdf"></i></div>
        <div class="stat-info">
            <?php
            $db = getDB();
            $totalPages = $db->query("SELECT COUNT(*) as c FROM pdf_pages")->fetch()['c'];
            ?>
            <h3><?= $totalPages ?></h3>
            <p>Páginas extraídas</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
            <?php $pending = $db->query("SELECT COUNT(*) as c FROM pdf_pages WHERE status = 'pending'")->fetch()['c']; ?>
            <h3><?= $pending ?></h3>
            <p>Pendientes de análisis</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info">
            <?php $unified = $db->query("SELECT COUNT(*) as c FROM cv_unified WHERE status = 'active'")->fetch()['c']; ?>
            <h3><?= $unified ?></h3>
            <p>CVs unificados</p>
        </div>
    </div>
</div>

<div class="card mb-24">
    <div class="card-header">
        <h2><i class="fas fa-upload"></i> Subir PDF de CVs</h2>
    </div>
    <div class="card-body">
        <div class="grid-2">
            <div class="form-group">
                <label for="selectionName">Nombre de la Selección</label>
                <input type="text" id="selectionName" class="form-control" placeholder="Ej: Selección Enero 2026 - Desarrolladores">
            </div>
            <div class="form-group">
                <label for="selectionExisting">O seleccionar existente</label>
                <select id="selectionExisting" class="form-control">
                    <option value="">-- Nueva selección --</option>
                    <?php foreach ($selections as $sel): ?>
                        <option value="<?= $sel['id'] ?>"><?= sanitize($sel['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="upload-zone" id="uploadZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <p class="upload-label">Arrastra tu PDF aquí o haz clic para seleccionar</p>
            <p>Formatos aceptados: PDF | Tamaño máximo: 500MB</p>
            <input type="file" id="fileInput" accept=".pdf" style="display:none">
        </div>

        <div id="fileInfo" style="display:none">
            <div class="file-info">
                <i class="fas fa-file-pdf"></i>
                <div class="file-info-text">
                    <h4 id="fileName"></h4>
                    <p id="fileSize"></p>
                </div>
                <button class="btn btn-sm btn-outline" id="removeFile"><i class="fas fa-times"></i></button>
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-primary" id="btnUpload" disabled>
                <i class="fas fa-scissors"></i> Subir y Separar PDF
            </button>
        </div>
    </div>
</div>

<div id="splitProgress" style="display:none">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-cogs"></i> Procesando PDF</h2>
        </div>
        <div class="card-body">
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progressFill" style="width:0%"></div>
            </div>
            <div class="progress-info">
                <span id="progressText">Preparando...</span>
                <span id="progressPercent">0%</span>
            </div>
            <div id="splitLog" style="margin-top:16px; max-height:200px; overflow-y:auto; font-family:monospace; font-size:13px; color:var(--gray-500);"></div>
        </div>
    </div>
</div>
