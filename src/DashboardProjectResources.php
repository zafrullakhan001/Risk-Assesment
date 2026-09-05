<?php

declare(strict_types=1);

namespace RiskAssessment;

use RiskAssessment\Repositories\ProjectLinksRepository;
use RiskAssessment\Repositories\ProjectMermaidRepository;
use RiskAssessment\Repositories\ProjectPicturesRepository;

final class DashboardProjectResources
{
    private string $shareToken = '';

    /**
     * @param list<array{id: int, label: string, url: string, sort_order: int}> $links
     * @param list<array{id: int, title: string, source: string, sort_order: int}> $diagrams
     * @param list<array{id: int, title: string, mime_type: string, original_filename: string, sort_order: int}> $pictures
     * @param array{project_name: string, folder_url: string, items: list<array<string, mixed>>}|null $sharePointCatalog
     */
    public function render(
        int $assessmentId,
        array $links,
        array $diagrams,
        array $pictures = [],
        bool $canEdit = true,
        string $shareToken = '',
        ?array $sharePointCatalog = null
    ): string {
        $canSave = $canEdit && $assessmentId > 0;
        $maxLinks = ProjectLinksRepository::MAX_LINKS;
        $maxDiagrams = ProjectMermaidRepository::MAX_DIAGRAMS;
        $maxPictures = ProjectPicturesRepository::MAX_PICTURES;
        $this->shareToken = $shareToken;
        $catalogProject = trim((string) ($sharePointCatalog['project_name'] ?? ''));
        $catalogFolderUrl = trim((string) ($sharePointCatalog['folder_url'] ?? ''));
        $catalogItems = is_array($sharePointCatalog['items'] ?? null) ? $sharePointCatalog['items'] : [];
        $hasCatalog = $catalogProject !== '' && $catalogItems !== [];
        $catalogHref = $shareToken !== '' ? '' : 'sharepoint.php';

        ob_start();
        ?>
        <div
            class="project-resources"
            id="project-resources"
            data-max-links="<?= (int) $maxLinks ?>"
            data-max-diagrams="<?= (int) $maxDiagrams ?>"
            data-max-pictures="<?= (int) $maxPictures ?>"
            data-readonly="<?= $canSave ? '0' : '1' ?>"
        >
            <div class="project-resources-intro project-resources-hero">
                <div class="project-resources-hero-copy">
                    <div class="eyebrow">✨ Project workspace</div>
                    <h2>📐 Diagram &amp; links</h2>
                    <p><?= $canSave
                        ? 'Save up to ' . (int) $maxDiagrams . ' Mermaid diagrams, ' . (int) $maxPictures . ' pictures, and ' . (int) $maxLinks . ' reference links for this assessment version.'
                        : 'View Mermaid diagrams, pictures, and reference links for this assessment version.' ?></p>
                </div>
                <?php if (!$canSave): ?>
                    <span class="result-count project-resources-badge"><?= $shareToken !== '' ? '🔒 Read-only shared view' : '📁 Upload and save an assessment to persist changes' ?></span>
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

                <section class="table-card project-pictures-card project-resource-card project-resource-card-pictures" id="project-pictures" data-picture-view="files">
                    <div class="card-heading project-resource-heading">
                        <div class="project-resource-heading-main">
                            <span class="project-resource-icon" aria-hidden="true">🖼️</span>
                            <div>
                                <div class="eyebrow">Architecture pictures</div>
                                <h3>Project pictures</h3>
                            </div>
                        </div>
                        <span class="result-count project-count-badge project-count-pictures" id="project-pictures-count"><?= count($pictures) ?>/<?= (int) $maxPictures ?></span>
                    </div>
                    <p class="panel-help">📷 Drag and drop JPG or PNG files here. Other picture formats are converted to JPG or PNG, stored as base64, and opened only when you view them.</p>
                    <div class="project-pictures-toolbar project-resource-toolbar">
                        <button type="button" class="button ghost-light btn-accent-rose" id="btn-picture-browse"<?= count($pictures) >= $maxPictures || !$canSave ? ' disabled' : '' ?>>📁 Choose pictures</button>
                        <?php if ($canSave): ?>
                            <button type="button" class="button button-primary btn-accent-rose-solid" id="btn-pictures-save" hidden>💾 Save titles</button>
                        <?php endif; ?>
                        <div class="project-picture-view-switcher" id="project-picture-view-switcher" role="tablist" aria-label="Picture view">
                            <button type="button" class="project-picture-view-btn is-active" role="tab" aria-selected="true" data-picture-view="files">📂 Files</button>
                            <button type="button" class="project-picture-view-btn" role="tab" aria-selected="false" data-picture-view="cards">🖼️ Cards</button>
                        </div>
                        <span class="project-resource-status" id="pictures-save-status" hidden></span>
                    </div>
                    <div
                        class="project-picture-dropzone<?= $canSave ? '' : ' is-disabled' ?>"
                        id="project-picture-dropzone"
                        tabindex="0"
                        <?= $canSave ? '' : ' aria-disabled="true"' ?>
                    >
                        <input
                            type="file"
                            id="project-picture-file"
                            class="project-picture-file"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,image/jpeg,image/png,image/gif,image/webp,image/bmp"
                            multiple
                            hidden
                            <?= $canSave ? '' : ' disabled' ?>
                        >
                        <span class="project-picture-dropzone-icon" aria-hidden="true">📥</span>
                        <strong>Drop pictures here</strong>
                        <span>JPG and PNG stay as-is. GIF, WEBP, BMP, and similar files are converted to PNG. Max 2 MB each.</span>
                    </div>
                    <p class="empty-panel project-empty-state" id="project-pictures-empty"<?= $pictures === [] ? '' : ' hidden' ?>>🖼️ No pictures yet. Drag and drop an image or use <strong>📁 Choose pictures</strong>.</p>
                    <div class="project-picture-filelist-wrap" id="project-picture-filelist-wrap"<?= $pictures === [] ? ' hidden' : '' ?>>
                        <div class="project-picture-filelist-head">
                            <span>📂 Saved files</span>
                            <span class="project-picture-filelist-hint">Click a filename to open and view it</span>
                        </div>
                        <ul class="project-picture-filelist" id="project-picture-filelist">
                            <?php foreach ($pictures as $picture): ?>
                                <?= $this->renderPictureFileRow(
                                    (int) $picture['id'],
                                    (string) $picture['title'],
                                    (string) $picture['mime_type'],
                                    (string) $picture['original_filename'],
                                    $assessmentId
                                ) ?>
                            <?php endforeach; ?>
                        </ul>
                        <template id="project-picture-file-row-template">
                            <?= $this->renderPictureFileRow(0, '', 'image/png', '', $assessmentId) ?>
                        </template>
                    </div>
                    <div class="project-pictures-list" id="project-pictures-list" hidden>
                        <?php foreach ($pictures as $picture): ?>
                            <?= $this->renderPictureCard(
                                (int) $picture['id'],
                                (string) $picture['title'],
                                (string) $picture['mime_type'],
                                (string) $picture['original_filename'],
                                $assessmentId,
                                $canSave
                            ) ?>
                        <?php endforeach; ?>
                    </div>
                    <template id="project-picture-card-template">
                        <?= $this->renderPictureCard(0, '', 'image/png', '', $assessmentId, $canSave) ?>
                    </template>
                </section>

                <dialog class="project-picture-viewer" id="project-picture-viewer">
                    <div class="project-picture-viewer-head">
                        <div class="project-picture-viewer-copy">
                            <strong id="project-picture-viewer-title">Picture</strong>
                            <span id="project-picture-viewer-filename" class="project-picture-viewer-filename"></span>
                        </div>
                        <button type="button" class="button ghost-light" id="project-picture-viewer-close">Close</button>
                    </div>
                    <img id="project-picture-viewer-image" alt="">
                </dialog>

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

                <section class="table-card project-sharepoint-card project-resource-card" id="project-sharepoint-catalog">
                    <div class="card-heading project-resource-heading">
                        <div class="project-resource-heading-main">
                            <span class="project-resource-icon" aria-hidden="true">📁</span>
                            <div>
                                <div class="eyebrow">Architectural Projects</div>
                                <h3>SharePoint catalog</h3>
                            </div>
                        </div>
                        <?php if ($hasCatalog): ?>
                            <span class="result-count project-count-badge"><?= count($catalogItems) ?> link<?= count($catalogItems) === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasCatalog): ?>
                        <p class="panel-help">
                            Matched folder <strong><?= $this->e($catalogProject) ?></strong> from the SharePoint catalog.
                            Links open in SharePoint (sign-in may be required).
                        </p>
                        <?php if ($catalogFolderUrl !== ''): ?>
                            <div class="project-resource-toolbar">
                                <a class="button ghost-light btn-accent-violet" href="<?= $this->e($catalogFolderUrl) ?>" target="_blank" rel="noopener noreferrer">🔗 Open project folder</a>
                                <?php if ($catalogHref !== ''): ?>
                                    <a class="button ghost" href="<?= $this->e($catalogHref) ?>?q=<?= rawurlencode($catalogProject) ?>">🔎 Catalog search</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <ul class="sharepoint-link-list sharepoint-link-list-compact">
                            <?php foreach ($catalogItems as $item): ?>
                                <?php
                                $itemName = (string) ($item['name'] ?? '');
                                $itemUrl = (string) ($item['web_url'] ?? '');
                                $itemType = (string) ($item['item_type'] ?? 'file');
                                $rel = (string) ($item['relative_path'] ?? '');
                                $modified = (string) ($item['last_modified'] ?? '');
                                $modifiedBy = (string) ($item['modified_by'] ?? '');
                                $person = (string) ($item['person'] ?? '');
                                if ($itemUrl === '') {
                                    continue;
                                }
                                $icon = $itemType === 'folder' ? '📁' : '📄';
                                $modifiedDisplay = $modified;
                                if ($modified !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $modified) === 1) {
                                    try {
                                        $modifiedDisplay = (new \DateTimeImmutable($modified))->format('M j, Y g:i A');
                                    } catch (\Throwable) {
                                        $modifiedDisplay = $modified;
                                    }
                                }
                                ?>
                                <li class="sharepoint-link-row">
                                    <span class="sharepoint-link-icon" aria-hidden="true"><?= $icon ?></span>
                                    <div class="sharepoint-link-body">
                                        <a href="<?= $this->e($itemUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e($itemName) ?></a>
                                        <?php if ($rel !== '' && $rel !== $itemName): ?>
                                            <span class="sharepoint-link-path"><?= $this->e($rel) ?></span>
                                        <?php endif; ?>
                                        <span class="sharepoint-link-meta">
                                            <?php if ($modifiedDisplay !== ''): ?>
                                                <span title="Modified">🕒 <?= $this->e($modifiedDisplay) ?></span>
                                            <?php endif; ?>
                                            <?php if ($modifiedBy !== ''): ?>
                                                <span title="Modified By">✏️ <?= $this->e($modifiedBy) ?></span>
                                            <?php endif; ?>
                                            <?php if ($person !== ''): ?>
                                                <span title="Person">👤 <?= $this->e($person) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <span class="sharepoint-link-type"><?= $this->e($itemType) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty-panel project-empty-state">
                            📁 No matching SharePoint project folder for this assessment name.
                            <?php if ($catalogHref !== ''): ?>
                                Search the <a href="<?= $this->e($catalogHref) ?>">SharePoint catalog</a>
                                or ask an admin to sync/import the Architectural Projects listing.
                            <?php else: ?>
                                The catalog may not include this project yet.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
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

    private function renderPictureCard(
        int $id,
        string $title,
        string $mimeType,
        string $originalFilename,
        int $assessmentId,
        bool $canEdit
    ): string {
        $viewUrl = $this->pictureViewUrl($assessmentId, $id);
        $formatLabel = $mimeType === 'image/jpeg' ? 'JPG' : 'PNG';
        $displayName = $originalFilename !== '' ? $originalFilename : ($title !== '' ? $title : 'Picture');

        ob_start();
        ?>
        <article
            class="project-picture-card"
            data-picture-id="<?= (int) $id ?>"
            data-view-url="<?= $this->e($viewUrl) ?>"
            data-filename="<?= $this->e($originalFilename) ?>"
        >
            <button type="button" class="project-picture-thumb-btn" <?= $viewUrl === '' ? ' disabled' : '' ?>>
                <?php if ($viewUrl !== ''): ?>
                    <img class="project-picture-thumb" src="<?= $this->e($viewUrl) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="project-picture-thumb is-empty" aria-hidden="true">🖼️</span>
                <?php endif; ?>
            </button>
            <div class="project-picture-card-body">
                <label>
                    <span>🏷️ Title</span>
                    <input type="text" class="project-picture-title" maxlength="200" value="<?= $this->e($title) ?>" placeholder="Architecture photo"<?= $canEdit ? '' : ' readonly' ?>>
                </label>
                <div class="project-picture-meta">
                    <span class="project-picture-format"><?= $this->e($formatLabel) ?></span>
                    <button
                        type="button"
                        class="project-picture-filename project-picture-filename-open"
                        title="View <?= $this->e($displayName) ?>"
                        <?= $originalFilename === '' || $viewUrl === '' ? ' hidden' : '' ?>
                    ><?= $this->e($displayName) ?></button>
                </div>
                <div class="project-picture-actions">
                    <button type="button" class="button ghost-light btn-accent-rose project-picture-view" <?= $viewUrl === '' ? ' disabled' : '' ?>>👁️ View</button>
                    <?php if ($canEdit): ?>
                        <button type="button" class="button danger-btn project-picture-remove" title="Remove picture" aria-label="Remove picture">🗑️</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php

        return (string) ob_get_clean();
    }

    private function renderPictureFileRow(
        int $id,
        string $title,
        string $mimeType,
        string $originalFilename,
        int $assessmentId
    ): string {
        $viewUrl = $this->pictureViewUrl($assessmentId, $id);
        $formatLabel = $mimeType === 'image/jpeg' ? 'JPG' : 'PNG';
        $displayName = $originalFilename !== '' ? $originalFilename : ($title !== '' ? $title : 'Picture');

        ob_start();
        ?>
        <li
            class="project-picture-file-row"
            data-picture-id="<?= (int) $id ?>"
            data-view-url="<?= $this->e($viewUrl) ?>"
            data-filename="<?= $this->e($displayName) ?>"
        >
            <button type="button" class="project-picture-file-open" <?= $viewUrl === '' ? ' disabled' : '' ?>>
                <span class="project-picture-file-icon" aria-hidden="true">📄</span>
                <span class="project-picture-file-name"><?= $this->e($displayName) ?></span>
                <span class="project-picture-format"><?= $this->e($formatLabel) ?></span>
                <?php if ($title !== '' && $title !== $displayName): ?>
                    <span class="project-picture-file-title"><?= $this->e($title) ?></span>
                <?php endif; ?>
            </button>
        </li>
        <?php

        return (string) ob_get_clean();
    }

    private function pictureViewUrl(int $assessmentId, int $id): string
    {
        if ($id <= 0 || $assessmentId <= 0) {
            return '';
        }

        if ($this->shareToken !== '') {
            return 'share.php?t=' . rawurlencode($this->shareToken)
                . '&action=view_project_picture&picture_id=' . $id;
        }

        return 'index.php?action=view_project_picture&assessment_id=' . $assessmentId . '&picture_id=' . $id;
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
