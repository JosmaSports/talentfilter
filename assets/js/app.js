/* ============================================
   Talent Filter - Frontend Application
   ============================================ */

const API = {
    upload: 'api/upload.php',
    split: 'api/split.php',
    analyze: 'api/analyze.php',
    unify: 'api/unify.php',
    download: 'api/download.php',
    delete: 'api/delete.php',
    config: 'api/config.php',
    logs: 'api/logs.php',
    selections: 'api/selections.php',
};

// ============================================
// Utilities
// ============================================

function toast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    const icons = { success: 'check-circle', error: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
    t.innerHTML = `<i class="fas fa-${icons[type] || 'info-circle'}"></i> ${message}`;
    container.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 4000);
}

async function api(url, options = {}) {
    try {
        const resp = await fetch(url, options);
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('[API] Respuesta no-JSON', { url, status: resp.status, body: text });
            const snippet = (text || '').replace(/<[^>]+>/g, ' ').trim().slice(0, 200);
            throw new Error(
                `Respuesta inválida del servidor (HTTP ${resp.status})${snippet ? ': ' + snippet : ''}`
            );
        }
        if (!resp.ok) throw new Error(data.error || data.message || 'Error del servidor');
        return data;
    } catch (e) {
        if (e.message !== 'Failed to fetch') toast(e.message, 'error');
        throw e;
    }
}

async function apiPost(url, body) {
    return api(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
}

function showModal(title, body, onConfirm) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = body;
    document.getElementById('modalOverlay').style.display = 'flex';

    const confirmBtn = document.getElementById('modalConfirm');
    const newConfirm = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);
    newConfirm.id = 'modalConfirm';
    newConfirm.addEventListener('click', () => {
        hideModal();
        onConfirm();
    });
}

function hideModal() {
    document.getElementById('modalOverlay').style.display = 'none';
}

let debounceTimer = null;
function debounceLoadListos() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadListos(), 400);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function badgeForStatus(status) {
    const map = {
        pending: ['pending', 'Pendiente'],
        analyzing: ['analyzing', 'Analizando'],
        analyzed: ['success', 'Analizado'],
        unified: ['success', 'Unificado'],
        error: ['error', 'Error'],
    };
    const [cls, label] = map[status] || ['info', status];
    return `<span class="badge badge-${cls}">${label}</span>`;
}

function badgeForLevel(level) {
    const map = { info: 'info', success: 'success', warning: 'warning', error: 'error' };
    return `<span class="badge badge-${map[level] || 'info'}">${level}</span>`;
}

function renderPagination(containerId, currentPage, totalPages, loadFn) {
    const container = document.getElementById(containerId);
    if (!container || totalPages <= 1) { if (container) container.innerHTML = ''; return; }

    let html = '';
    html += `<button ${currentPage <= 1 ? 'disabled' : ''} onclick="${loadFn}(${currentPage - 1})">&laquo;</button>`;

    const start = Math.max(1, currentPage - 2);
    const end = Math.min(totalPages, currentPage + 2);

    if (start > 1) html += `<button onclick="${loadFn}(1)">1</button>`;
    if (start > 2) html += `<button disabled>...</button>`;

    for (let i = start; i <= end; i++) {
        html += `<button class="${i === currentPage ? 'active' : ''}" onclick="${loadFn}(${i})">${i}</button>`;
    }

    if (end < totalPages - 1) html += `<button disabled>...</button>`;
    if (end < totalPages) html += `<button onclick="${loadFn}(${totalPages})">${totalPages}</button>`;

    html += `<button ${currentPage >= totalPages ? 'disabled' : ''} onclick="${loadFn}(${currentPage + 1})">&raquo;</button>`;
    container.innerHTML = html;
}

// ============================================
// Extractor (Upload & Split)
// ============================================

let selectedFile = null;

document.addEventListener('DOMContentLoaded', () => {
    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('fileInput');

    if (!uploadZone) return;

    uploadZone.addEventListener('click', () => fileInput.click());

    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });

    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));

    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) handleFileSelect(e.dataTransfer.files[0]);
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length) handleFileSelect(e.target.files[0]);
    });

    const removeBtn = document.getElementById('removeFile');
    if (removeBtn) removeBtn.addEventListener('click', clearFile);

    const uploadBtn = document.getElementById('btnUpload');
    if (uploadBtn) uploadBtn.addEventListener('click', startUpload);

    const selExisting = document.getElementById('selectionExisting');
    if (selExisting) {
        selExisting.addEventListener('change', function () {
            const nameInput = document.getElementById('selectionName');
            if (this.value) {
                nameInput.disabled = true;
                nameInput.value = '';
            } else {
                nameInput.disabled = false;
            }
        });
    }

    // Modal close handlers
    const modalClose = document.getElementById('modalClose');
    const modalCancel = document.getElementById('modalCancel');
    const modalOverlay = document.getElementById('modalOverlay');
    if (modalClose) modalClose.addEventListener('click', hideModal);
    if (modalCancel) modalCancel.addEventListener('click', hideModal);
    if (modalOverlay) modalOverlay.addEventListener('click', (e) => { if (e.target === modalOverlay) hideModal(); });

    // Sidebar toggle
    const menuToggle = document.getElementById('menuToggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('open');
        });
    }
});

function handleFileSelect(file) {
    if (file.type !== 'application/pdf') {
        toast('Solo se permiten archivos PDF', 'error');
        return;
    }
    selectedFile = file;
    document.getElementById('uploadZone').style.display = 'none';
    document.getElementById('fileInfo').style.display = 'block';
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = formatFileSize(file.size);
    document.getElementById('btnUpload').disabled = false;
}

function clearFile() {
    selectedFile = null;
    document.getElementById('uploadZone').style.display = '';
    document.getElementById('fileInfo').style.display = 'none';
    document.getElementById('fileInput').value = '';
    document.getElementById('btnUpload').disabled = true;
}

function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
    return bytes.toFixed(2) + ' ' + units[i];
}

async function startUpload() {
    if (!selectedFile) return;

    const selectionId = document.getElementById('selectionExisting').value;
    const selectionName = document.getElementById('selectionName').value.trim();

    if (!selectionId && !selectionName) {
        toast('Introduce un nombre para la selección o elige una existente', 'warning');
        return;
    }

    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Subiendo...';

    const formData = new FormData();
    formData.append('pdf', selectedFile);
    if (selectionId) formData.append('selection_id', selectionId);
    else formData.append('selection_name', selectionName);

    try {
        const resp = await fetch(API.upload, { method: 'POST', body: formData });
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('[upload] Respuesta no-JSON', { status: resp.status, body: text });
            const snippet = (text || '').replace(/<[^>]+>/g, ' ').trim().slice(0, 200);
            throw new Error(
                `Respuesta inválida del servidor (HTTP ${resp.status})${snippet ? ': ' + snippet : ''}`
            );
        }

        if (!resp.ok) throw new Error(data.error || 'Error al subir');

        toast(data.message, 'success');
        document.getElementById('splitProgress').style.display = 'block';

        await splitPDF(data.upload_id);
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-scissors"></i> Subir y Separar PDF';
    }
}

async function splitPDF(uploadId, batchStart = 1) {
    const batchSize = 50;
    const log = document.getElementById('splitLog');

    try {
        const data = await apiPost(API.split, {
            upload_id: uploadId,
            batch_start: batchStart,
            batch_size: batchSize
        });

        const percent = Math.round((data.batch_end / data.total_pages) * 100);
        document.getElementById('progressFill').style.width = percent + '%';
        document.getElementById('progressPercent').textContent = percent + '%';
        document.getElementById('progressText').textContent =
            `Separadas ${data.batch_end} de ${data.total_pages} páginas...`;

        log.innerHTML += `<div>✓ Páginas ${data.batch_start}-${data.batch_end} separadas</div>`;
        log.scrollTop = log.scrollHeight;

        if (!data.is_complete) {
            await splitPDF(uploadId, data.next_batch_start);
        } else {
            document.getElementById('progressText').textContent =
                `¡Completado! ${data.total_pages} páginas separadas correctamente.`;
            document.getElementById('progressFill').style.background = 'var(--success)';
            toast(`PDF separado: ${data.total_pages} páginas extraídas`, 'success');
            log.innerHTML += `<div style="color:var(--success); font-weight:600;">✓ Separación completada. Ve a "CVs Pendientes" para unificar.</div>`;
        }
    } catch (e) {
        document.getElementById('progressText').textContent = 'Error durante la separación';
        document.getElementById('progressFill').style.background = 'var(--danger)';
    }
}

// ============================================
// Pendientes (Pending CVs)
// ============================================

function loadPendientes(page = 1) {
    const selectionId = document.getElementById('filterSelection')?.value || '';
    const uploadId = document.getElementById('filterUpload')?.value || '';

    let url = `${API.selections}?action=pending_pages&page=${page}`;
    if (selectionId) url += `&selection_id=${selectionId}`;
    if (uploadId) url += `&upload_id=${uploadId}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('pendientesBody');
            const count = document.getElementById('pendingCount');

            if (!tbody) return;

            count.textContent = `${data.total} pendientes`;

            if (!data.pages.length) {
                tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
                    <i class="fas fa-inbox"></i><h3>No hay páginas pendientes</h3>
                    <p>Sube un PDF desde el Extractor para comenzar</p></div></td></tr>`;
                return;
            }

            tbody.innerHTML = data.pages.map(p => {
                let iaBadge = '';
                if (p.status === 'analyzed' || p.status === 'unified') {
                    if (p.is_cv_start === '1' || p.is_cv_start === 1) {
                        iaBadge = '<span class="badge badge-success">✅ Inicio CV</span>';
                    } else if (p.is_cv_start === '0' || p.is_cv_start === 0) {
                        iaBadge = '<span class="badge badge-info">➡️ Continuación</span>';
                    }
                } else {
                    iaBadge = '<span style="color:var(--gray-400);font-size:12px">—</span>';
                }
                return `<tr>
                    <td><strong>#${p.id}</strong></td>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.original_filename || ''}">${p.original_filename || '-'}</td>
                    <td>Pág. ${p.page_number}</td>
                    <td><code style="font-size:12px">${p.file_name}</code></td>
                    <td>${badgeForStatus(p.status)}</td>
                    <td>${iaBadge}</td>
                    <td>${p.selection_name || '-'}</td>
                    <td><a href="api/debug_page.php?page_id=${p.id}" target="_blank" class="btn btn-sm btn-outline btn-icon" title="Ver diagnóstico"><i class="fas fa-bug"></i></a></td>
                </tr>`;
            }).join('');

            renderPagination('pendientesPagination', data.page, data.total_pages, 'loadPendientes');
        })
        .catch(() => {});
}

async function deleteAllPendientes() {
    const selectionId = document.getElementById('filterSelection')?.value || '';
    const uploadId    = document.getElementById('filterUpload')?.value || '';

    const scope = uploadId
        ? 'el archivo seleccionado'
        : selectionId
            ? 'la selección activa'
            : 'TODOS los registros pendientes';

    if (!confirm(`¿Seguro que quieres eliminar ${scope}?\n\nSe borrarán los archivos PDF y todos sus datos. Esta acción no se puede deshacer.`)) return;

    const btn = document.getElementById('btnDeleteAll');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Eliminando...';

    try {
        const body = { action: 'delete_all_pending' };
        if (selectionId) body.selection_id = parseInt(selectionId);
        if (uploadId)    body.upload_id    = parseInt(uploadId);

        const data = await apiPost(API.delete, body);
        toast(data.message, 'success');
        loadPendientes();
        loadUploadFilter();
    } catch (e) {
        // el error ya se muestra via toast en apiPost
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Eliminar todos';
    }
}

function loadUploadFilter() {
    fetch(`${API.selections}?action=uploads`)
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('filterUpload');
            if (!sel) return;
            const current = sel.value;
            sel.innerHTML = '<option value="">Todos los archivos</option>';
            (data.uploads || []).forEach(u => {
                sel.innerHTML += `<option value="${u.id}" ${u.id == current ? 'selected' : ''}>${u.original_filename} (${u.total_pages} pág.)</option>`;
            });
        })
        .catch(() => {});
}

// ============================================
// Unification Process — Fase 1: análisis paralelo, Fase 2: exportar
// ============================================

let unifyAborted = false;

async function startUnification() {
    const selectionId = document.getElementById('filterSelection')?.value || '';
    const uploadId    = document.getElementById('filterUpload')?.value || '';

    if (!selectionId && !uploadId) {
        toast('Selecciona una selección o un archivo para unificar', 'warning');
        return;
    }

    const btn = document.getElementById('btnUnify');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Procesando...';

    document.getElementById('unifyProgress').style.display = 'block';
    unifyAborted = false;

    try {
        const totalCVs = await analyzeParallelThenUnify(uploadId, selectionId);

        document.getElementById('unifyProgressFill').style.width = '100%';
        document.getElementById('unifyProgressFill').style.background = 'var(--success)';
        document.getElementById('unifyProgressPercent').textContent = '100%';
        document.getElementById('unifyProgressText').textContent =
            `¡Completado! ${totalCVs} CV${totalCVs !== 1 ? 's' : ''} guardado${totalCVs !== 1 ? 's' : ''}`;

        toast(`¡Proceso completado! ${totalCVs} CVs exportados`, 'success');
        loadPendientes();
        if (typeof loadListos === 'function' && document.getElementById('listosBody')) loadListos();
    } catch (e) {
        toast('Error en el proceso: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-object-group"></i> Unificar CVs';
    }
}

/**
 * FASE 1 — Pool paralelo de análisis IA (8 páginas simultáneas).
 * FASE 2 — Una sola llamada a unify para exportar todos los CVs.
 *
 * Tiempo estimado: ~(total_páginas / 8) × latencia_openai
 * vs secuencial:    total_páginas × latencia_openai
 */
async function analyzeParallelThenUnify(uploadId, selectionId) {
    // ── FASE 1: Obtener todas las páginas pendientes ──────────────────────
    let idsUrl = `${API.selections}?action=all_pending_ids`;
    if (uploadId)    idsUrl += `&upload_id=${uploadId}`;
    else if (selectionId) idsUrl += `&selection_id=${selectionId}`;

    const idsData = await api(idsUrl);
    const pages   = idsData.pages || [];
    const total   = pages.length;

    if (total === 0) {
        document.getElementById('unifyProgressText').textContent = 'Sin páginas pendientes, exportando...';
    }

    // ── FASE 1: Pool de 8 workers en paralelo ─────────────────────────────
    let analyzed  = 0;
    let errors    = 0;
    let pageIndex = 0;
    const CONCURRENCY = 8;

    function setProgress(text) {
        const pct = total > 0 ? Math.round((analyzed / total) * 100) : 0;
        document.getElementById('unifyProgressFill').style.width = pct + '%';
        document.getElementById('unifyProgressPercent').textContent = pct + '%';
        document.getElementById('unifyProgressText').textContent = text;
    }

    async function worker() {
        while (!unifyAborted && pageIndex < pages.length) {
            const page = pages[pageIndex++];
            try {
                const resp = await fetch(API.analyze, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ page_id: page.id })
                });
                const result = await resp.json();

                if (resp.status === 422 && result.needs_gs) {
                    unifyAborted = true;
                    document.getElementById('unifyProgressFill').style.background = 'var(--danger)';
                    document.getElementById('unifyProgressText').textContent = '⚠️ No se pudo analizar alguna página';
                    toast('No se pudo analizar la página: el PDF es de imagen y no hay método disponible en el servidor.', 'error');
                    return;
                }
            } catch (e) {
                errors++;
            }
            analyzed++;
            setProgress(`Analizando páginas… ${analyzed}/${total} (${CONCURRENCY} en paralelo)`);
            // Refrescar tabla cada 10 páginas
            if (analyzed % 10 === 0) loadPendientes();
        }
    }

    // Lanzar N workers simultáneamente y esperar a que todos terminen
    setProgress(`Iniciando análisis de ${total} páginas en paralelo…`);
    const workers = Array.from({ length: Math.min(CONCURRENCY, total) }, worker);
    await Promise.all(workers);

    if (unifyAborted) return 0;

    // ── FASE 2: Exportar CVs con una sola llamada ────────────────────────
    setProgress('Análisis completado. Exportando CVs…');
    loadPendientes();

    let totalCVsCreated = 0;

    // Obtener los upload_ids afectados para exportar
    const uploadIds = uploadId
        ? [parseInt(uploadId)]
        : [...new Set(pages.map(p => p.upload_id))];

    for (const uid of uploadIds) {
        try {
            const unifyResult = await apiPost(API.unify, { upload_id: uid });
            if (unifyResult.cvs_created > 0) {
                totalCVsCreated += unifyResult.cvs_created;
                unifyResult.cvs.forEach(cv => {
                    toast(`CV exportado: ${cv.candidate_name || 'pág. ' + cv.page_range} (${cv.num_pages} pág.)`, 'success');
                });
            }
        } catch (e) {
            console.error('Error exportando upload', uid, e);
        }
    }

    loadPendientes();
    return totalCVsCreated;
}

// ============================================
// Listos (Completed CVs)
// ============================================

function loadListos(page = 1) {
    const selectionId = document.getElementById('filterSelectionListos')?.value || '';
    const candidateName = document.getElementById('filterCandidateName')?.value || '';
    const numPages = document.getElementById('filterPages')?.value || '';

    let url = `${API.selections}?action=unified_cvs&page=${page}`;
    if (selectionId) url += `&selection_id=${selectionId}`;
    if (candidateName) url += `&candidate_name=${encodeURIComponent(candidateName)}`;
    if (numPages) url += `&num_pages=${numPages}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('listosBody');
            const count = document.getElementById('listosCount');

            if (!tbody) return;

            count.textContent = `${data.total} CVs`;

            if (!data.cvs.length) {
                tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
                    <i class="fas fa-folder-open"></i><h3>No hay CVs unificados</h3>
                    <p>Procesa los CVs pendientes desde la sección correspondiente</p></div></td></tr>`;
                return;
            }

            tbody.innerHTML = data.cvs.map(cv => `
                <tr>
                    <td><strong>#${cv.id}</strong></td>
                    <td>${cv.candidate_name || '<span style="color:var(--gray-400)">Sin identificar</span>'}</td>
                    <td>${cv.selection_name}</td>
                    <td><span class="badge badge-info">${cv.num_pages} pág.</span></td>
                    <td>${cv.page_range}</td>
                    <td>${formatDate(cv.created_at)}</td>
                    <td>
                        <div class="action-buttons">
                            <a href="${API.download}?action=single&id=${cv.id}" class="btn btn-sm btn-primary btn-icon" title="Descargar">
                                <i class="fas fa-download"></i>
                            </a>
                            <button class="btn btn-sm btn-danger btn-icon" title="Eliminar" onclick="deleteCV(${cv.id}, '${(cv.candidate_name || cv.file_name).replace(/'/g, "\\'")}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');

            renderPagination('listosPagination', data.page, data.total_pages, 'loadListos');
        })
        .catch(() => {});
}

function deleteCV(cvId, name) {
    showModal(
        'Eliminar CV',
        `<p>¿Estás seguro de que quieres eliminar el CV de <strong>${name}</strong>?</p>
         <p style="color:var(--gray-500); margin-top:8px;">Esta acción no se puede deshacer.</p>`,
        async () => {
            try {
                await apiPost(API.delete, { cv_id: cvId });
                toast('CV eliminado correctamente', 'success');
                loadListos();
            } catch (e) {
                // error already shown by api()
            }
        }
    );
}

function downloadAllCVs() {
    const selectionId = document.getElementById('filterSelectionListos')?.value || '';
    const candidateName = document.getElementById('filterCandidateName')?.value || '';
    const numPages = document.getElementById('filterPages')?.value || '';

    let url = `${API.download}?action=zip`;
    if (selectionId) url += `&selection_id=${selectionId}`;
    if (candidateName) url += `&candidate_name=${encodeURIComponent(candidateName)}`;
    if (numPages) url += `&num_pages=${numPages}`;

    toast('Preparando descarga ZIP...', 'info');
    window.location.href = url;
}

// ============================================
// Config
// ============================================

async function saveConfig(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';

    try {
        await apiPost(API.config, {
            api_key: document.getElementById('apiKey').value,
            model: document.getElementById('modelSelect').value,
            max_tokens: parseInt(document.getElementById('maxTokens').value)
        });
        toast('Configuración guardada', 'success');
    } catch (e) {
        // handled
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Guardar configuración';
    }
}

async function savePrompt(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';

    try {
        await apiPost(API.config, {
            action: 'save_prompt',
            cv_detect_prompt: document.getElementById('cvPrompt').value
        });
        toast('Prompt guardado correctamente', 'success');
    } catch (e) {
        // handled
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Guardar prompt';
    }
}

async function saveGsPath(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Guardando...';
    try {
        await apiPost(API.config, { action: 'save_gs_path', gs_path: document.getElementById('gsPathInput').value });
        toast('Ruta de GhostScript guardada', 'success');
    } catch (e) { /* handled */ } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Guardar ruta';
    }
}

async function testGhostScript() {
    const resultDiv = document.getElementById('gsTestResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span class="spinner spinner-dark"></span> Probando GhostScript...';
    try {
        const data = await apiPost(API.config, { action: 'test_gs' });
        resultDiv.innerHTML = `<div style="color:var(--success);font-weight:500"><i class="fas fa-check-circle"></i> ${data.message}<br><small style="color:var(--gray-500)">${data.path}</small></div>`;
    } catch (e) {
        resultDiv.innerHTML = `<div style="color:var(--danger);font-weight:500"><i class="fas fa-times-circle"></i> ${e.message}</div>`;
    }
}

async function resetPrompt() {
    showModal(
        'Restaurar prompt por defecto',
        '<p>¿Estás seguro de que quieres restaurar el prompt a los valores por defecto?</p><p style="color:var(--gray-500);margin-top:8px;">Se perderán tus personalizaciones.</p>',
        async () => {
            try {
                const data = await apiPost(API.config, { action: 'reset_prompt' });
                document.getElementById('cvPrompt').value = data.prompt || '';
                toast('Prompt restaurado', 'success');
            } catch (e) {
                // handled
            }
        }
    );
}

async function testOpenAI() {
    const resultDiv = document.getElementById('testResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<span class="spinner spinner-dark"></span> Probando conexión...';

    try {
        const data = await api(`${API.config}?action=test`);
        resultDiv.innerHTML = `<div style="color:var(--success); font-weight:500;"><i class="fas fa-check-circle"></i> ${data.message}</div>`;
    } catch (e) {
        resultDiv.innerHTML = `<div style="color:var(--danger); font-weight:500;"><i class="fas fa-times-circle"></i> ${e.message}</div>`;
    }
}

// ============================================
// Logs
// ============================================

function loadLogs(page = 1) {
    const level = document.getElementById('filterLogLevel')?.value || '';
    const selectionId = document.getElementById('filterLogSelection')?.value || '';
    const date = document.getElementById('filterLogDate')?.value || '';

    let url = `${API.logs}?page=${page}`;
    if (level) url += `&level=${level}`;
    if (selectionId) url += `&selection_id=${selectionId}`;
    if (date) url += `&date=${date}`;

    fetch(url)
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('logsBody');
            const count = document.getElementById('logsCount');

            if (!tbody) return;

            count.textContent = `${data.total} entradas`;

            if (!data.logs.length) {
                tbody.innerHTML = `<tr><td colspan="4"><div class="empty-state">
                    <i class="fas fa-clipboard-list"></i><h3>No hay logs</h3>
                    <p>Los eventos del sistema aparecerán aquí</p></div></td></tr>`;
                return;
            }

            tbody.innerHTML = data.logs.map(l => `
                <tr>
                    <td style="white-space:nowrap; font-size:13px; color:var(--gray-500)">${formatDate(l.created_at)}</td>
                    <td>${badgeForLevel(l.level)}</td>
                    <td>${l.message}</td>
                    <td>${l.selection_name || '-'}</td>
                </tr>
            `).join('');

            renderPagination('logsPagination', data.page, data.total_pages, 'loadLogs');
        })
        .catch(() => {});
}

async function clearLogs() {
    showModal(
        'Limpiar Logs',
        '<p>¿Estás seguro de que quieres eliminar todos los logs?</p>',
        async () => {
            try {
                await fetch(API.logs, { method: 'DELETE' });
                toast('Logs limpiados', 'success');
                loadLogs();
            } catch (e) {
                // handled
            }
        }
    );
}
