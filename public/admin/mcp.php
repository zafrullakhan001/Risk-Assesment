<?php

declare(strict_types=1);

/**
 * Admin MCP / AI Tokens Management
 * 
 * Create and manage MCP (Model Context Protocol) tokens for AI assistant access.
 */

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$error = '';
$flash = '';

$adminTitle = 'MCP / AI Tokens';
$adminTab = 'mcp';
$adminEyebrow = 'AI assistant integration';
$adminHeading = 'MCP / <em>AI</em> Tokens';
$adminIntro = 'Create tokens so AI assistants like Claude, Cursor, and GitHub Copilot can search your SharePoint catalog.';

require dirname(__DIR__) . '/includes/admin-header.php';
?>
<style>
.token-list {
    display: grid;
    gap: 1rem;
    margin-top: 1.5rem;
}

.token-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    padding: 1.25rem;
}

.token-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.token-name {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0 0 0.25rem 0;
}

.token-prefix {
    font-family: monospace;
    color: var(--muted, #64748b);
    font-size: 0.875rem;
}

.token-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    color: var(--muted, #64748b);
    font-size: 0.875rem;
}

.token-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-small {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 4px;
    border: 1px solid var(--border, #cbd5e1);
    background: var(--surface, #fff);
    color: var(--ink, #0f172a);
    cursor: pointer;
}

.btn-small:hover {
    background: var(--surface-2, #f8fafc);
}

.btn-danger {
    color: #dc2626;
    border-color: #dc2626;
}

.btn-danger:hover {
    background: #fef2f2;
}

.mcp-intro-card {
    background: var(--info-bg, #eff6ff);
    border: 1px solid var(--info-border, #60a5fa);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.mcp-intro-card h2 {
    margin: 0 0 0.75rem 0;
    font-size: 1.25rem;
}

.mcp-intro-card p {
    margin: 0.5rem 0;
}

.mcp-intro-card ul {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
}

.mcp-intro-card li {
    margin: 0.25rem 0;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.modal.is-active {
    display: flex;
}

.modal-content {
    background: var(--surface, #ffffff);
    color: var(--ink, #0f172a);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
    padding: 1.75rem 2rem;
    max-width: 520px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.modal-header h2 {
    margin: 0;
    color: var(--ink, #0f172a);
}

.modal-close {
    background: transparent;
    border: none;
    color: var(--muted, #64748b);
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 2rem;
    height: 2rem;
    border-radius: 6px;
}

.modal-close:hover {
    background: var(--surface-2, #f1f5f9);
    color: var(--ink, #0f172a);
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: var(--ink, #0f172a);
}

.form-group input,
.form-group select {
    width: 100%;
    box-sizing: border-box;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--border, #cbd5e1);
    border-radius: 6px;
    background: var(--surface, #fff);
    color: var(--ink, #0f172a);
    font-size: 1rem;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

.token-display {
    background: var(--code-bg, #f3f4f6);
    border: 1px solid var(--code-border, #d1d5db);
    border-radius: 4px;
    padding: 1rem;
    font-family: monospace;
    word-break: break-all;
    margin: 1rem 0;
}

.warning-text {
    color: #d97706;
    font-weight: 500;
    margin-top: 0.75rem;
}
</style>

<div class="mcp-intro-card">
    <h2>🤖 MCP / AI Assistant Integration</h2>
    <p><strong>MCP (Model Context Protocol)</strong> lets AI assistants like Claude and Cursor search your SharePoint catalog programmatically.</p>
    <p><strong>What you can do:</strong></p>
    <ul>
        <li>Create tokens for AI assistants (Claude, Cursor, GitHub Copilot)</li>
        <li>Search SharePoint catalog with natural language queries</li>
        <li>Set expiration dates for security</li>
        <li>Revoke tokens when no longer needed</li>
        <li>View usage statistics and last used dates</li>
    </ul>
    <p>To get started, click <strong>Create Token</strong> below, name it after your AI tool, and copy the token to your MCP configuration.</p>
</div>

<section class="upload-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0;">Your MCP Tokens</h2>
        <button type="button" class="button button-primary" id="create-token-btn">Create Token</button>
    </div>
    <div id="tokens-list">
        <p style="color: var(--text-muted);">Loading tokens...</p>
    </div>
</section>

<!-- Create Token Modal -->
<div class="modal" id="create-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create MCP Token</h2>
            <button type="button" class="modal-close" id="create-close">&times;</button>
        </div>
        <form id="create-form">
            <div class="form-group">
                <label for="token-name">Token Name *</label>
                <input type="text" id="token-name" name="name" required 
                       placeholder="e.g., Cursor IDE, Claude Desktop, GitHub Copilot">
            </div>
            <div class="form-group">
                <label for="token-expires">Expiration</label>
                <select id="token-expires" name="expires">
                    <option value="90">90 days (recommended)</option>
                    <option value="30">30 days</option>
                    <option value="180">180 days</option>
                    <option value="365">1 year</option>
                    <option value="0">Never expires</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="button" id="cancel-create">Cancel</button>
                <button type="submit" class="button button-primary">Create Token</button>
            </div>
        </form>
    </div>
</div>

<!-- Token Display Modal -->
<div class="modal" id="display-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Token Created! ✓</h2>
            <button type="button" class="modal-close" id="display-close">&times;</button>
        </div>
        <p><strong>Copy this token now!</strong> It will only be shown once.</p>
        <div class="token-display" id="token-value"></div>
        <button type="button" class="button button-primary" id="copy-token" style="width: 100%;">
            📋 Copy to Clipboard
        </button>
        <p class="warning-text">⚠️ Save this token securely. You won't be able to see it again.</p>
        <div class="form-actions">
            <button type="button" class="button" id="display-done">Done</button>
        </div>
    </div>
</div>

<script>
(function() {
    const tokensList = document.getElementById('tokens-list');
    const createBtn = document.getElementById('create-token-btn');
    const createModal = document.getElementById('create-modal');
    const createClose = document.getElementById('create-close');
    const cancelCreate = document.getElementById('cancel-create');
    const createForm = document.getElementById('create-form');
    const displayModal = document.getElementById('display-modal');
    const displayClose = document.getElementById('display-close');
    const displayDone = document.getElementById('display-done');
    const copyToken = document.getElementById('copy-token');
    const tokenValue = document.getElementById('token-value');

    function loadTokens() {
        fetch('../api/mcp-tokens.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                const tokens = Array.isArray(data.tokens) ? data.tokens : (Array.isArray(data.data) ? data.data : []);
                if (tokens.length === 0) {
                    tokensList.innerHTML = '<p style="color: var(--muted, #64748b);">No tokens yet. Create one to get started!</p>';
                    return;
                }
                
                tokensList.innerHTML = tokens.map(token => {
                    const createdAt = token.createdAt ?? token.created_at;
                    const lastUsedAt = token.lastUsedAt ?? token.last_used_at;
                    const expiresAt = token.expiresAt ?? token.expires_at;
                    const revokedAt = token.revokedAt ?? token.revoked_at;
                    const tokenPrefix = token.tokenPrefix ?? token.token_prefix ?? '';

                    const createdDate = createdAt ? new Date(createdAt * 1000).toLocaleDateString() : '—';
                    const lastUsed = lastUsedAt
                        ? new Date(lastUsedAt * 1000).toLocaleDateString()
                        : 'Never';
                    const expires = expiresAt
                        ? new Date(expiresAt * 1000).toLocaleDateString()
                        : 'Never';
                    const isExpired = expiresAt && expiresAt < Date.now() / 1000;
                    const isRevoked = revokedAt !== null && revokedAt !== undefined;
                    
                    let statusBadge = '';
                    if (isRevoked) {
                        statusBadge = '<span style="color: #dc2626; font-weight: 500;">Revoked</span>';
                    } else if (isExpired) {
                        statusBadge = '<span style="color: #f59e0b; font-weight: 500;">Expired</span>';
                    } else {
                        statusBadge = '<span style="color: #10b981; font-weight: 500;">Active</span>';
                    }
                    
                    return `
                        <div class="token-card">
                            <div class="token-card-header">
                                <div>
                                    <div class="token-name">${escapeHtml(token.name || '')}</div>
                                    <div class="token-prefix">${escapeHtml(tokenPrefix)}••••••••••••</div>
                                </div>
                                <div class="token-actions">
                                    ${!isRevoked && !isExpired ? `
                                        <button type="button" class="btn-small btn-danger" data-revoke-id="${token.id}" data-revoke-name="${escapeHtml(token.name || '')}">
                                            Revoke
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="token-meta">
                                <span>Status: ${statusBadge}</span>
                                <span>Created: ${createdDate}</span>
                                <span>Last used: ${lastUsed}</span>
                                <span>Expires: ${expires}</span>
                            </div>
                        </div>
                    `;
                }).join('');

                tokensList.querySelectorAll('[data-revoke-id]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        revokeToken(Number(btn.getAttribute('data-revoke-id')), btn.getAttribute('data-revoke-name') || '');
                    });
                });
            })
            .catch(err => {
                tokensList.innerHTML = '<p style="color: #dc2626;">Error loading tokens.</p>';
                console.error('Load tokens error:', err);
            });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML;
    }

    function revokeToken(id, name) {
        if (!confirm(`Revoke token "${name}"? This cannot be undone.`)) {
            return;
        }
        
        fetch('../api/mcp-tokens.php', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            if (ok && (data.ok || data.data)) {
                loadTokens();
            } else {
                alert('Error: ' + (data.error || 'Failed to revoke token'));
            }
        })
        .catch(err => {
            alert('Error revoking token');
            console.error('Revoke error:', err);
        });
    }

    createBtn.addEventListener('click', () => {
        createModal.classList.add('is-active');
    });

    createClose.addEventListener('click', () => {
        createModal.classList.remove('is-active');
    });

    cancelCreate.addEventListener('click', () => {
        createModal.classList.remove('is-active');
    });

    createForm.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const formData = new FormData(createForm);
        const payload = {
            name: formData.get('name'),
            expiresInDays: parseInt(String(formData.get('expires') || '90'), 10)
        };
        
        fetch('../api/mcp-tokens.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json().then(data => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            const token = data.token || (data.data && data.data.token);
            if (ok && token) {
                createModal.classList.remove('is-active');
                createForm.reset();
                tokenValue.textContent = token;
                displayModal.classList.add('is-active');
                loadTokens();
            } else {
                alert('Error: ' + (data.error || 'Failed to create token'));
            }
        })
        .catch(err => {
            alert('Error creating token');
            console.error('Create error:', err);
        });
    });

    displayClose.addEventListener('click', () => {
        displayModal.classList.remove('is-active');
    });

    displayDone.addEventListener('click', () => {
        displayModal.classList.remove('is-active');
    });

    copyToken.addEventListener('click', () => {
        const text = tokenValue.textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(() => {
                const original = copyToken.textContent;
                copyToken.textContent = '✓ Copied!';
                setTimeout(() => {
                    copyToken.textContent = original;
                }, 2000);
            }).catch(err => {
                console.error('Copy failed:', err);
                alert('Failed to copy. Please copy manually.');
            });
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            copyToken.textContent = '✓ Copied!';
            setTimeout(() => {
                copyToken.textContent = '📋 Copy to Clipboard';
            }, 2000);
        }
    });

    loadTokens();
})();
</script>

<?php
require dirname(__DIR__) . '/includes/admin-footer.php';
