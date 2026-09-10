<?php

declare(strict_types=1);

/**
 * Admin MCP / AI Tokens Management
 * 
 * Create and manage MCP (Model Context Protocol) tokens for AI assistant access.
 */

require dirname(__DIR__) . '/bootstrap.php';

$currentUser = $auth->requireAdmin();
$branding = \RiskAssessment\Branding::fromSettings($settings);

$pageTitle = 'MCP / AI Tokens';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e($branding->documentTitle()) ?></title>
    <?php require __DIR__ . '/../includes/theme-head.php'; ?>
    <?php require __DIR__ . '/../includes/head-branding.php'; ?>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
    <style>
        .mcp-tokens-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .mcp-header {
            margin-bottom: 2rem;
        }
        
        .mcp-intro {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .mcp-intro h2 {
            margin: 0 0 0.5rem 0;
            font-size: 1.25rem;
        }
        
        .mcp-intro p {
            margin: 0.5rem 0;
            color: var(--text-secondary);
        }
        
        .mcp-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .mcp-tokens-table {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .mcp-tokens-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .mcp-tokens-table th,
        .mcp-tokens-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--panel-border);
        }
        
        .mcp-tokens-table th {
            background: var(--panel-bg-secondary, #f8f9fa);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
        }
        
        .mcp-tokens-table tr:last-child td {
            border-bottom: none;
        }
        
        .mcp-tokens-table tbody tr:hover {
            background: var(--panel-hover, #f8f9fa);
        }
        
        .token-prefix {
            font-family: 'Courier New', monospace;
            background: var(--code-bg, #f1f3f5);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        
        .token-scopes {
            display: flex;
            gap: 0.25rem;
        }
        
        .scope-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .scope-read {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .scope-write {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .token-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-revoked {
            background: #ffebee;
            color: #c62828;
        }
        
        .status-expired {
            background: #fafafa;
            color: #616161;
        }
        
        .token-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--text-secondary);
        }
        
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--panel-bg);
            border-radius: 8px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--panel-border);
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--panel-border);
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--panel-border);
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-help {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .token-display {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            margin: 1rem 0;
        }
        
        .copy-button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #17a2b8;
            color: #0c5460;
        }
    </style>
</head>
<body>
    <div class="shell upload-page">
        <header class="topbar topbar-uplift">
            <a class="brand brand-link" href="../index.php" title="Return to home">
                <?php require __DIR__ . '/../includes/brand-mark.php'; ?>
                <div class="brand-text">
                    <div class="brand-title"><?= e($branding->brandTitle()) ?></div>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </a>
            <div class="topbar-actions">
                <?php require __DIR__ . '/../includes/topbar-menu-start.php'; ?>
                <?php require __DIR__ . '/../includes/admin-nav.php'; ?>
                <?php require __DIR__ . '/../includes/topbar-menu-end.php'; ?>
            </div>
        </header>

        <main class="mcp-tokens-page">
            <div class="mcp-intro">
                <h2>🤖 MCP / AI Assistant Integration</h2>
                <p><strong>MCP (Model Context Protocol)</strong> lets AI assistants like Claude and Cursor search your SharePoint catalog programmatically.</p>
                <p>Create tokens below, then add them to your AI assistant's MCP configuration. Tokens are user-bound and scoped (read-only by default).</p>
                <p>📚 <a href="../help.php#sharepoint-mcp-integration">See Help & About → SharePoint → MCP integration</a> for setup instructions.</p>
            </div>

            <div class="mcp-actions">
                <button type="button" class="button button-primary" id="create-token-btn">
                    + Create Token
                </button>
                <button type="button" class="button ghost" id="refresh-tokens-btn">
                    ↻ Refresh
                </button>
                <label style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem;">
                    <input type="checkbox" id="show-revoked">
                    Show revoked
                </label>
            </div>

            <div class="mcp-tokens-table">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>User</th>
                            <th>Token</th>
                            <th>Scopes</th>
                            <th>Status</th>
                            <th>Last Used</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tokens-tbody">
                        <tr>
                            <td colspan="8" class="empty-state">
                                <div class="empty-state-icon">⏳</div>
                                <div>Loading tokens...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </main>

        <?php require __DIR__ . '/../includes/site-footer.php'; ?>
    </div>

    <!-- Create Token Modal -->
    <div class="modal-overlay" id="create-modal">
        <div class="modal">
            <div class="modal-header">
                <h3>Create MCP Token</h3>
            </div>
            <div class="modal-body">
                <form id="create-token-form">
                    <div class="form-group">
                        <label for="token-name">Token Name *</label>
                        <input type="text" id="token-name" name="name" placeholder="e.g., Cursor IDE" required>
                        <div class="form-help">Descriptive name to identify this token</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="token-user">User</label>
                        <select id="token-user" name="userId">
                            <option value="<?= (int) $currentUser['id'] ?>" selected><?= e($currentUser['username']) ?> (You)</option>
                        </select>
                        <div class="form-help">User this token will act as</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Scopes</label>
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="scope-read" checked disabled>
                            <span>Read (search and list)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="scope-write" id="scope-write">
                            <span>Write (future use)</span>
                        </label>
                        <div class="form-help">Read scope is always included</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="token-expiry">Expiration</label>
                        <select id="token-expiry" name="expiresInDays">
                            <option value="">Never expires</option>
                            <option value="30">30 days</option>
                            <option value="90" selected>90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">1 year</option>
                        </select>
                        <div class="form-help">Token will automatically expire after this period</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="button ghost" id="create-cancel-btn">Cancel</button>
                <button type="button" class="button button-primary" id="create-submit-btn">Create Token</button>
            </div>
        </div>
    </div>

    <!-- Token Display Modal -->
    <div class="modal-overlay" id="token-display-modal">
        <div class="modal">
            <div class="modal-header">
                <h3>✅ Token Created</h3>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>⚠️ Copy this token now!</strong> You won't be able to see it again.
                </div>
                
                <div class="form-group">
                    <label>Your Token:</label>
                    <div class="token-display" id="created-token-value"></div>
                    <button type="button" class="button" id="copy-token-btn">
                        📋 Copy to Clipboard
                    </button>
                </div>
                
                <div class="alert alert-info">
                    <strong>Next steps:</strong>
                    <ol style="margin: 0.5rem 0 0 1.5rem; padding: 0;">
                        <li>Copy the token above</li>
                        <li>Add it to your Cursor MCP settings</li>
                        <li>See <a href="../help.php#sharepoint-mcp-integration" target="_blank">Help & About</a> for configuration details</li>
                    </ol>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="button button-primary" id="token-display-close-btn">Done</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/theme.js?v=<?= filemtime(__DIR__ . '/../assets/js/theme.js') ?>"></script>
    <script>
        (function() {
            const csrfToken = '<?= e($_SESSION['csrf_token'] ?? '') ?>';
            const apiBase = '../api/mcp-tokens.php';
            
            let tokens = [];
            let showRevoked = false;
            
            // Load tokens
            async function loadTokens() {
                try {
                    const url = showRevoked ? `${apiBase}?include_revoked=true` : apiBase;
                    const response = await fetch(url);
                    const result = await response.json();
                    
                    if (result.data) {
                        tokens = result.data;
                        renderTokens();
                    }
                } catch (error) {
                    console.error('Failed to load tokens:', error);
                }
            }
            
            // Render tokens table
            function renderTokens() {
                const tbody = document.getElementById('tokens-tbody');
                
                if (tokens.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" class="empty-state">
                                <div class="empty-state-icon">🔑</div>
                                <div>No tokens yet. Create your first token to get started!</div>
                            </td>
                        </tr>
                    `;
                    return;
                }
                
                tbody.innerHTML = tokens.map(token => {
                    const status = token.revokedAt ? 'revoked' 
                        : (token.expiresAt && token.expiresAt < Date.now() / 1000) ? 'expired'
                        : 'active';
                    
                    const lastUsed = token.lastUsedAt 
                        ? new Date(token.lastUsedAt * 1000).toLocaleString()
                        : 'Never';
                    
                    const created = new Date(token.createdAt * 1000).toLocaleString();
                    
                    return `
                        <tr>
                            <td><strong>${escapeHtml(token.name)}</strong></td>
                            <td>${escapeHtml(token.username || 'Unknown')}</td>
                            <td><code class="token-prefix">${escapeHtml(token.tokenPrefix)}</code></td>
                            <td>
                                <div class="token-scopes">
                                    ${token.scopes.map(s => `<span class="scope-badge scope-${s}">${s}</span>`).join('')}
                                </div>
                            </td>
                            <td><span class="token-status status-${status}">${status}</span></td>
                            <td>${lastUsed}</td>
                            <td>${created}</td>
                            <td>
                                <div class="token-actions">
                                    ${status === 'active' ? `
                                        <button type="button" class="button ghost" onclick="revokeToken(${token.id}, '${escapeHtml(token.name)}')">
                                            Revoke
                                        </button>
                                    ` : '<span style="color: var(--text-secondary);">—</span>'}
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
            
            // Create token
            async function createToken() {
                const form = document.getElementById('create-token-form');
                const formData = new FormData(form);
                
                const scopes = ['read'];
                if (document.getElementById('scope-write').checked) {
                    scopes.push('write');
                }
                
                const data = {
                    name: formData.get('name'),
                    userId: parseInt(formData.get('userId')),
                    scopes: scopes,
                    expiresInDays: formData.get('expiresInDays') ? parseInt(formData.get('expiresInDays')) : null,
                };
                
                try {
                    const response = await fetch(apiBase, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data),
                    });
                    
                    const result = await response.json();
                    
                    if (result.data && result.data.token) {
                        // Close create modal
                        document.getElementById('create-modal').classList.remove('active');
                        
                        // Show token
                        document.getElementById('created-token-value').textContent = result.data.token;
                        document.getElementById('token-display-modal').classList.add('active');
                        
                        // Reload tokens
                        await loadTokens();
                        
                        // Reset form
                        form.reset();
                    } else {
                        alert('Failed to create token: ' + (result.error || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Failed to create token:', error);
                    alert('Failed to create token');
                }
            }
            
            // Revoke token
            window.revokeToken = async function(tokenId, tokenName) {
                if (!confirm(`Revoke token "${tokenName}"?\n\nThis action cannot be undone. The token will stop working immediately.`)) {
                    return;
                }
                
                try {
                    const response = await fetch(`${apiBase}/${tokenId}`, {
                        method: 'DELETE',
                    });
                    
                    const result = await response.json();
                    
                    if (result.data) {
                        await loadTokens();
                    } else {
                        alert('Failed to revoke token: ' + (result.error || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Failed to revoke token:', error);
                    alert('Failed to revoke token');
                }
            };
            
            // Copy token to clipboard
            document.getElementById('copy-token-btn').addEventListener('click', async function() {
                const tokenValue = document.getElementById('created-token-value').textContent;
                
                try {
                    await navigator.clipboard.writeText(tokenValue);
                    this.textContent = '✓ Copied!';
                    setTimeout(() => {
                        this.textContent = '📋 Copy to Clipboard';
                    }, 2000);
                } catch (error) {
                    alert('Failed to copy to clipboard');
                }
            });
            
            // Modal controls
            document.getElementById('create-token-btn').addEventListener('click', function() {
                document.getElementById('create-modal').classList.add('active');
            });
            
            document.getElementById('create-cancel-btn').addEventListener('click', function() {
                document.getElementById('create-modal').classList.remove('active');
            });
            
            document.getElementById('create-submit-btn').addEventListener('click', createToken);
            
            document.getElementById('token-display-close-btn').addEventListener('click', function() {
                document.getElementById('token-display-modal').classList.remove('active');
            });
            
            document.getElementById('refresh-tokens-btn').addEventListener('click', loadTokens);
            
            document.getElementById('show-revoked').addEventListener('change', function() {
                showRevoked = this.checked;
                loadTokens();
            });
            
            // Close modals on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });
            
            // Utility function
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            // Initial load
            loadTokens();
        })();
    </script>
</body>
</html>
