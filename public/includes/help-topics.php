<?php

declare(strict_types=1);

/**
 * Help & About topic groups. HTML is stripped to a small allow-list before render.
 *
 * @return list<array{id: string, label: string, topics: list<array{id: string, title: string, html: string}>}>
 */
function help_topic_groups(): array
{
    return [
        [
            'id' => 'about',
            'label' => 'About',
            'topics' => [
                [
                    'id' => 'about-app',
                    'title' => 'About this app',
                    'html' => <<<'HTML'
<p>This is the <strong>Architecture Risk Assessment register</strong>: a local web app for turning Excel workbooks into an interactive dashboard, then keeping project folders, templates, and go-live decisions in one place.</p>
<p>The on-screen name can be customized under Admin → Branding. The default brand is Architecture Risk / Assessment register.</p>
<ul>
<li>Upload a matured Risk Register or Adaptive Architecture workbook (<code>.xlsx</code>).</li>
<li>Search saved projects, compare versions, and record responses, exceptions, and a final go-live evaluation.</li>
<li>Keep blank templates (workbook, AI prompt, guide images, Mermaid).</li>
<li>Index SharePoint project folders so people can search files without hunting in the library.</li>
</ul>
<p>The app runs on PHP 8+ with a SQLite database on this server. Uploaded workbooks stay in <code>uploads/</code>; the database is not in git, so updates do not wipe your projects.</p>
HTML,
                ],
                [
                    'id' => 'who-its-for',
                    'title' => 'Who it is for',
                    'html' => <<<'HTML'
<p>Architecture, technology-risk, and engagement teams who review solutions before go-live. Typical work:</p>
<ul>
<li>Assessors upload a workbook, answer gaps and risks, and capture a final evaluation.</li>
<li>Reviewers search a project by name, owner, or go-live status and open the dashboard.</li>
<li>Anyone with a public share link can view a read-only assessment, catalog, or owners board without signing in.</li>
<li>Administrators manage users, LDAP, branding, SharePoint sync, SQLite backups, and app updates.</li>
</ul>
<p>You must sign in for Find, Upload, Templates, and SharePoint. Public links are the only unsigned-in views, and they never let recipients sync, edit folders, or change assessments.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'getting-started',
            'label' => 'Getting started',
            'topics' => [
                [
                    'id' => 'home-tabs',
                    'title' => 'Home tabs',
                    'html' => <<<'HTML'
<p>After you sign in, four work areas sit under the hero. Use the top bar or these tabs to move around:</p>
<ul>
<li><strong>Find projects</strong> — search and open saved assessments. Start here: <a href="index.php#find-projects">Find projects</a>.</li>
<li><strong>Upload assessment</strong> — drop one or more <code>.xlsx</code> workbooks. Open <a href="index.php#upload">Upload</a>.</li>
<li><strong>Template library</strong> — blank workbooks, AI prompts, and guides. Open <a href="templates.php">Templates</a>.</li>
<li><strong>SharePoint catalog</strong> — searchable project folders from SharePoint. Open <a href="sharepoint.php">SharePoint</a>.</li>
</ul>
<p>Open a saved row on Find projects to enter that assessment’s dashboard (readiness score, registers, Actions, diagrams, and sharing).</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'display',
            'label' => 'Display',
            'topics' => [
                [
                    'id' => 'theme-size',
                    'title' => 'Theme and layout size',
                    'html' => <<<'HTML'
<p>The top bar has <strong>Theme</strong> and <strong>Size</strong> controls. They apply on every signed-in page and are stored in this browser only.</p>
<ul>
<li><strong>Signal</strong> — light teal theme (default).</li>
<li><strong>Midnight</strong> — indigo dark theme.</li>
<li><strong>Auto</strong> — pick a layout size from the window width.</li>
<li><strong>S through XXL</strong> — lock a compact or wide layout. S and M tighten spacing (compact mode).</li>
</ul>
<p>Help uses the same tokens, so this two-pane page follows the theme you already chose.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'projects',
            'label' => 'Projects',
            'topics' => [
                [
                    'id' => 'find-projects',
                    'title' => 'Find projects',
                    'html' => <<<'HTML'
<p>On <a href="index.php#find-projects">Find projects</a>, search any stored field: name, vendor, owner, scope, reviewer, architecture, filename, evaluator, executive summary, dates, template format, or go-live status. Leave the box blank to browse everything.</p>
<p>Useful search words:</p>
<ul>
<li>Template format: <code>adaptive</code> or <code>matured</code>.</li>
<li>Go-live: <code>ready</code>, <code>not ready</code>, or <code>no final</code>.</li>
</ul>
<p>Use <strong>Show filters</strong> to narrow columns, then switch <strong>Cards</strong>, <strong>Table</strong>, or <strong>Strip</strong>. Change rows per page and sort from the table header. Click a project name to open its dashboard.</p>
HTML,
                ],
                [
                    'id' => 'upload',
                    'title' => 'Upload an assessment',
                    'html' => <<<'HTML'
<p>Open <a href="index.php#upload">Upload assessment</a> and choose one or more Excel workbooks (<code>.xlsx</code>). The parser accepts:</p>
<ul>
<li><strong>Matured Risk Register</strong> — architecture checks, due diligence, governance, and scoring legend tabs.</li>
<li><strong>Adaptive Architecture</strong> — Question Router, material findings, due diligence evidence, classification, and related sheets.</li>
</ul>
<p>A new upload for the same solution name is stored as another version. Open the latest from Find projects, then use Actions → Versions to compare or remove older copies.</p>
<p>Need a blank file to fill in? Download one from the <a href="templates.php">Template library</a>.</p>
HTML,
                ],
                [
                    'id' => 'versions',
                    'title' => 'Versions and changes',
                    'html' => <<<'HTML'
<p>Each upload of the same solution is a version. The dashboard can highlight rows that <strong>changed since the last upload</strong> (filter: Changed since last upload).</p>
<p>Under <strong>Actions → Versions</strong> you can:</p>
<ul>
<li>See the version list and open an older copy.</li>
<li>Compare this upload with the previous one.</li>
<li>Delete this assessment, or delete older versions and keep the current one.</li>
</ul>
<p>Deleting a project from Find projects removes that saved workbook from the register. It does not change SharePoint.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'dashboard',
            'label' => 'Assessment dashboard',
            'topics' => [
                [
                    'id' => 'decision-desk',
                    'title' => 'Go-live and executive summary',
                    'html' => <<<'HTML'
<p>The top of an open assessment is the <strong>decision desk</strong>: a go-live readiness score (0–100), a band, and an executive headline plus paragraph.</p>
<ul>
<li>The score is computed from remaining risks, gaps, TBDs, and high-severity items.</li>
<li>You can customize the headline and summary, or restore the auto-generated wording.</li>
<li><strong>Presets</strong> fill both fields; you can still edit before saving.</li>
<li>A final “ready to go live” decision is recorded under Actions → Final evaluation, not by the score alone.</li>
</ul>
<p>Buttons on the desk jump to Actions (risks, sign-off, or a public share link).</p>
HTML,
                ],
                [
                    'id' => 'dashboard-tabs',
                    'title' => 'Dashboard tabs',
                    'html' => <<<'HTML'
<p>Workbook tabs change with the file format:</p>
<ul>
<li><strong>Question Router</strong> (adaptive) — selected, conditional, and excluded scenarios by module.</li>
<li><strong>Architecture checks</strong> or <strong>Material findings</strong> — control status, risk levels, and the register. Adaptive files show Gap / Risk / Decision Required rows here; the router keeps the full catalog.</li>
<li><strong>Due diligence</strong> — evidence and control-attestation items.</li>
<li><strong>Actions</strong> — responses, exceptions, sign-off, versions, share, and workspace.</li>
<li><strong>Diagram &amp; links</strong> — Mermaid diagrams, pictures, and project URLs.</li>
<li><strong>Governance summary</strong> — classification, exceptions, ADRs, or JSON diligence fields when the workbook has them.</li>
<li><strong>Scoring legend</strong> — status meanings, risk guidance, and evidence checklist.</li>
</ul>
<p>Charts and KPI chips sit above the register. Click a chip or chart slice to filter the table.</p>
HTML,
                ],
                [
                    'id' => 'actions',
                    'title' => 'Actions, evaluation, and workspace',
                    'html' => <<<'HTML'
<p><strong>Actions</strong> is where you record decisions on open items:</p>
<ul>
<li><strong>Risks / Gaps / TBD</strong> — set Taken care, Ignore, Not applicable, Closed, or leave Open. Add a comment. History is kept per item.</li>
<li><strong>Exceptions</strong> — accepted exceptions, mitigations, owners, and timelines. On the register, ✏️ adds comments and up to 5 ServiceNow links.</li>
<li><strong>Final evaluation</strong> — evaluator name, notes, and ready-to-go-live. Go-live gates summarize what still blocks a clean sign-off.</li>
<li><strong>Versions</strong> — compare and manage uploads of this solution.</li>
<li><strong>Share</strong> — create or revoke a read-only public link (shown once when created).</li>
<li><strong>Workspace</strong> — owner workload, remediation timelines, and evidence completeness. Click a row to filter Actions.</li>
</ul>
<p>Adaptive workbooks also split actionable rows into <strong>Material findings</strong> and <strong>Due diligence</strong> source sub-tabs.</p>
HTML,
                ],
                [
                    'id' => 'filters-status',
                    'title' => 'Filters, statuses, and charts',
                    'html' => <<<'HTML'
<p>Each register has a search box plus filters for section, status, risk level, response, and changed rows. <strong>Reset</strong> clears them. <strong>Respond in Actions</strong> jumps to the matching Actions tab.</p>
<p>Workbook statuses:</p>
<ul>
<li><code>Pass</code>, <code>Gap</code>, <code>Risk</code>, <code>TBD</code>, <code>N/A</code></li>
</ul>
<p>Response statuses on Actions:</p>
<ul>
<li>Open, Taken care, Ignore, Not applicable, Closed, plus “has comment”.</li>
</ul>
<p>Risk levels are High, Med, and Low. Donut and section charts stay in sync with the filtered table.</p>
HTML,
                ],
                [
                    'id' => 'diagram-links',
                    'title' => 'Diagrams, pictures, and links',
                    'html' => <<<'HTML'
<p>The <strong>Diagram &amp; links</strong> tab stores project context next to the assessment:</p>
<ul>
<li><strong>Mermaid diagrams</strong> — named views (network, data flow, deployment). Preview here or open in Mermaid Live.</li>
<li><strong>Pictures</strong> — drag JPG or PNG. Other image types are converted, stored, and opened only when you view them.</li>
<li><strong>Links</strong> — SharePoint folders, runbooks, tickets, or other URLs (capped per project). They open in a new tab.</li>
</ul>
<p>If a matching SharePoint catalog project exists, the dashboard can surface that folder alongside these resources.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'templates',
            'label' => 'Templates',
            'topics' => [
                [
                    'id' => 'template-library',
                    'title' => 'Template library',
                    'html' => <<<'HTML'
<p>The <a href="templates.php">Template library</a> holds blank workbooks anyone signed in can download. Capacity is limited (default 10 slots).</p>
<p>Each template can include:</p>
<ul>
<li>An Excel workbook (<code>.xlsx</code>).</li>
<li>An AI prompt file (<code>.txt</code>, <code>.md</code>, or <code>.prompt</code>).</li>
<li>Up to a handful of guide images (JPG/PNG).</li>
<li>One Mermaid diagram explaining how to fill the workbook.</li>
</ul>
<p>Dropping a workbook can auto-save after a moment — add the prompt, images, and Mermaid first if you have them. You can rename, replace files, preview guides, download, or delete a template when you need a free slot.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'sharepoint',
            'label' => 'SharePoint',
            'topics' => [
                [
                    'id' => 'sharepoint-folders',
                    'title' => 'Folders and catalog layout',
                    'html' => <<<'HTML'
<p>Open <a href="sharepoint.php">SharePoint catalog</a>. Each SharePoint folder is its own catalog and search index.</p>
<ul>
<li><strong>Comfort / Compact / Table</strong> change how folder cards look. Compact leaves more room for search.</li>
<li>You can open folders or the catalog in a <strong>new tab</strong> or a <strong>separate window</strong>.</li>
<li>The section board lets you <strong>reorder panels</strong> (folders, search, owners, shares). Use Reset section order to restore the default.</li>
</ul>
<p>Administrators add folder URLs, sync listings, and manage settings. Signed-in users can browse and search the catalogs they are allowed to see.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-search',
                    'title' => 'Search, compare, and tags',
                    'html' => <<<'HTML'
<p>Catalog search looks across project names, paths, and files (full-text). Select more than one catalog to <strong>compare</strong> — results show where a project is found and where it is missing.</p>
<ul>
<li>Use extension filters to keep PDFs, Visio, or Office files in view.</li>
<li>Each compare panel has its own search and extension filters.</li>
<li>Saved <strong>search tags</strong> (for example <code>tag:name</code>) reuse a common query. Admins maintain the tag list.</li>
</ul>
<p>Open a project row for a workspace dialog: files, links, archive, and related actions without leaving the catalog.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-sync',
                    'title' => 'Sync and import',
                    'html' => <<<'HTML'
<p>Folder cards stay useful only if the listing is current. Admins can refresh a catalog in several ways:</p>
<ul>
<li><strong>Sync</strong> — one-click Microsoft sign-in (MFA in a popup). Allow popups for this site. Needs Tenant ID and Client ID saved under SharePoint settings.</li>
<li><strong>Console sync</strong> — fallback: copy a script, paste it into the SharePoint browser console after MFA, then wait for completion.</li>
<li><strong>Graph sync</strong> — app-only connection with a client secret (daemon-style, no interactive login).</li>
<li><strong>Excel / CSV import</strong> — replace the catalog from a listing file (Name/Path columns).</li>
</ul>
<p>Admins can also reindex search and purge a catalog. Entra app setup (redirect URI, <code>Sites.Read.All</code> consent) is an IT task; ask an administrator if Sync is not offered yet.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-owners',
                    'title' => 'Owners dashboard',
                    'html' => <<<'HTML'
<p>The <strong>Owners</strong> view (top bar on SharePoint, or a solo window) shows owner × period insights: how many projects, activity, and related catalog signals.</p>
<ul>
<li>Expand a row to load detail, or open the board in a new tab or window.</li>
<li>You can create a <strong>public owners link</strong> so people browse only the owner cards without signing in.</li>
</ul>
<p>Recipients of that link cannot sync, edit folders, or open assessments.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-archive',
                    'title' => 'Archive and ignore',
                    'html' => <<<'HTML'
<p>Archive hides a catalog, a project, or a file/folder path from everyday search without deleting SharePoint. Flags survive a resync because they are keyed by source, project, and relative path.</p>
<ul>
<li>Non-admins do not see archived items in search.</li>
<li>Admins can reveal archived rows and turn archive off.</li>
</ul>
<p>Use archive for noise (duplicates, retired folders, working files) rather than for access control. True permission still lives in SharePoint and in who you give public links to.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'sharing',
            'label' => 'Sharing',
            'topics' => [
                [
                    'id' => 'share-assessment',
                    'title' => 'Share an assessment',
                    'html' => <<<'HTML'
<p>From an open project, open <strong>Actions → Share</strong>. Create a public read-only link. Copy it immediately — the full URL is shown only once.</p>
<ul>
<li>Anyone with the link can open the dashboard without signing in.</li>
<li>They cannot change responses, upload versions, or create new shares.</li>
<li>Revoke the link when it should stop working. Invalid or revoked tokens show “Share link unavailable”.</li>
</ul>
<p>Tag or label shares in your own notes; treat the URL like a secret. Search engines are asked not to index these pages.</p>
HTML,
                ],
                [
                    'id' => 'share-catalog',
                    'title' => 'Share catalog and owners',
                    'html' => <<<'HTML'
<p>On SharePoint, administrators can create public links for <strong>catalog cards</strong> or <strong>owner cards</strong> only.</p>
<ul>
<li>Recipients browse and search the selected catalogs (or owners) without signing in.</li>
<li>They cannot sync, edit folders, or open assessments.</li>
<li>Uncheck any catalog you want to keep private. A tag/label is required so you can tell links apart.</li>
<li>There is a maximum number of active links. Copy the URL when it appears — it is shown only once. Revoke when finished.</li>
</ul>
<p>Use an assessment share when someone needs the full dashboard; use a catalog or owners share when they only need to find folders or owners.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'account',
            'label' => 'Account & admin',
            'topics' => [
                [
                    'id' => 'sign-in',
                    'title' => 'Sign in, local, and LDAP',
                    'html' => <<<'HTML'
<p>Open the sign-in page and choose <strong>Auto</strong> (LDAP first, then local), <strong>Local</strong>, or <strong>LDAP</strong> when both are enabled. <strong>Remember me</strong> keeps this browser signed in longer. Usernames that contain <code>@localhost</code> skip LDAP.</p>
<ul>
<li><strong>Local accounts</strong> store a password hash. New passwords need 8+ characters, one uppercase letter, one number, and one special character.</li>
<li><strong>Self-registration</strong> (if enabled) stays pending until an administrator approves it.</li>
<li><strong>LDAP / Active Directory</strong> checks the password against the directory. Directory passwords are never stored here.</li>
</ul>
<p>The default first admin is <code>admin</code> / <code>admin123</code>. Change that password under Admin → Overview before exposing the app on a network.</p>
HTML,
                ],
                [
                    'id' => 'admin',
                    'title' => 'Administration',
                    'html' => <<<'HTML'
<p>The <strong>Admin</strong> link appears in the top bar for administrators. Open <a href="admin/index.php">Admin overview</a>.</p>
<ul>
<li><strong>Users</strong> — create, approve, disable, promote, reset local passwords, search LDAP, inspect directory details, review the audit log. LDAP details never include passwords.</li>
<li><strong>Authentication</strong> — turn local and LDAP on or off, configure directory servers, test the bind.</li>
<li><strong>Branding</strong> — title, subtitle, hero text, logo, favicon, footer.</li>
<li><strong>SQLite</strong> — integrity check, VACUUM, ANALYZE, snapshots, restore. Treat backup files as secrets if encryption is on.</li>
<li><strong>App updates</strong> — GitHub personal access token, check GitHub Releases, download and apply a packaged zip. Git is not required. Database, uploads, and branding stay in place.</li>
<li><strong>SharePoint</strong> — jump to catalog admin (Tenant ID, Client ID, sync, import).</li>
</ul>
<p>Only administrators can open these pages. SharePoint folder management, sync, import, purge, tags, and archive controls are admin-gated as well.</p>
HTML,
                ],
            ],
        ],
    ];
}

/**
 * @param list<array{id: string, label: string, topics: list<array{id: string, title: string, html: string}>}> $groups
 * @return list<array{id: string, title: string, html: string, group: string}>
 */
function help_flatten_topics(array $groups): array
{
    $flat = [];
    foreach ($groups as $group) {
        $label = (string) ($group['label'] ?? '');
        foreach ($group['topics'] as $topic) {
            $flat[] = [
                'id' => (string) $topic['id'],
                'title' => (string) $topic['title'],
                'html' => (string) $topic['html'],
                'group' => $label,
            ];
        }
    }

    return $flat;
}

function help_allowed_html(string $html): string
{
    $clean = strip_tags($html, '<p><ul><ol><li><strong><em><a><code>');
    $replaced = preg_replace_callback(
        '/<a\s+([^>]*?)>/i',
        static function (array $match): string {
            $attrs = $match[1];
            $href = '';
            if (preg_match('/href\s*=\s*"([^"]*)"/i', $attrs, $hrefMatch) === 1) {
                $href = html_entity_decode($hrefMatch[1], ENT_QUOTES, 'UTF-8');
            } elseif (preg_match("/href\\s*=\\s*'([^']*)'/i", $attrs, $hrefMatch) === 1) {
                $href = html_entity_decode($hrefMatch[1], ENT_QUOTES, 'UTF-8');
            }
            $href = trim($href);
            if ($href === '' || preg_match('/^(javascript|data|vbscript):/i', $href) === 1) {
                return '<a>';
            }
            if (preg_match('#^https?://#i', $href) === 1) {
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
            }
            if (preg_match('/^[a-zA-Z0-9_\\/.#?=&%-]+$/', $href) !== 1) {
                return '<a>';
            }

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        },
        $clean
    );

    return is_string($replaced) ? $replaced : $clean;
}
