<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$project = $id > 0 ? ProjectRepository::find($id) : null;

if ($project === null) {
    flashSet('error', 'Project not found.');
    redirect('index.php');
}

$parsed = json_decode((string) $project['parsed_json'], true);
if (!is_array($parsed)) {
    $parsed = [];
}

$mappedSummary = ProjectSummaryMapper::map($project, $parsed);
$aiReasoning = ProjectRepository::getAiReasoning($id);
$aiTemplate = is_array($aiReasoning['template'] ?? null) ? $aiReasoning['template'] : null;
$summary = ProjectSummaryMapper::mergeWithAi($mappedSummary, $aiTemplate);
$aiStatus = (new DossierReasoningService())->status();
$ownerName = projectOwnerName($project);
$ownerTitle = projectOwnerTitle($project);
$cssV = cssVersion();
$token = csrfToken();
$defaultEta = (int) ($aiReasoning['eta_hint_seconds'] ?? $aiStatus['eta_seconds'] ?? DossierReasoningService::DEFAULT_ETA_SECONDS);

/**
 * @param array{label: string, value: string, available: bool, source?: string} $field
 */
function renderSummaryField(array $field, string $path, string $extraClass = ''): void
{
    $available = !empty($field['available']);
    $source = (string) ($field['source'] ?? 'mapper');
    $classes = trim(
        'summary-field'
        . ($available ? '' : ' is-missing')
        . (($source === 'gemma' || $source === 'ai') ? ' is-ai-filled' : '')
        . ($extraClass !== '' ? ' ' . $extraClass : '')
    );
    echo '<div class="' . e($classes) . '" data-summary-path="' . e($path) . '">';
    echo '<dt>' . e((string) $field['label']);
    if ($source === 'gemma' || $source === 'ai') {
        echo ' <span class="summary-ai-badge" title="Filled by local AI">AI</span>';
    }
    echo '</dt>';
    echo '<dd data-summary-value>' . nl2br(e((string) $field['value'])) . '</dd>';
    echo '</div>';
}

/**
 * @param array{label: string, value: string, available: bool, tone: string, source?: string} $item
 */
function renderSummaryCheck(array $item, string $path): void
{
    $source = (string) ($item['source'] ?? 'mapper');
    $classes = 'summary-check tone-' . e((string) $item['tone'])
        . (empty($item['available']) ? ' is-missing' : '')
        . (($source === 'gemma' || $source === 'ai') ? ' is-ai-filled' : '');
    echo '<li class="' . $classes . '" data-summary-path="' . e($path) . '">';
    echo '<span class="summary-check-label">' . e((string) $item['label']);
    if ($source === 'gemma' || $source === 'ai') {
        echo ' <span class="summary-ai-badge" title="Filled by local AI">AI</span>';
    }
    echo '</span>';
    echo '<span class="summary-check-value" data-summary-value>' . nl2br(e((string) $item['value'])) . '</span>';
    echo '</li>';
}

/**
 * @param list<string>|mixed $items
 */
function renderReasoningList(mixed $items): void
{
    if (!is_array($items) || $items === []) {
        echo '<p class="summary-ai-empty">None identified from current dossier evidence.</p>';
        return;
    }
    echo '<ul class="summary-ai-list">';
    foreach ($items as $item) {
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        echo '<li>' . e($text) . '</li>';
    }
    echo '</ul>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="teal">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product &amp; Design Summary · <?= e((string) $project['title']) ?> · Ticket Dossier · <?= e($branding->documentTitle()) ?></title>
    <?php require dirname(__DIR__) . '/includes/theme-head.php'; ?>
    <?php require dirname(__DIR__) . '/includes/head-branding.php'; ?>
    <link rel="stylesheet" href="<?= e($auth->publicPrefix()) ?>assets/css/dashboard.css?v=<?= e(dashboardCssVersion()) ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= e($cssV) ?>">
</head>
<body>
<div class="shell dossier-page summary-page">
    <header class="topbar topbar-uplift">
        <a class="brand brand-link" href="index.php" title="Ticket Dossier home">
            <?php require dirname(__DIR__) . '/includes/brand-mark.php'; ?>
            <div class="brand-text">
                <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                <h1>Ticket Dossier</h1>
            </div>
        </a>
        <div class="topbar-actions">
            <?php
            ob_start();
            ?>
            <a class="button ghost home-link" data-menu-group="ticket" data-menu-tone="sky" href="project.php?id=<?= (int) $id ?>" title="Return to the full project dossier"><span class="topbar-menu-emoji" aria-hidden="true">📋</span>Full dossier</a>
            <a class="button ghost home-link" data-menu-group="ticket" data-menu-tone="sky" href="index.php" title="Return to the Ticket Dossier project list"><span class="topbar-menu-emoji" aria-hidden="true">🗂</span>All projects</a>
            <button type="button" class="button ghost home-link" data-menu-group="ticket" data-menu-tone="mint" onclick="window.print()" title="Print or save this summary as PDF"><span class="topbar-menu-emoji" aria-hidden="true">🖨</span>Print</button>
            <?php
            $topbarMenuExtraBefore = ob_get_clean();
            require __DIR__ . '/includes/app-nav.php';
            ?>
        </div>
    </header>

    <main>
        <section class="hero hero-compact">
            <div class="hero-main">
                <div class="hero-intro">
                    <p class="eyebrow">Product &amp; Design Summary</p>
                    <h2><?= e((string) $project['title']) ?></h2>
                    <p>Rule-mapped from dossier sources, then optionally filled by local AI (<?= e(TD_OLLAMA_MODEL) ?>) from the JSON export. Missing evidence still shows as “Not available in dossier.”</p>
                    <p class="hero-edit">
                        <a class="button ghost button-small" href="project.php?id=<?= (int) $id ?>">← Back to full dossier</a>
                    </p>
                </div>
                <div class="hero-chips">
                    <span class="pill teal" data-search-label="Owner"<?= $ownerTitle !== '' ? ' title="' . e($ownerTitle) . '"' : '' ?>>👤 Owner: <?= e($ownerName !== '' ? $ownerName : 'Unknown') ?></span>
                    <?php if (!empty($project['vendor'])): ?>
                        <span class="pill gray">🏢 <?= e((string) $project['vendor']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['demand_number'])): ?>
                        <span class="pill teal">🎯 <?= e((string) $project['demand_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($project['ddr_number'])): ?>
                        <span class="pill teal">🛡 <?= e((string) $project['ddr_number']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section
            class="panel panel-tone-assessments summary-panel summary-ai-panel"
            id="gemma-reasoning"
            data-project-id="<?= (int) $id ?>"
            data-default-eta="<?= (int) $defaultEta ?>"
        >
            <div class="summary-ai-header">
                <div>
                    <h2>AI Summary &amp; Reasoning</h2>
                    <p class="summary-ai-status" id="gemma-status">
                        <?= e((string) ($aiStatus['message'] ?? 'Checking local AI model…')) ?>
                    </p>
                    <p class="summary-ai-hint">Fills Product Summary and the rest of this template from the dossier JSON export, then adds architecture reasoning. Model: <?= e(TD_OLLAMA_MODEL) ?>. Original PDF attachments are not sent to the model.</p>
                </div>
                <div class="summary-ai-actions">
                    <button
                        type="button"
                        class="button"
                        id="gemma-run"
                        <?= empty($aiStatus['available']) ? 'disabled' : '' ?>
                        title="Run local AI to fill the summary template"
                    >Fill summary with AI</button>
                </div>
            </div>

            <div class="summary-ai-progress" id="gemma-progress" hidden>
                <div class="summary-ai-progress-top">
                    <strong id="gemma-progress-label">Starting local AI…</strong>
                    <span id="gemma-progress-eta">ETA —</span>
                </div>
                <div class="summary-ai-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="gemma-progress-bar">
                    <div class="summary-ai-progress-fill" id="gemma-progress-fill"></div>
                </div>
                <div class="summary-ai-progress-meta">
                    <span id="gemma-progress-pct">0%</span>
                    <span id="gemma-progress-elapsed">Elapsed 0s</span>
                </div>
                <ol class="summary-ai-stages" id="gemma-stages">
                    <li data-stage="prepare">Prepare JSON export</li>
                    <li data-stage="export_ready">Contact local AI</li>
                    <li data-stage="generating">Fill template + reasoning</li>
                    <li data-stage="parsing">Apply Product &amp; Design Summary</li>
                    <li data-stage="complete">Complete</li>
                </ol>
            </div>

            <div class="summary-ai-body" id="gemma-body"<?= $aiReasoning === null ? ' hidden' : '' ?>>
                <div class="summary-field">
                    <dt>Executive summary</dt>
                    <dd id="gemma-executive"><?= $aiReasoning !== null ? nl2br(e((string) ($aiReasoning['executive_summary'] ?? ''))) : '' ?></dd>
                </div>
                <div class="summary-ai-grid">
                    <div>
                        <h3 class="summary-subhead">Architecture risks</h3>
                        <div id="gemma-risks"><?php renderReasoningList(($aiReasoning ?? [])['architecture_risks'] ?? []); ?></div>
                    </div>
                    <div>
                        <h3 class="summary-subhead">Security gaps</h3>
                        <div id="gemma-security"><?php renderReasoningList(($aiReasoning ?? [])['security_gaps'] ?? []); ?></div>
                    </div>
                    <div>
                        <h3 class="summary-subhead">Open questions</h3>
                        <div id="gemma-questions"><?php renderReasoningList(($aiReasoning ?? [])['open_questions'] ?? []); ?></div>
                    </div>
                    <div>
                        <h3 class="summary-subhead">Recommended next steps</h3>
                        <div id="gemma-steps"><?php renderReasoningList(($aiReasoning ?? [])['recommended_next_steps'] ?? []); ?></div>
                    </div>
                </div>
                <div>
                    <h3 class="summary-subhead">Evidence notes</h3>
                    <div id="gemma-evidence"><?php renderReasoningList(($aiReasoning ?? [])['evidence_notes'] ?? []); ?></div>
                </div>
                <p class="summary-ai-meta" id="gemma-meta">
                    <?php if ($aiReasoning !== null): ?>
                        Model: <?= e((string) ($aiReasoning['model'] ?? TD_OLLAMA_MODEL)) ?>
                        · Source: <?= e((string) ($aiReasoning['source'] ?? 'ticket-dossier-json-export')) ?>
                        · Context: <?= e((string) ($aiReasoning['context_chars'] ?? '')) ?> chars
                        <?php if (!empty($aiReasoning['duration_ms'])): ?>
                            · Took: <?= e((string) round(((int) $aiReasoning['duration_ms']) / 1000)) ?>s
                        <?php endif; ?>
                        · Generated: <?= e((string) ($aiReasoning['generated_at'] ?? '')) ?>
                    <?php endif; ?>
                </p>
            </div>
            <p class="summary-ai-error" id="gemma-error" hidden></p>
        </section>

        <div class="summary-layout" id="summary-template">
            <div class="summary-column">
                <section class="panel panel-tone-overview summary-panel">
                    <h2>Product Summary</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['product_summary'] as $key => $field): ?>
                            <?php renderSummaryField($field, 'product_summary.' . $key); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-demand summary-panel">
                    <h2>Design Summary</h2>
                    <h3 class="summary-subhead">Business Requirements and Key Constraints</h3>
                    <div class="summary-field-list">
                        <?php foreach ($summary['business_requirements'] as $key => $field): ?>
                            <?php
                            if ($key === 'driving_factors') {
                                echo '<div class="summary-key-questions">';
                                echo '<h4>Key Questions</h4>';
                                echo '<div class="summary-field-list summary-field-list-nested">';
                                foreach ($summary['key_questions'] as $qi => $question) {
                                    renderSummaryField($question, 'key_questions.' . $qi, 'summary-field-question');
                                }
                                echo '</div></div>';
                            }
                            renderSummaryField($field, 'business_requirements.' . $key);
                            ?>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="summary-subhead">This design includes</h3>
                    <ul class="summary-checklist">
                        <?php foreach ($summary['design_includes'] as $di => $item): ?>
                            <?php renderSummaryCheck($item, 'design_includes.' . $di); ?>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>

            <div class="summary-column">
                <section class="panel panel-tone-story summary-panel">
                    <h2>Goals</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['goals'] as $key => $field): ?>
                            <?php renderSummaryField($field, 'goals.' . $key); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-task summary-panel">
                    <h2>Any Integrations</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['integrations'] as $ii => $field): ?>
                            <?php renderSummaryField($field, 'integrations.' . $ii); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-ddr summary-panel">
                    <h2>3rd Party Review Status</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['third_party_review'] as $key => $field): ?>
                            <?php renderSummaryField($field, 'third_party_review.' . $key); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-vendor summary-panel">
                    <h2>Owners</h2>
                    <div class="summary-field-list">
                        <?php foreach ($summary['owners'] as $key => $field): ?>
                            <?php renderSummaryField($field, 'owners.' . $key, 'summary-field-owner'); ?>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel panel-tone-assessments summary-panel">
                    <h2>Vendor Commitments</h2>
                    <p class="summary-note"><?= e((string) $summary['vendor_commitments']['note']) ?></p>
                    <h3 class="summary-subhead">Additional info to be collected</h3>
                    <div class="summary-field-list">
                        <?php foreach ($summary['vendor_commitments']['items'] as $vi => $field): ?>
                            <?php renderSummaryField($field, 'vendor_commitments.items.' . $vi, 'summary-field-collect'); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?php require dirname(__DIR__) . '/includes/site-footer.php'; ?>
</div>
<script src="<?= e($auth->publicPrefix()) ?>assets/js/theme.js?v=<?= e(themeJsVersion()) ?>"></script>
<script>
(() => {
    const panel = document.getElementById('gemma-reasoning');
    const runBtn = document.getElementById('gemma-run');
    const statusEl = document.getElementById('gemma-status');
    const bodyEl = document.getElementById('gemma-body');
    const errorEl = document.getElementById('gemma-error');
    const progressEl = document.getElementById('gemma-progress');
    const progressFill = document.getElementById('gemma-progress-fill');
    const progressBar = document.getElementById('gemma-progress-bar');
    const progressLabel = document.getElementById('gemma-progress-label');
    const progressEta = document.getElementById('gemma-progress-eta');
    const progressPct = document.getElementById('gemma-progress-pct');
    const progressElapsed = document.getElementById('gemma-progress-elapsed');
    const csrf = <?= json_encode($token, JSON_UNESCAPED_UNICODE) ?>;
    if (!panel || !runBtn) return;

    const projectId = panel.getAttribute('data-project-id') || '0';
    const etaStorageKey = 'td_gemma_eta_seconds';
    let etaSeconds = Number(localStorage.getItem(etaStorageKey)) || Number(panel.getAttribute('data-default-eta') || 95);
    let tickTimer = null;
    let startedAt = 0;
    let lastServerPercent = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatSeconds(total) {
        const s = Math.max(0, Math.round(total));
        if (s < 60) return s + 's';
        const m = Math.floor(s / 60);
        const r = s % 60;
        return m + 'm ' + r + 's';
    }

    function renderList(targetId, items) {
        const el = document.getElementById(targetId);
        if (!el) return;
        if (!Array.isArray(items) || items.length === 0) {
            el.innerHTML = '<p class="summary-ai-empty">None identified from current dossier evidence.</p>';
            return;
        }
        el.innerHTML = '<ul class="summary-ai-list">' + items.map((item) => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>';
    }

    function setProgress(percent, message, etaRemaining, elapsed) {
        const pct = Math.max(0, Math.min(100, Math.round(percent)));
        progressFill.style.width = pct + '%';
        progressBar.setAttribute('aria-valuenow', String(pct));
        progressPct.textContent = pct + '%';
        if (message) progressLabel.textContent = message;
        progressEta.textContent = etaRemaining > 0 ? ('ETA ~' + formatSeconds(etaRemaining)) : 'Finishing…';
        progressElapsed.textContent = 'Elapsed ' + formatSeconds(elapsed || 0);
    }

    function setStage(stage) {
        document.querySelectorAll('#gemma-stages li').forEach((li) => {
            const key = li.getAttribute('data-stage');
            li.classList.toggle('is-active', key === stage);
            const order = ['prepare', 'export_ready', 'generating', 'parsing', 'complete'];
            const idx = order.indexOf(key);
            const cur = order.indexOf(stage);
            li.classList.toggle('is-done', cur >= 0 && idx >= 0 && idx < cur);
        });
    }

    function lookupPath(obj, path) {
        return String(path || '').split('.').reduce((acc, key) => {
            if (acc == null) return null;
            return acc[key];
        }, obj);
    }

    function applySummary(summary) {
        if (!summary) return;
        document.querySelectorAll('[data-summary-path]').forEach((node) => {
            const path = node.getAttribute('data-summary-path');
            const field = lookupPath(summary, path);
            if (!field || typeof field !== 'object') return;
            const valueEl = node.querySelector('[data-summary-value]');
            if (valueEl) {
                valueEl.innerHTML = escapeHtml(field.value || '').replace(/\n/g, '<br>');
            }
            const available = !!field.available;
            const source = field.source || 'mapper';
            node.classList.toggle('is-missing', !available);
            node.classList.toggle('is-ai-filled', source === 'gemma' || source === 'ai');
            const dt = node.querySelector('dt, .summary-check-label');
            if (dt) {
                let badge = dt.querySelector('.summary-ai-badge');
                if (source === 'gemma' || source === 'ai') {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'summary-ai-badge';
                        badge.title = 'Filled by local AI';
                        badge.textContent = 'AI';
                        dt.appendChild(document.createTextNode(' '));
                        dt.appendChild(badge);
                    }
                } else if (badge) {
                    badge.remove();
                }
            }
        });
    }

    function applyReasoning(reasoning) {
        if (!reasoning) return;
        bodyEl.hidden = false;
        document.getElementById('gemma-executive').innerHTML = escapeHtml(reasoning.executive_summary || '').replace(/\n/g, '<br>');
        renderList('gemma-risks', reasoning.architecture_risks || []);
        renderList('gemma-security', reasoning.security_gaps || []);
        renderList('gemma-questions', reasoning.open_questions || []);
        renderList('gemma-steps', reasoning.recommended_next_steps || []);
        renderList('gemma-evidence', reasoning.evidence_notes || []);
        const took = reasoning.duration_ms ? (' · Took: ' + Math.round(reasoning.duration_ms / 1000) + 's') : '';
        document.getElementById('gemma-meta').textContent =
            'Model: ' + (reasoning.model || '')
            + ' · Source: ' + (reasoning.source || 'ticket-dossier-json-export')
            + (reasoning.context_chars ? (' · Context: ' + reasoning.context_chars + ' chars') : '')
            + took
            + ' · Generated: ' + (reasoning.generated_at || '');
        if (reasoning.eta_hint_seconds) {
            etaSeconds = Number(reasoning.eta_hint_seconds) || etaSeconds;
            localStorage.setItem(etaStorageKey, String(etaSeconds));
        } else if (reasoning.duration_ms) {
            etaSeconds = Math.max(30, Math.round(reasoning.duration_ms / 1000));
            localStorage.setItem(etaStorageKey, String(etaSeconds));
        }
    }

    function applyStatus(status) {
        const available = !!(status && status.available);
        runBtn.dataset.available = available ? '1' : '0';
        runBtn.disabled = !available || runBtn.dataset.busy === '1';
        statusEl.textContent = (status && status.message) ? status.message : 'Local AI status unknown.';
        if (status && status.eta_seconds && !localStorage.getItem(etaStorageKey)) {
            etaSeconds = Number(status.eta_seconds) || etaSeconds;
        }
    }

    function setBusy(busy) {
        runBtn.dataset.busy = busy ? '1' : '0';
        runBtn.disabled = busy || runBtn.dataset.available !== '1';
        runBtn.textContent = busy ? 'AI working…' : 'Fill summary with AI';
        progressEl.hidden = !busy;
        if (busy) {
            lastServerPercent = 0;
            startedAt = Date.now();
            setStage('prepare');
            setProgress(2, 'Starting local AI…', etaSeconds, 0);
            if (tickTimer) clearInterval(tickTimer);
            tickTimer = setInterval(() => {
                const elapsed = (Date.now() - startedAt) / 1000;
                const soft = Math.min(90, Math.round((elapsed / Math.max(etaSeconds, 1)) * 85));
                const pct = Math.max(lastServerPercent, soft);
                const remain = Math.max(0, etaSeconds - elapsed);
                setProgress(pct, progressLabel.textContent, remain, elapsed);
            }, 500);
        } else if (tickTimer) {
            clearInterval(tickTimer);
            tickTimer = null;
        }
    }

    async function readSse(response) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let resultPayload = null;
        let errorPayload = null;

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const parts = buffer.split('\n\n');
            buffer = parts.pop() || '';
            for (const part of parts) {
                const lines = part.split('\n');
                let event = 'message';
                const dataLines = [];
                for (const line of lines) {
                    if (line.startsWith('event:')) event = line.slice(6).trim();
                    if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
                }
                if (!dataLines.length) continue;
                let data;
                try { data = JSON.parse(dataLines.join('\n')); } catch { continue; }

                if (event === 'status') {
                    applyStatus(data);
                } else if (event === 'progress') {
                    lastServerPercent = Number(data.percent || 0);
                    setStage(data.stage || 'generating');
                    setProgress(
                        lastServerPercent,
                        data.message || 'Working…',
                        Number(data.eta_seconds || 0),
                        Number(data.elapsed_seconds || 0)
                    );
                } else if (event === 'result') {
                    resultPayload = data;
                } else if (event === 'error') {
                    errorPayload = data;
                }
            }
        }

        if (errorPayload) {
            throw new Error(errorPayload.error || 'Reasoning failed');
        }
        if (!resultPayload || !resultPayload.ok) {
            throw new Error((resultPayload && resultPayload.error) || 'No result from local AI');
        }
        return resultPayload;
    }

    runBtn.addEventListener('click', async () => {
        errorEl.hidden = true;
        errorEl.textContent = '';
        progressEl.classList.remove('is-failed');
        setBusy(true);
        statusEl.textContent = 'Running <?= e(TD_OLLAMA_MODEL) ?> over the dossier JSON export…';
        try {
            const form = new FormData();
            form.append('csrf_token', csrf);
            form.append('id', projectId);
            form.append('stream', '1');
            const res = await fetch('reason.php?stream=1', {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'Accept': 'text/event-stream' },
            });
            if (!res.ok && !res.headers.get('content-type')?.includes('text/event-stream')) {
                const fallback = await res.json().catch(() => ({}));
                throw new Error(fallback.error || ('HTTP ' + res.status));
            }
            const data = await readSse(res);
            if (data.status) applyStatus(data.status);
            applyReasoning(data.reasoning);
            applySummary(data.summary);
            setStage('complete');
            setProgress(100, 'Template filled by local AI.', 0, (data.reasoning && data.reasoning.duration_ms) ? data.reasoning.duration_ms / 1000 : 0);
            statusEl.textContent = 'AI filled the Product & Design Summary and saved reasoning.';
        } catch (err) {
            errorEl.hidden = false;
            errorEl.textContent = err && err.message ? err.message : 'Reasoning failed';
            statusEl.textContent = 'AI run failed.';
            progressLabel.textContent = 'AI run failed — ' + (err && err.message ? err.message : 'unknown error');
            progressEl.classList.add('is-failed');
            setStage('generating');
        } finally {
            setBusy(false);
            // Keep progress visible so the user can see the failure/success state.
            progressEl.hidden = false;
        }
    });

    applyStatus(<?= json_encode($aiStatus, JSON_UNESCAPED_UNICODE) ?>);
})();
</script>
</body>
</html>
