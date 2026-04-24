<?php
$apiKey    = getConfig('openai_api_key');
$model     = getConfig('openai_model', 'gpt-4o-mini');
$maxTokens = getConfig('max_tokens', '500');
$cvPrompt  = getConfig('cv_detect_prompt');
$maskedKey = $apiKey ? str_repeat('*', max(0, strlen($apiKey) - 8)) . substr($apiKey, -8) : '';
?>

<div class="config-section">
    <div class="card mb-24">
        <div class="card-header">
            <h2><i class="fas fa-key"></i> API de OpenAI</h2>
        </div>
        <div class="card-body">
            <form id="configForm" onsubmit="saveConfig(event)">
                <div class="form-group">
                    <label for="apiKey">API Key de OpenAI</label>
                    <input type="password" id="apiKey" class="form-control" placeholder="sk-..." value="<?= sanitize($apiKey) ?>">
                    <?php if ($maskedKey): ?>
                        <small style="color:var(--gray-500); margin-top:4px; display:block;">
                            Actual: <?= $maskedKey ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="modelSelect">Modelo</label>
                    <select id="modelSelect" class="form-control">
                        <option value="gpt-4o-mini" <?= $model === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini (Recomendado - Económico)</option>
                        <option value="gpt-4o" <?= $model === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o (Más preciso)</option>
                        <option value="gpt-4-turbo" <?= $model === 'gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                        <option value="gpt-3.5-turbo" <?= $model === 'gpt-3.5-turbo' ? 'selected' : '' ?>>GPT-3.5 Turbo (Más económico)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="maxTokens">Max Tokens por respuesta</label>
                    <input type="number" id="maxTokens" class="form-control" value="<?= sanitize($maxTokens) ?>" min="100" max="4000">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar configuración
                </button>
            </form>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header">
            <h2><i class="fas fa-robot"></i> Prompt de detección de CVs</h2>
            <button class="btn btn-sm btn-outline" onclick="resetPrompt()">
                <i class="fas fa-undo"></i> Restaurar por defecto
            </button>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px; color:var(--gray-500); font-size:14px;">
                Este es el prompt que se envía a OpenAI para detectar el inicio de cada curriculum. 
                El sistema añade automáticamente al final la instrucción de responder en JSON.
                Personalízalo según el formato exacto de tus PDFs.
            </p>
            <form id="promptForm" onsubmit="savePrompt(event)">
                <div class="form-group">
                    <textarea id="cvPrompt" class="form-control" rows="18"
                        style="font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6; resize: vertical;"
                        placeholder="Escribe aquí el prompt de detección..."><?= htmlspecialchars($cvPrompt, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <small style="color:var(--gray-400);">
                        <i class="fas fa-info-circle"></i>
                        El prompt debe indicar a la IA que responda con JSON: <code>{"is_cv_start": true/false, "confidence": 0-1, "candidate_name": "...", "reasoning": "..."}</code>
                    </small>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar prompt
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-24">
        <div class="card-header">
            <h2><i class="fas fa-flask"></i> Test de conexión OpenAI</h2>
        </div>
        <div class="card-body">
            <p style="margin-bottom:16px; color:var(--gray-500);">Comprueba que la API key funciona correctamente antes de procesar CVs.</p>
            <button class="btn btn-outline" onclick="testOpenAI()">
                <i class="fas fa-plug"></i> Probar conexión
            </button>
            <div id="testResult" style="margin-top:16px; display:none;"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-server"></i> Información del servidor</h2>
        </div>
        <div class="card-body">
            <table style="width:100%">
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500); width:200px;">PHP Version</td>
                    <td style="padding:8px 0; font-weight:500;"><?= PHP_VERSION ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500);">upload_max_filesize</td>
                    <td style="padding:8px 0; font-weight:500;"><?= ini_get('upload_max_filesize') ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500);">post_max_size</td>
                    <td style="padding:8px 0; font-weight:500;"><?= ini_get('post_max_size') ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500);">max_execution_time</td>
                    <td style="padding:8px 0; font-weight:500;"><?= ini_get('max_execution_time') ?>s</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500);">memory_limit</td>
                    <td style="padding:8px 0; font-weight:500;"><?= ini_get('memory_limit') ?></td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:var(--gray-500);">cURL</td>
                    <td style="padding:8px 0; font-weight:500;"><?= function_exists('curl_version') ? 'Disponible' : 'No disponible' ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>
