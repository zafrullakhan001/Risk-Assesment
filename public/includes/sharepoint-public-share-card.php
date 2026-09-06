<?php

declare(strict_types=1);

use RiskAssessment\Repositories\CatalogShareRepository;

/**
 * Collapsible public-share card with compact paginated history.
 *
 * @var string $sharePanelId
 * @var string $shareKind
 * @var string $shareHeading
 * @var string $shareHelp
 * @var string $shareFreshLabel
 * @var bool $shareHasActive
 * @var string|null $shareFreshUrl
 * @var list<array{id: int, source_keys: list<string>, created_at: string, created_by_username: string, expires_at: ?string, last_accessed_at: ?string, is_active: bool}> $shareLinks
 * @var int $shareHistoryPage
 * @var int $shareHistoryPages
 * @var int $shareHistoryTotal
 * @var string $sharePageParam
 * @var string $shareCreateAction
 * @var string $shareRevokeAction
 * @var string $sharePurgeAction
 * @var int $shareActiveId
 * @var string $activeSourceKey
 * @var list<array<string, mixed>> $allSources
 * @var array<string, string> $sourceTitleByKey
 * @var bool $shareForceOpen
 * @var callable(array<string, scalar|null>): string $shareListUrl
 */

$shareKind = CatalogShareRepository::normalizeKind((string) ($shareKind ?? CatalogShareRepository::KIND_CATALOG));
$sharePanelId = (string) ($sharePanelId ?? ($shareKind === CatalogShareRepository::KIND_OWNERS ? 'owners-share-panel' : 'catalog-share-panel'));
$shareHasActive = !empty($shareHasActive);
$shareFreshUrl = isset($shareFreshUrl) ? (string) $shareFreshUrl : '';
$shareForceOpen = !empty($shareForceOpen) || $shareFreshUrl !== '';
$shareHistoryPage = max(1, (int) ($shareHistoryPage ?? 1));
$shareHistoryPages = max(1, (int) ($shareHistoryPages ?? 1));
$shareHistoryTotal = max(0, (int) ($shareHistoryTotal ?? 0));
$shareLinks = is_array($shareLinks ?? null) ? $shareLinks : [];
$shareActiveId = (int) ($shareActiveId ?? 0);
$formSuffix = preg_replace('/[^a-z0-9_-]/i', '', $shareKind) ?: 'catalog';
$createFormId = 'share-create-' . $formSuffix;
$revokeFormId = 'share-revoke-' . $formSuffix;
$purgeFormId = 'share-purge-' . $formSuffix;
$urlInputId = 'share-link-url-' . $formSuffix;
$copyBtnId = 'btn-copy-share-link-' . $formSuffix;
$statusId = 'share-link-copy-status-' . $formSuffix;
$canPurge = $shareHistoryTotal > ($shareHasActive ? 1 : 0);
?>
<section class="upload-card share-link-card sharepoint-share-card" id="<?= e($sharePanelId) ?>" data-share-kind="<?= e($shareKind) ?>">
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
                <span class="result-count result-count-badge"><?= $shareHasActive ? 'Active' : 'Off' ?></span>
                <span class="sharepoint-sources-collapse-hint" aria-hidden="true"></span>
            </div>
        </summary>
        <div class="sharepoint-share-body">
            <p class="panel-help"><?= $shareHelp ?></p>

            <?php if ($shareFreshUrl !== ''): ?>
                <div class="share-link-fresh alert alert-success">
                    <strong><?= e((string) ($shareFreshLabel ?? 'Copy this public link now')) ?></strong> — it will not be shown again.
                    <div class="share-link-copy-row">
                        <input type="text" class="share-link-url-input" id="<?= e($urlInputId) ?>" readonly value="<?= e($shareFreshUrl) ?>">
                        <button type="button" class="button button-primary share-link-copy-btn" data-copy-input="<?= e($urlInputId) ?>" data-copy-status="<?= e($statusId) ?>" id="<?= e($copyBtnId) ?>">📋 Copy</button>
                        <a class="button ghost-light" href="<?= e($shareFreshUrl) ?>" target="_blank" rel="noopener noreferrer">↗ Open</a>
                    </div>
                    <p class="share-link-copy-status" id="<?= e($statusId) ?>" hidden></p>
                </div>
            <?php endif; ?>

            <form method="post" class="catalog-share-create-form" id="<?= e($createFormId) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= e((string) $shareCreateAction) ?>">
                <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                <?php if (($shareView ?? '') !== ''): ?>
                    <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                <?php endif; ?>
                <?php if (count($allSources) > 1): ?>
                    <fieldset class="catalog-share-scope">
                        <legend>Catalogs on this link</legend>
                        <p class="panel-help">Leave every box checked to share all catalog cards. Uncheck any catalog you want to keep private.</p>
                        <div class="catalog-share-scope-list">
                            <?php foreach ($allSources as $src): ?>
                                <?php
                                $srcKey = (string) ($src['source_key'] ?? '');
                                $srcTitle = (string) ($src['title'] ?? $srcKey);
                                ?>
                                <label class="catalog-share-scope-chip">
                                    <input type="checkbox" name="share_source_keys[]" value="<?= e($srcKey) ?>" checked>
                                    <span><?= e($srcTitle) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>
                <div class="share-link-actions">
                    <button type="submit" class="button button-primary">
                        <?= $shareHasActive ? '🔄 Create new public link' : '🔗 Create public link' ?>
                    </button>
                    <?php if ($shareHasActive): ?>
                        <button type="submit" class="button danger-btn" form="<?= e($revokeFormId) ?>" onclick="return confirm('Revoke the public link? Anyone with the old URL will lose access.');">🚫 Revoke link</button>
                    <?php endif; ?>
                    <?php if ($canPurge): ?>
                        <button type="submit" class="button ghost" form="<?= e($purgeFormId) ?>" onclick="return confirm('Permanently delete revoked and expired share history? The active link is kept.');">🧹 Purge history</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($shareHasActive): ?>
                <form method="post" id="<?= e($revokeFormId) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= e((string) $shareRevokeAction) ?>">
                    <input type="hidden" name="source" value="<?= e((string) $activeSourceKey) ?>">
                    <?php if (($shareView ?? '') !== ''): ?>
                        <input type="hidden" name="view" value="<?= e((string) $shareView) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="share_id" value="<?= (int) $shareActiveId ?>">
                </form>
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
                <div class="share-link-history share-link-history-compact">
                    <div class="share-link-history-head">
                        <div class="eyebrow">History</div>
                        <h3>Recent links</h3>
                        <span class="share-link-history-count"><?= (int) $shareHistoryTotal ?></span>
                    </div>
                    <ul class="share-link-list">
                        <?php foreach ($shareLinks as $link): ?>
                            <?php
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
                            ?>
                            <li class="share-link-row <?= !empty($link['is_active']) ? 'is-active' : 'is-revoked' ?>">
                                <strong><?= !empty($link['is_active']) ? 'Active' : 'Revoked' ?></strong>
                                <span class="share-link-row-scope"><?= e($scopeLabel) ?></span>
                                <span class="share-link-row-meta"><?= e($meta) ?></span>
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
            <?php endif; ?>
        </div>
    </details>
</section>
