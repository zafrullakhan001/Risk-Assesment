<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\ProjectLinksRepository;
use RiskAssessment\Repositories\ProjectMermaidRepository;

final class DashboardProjectResources
{
    /**
     * @param list<array{id: int, label: string, url: string, sort_order: int}> $links
     * @param list<array{id: int, title: string, source: string, sort_order: int}> $diagrams
     */
    public function render(int $assessmentId, array $links, array $diagrams): string
    {
        $canSave = $assessmentId > 0;
        $maxLinks = ProjectLinksRepository::MAX_LINKS;
        $maxDiagrams = ProjectMermaidRepository::MAX_DIAGRAMS;

        ob_start();
        ?>
        <div
            class="project-resources"
            id="project-resources"
            data-max-links="<?= (int) $maxLinks ?>"
            data-max-diagrams="<?= (int) $maxDiagrams ?>"
        >
            <div class="project-resources-intro project-resources-hero">
                <div class="project-resources-hero-copy">
                    <div class="eyebrow">✨ Project workspace</div>
                    <h2>📐 Diagram &amp; links</h2>
                    <p>Save up to <?= (int) $maxDiagrams ?> Mermaid architecture diagrams and <?= (int) $maxLinks ?> reference links for this assessment version.</p>
                </div>
                <?php if (!$canSave): ?>
                    <span class="result-count project-resources-badge">📁 Upload and save an assessment to persist changes</span>
                <?php else: ?>
                    <span class="result-count project-resources-badge is-live">💾 Changes save per project version</span>
                <?php endif; ?>
            </div>

            <div class="project-resources-stack">
                <section class="table-card project-mermaid-card project-resource-card project-resource-card-diagrams" id="project-mermaid">
                    <div class="card-heading project-resource-heading">
                        <div class="project-resource-heading-main">
                            <span class="project-resource-icon" aria-hidden="true">🗺️</span>
                            <div>
                                <div class="eyebrow">Architecture diagrams</div>
                                <h3>Mermaid diagrams</h3>
                            </div>
                        </div>
                        <span class="result-count project-count-badge project-count-diagrams" id="mermaid-diagrams-count"><?= count($diagrams) ?>/<?= (int) $maxDiagrams ?></span>
                    </div>
                    <p class="panel-help">🎨 Add named diagrams for network, data flow, deployment, or other views. Preview here or open in Mermaid Live.</p>
                    <div class="mermaid-toolbar project-resource-toolbar">
                        <button type="button" class="button ghost-light btn-accent-teal" id="btn-diagram-add"<?= count($diagrams) >= $maxDiagrams || !$canSave ? ' disabled' : '' ?>>➕ Add diagram</button>
                        <?php if ($canSave): ?>
                            <button type="button" class="button button-primary" id="btn-diagrams-save">💾 Save diagrams</button>
                        <?php endif; ?>
                        <span class="project-resource-status" id="diagrams-save-status" hidden></span>
                    </div>

                    <div class="mermaid-preview-wrap mermaid-preview-shared">
                        <div class="mermaid-preview-head">
                            <span class="mermaid-preview-label">👁️ Preview</span>
                            <span class="mermaid-preview-title" id="mermaid-preview-title"></span>
                            <span class="mermaid-preview-note" id="mermaid-preview-note"></span>
                        </div>
                        <div class="mermaid-preview-panel" id="mermaid-preview-panel">
                            <div class="mermaid-preview-inner">
                                <pre class="mermaid" id="mermaid-preview">flowchart LR
  A[Select a diagram] --> B[Click Preview]</pre>
                            </div>
                        </div>
                    </div>

                    <div class="mermaid-diagrams-list" id="mermaid-diagrams-list">
                        <p class="empty-panel project-empty-state" id="mermaid-diagrams-empty"<?= $diagrams === [] ? '' : ' hidden' ?>>🗺️ No diagrams yet. Use <strong>➕ Add diagram</strong> to create your first architecture view.</p>
                        <?php foreach ($diagrams as $diagram): ?>
                            <?= $this->renderDiagramRow((string) $diagram['title'], (string) $diagram['source'], $canSave) ?>
                        <?php endforeach; ?>
                    </div>
                    <template id="mermaid-diagram-row-template">
                        <?= $this->renderDiagramRow('', '', $canSave) ?>
                    </template>
                </section>

                <section class="table-card project-links-card project-resource-card project-resource-card-links" id="project-links">
                    <div class="card-heading project-resource-heading">
                        <div class="project-resource-heading-main">
                            <span class="project-resource-icon" aria-hidden="true">🔗</span>
                            <div>
                                <div class="eyebrow">Reference links</div>
                                <h3>Project links</h3>
                            </div>
                        </div>
                        <span class="result-count project-count-badge project-count-links" id="project-links-count"><?= count($links) ?>/<?= (int) $maxLinks ?></span>
                    </div>
                    <p class="panel-help">📎 Add SharePoint folders, runbooks, tickets, or other URLs for this project (max <?= (int) $maxLinks ?>). Links open in a new tab.</p>
                    <div class="project-links-toolbar project-resource-toolbar">
                        <button type="button" class="button ghost-light btn-accent-violet" id="btn-link-add"<?= count($links) >= $maxLinks || !$canSave ? ' disabled' : '' ?>>➕ Add link</button>
                        <?php if ($canSave): ?>
                            <button type="button" class="button button-primary btn-accent-violet-solid" id="btn-links-save">💾 Save links</button>
                        <?php endif; ?>
                        <span class="project-resource-status" id="links-save-status" hidden></span>
                    </div>
                    <div class="project-links-list" id="project-links-list">
                        <p class="empty-panel project-empty-state" id="project-links-empty"<?= $links === [] ? '' : ' hidden' ?>>🔗 No links yet. Use <strong>➕ Add link</strong> to attach SharePoint or other references.</p>
                        <?php foreach ($links as $link): ?>
                            <?= $this->renderLinkRow((string) $link['label'], (string) $link['url'], $canSave) ?>
                        <?php endforeach; ?>
                    </div>
                    <template id="project-link-row-template">
                        <?= $this->renderLinkRow('', '', $canSave) ?>
                    </template>
                </section>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function renderDiagramRow(string $title, string $source, bool $canEdit): string
    {
        ob_start();
        ?>
        <article class="mermaid-diagram-row">
            <div class="mermaid-diagram-row-accent" aria-hidden="true"></div>
            <div class="mermaid-diagram-row-body">
                <div class="mermaid-diagram-row-head">
                    <label>
                        <span>🏷️ Diagram title</span>
                        <input type="text" class="mermaid-diagram-title" maxlength="200" value="<?= $this->e($title) ?>" placeholder="Network topology"<?= $canEdit ? '' : ' readonly' ?>>
                    </label>
                    <div class="mermaid-diagram-row-actions">
                        <button type="button" class="button ghost-light btn-accent-teal mermaid-diagram-preview">👁️ Preview</button>
                        <button type="button" class="button ghost-light btn-accent-live mermaid-diagram-live">✨ Mermaid Live</button>
                        <?php if ($canEdit): ?>
                            <button type="button" class="button danger-btn mermaid-diagram-remove" title="Remove diagram" aria-label="Remove diagram">🗑️</button>
                        <?php endif; ?>
                    </div>
                </div>
                <label class="mermaid-diagram-source-wrap">
                    <span>📝 Mermaid source</span>
                    <textarea class="mermaid-diagram-source" rows="8" spellcheck="false" placeholder="flowchart LR&#10;  User[Users] --> App[Application]&#10;  App --> DB[(Database)]"<?= $canEdit ? '' : ' readonly' ?>><?= $this->e($source) ?></textarea>
                </label>
            </div>
        </article>
        <?php

        return (string) ob_get_clean();
    }

    private function renderLinkRow(string $label, string $url, bool $canEdit): string
    {
        ob_start();
        ?>
        <div class="project-link-row">
            <span class="project-link-row-icon" aria-hidden="true">🔗</span>
            <label>
                <span>🏷️ Label</span>
                <input type="text" class="project-link-label" maxlength="200" value="<?= $this->e($label) ?>" placeholder="SharePoint folder"<?= $canEdit ? '' : ' readonly' ?>>
            </label>
            <label class="project-link-url-field">
                <span>🌐 URL</span>
                <input type="url" class="project-link-url" maxlength="2000" value="<?= $this->e($url) ?>" placeholder="https://..."<?= $canEdit ? '' : ' readonly' ?>>
            </label>
            <div class="project-link-actions">
                <?php if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)): ?>
                    <a class="button ghost-light btn-accent-violet project-link-open" href="<?= $this->e($url) ?>" target="_blank" rel="noopener noreferrer">↗️ Open</a>
                <?php else: ?>
                    <a class="button ghost-light btn-accent-violet project-link-open" href="#" hidden target="_blank" rel="noopener noreferrer">↗️ Open</a>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <button type="button" class="button danger-btn project-link-remove" title="Remove link" aria-label="Remove link">🗑️</button>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
