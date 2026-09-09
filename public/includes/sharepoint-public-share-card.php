<?php

declare(strict_types=1);

use RiskAssessment\Repositories\CatalogShareRepository;
use RiskAssessment\ShareUrlPresenter;

/**
 * Collapsible public-share card with compact paginated history.
 *
 * @var string $sharePanelId
 * @var string $shareKind
 * @var string $shareHeading
 * @var string $shareHelp
 * @var string $shareFreshLabel
 * @var string $shareFreshTag
 * @var bool $shareHasActive
 * @var int $shareActiveCount
 * @var string|null $shareFreshUrl
 * @var int|null $shareFreshId
 * @var list<array{id: int, label?: string, source_keys: list<string>, created_at: string, created_by_username: string, expires_at: ?string, last_accessed_at: ?string, is_active: bool, url?: string, can_copy?: bool}> $shareLinks
 * @var int $shareHistoryPage
 * @var int $shareHistoryPages
 * @var int $shareHistoryTotal
 * @var string $sharePageParam
 * @var string $shareCreateAction
 * @var string $shareRevokeAction
 * @var string $sharePurgeAction
 * @var string $shareEmailAction
 * @var bool $smtpEnabled
 * @var bool $smtpConfigured
 * @var bool $viewerIsAdmin
 * @var string $activeSourceKey
 * @var list<array<string, mixed>> $allSources
 * @var array<string, string> $sourceTitleByKey
 * @var bool $shareForceOpen
 * @var string $sharePanelFlash
 * @var string $sharePanelFlashType
 * @var callable(array<string, scalar|null>): string $shareListUrl
 */

$shareKind = CatalogShareRepository::normalizeKind((string) ($shareKind ?? CatalogShareRepository::KIND_CATALOG));
$sharePanelId = (string) ($sharePanelId ?? ($shareKind === CatalogShareRepository::KIND_OWNERS ? 'owners-share-panel' : 'catalog-share-panel'));
$shareActiveCount = max(0, (int) ($shareActiveCount ?? 0));
$shareHasActive = !empty($shareHasActive) || $shareActiveCount > 0;
$shareFreshUrl = isset($shareFreshUrl) ? (string) $shareFreshUrl : '';
$shareFreshTag = trim((string) ($shareFreshTag ?? ''));
$shareFreshId = isset($shareFreshId) ? (int) $shareFreshId : 0;
$sharePanelFlash = trim((string) ($sharePanelFlash ?? ''));
$sharePanelFlashType = ($sharePanelFlashType ?? '') === 'error' ? 'error' : 'success';
$shareForceOpen = !empty($shareForceOpen) || $shareFreshUrl !== '' || $sharePanelFlash !== '';
$shareHistoryPage = max(1, (int) ($shareHistoryPage ?? 1));
$shareHistoryPages = max(1, (int) ($shareHistoryPages ?? 1));
$shareHistoryTotal = max(0, (int) ($shareHistoryTotal ?? 0));
$shareLinks = is_array($shareLinks ?? null) ? $shareLinks : [];
$smtpEnabled = !empty($smtpEnabled);
$smtpConfigured = !empty($smtpConfigured);
$viewerIsAdmin = !empty($viewerIsAdmin);
$shareEmailAction = (string) ($shareEmailAction ?? '');
$formSuffix = preg_replace('/[^a-z0-9_-]/i', '', $shareKind) ?: 'catalog';
$createFormId = 'share-create-' . $formSuffix;
$purgeFormId = 'share-purge-' . $formSuffix;
$emailFormId = 'share-email-' . $formSuffix;
$urlInputId = 'share-link-url-' . $formSuffix;
$copyBtnId = 'btn-copy-share-link-' . $formSuffix;
$statusId = 'share-link-copy-status-' . $formSuffix;
$tagInputId = 'share-tag-' . $formSuffix;
$shareAtLimit = $shareActiveCount >= CatalogShareRepository::MAX_ACTIVE;
$canPurge = $shareHistoryTotal > $shareActiveCount;
$badgeText = $shareActiveCount > 0
    ? $shareActiveCount . ' active'
    : 'Off';
$shareSectionKey = (string) ($shareSectionKey ?? ($shareKind === CatalogShareRepository::KIND_OWNERS ? 'owners-share' : 'catalog-share'));
$showSectionMove = !empty($showSectionMove);

$copyableLinks = [];
foreach ($shareLinks as $link) {
    if (
        !empty($link['is_active'])
        && !empty($link['can_copy'])
        && trim((string) ($link['url'] ?? '')) !== ''
        && (int) ($link['id'] ?? 0) > 0
    ) {
        $copyableLinks[] = $link;
    }
}
$defaultEmailShareId = $shareFreshId > 0 ? $shareFreshId : (int) ($copyableLinks[0]['id'] ?? 0);
$canEmailLinks = $smtpEnabled && $shareEmailAction !== '' && $copyableLinks !== [];
$openCreate = $shareFreshUrl === '' && ($shareHistoryTotal === 0 || $sharePanelFlashType === 'error');
$openEmail = $canEmailLinks && ($shareFreshUrl !== '' || isset($_GET['emailed']) || $shareFreshId > 0);
$openHistory = $shareHistoryPage > 1 || ($shareHistoryTotal > 0 && $shareFreshUrl === '' && !$openEmail);
?>
<section
    class="upload-card share-link-card sharepoint-share-card"
    id="<?= e($sharePanelId) ?>"
    data-sp-section="<?= e($shareSectionKey) ?>"
    data-share-kind="<?= e($shareKind) ?>"
    data-share-anchor="1"
>
    <details class="sharepoint-share-shell" id="<?= e($sharePanelId) ?>-shell"<?= $shareForceOpen ? ' open' : '' ?>>
        <summary class="sharepoint-share-summary">
            <div class="card-heading-with-icon">
                <span class="card-icon" aria-hidden="true">🔗</span>
                <div>
                    <div class="eyebrow">Public access</div>
                    <h2><?= e((string) $shareHeading) ?></h2>
                </div>
            </div>
            <div class="sharepoint-share-summary-tools" data-no-toggle onclick="event.stopPropagation()">
                <?php require __DIR__ . '/sharepoint-section-move.php'; ?>
                <span class="result-count result-count-badge"><?= e($badgeText) ?></span>
                <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
            </div>
        </summary>
        <div class="sharepoint-share-body">
            <p class="panel-help share-panel-help-compact"><?= $shareHelp ?></p>

            <?php if ($sharePanelFlash !== ''): ?>
                <div class="alert <?= $sharePanelFlashType === 'error' ? 'alert-error' : 'alert-success' ?> share-panel-flash" role="status">
                    <?= $sharePanelFlashType === 'error' ? '⚠️' : '✅' ?> <?= e($sharePanelFlash) ?>
                </div>
            <?php endif; ?>

            <?php if ($shareFreshUrl !== ''): ?>
                <?php $shareFreshPreview = ShareUrlPresenter::truncate($shareFreshUrl); ?>
                <div class="share-link-fresh alert alert-success" id="<?= e($sharePanelId) ?>-fresh">
                    <strong><?= e((string) ($shareFreshLabel ?? 'Public link created')) ?><?= $shareFreshTag !== '' ? ' · ' . e($shareFreshTag) : '' ?></strong>
                    <div class="share-link-copy-row share-link-copy-row-uplift">
                        <input type="hidden" id="<?= e($urlInputId) ?>" value="<?= e($shareFreshUrl) ?>">
                        <code class="share-link-url-preview" title="<?= e($shareFreshUrl) ?>"><?= e($shareFreshPreview) ?></code>
                        <div class="share-link-action-group">
                            <button type="button" class="button button-primary share-link-copy-btn" data-copy-input="<?= e($urlInputId) ?>" data-copy-status="<?= e($statusId) ?>" id="<?= e($copyBtnId) ?>" title="Copy full link">📋 Copy</button>
                            <a class="button ghost-light" href="<?= e($shareFreshUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open share link">↗ Open</a>
                        </div>
                    </div>
                    <p class="share-link-copy-status" id="<?= e($statusId) ?>" hidden></p>
                </div>
            <?php endif; ?>

            <details class="share-subpanel"<?= $openCreate ? ' open' : '' ?>>
                <summary class="share-subpanel-summary">
                    <span class="share-subpanel-icon" aria-hidden="true">➕</span>
                    <span class="share-subpanel-title">Create public link</span>
                    <span class="share-subpanel-meta"><?= (int) $shareActiveCount ?> / <?= (int) CatalogShareRepository::MAX_ACTIVE ?></span>
                </summary>
                <div class="share-subpanel-body">
                    <form method="post" class="catalog-share-create-form" id="<?= e($createFormId) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= e((string) $shareCreateAction) ?>">
                        <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                        <?php if (($shareView ?? '') !== ''): ?>
                            <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                        <?php endif; ?>
                        <div class="share-create-grid">
                            <label class="catalog-share-tag" for="<?= e($tagInputId) ?>">
                                <span class="catalog-share-tag-label">Tag</span>
                                <input
                                    type="text"
                                    name="share_tag"
                                    id="<?= e($tagInputId) ?>"
                                    maxlength="<?= (int) CatalogShareRepository::LABEL_MAX_LENGTH ?>"
                                    required
                                    <?= $shareAtLimit ? 'disabled' : '' ?>
                                    autocomplete="off"
                                    placeholder="e.g. Vendor review, Finance team"
                                >
                            </label>
                            <div class="share-link-actions share-create-actions">
                                <button type="submit" class="button button-primary"<?= $shareAtLimit ? ' disabled' : '' ?>>
                                    <?= $shareHasActive ? '🔗 Create another' : '🔗 Create link' ?>
                                </button>
                                <?php if ($canPurge): ?>
                                    <button type="submit" class="button ghost" form="<?= e($purgeFormId) ?>" data-share-confirm="Permanently delete revoked and expired share history? Active links are kept.">🧹 Purge</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (count($allSources) > 1): ?>
                            <details class="share-nested-details"<?= $shareAtLimit ? '' : '' ?>>
                                <summary>Catalogs on this link</summary>
                                <fieldset class="catalog-share-scope"<?= $shareAtLimit ? ' disabled' : '' ?>>
                                    <p class="panel-help">Leave every box checked to share all catalogs. Uncheck any to keep private.</p>
                                    <div class="catalog-share-scope-list">
                                        <?php foreach ($allSources as $src): ?>
                                            <?php
                                            $srcKey = (string) ($src['source_key'] ?? '');
                                            $srcTitle = (string) ($src['title'] ?? $srcKey);
                                            ?>
                                            <label class="catalog-share-scope-chip">
                                                <input type="checkbox" name="share_source_keys[]" value="<?= e($srcKey) ?>" checked<?= $shareAtLimit ? ' disabled' : '' ?>>
                                                <span><?= e($srcTitle) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            </details>
                        <?php endif; ?>
                        <?php if ($shareAtLimit): ?>
                            <p class="catalog-share-limit-note">You already have <?= (int) CatalogShareRepository::MAX_ACTIVE ?> active public links. Revoke one before creating another.</p>
                        <?php endif; ?>
                    </form>
                </div>
            </details>

            <?php if ($canEmailLinks): ?>
                <details class="share-subpanel share-subpanel-email"<?= $openEmail ? ' open' : '' ?> id="<?= e($sharePanelId) ?>-email">
                    <summary class="share-subpanel-summary">
                        <span class="share-subpanel-icon" aria-hidden="true">✉️</span>
                        <span class="share-subpanel-title">Email a public link</span>
                        <span class="share-subpanel-meta"><?= count($copyableLinks) ?> ready</span>
                    </summary>
                    <div class="share-subpanel-body">
                        <form method="post" class="share-email-form share-email-form-panel" id="<?= e($emailFormId) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= e($shareEmailAction) ?>">
                            <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                            <?php if (($shareView ?? '') !== ''): ?>
                                <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                            <?php endif; ?>
                            <div class="share-email-grid">
                                <?php if (count($copyableLinks) > 1): ?>
                                    <label class="share-email-field share-email-field-link">
                                        <span>Link</span>
                                        <select name="share_id" required>
                                            <?php foreach ($copyableLinks as $link): ?>
                                                <?php
                                                $optId = (int) $link['id'];
                                                $optLabel = trim((string) ($link['label'] ?? ''));
                                                if ($optLabel === '') {
                                                    $optLabel = 'Link #' . $optId;
                                                }
                                                ?>
                                                <option value="<?= $optId ?>"<?= $optId === $defaultEmailShareId ? ' selected' : '' ?>><?= e($optLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php else: ?>
                                    <input type="hidden" name="share_id" value="<?= (int) $defaultEmailShareId ?>">
                                    <?php $onlyLabel = trim((string) ($copyableLinks[0]['label'] ?? '')); ?>
                                    <?php if ($onlyLabel !== ''): ?>
                                        <p class="share-email-link-tag">Sending <strong><?= e($onlyLabel) ?></strong></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <label class="share-email-field share-email-field-to">
                                    <span>To</span>
                                    <input type="text" name="email_to" required maxlength="2000" placeholder="colleague@example.com" inputmode="email" autocomplete="email">
                                </label>
                                <label class="share-email-field share-email-field-note">
                                    <span>Note <em>(optional)</em></span>
                                    <input type="text" name="email_note" maxlength="1000" placeholder="Short message…">
                                </label>
                                <div class="share-email-actions">
                                    <button type="submit" class="button button-primary">📨 Send</button>
                                </div>
                            </div>
                            <p class="share-email-foot">Recipients open the read-only link without signing in. Separate multiple addresses with commas.</p>
                        </form>
                    </div>
                </details>
            <?php elseif ($viewerIsAdmin && !$smtpEnabled): ?>
                <p class="share-email-hint">
                    <?php if ($smtpConfigured): ?>
                        SMTP is saved but not enabled. Turn on <strong>Enable outbound email</strong> under <a href="admin/email.php">Admin → Email</a>, then save.
                    <?php else: ?>
                        To email public links, configure SMTP under <a href="admin/email.php">Admin → Email</a>.
                    <?php endif; ?>
                </p>
            <?php elseif ($smtpEnabled && !$shareHasActive): ?>
                <p class="share-email-hint">Create a public link above, then email it from this panel.</p>
            <?php endif; ?>

            <form method="post" id="<?= e($purgeFormId) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e((string) $sharePurgeAction) ?>">
                <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                <?php if (($shareView ?? '') !== ''): ?>
                    <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                <?php endif; ?>
            </form>

            <?php if ($shareHistoryTotal === 0): ?>
                <p class="empty-panel project-empty-state">No public links yet.</p>
            <?php else: ?>
                <details class="share-subpanel"<?= $openHistory ? ' open' : '' ?>>
                    <summary class="share-subpanel-summary">
                        <span class="share-subpanel-icon" aria-hidden="true">📋</span>
                        <span class="share-subpanel-title">Link history</span>
                        <span class="share-subpanel-meta"><?= (int) $shareHistoryTotal ?></span>
                    </summary>
                    <div class="share-subpanel-body">
                        <div class="share-link-history share-link-history-compact">
                            <ul class="share-link-list">
                                <?php foreach ($shareLinks as $link): ?>
                                    <?php
                                    $linkId = (int) ($link['id'] ?? 0);
                                    $linkLabel = trim((string) ($link['label'] ?? ''));
                                    $linkActive = !empty($link['is_active']);
                                    $linkUrl = trim((string) ($link['url'] ?? ''));
                                    $linkCanCopy = $linkActive && !empty($link['can_copy']) && $linkUrl !== '';
                                    $scopeKeys = $link['source_keys'] ?? [];
                                    if ($scopeKeys === []) {
                                        $scopeLabel = 'All catalogs';
                                    } else {
                                        $names = [];
                                        foreach ($scopeKeys as $key) {
                                            $names[] = $sourceTitleByKey[$key] ?? $key;
                                        }
                                        $scopeLabel = implode(', ', $names);
                                    }
                                    $meta = 'Created ' . (string) ($link['created_at'] ?? '');
                                    if (($link['created_by_username'] ?? '') !== '') {
                                        $meta .= ' · ' . (string) $link['created_by_username'];
                                    }
                                    if (($link['last_accessed_at'] ?? null) !== null) {
                                        $meta .= ' · Opened ' . (string) $link['last_accessed_at'];
                                    }
                                    $revokeFormId = 'share-revoke-' . $formSuffix . '-' . $linkId;
                                    $rowUrlInputId = 'share-link-url-' . $formSuffix . '-' . $linkId;
                                    $rowStatusId = 'share-link-copy-status-' . $formSuffix . '-' . $linkId;
                                    $linkPreview = $linkUrl !== '' ? ShareUrlPresenter::truncate($linkUrl) : '';
                                    $revokeConfirm = $linkLabel !== ''
                                        ? 'Revoke the public link "' . $linkLabel . '"? Anyone with that URL will lose access.'
                                        : 'Revoke this public link? Anyone with that URL will lose access.';
                                    ?>
                                    <li class="share-link-row share-link-row-uplift <?= $linkActive ? 'is-active' : 'is-revoked' ?>">
                                        <div class="share-link-row-main">
                                            <span class="share-link-status-pill <?= $linkActive ? 'is-active' : 'is-revoked' ?>"><?= $linkActive ? 'Active' : 'Revoked' ?></span>
                                            <?php if ($linkLabel !== ''): ?>
                                                <span class="share-link-row-tag"><?= e($linkLabel) ?></span>
                                            <?php endif; ?>
                                            <span class="share-link-row-scope"><?= e($scopeLabel) ?></span>
                                            <span class="share-link-row-meta"><?= e($meta) ?></span>
                                        </div>
                                        <?php if ($linkCanCopy): ?>
                                            <div class="share-link-copy-row share-link-copy-row-uplift">
                                                <input type="hidden" id="<?= e($rowUrlInputId) ?>" value="<?= e($linkUrl) ?>">
                                                <code class="share-link-url-preview" title="<?= e($linkUrl) ?>"><?= e($linkPreview) ?></code>
                                                <div class="share-link-action-group">
                                                    <button type="button" class="button button-primary share-link-copy-btn" data-copy-input="<?= e($rowUrlInputId) ?>" data-copy-status="<?= e($rowStatusId) ?>" title="Copy full link">📋 Copy</button>
                                                    <a class="button ghost-light" href="<?= e($linkUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open share link">↗ Open</a>
                                                    <?php if ($linkId > 0): ?>
                                                        <form method="post" id="<?= e($revokeFormId) ?>" class="inline-form share-link-row-revoke" data-share-confirm="<?= e($revokeConfirm) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="<?= e((string) $shareRevokeAction) ?>">
                                                            <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                                                            <?php if (($shareView ?? '') !== ''): ?>
                                                                <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="share_id" value="<?= $linkId ?>">
                                                            <button type="submit" class="button danger-btn share-link-revoke-one" title="Revoke this link">🚫 Revoke</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <p class="share-link-copy-status" id="<?= e($rowStatusId) ?>" hidden></p>
                                        <?php elseif ($linkActive): ?>
                                            <div class="share-link-copy-row share-link-copy-row-uplift">
                                                <p class="share-link-row-legacy">URL was not stored for this older link. Revoke it and create a new one to copy again.</p>
                                                <?php if ($linkId > 0): ?>
                                                    <div class="share-link-action-group">
                                                        <form method="post" id="<?= e($revokeFormId) ?>" class="inline-form share-link-row-revoke" data-share-confirm="<?= e($revokeConfirm) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="<?= e((string) $shareRevokeAction) ?>">
                                                            <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                                                            <?php if (($shareView ?? '') !== ''): ?>
                                                                <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                                                            <?php endif; ?>
                                                            <input type="hidden" name="share_id" value="<?= $linkId ?>">
                                                            <button type="submit" class="button danger-btn share-link-revoke-one" title="Revoke this link">🚫 Revoke</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($shareHistoryPages > 1): ?>
                                <nav class="share-link-history-pager" aria-label="Share history pages">
                                    <?php if ($shareHistoryPage > 1): ?>
                                        <a class="button ghost" href="<?= e($shareListUrl([$sharePageParam => $shareHistoryPage - 1, '_hash' => $sharePanelId])) ?>">←</a>
                                    <?php endif; ?>
                                    <span class="share-link-history-page">Page <?= (int) $shareHistoryPage ?> of <?= (int) $shareHistoryPages ?></span>
                                    <?php if ($shareHistoryPage < $shareHistoryPages): ?>
                                        <a class="button ghost" href="<?= e($shareListUrl([$sharePageParam => $shareHistoryPage + 1, '_hash' => $sharePanelId])) ?>">→</a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </details>
</section>
