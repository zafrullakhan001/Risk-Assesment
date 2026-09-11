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
<p>This is the <strong>Architecture Risk Assessment register</strong>: a local web app for turning Excel workbooks into an interactive dashboard, then keeping project folders, ServiceNow ticket packets, templates, and go-live decisions in one place.</p>
<p>The on-screen name can be customized under Admin → Branding. The default brand is Architecture Risk / Assessment register. The installed release is recorded in <code>VERSION.json</code> (shown under Admin → App updates).</p>
<ul>
<li>Upload a matured Risk Register or Adaptive Architecture workbook (<code>.xlsx</code>).</li>
<li>Search saved assessments, compare versions, and record responses, exceptions, and a final go-live evaluation.</li>
<li>Build a <strong>Ticket Dossier</strong> from ServiceNow Demand, Story, Task, and DDR exports (any subset is enough).</li>
<li>Keep blank templates (workbook, AI prompt, guide images, Mermaid) — up to 15 slots by default.</li>
<li>Index SharePoint project folders with live search, fuzzy matching, QR codes, owner insights, and public share links.</li>
<li>Open a dedicated <strong>architecture project catalog(s)</strong> view from the top bar for a focused catalog search.</li>
</ul>
<p>The app runs on PHP 8+ with SQLite on this server. Assessment workbooks stay in <code>uploads/</code>. Ticket Dossier files live under <code>database/ticket-dossier-storage/</code> with their own <code>ticketdetails.sqlite</code>. App updates apply a GitHub Release zip (git is not required); the database folder, uploads, and branding stay in place.</p>
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
<li>Engagement teams assemble a Ticket Dossier from ServiceNow PDFs and DDR JSON to read demand, story, task, vendor, and questionnaire answers in one place.</li>
<li>Anyone with a public share link can view a read-only assessment, catalog, or owners board without signing in.</li>
<li>Administrators manage users, LDAP, branding, SharePoint sync, SQLite backups, and app updates.</li>
</ul>
<p>You must sign in for Find, Upload, Templates, SharePoint, architecture project catalog(s), and Ticket Dossier. Public links are the only unsigned-in views, and they never let recipients sync, edit folders, or change assessments. Ticket Dossier has no public share — it stays behind sign-in.</p>
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
<p>After you sign in, four work areas sit under the hero. Extra modules sit in the top bar.</p>
<p>Home tabs:</p>
<ul>
<li><strong>Find projects</strong> — search and open saved assessments. Start here: <a href="index.php#find-projects">Find projects</a>.</li>
<li><strong>Upload assessment</strong> — drop one or more <code>.xlsx</code> workbooks. Open <a href="index.php#upload">Upload</a>.</li>
<li><strong>Template library</strong> — blank workbooks, AI prompts, and guides. Open <a href="templates.php">Templates</a>.</li>
<li><strong>SharePoint catalog</strong> — searchable project folders from SharePoint. Open <a href="sharepoint.php">SharePoint</a>.</li>
</ul>
<p>Top-bar shortcuts (every signed-in page):</p>
<ul>
<li><strong>architecture project catalog(s)</strong> — a focused catalog search (default source, OR mode, 100 per page). Open <a href="sharepoint.php?view=catalog&amp;source=default&amp;mode=or&amp;per=100">architecture project catalog(s)</a>.</li>
<li><strong>Ticket Dossier</strong> — ServiceNow packet viewer. Open <a href="ticket-dossier/">Ticket Dossier</a>.</li>
</ul>
<p>Open a saved row on Find projects to enter that assessment’s dashboard (readiness score, registers, Actions, diagrams, and sharing). Open a Ticket Dossier row to read Demand / Story / Task / DDR chapters for that engagement.</p>
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
<p>On <a href="help.php">Help &amp; About</a>, a reading toolbar adds page-only options (also stored in this browser):</p>
<ul>
<li><strong>Font</strong> — choose a Sans, Serif, Monospace, or Cursive face (web fonts plus system fonts such as Arial, Georgia, and Courier New) for the topic list and article.</li>
<li><strong>Read aloud</strong> — Play, Pause, and Stop use the browser’s speech synthesis with up to four US English male and four US English female voices installed on your device.</li>
</ul>
<p>Help, SharePoint, and Ticket Dossier use the same theme tokens, so every signed-in page follows the theme you already chose. Font and Read aloud apply only on Help &amp; About.</p>
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
<p>The person who first creates a project owns it. Everyone signed in can see projects in the list. Editing requires the owner’s permission. If a project shows <strong>Locked</strong>, only the owner, invited editors, and administrators can open it — other people still see it listed.</p>
<p>When someone grants you edit access or transfers ownership to you, a <strong>people</strong> icon in the header shows a badge and lists recent notices. Open it to see grants, ownership handoffs, and projects shared with you. If Admin → Email is configured, both parties also receive an email.</p>
<p>If you are leaving the team, open <strong>Actions → Access</strong> on a project you own to transfer that project, or use <a href="transfer-ownership.php">Transfer ownership</a> to hand off selected projects or everything you own to another approved user.</p>
HTML,
                ],
                [
                    'id' => 'upload',
                    'title' => 'Upload an assessment',
                    'html' => <<<'HTML'
<p>Open <a href="index.php#upload">Upload assessment</a>, choose one or more Excel workbooks (<code>.xlsx</code>), then click <strong>Generate dashboard</strong>. The parser accepts:</p>
<ul>
<li><strong>Classic Risk Register</strong> (matured) — Architecture sheet (metadata in early rows, checks from the header row), Due Diligence Extension, JSON Due Diligence Summary, governance, and scoring legend.</li>
<li><strong>Adaptive Architecture</strong> — classify → route → material findings: Question Router, material findings, due diligence evidence, classification, and related sheets.</li>
</ul>
<p>A new upload for the same solution name is stored as another version and keeps the original owner, lock setting, and editors. You need edit access on that project to upload another version. Open the latest from Find projects, then use Actions → Versions to compare or remove older copies.</p>
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
<li>If you own the project: delete this assessment, or delete older versions and keep the current one.</li>
</ul>
<p>Deleting a project from Find projects (owner only) removes that saved workbook from the register. It does not change SharePoint.</p>
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
<li>A final “ready to go live” decision is recorded under <strong>Actions → Sign-off</strong> (Final evaluation form), not by the score alone.</li>
</ul>
<p>Buttons on the desk jump to Actions (risks, Sign-off, or a public share link).</p>
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
<li><strong>Actions</strong> — responses, exceptions, Sign-off, versions, access (owner), and share (owner).</li>
<li><strong>Diagram &amp; links</strong> — Mermaid diagrams, pictures, and project URLs.</li>
<li><strong>Governance summary</strong> — classification, exceptions, ADRs, or JSON diligence fields when the workbook has them.</li>
<li><strong>Scoring legend</strong> — status meanings, risk guidance, and evidence checklist.</li>
</ul>
<p>Charts and KPI chips sit above the register. Click a chip or chart slice to filter the table.</p>
HTML,
                ],
                [
                    'id' => 'actions',
                    'title' => 'Actions and Sign-off',
                    'html' => <<<'HTML'
<p><strong>Actions</strong> is where you record decisions on open items. Use <strong>Finding sources</strong> to narrow the list:</p>
<ul>
<li><strong>All sources</strong> — every actionable row.</li>
<li><strong>Architecture</strong> (matured) or <strong>Material findings</strong> (adaptive) — register findings only.</li>
<li><strong>Due diligence</strong> — evidence / diligence rows only.</li>
<li><strong>Sections</strong> chips — further filter by workbook section.</li>
</ul>
<p>Action tabs:</p>
<ul>
<li><strong>Risks / Gaps / TBD</strong> — set Taken care, Ignore, Not applicable, Closed, or leave Open. Add a comment. History is kept per item.</li>
<li><strong>Exceptions</strong> — accepted exceptions, mitigations, owners, and timelines. On the register, ✏️ adds comments and up to 5 ServiceNow links.</li>
<li><strong>Sign-off</strong> — Final evaluation form: evaluator name, notes, and ready-to-go-live. Go-live gates summarize what still blocks a clean sign-off. Sign-off history is kept.</li>
<li><strong>Versions</strong> — compare and manage uploads of this solution.</li>
<li><strong>Access</strong> — owners lock or unlock the project, invite editors, and transfer ownership when leaving the team. Grants and transfers notify both parties in-app (people icon / toaster) and by email when SMTP is configured.</li>
<li><strong>Share</strong> — owners create or revoke a read-only public link (shown once when created).</li>
</ul>
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
<p>The <a href="templates.php">Template library</a> holds blank workbooks anyone signed in can download. Capacity is limited (default 15 slots).</p>
<p>Each template can include:</p>
<ul>
<li>An Excel workbook (<code>.xlsx</code>).</li>
<li>An AI prompt — upload <code>.txt</code> / <code>.md</code> / <code>.prompt</code>, or paste the prompt text in the form.</li>
<li>Up to <strong>5</strong> guide images (JPG/PNG).</li>
<li>One Mermaid diagram with <strong>Default</strong> or <strong>ELK</strong> layout and flow direction (TB, LR, and related options).</li>
</ul>
<p>Dropping a workbook can auto-save after a moment — add the prompt, images, and Mermaid first if you have them. Use <strong>Open guide</strong> for the popup with images and the diagram. You can rename, replace files, download, or delete a template when you need a free slot.</p>
HTML,
                ],
            ],
        ],
        [
            'id' => 'ticket-dossier',
            'label' => 'Ticket Dossier',
            'topics' => [
                [
                    'id' => 'dossier-overview',
                    'title' => 'What Ticket Dossier is',
                    'html' => <<<'HTML'
<p><a href="ticket-dossier/">Ticket Dossier</a> is a signed-in ServiceNow packet viewer. It turns Demand, Story, Task, and Due Diligence (DDR) exports into one readable dossier — without replacing the Excel assessment register.</p>
<ul>
<li>You can start with <strong>any subset</strong> of files. Missing chapters stay empty until you upload them later.</li>
<li>Types are detected from <strong>file contents</strong> first, then from the filename (for example <code>dmn_demand.pdf</code>, <code>rm_story.pdf</code>, <code>sc_task.pdf</code>, <code>DDR_….json</code>).</li>
<li>A fresh install can seed a sample packet once so you can explore the layout. Deleting every dossier does not re-seed it.</li>
<li>Dossier data is stored separately from assessments: <code>database/ticketdetails.sqlite</code> and <code>database/ticket-dossier-storage/</code>. App updates keep that folder.</li>
</ul>
<p>Use the assessment dashboard for go-live scoring and workbook findings. Use Ticket Dossier to read the ServiceNow demand / story / task / DDR packet that sits alongside that work.</p>
HTML,
                ],
                [
                    'id' => 'dossier-upload',
                    'title' => 'Upload and classify files',
                    'html' => <<<'HTML'
<p>Open <a href="ticket-dossier/">Ticket Dossier</a> and use <strong>New project</strong>. Drop or browse PDF and JSON files (up to 10 files, 15 MB each).</p>
<ul>
<li><strong>Demand</strong> — ServiceNow demand PDF (business case, description, related records).</li>
<li><strong>Story</strong> — story / RM PDF.</li>
<li><strong>Task</strong> — catalog task PDF.</li>
<li><strong>DDR</strong> — Due Diligence JSON export (vendor, DDR fields, internal and external questionnaires).</li>
</ul>
<p>The dropzone classifies each file as you add it and shows whether it was recognized from contents or filename. Unrecognized files are skipped. An optional title can be left blank so the app names the dossier from the files.</p>
<p>Click <strong>Create dossier</strong> when at least one recognized file is ready. On an existing dossier, open <strong>Complete this dossier</strong> (or <strong>Replace or refresh a source</strong>) to fill gaps or replace Demand, Story, Task, or DDR with a newer export.</p>
HTML,
                ],
                [
                    'id' => 'dossier-list',
                    'title' => 'Find dossier projects',
                    'html' => <<<'HTML'
<p>The <a href="ticket-dossier/#find-projects">Projects</a> list on Ticket Dossier searches title, vendor, owner, demand, story, task, and DDR numbers. Leave the box blank to browse everything.</p>
<ul>
<li>Switch <strong>Cards</strong>, <strong>Table</strong>, or <strong>Strip</strong>. The choice is stored in this browser.</li>
<li>Use <strong>Show filters</strong> to narrow ID, project, vendor, owner, ticket numbers, or updated date. Sort from the table headers.</li>
<li>The <strong>Owner</strong> column is the signed-in user who created the dossier. You can change the name or linked account from <strong>Edit details</strong> on the dossier.</li>
<li>Change rows per page (10, 25, 50, or 100). Source pills show which of the four files are present (for example 3/4 sources).</li>
<li>Open a row to read the dossier. Incomplete rows also have <strong>Complete</strong>. <strong>Delete</strong> removes that dossier and its stored files (it does not change ServiceNow or the assessment register).</li>
</ul>
HTML,
                ],
                [
                    'id' => 'dossier-view',
                    'title' => 'Read a dossier',
                    'html' => <<<'HTML'
<p>An open dossier shows a Demand → Story → Task → DDR ribbon (present or not uploaded), then only the chapters that have data:</p>
<ul>
<li><strong>Overview</strong> — owner (who created the dossier), description, business case (from demand), vendor, and ticket numbers / states. Use <strong>Edit details</strong> to change the project name, vendor, or owner if something was missed.</li>
<li><strong>Demand / Story / Task</strong> — parsed fields, related records, and long text such as description or business case.</li>
<li><strong>Due Diligence</strong> — DDR fields from the JSON export.</li>
<li><strong>Vendor</strong> — third-party fields when the DDR includes them.</li>
<li><strong>Assessments</strong> — external and internal questionnaires, with progress, a question search, and an “Answered only” toggle.</li>
<li><strong>Original files</strong> — download each stored PDF or JSON.</li>
</ul>
<p>Use the page search box to find any on-screen text, including misspellings. Ranked snippets jump to the matching field. Shortcut chips appear when Vendor, Owner, Business Owner, Executive Sponsor, Product Owner, Product Manager, Demand Manager, Requested by, or Assignee are present. Use <strong>+ Custom preset</strong> to save your own jump/search chips in this browser. <strong>Hide section dups</strong> hides fields that repeat with the same value across Demand, Story, Task, and DDR (keeping the earliest section). <strong>Show all fields</strong> reveals empty values that are hidden by default. Jump between chapters with the section nav.</p>
HTML,
                ],
                [
                    'id' => 'dossier-export',
                    'title' => 'Export and import ZIP',
                    'html' => <<<'HTML'
<p>Use ZIP backups to copy a dossier (or every dossier) to another machine or to restore after a wipe.</p>
<ul>
<li>From an open dossier, <strong>Export ZIP</strong> downloads that project plus its original Demand / Story / Task / DDR files.</li>
<li>On the Ticket Dossier home page, each row has a ZIP action, and <strong>Export all ZIP</strong> packs every project into one archive.</li>
<li><strong>Import ZIP</strong> on the home page restores a single-project ZIP or a full backup. Imports always create <strong>new</strong> projects; they do not overwrite existing IDs.</li>
</ul>
<p><strong>Export JSON</strong> is still available for offline or AI analysis. That file has metadata and parsed fields, not the original PDF bytes. ZIP is the format to use when you need a complete backup.</p>
<p>The download is named like <code>ticket-dossier-{id}-{title}.zip</code> or <code>ticket-dossier-all-{date}.zip</code>. Ticket Dossier is not publicly shareable. Recipients need a signed-in account on this app.</p>
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
<p>Open <a href="sharepoint.php">SharePoint catalog</a>. Each SharePoint folder is its own catalog and search index. The top bar also has a dedicated <a href="sharepoint.php?view=catalog&amp;source=default&amp;mode=or&amp;per=100">architecture project catalog(s)</a> shortcut — a focused search view (default source, OR mode, 100 rows) without the folder-admin panels.</p>
<ul>
<li><strong>Comfort / Compact / Table</strong> change how folder cards look. Compact leaves more room for search.</li>
<li>You can open folders, the catalog, or Owners in a <strong>new tab</strong> or a <strong>separate window</strong>.</li>
<li>The section board lets you <strong>reorder panels</strong> (folders, catalog share, owners, owners share, search, projects, admin). Use Reset section order to restore the default.</li>
<li>On the project table, use <strong>Columns</strong> to show or hide Match, Items, dates, people, and action buttons. Select <strong>2–3 folders</strong> and open <strong>Compare selected</strong> for a side-by-side view. Select exactly <strong>two catalogs</strong> and choose <strong>Compare</strong> to compare all project folders, then drill into files for a match. Folder and compare dialogs have their own Columns pickers (Copy, QR, tags, and Archive).</li>
<li>Each project row has a <strong>QR</strong> button so you can scan the SharePoint folder URL on a phone (print or copy from the dialog).</li>
</ul>
<p>Administrators add folder URLs, sync listings, and manage settings. Signed-in users can browse and search the catalogs they are allowed to see.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-search',
                    'title' => 'Search, compare, and tags',
                    'html' => <<<'HTML'
<p>Catalog search is live as you type. It matches project names, nested files, subfolders, paths, Modified By, Created By, and search tags. Typing a tag name is enough — you do not need the <code>tag:</code> prefix. Use <code>tag:name</code> when you want to require that tag. Select two catalogs and choose <strong>Compare</strong> for a side-by-side project-folder comparison (shared, only left, only right). Selecting more catalogs still shows where a project is found and where it is missing. The search card also has Comfort / Compact density.</p>
<p>Search operators and toggles:</p>
<ul>
<li><code>tag:name</code> (optional prefix), <code>ext:pdf</code>, <code>type:visio</code>, <code>person:"Last, First"</code>, <code>path:drawings</code>, <code>has:pdf</code>, <code>"exact phrase"</code>, and <code>-exclude</code>.</li>
<li><strong>Fuzzy</strong> — tolerate typos and similar-sounding words (for example Encore ≈ Encor).</li>
<li><strong>Deep files</strong> — walk every cataloged file alongside the folder (names and paths, not file contents).</li>
<li><strong>Suggest</strong> — show query suggestions while typing.</li>
<li><strong>AND / OR</strong> — require every word or any word.</li>
<li><strong>Show archived</strong> (admins) — include catalogs, projects, and files you hid so you can restore them.</li>
<li><strong>Recent</strong> — browser-local chips for recent queries.</li>
</ul>
<p>Open <strong>Advanced</strong> for date, person, catalog presence (Any / All / Only / Missing), “projects that contain,” and “projects that lack,” plus file-type chips. <strong>Save search</strong> pins the query and filters; <strong>Export CSV</strong> downloads the full filtered set. Press <code>/</code> to focus the search box.</p>
<p>Open a project row for the workspace dialog: files, Copy / QR / tags, archive, and related actions. Admins maintain the reusable <strong>search tag</strong> list.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-sync',
                    'title' => 'Sync and import',
                    'html' => <<<'HTML'
<p>Folder cards stay useful only if the listing is current. Admins can refresh a catalog in several ways:</p>
<ul>
<li><strong>One-click Sync</strong> (recommended) — Microsoft sign-in with MFA in a popup. Allow popups for this site. Needs Tenant ID and Client ID only (no client secret). Uses a local Microsoft sign-in library.</li>
<li><strong>Console sync</strong> — fallback with no Entra app: prepare, copy the script, paste it into the SharePoint browser console after MFA, then wait for completion.</li>
<li><strong>Graph sync</strong> — app-only connection with a client secret (daemon-style, no interactive login).</li>
<li><strong>Excel / CSV import</strong> — replace the catalog from a listing file (Name/Path columns).</li>
</ul>
<p>Under SharePoint settings, <strong>Folder action buttons</strong> can show or hide One-click Sync and Console sync on each folder card. Admins can also reindex search and purge a catalog (type <code>PURGE</code>; optional clear of search tags; VACUUM after purge). Entra app setup (redirect URI, <code>Sites.Read.All</code> consent) is an IT task; ask an administrator if One-click Sync is not offered yet.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-owners',
                    'title' => 'Owners dashboard',
                    'html' => <<<'HTML'
<p>The <strong>Project owners</strong> view (top bar on SharePoint, or a solo window) shows owner × period insights across the catalogs you select.</p>
<ul>
<li>Group time by <strong>month</strong>, <strong>quarter</strong>, or <strong>year</strong>; filter by year; search people or projects.</li>
<li>Quick chips include <strong>This year</strong>, <strong>Touched this quarter</strong>, <strong>Quiet 12+ months</strong>, <strong>Unassigned</strong>, <strong>Undated</strong>, and <strong>Has assessment</strong>.</li>
<li><strong>Sort</strong> the leaderboard (projects, streak, items, newest owners, and related options). Click a cell to see the folders in that period.</li>
<li>Select <strong>2–3 owners</strong> and open <strong>Compare</strong> for a side-by-side view. Export <strong>CSV</strong> or <strong>Print</strong> a snapshot.</li>
<li>Scope which catalogs count toward the stats; optional catalog colors make sources easier to tell apart.</li>
<li>Create a <strong>public owners link</strong> (<strong>Share project owner cards</strong>) so people browse only the owner cards without signing in.</li>
</ul>
<p>Recipients of that link cannot sync, edit folders, or open assessments.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-archive',
                    'title' => 'Archive',
                    'html' => <<<'HTML'
<p><strong>Archive</strong> hides a catalog, a project, or a file/folder path from everyday search without deleting SharePoint. Flags survive a resync because they are keyed by source, project, and relative path.</p>
<ul>
<li>Non-admins do not see archived items in search.</li>
<li>Admins can use <strong>Show archived</strong> to reveal hidden rows and turn archive off (restore).</li>
</ul>
<p>Use archive for noise (duplicates, retired folders, working files) rather than for access control. True permission still lives in SharePoint and in who you give public links to.</p>
HTML,
                ],
                [
                    'id' => 'sharepoint-mcp-integration',
                    'title' => 'MCP integration for AI assistants',
                    'html' => <<<'HTML'
<p>The <strong>MCP (Model Context Protocol) integration</strong> allows AI assistants like Claude, Cursor, and GitHub Copilot to search your SharePoint catalog programmatically. Once configured, you can ask "Search the SharePoint catalog for projects containing 'encore'" or "Find all projects with PDF files" directly from your AI assistant.</p>

<p><strong>What it provides:</strong></p>
<ul>
<li>Full-text search across projects, files, folders, and people</li>
<li>Advanced filtering with operators: <code>tag:name</code>, <code>ext:pdf</code>, <code>person:"name"</code>, <code>"exact phrase"</code>, <code>-exclude</code></li>
<li>Project details with complete file structures, sizes, and metadata</li>
<li>Source management across multiple catalogs</li>
<li>Tag search and catalog statistics</li>
<li>User-bound tokens with read/write scopes</li>
<li>Gateway support for on-premise/enterprise deployments</li>
</ul>

<p><strong>Setup (administrator only):</strong></p>
<ol>
<li><strong>Create a token</strong> — Open <a href="admin/mcp.php">Admin → MCP / AI</a> and click <strong>Create Token</strong></li>
<li><strong>Name your token</strong> — Use a descriptive name like "Cursor IDE", "GitHub Copilot", or "Claude Desktop"</li>
<li><strong>Set expiration</strong> — Choose 90 days (recommended), or never expires</li>
<li><strong>Copy the token</strong> — You'll see it only once! It starts with <code>ramcp_</code></li>
<li><strong>Configure AI assistant</strong> — Add to your MCP settings (see examples below)</li>
<li><strong>Start using</strong> — Ask your AI assistant to search the catalog!</li>
</ol>

<p><strong>Cursor configuration example:</strong></p>
<p>Add this to your Cursor MCP settings. Prefer the <strong>global</strong> file <code>~/.cursor/mcp.json</code> (Windows: <code>%USERPROFILE%\.cursor\mcp.json</code>), or a project file <code>.cursor/mcp.json</code> (do not commit tokens):</p>
<pre><code>{
  "mcpServers": {
    "risk-register": {
      "url": "http://localhost/riskregister/api/mcp",
      "headers": {
        "Authorization": "Bearer ramcp_YOUR_TOKEN_HERE"
      }
    }
  }
}</code></pre>
<p>Replace <code>ramcp_YOUR_TOKEN_HERE</code> with the token from Admin → MCP / AI. Adjust the URL if your install path differs. After saving, reload MCP servers in Cursor (Settings → MCP).</p>

<p><strong>GitHub Copilot configuration:</strong></p>
<p>For GitHub Copilot in VS Code, add to settings (<code>.vscode/settings.json</code>):</p>
<pre><code>{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "http://localhost/riskregister/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_YOUR_TOKEN_HERE"
      }
    }
  }
}</code></pre>

<p><strong>On-premise / Enterprise setup with Gateway:</strong></p>
<p>For deployments behind a corporate network, use a gateway (ngrok, Cloudflare Tunnel, or corporate proxy) to expose the MCP endpoint securely:</p>
<ul>
<li><strong>Quick testing</strong> — Use ngrok: <code>ngrok http 80</code>, then use the HTTPS URL in your configuration</li>
<li><strong>Production</strong> — Use Cloudflare Tunnel or corporate reverse proxy with SSL</li>
<li><strong>Security</strong> — Enable IP allowlisting, rate limiting, and HTTPS-only access</li>
<li><strong>Complete guide</strong> — See <code>MCP-GATEWAY-GUIDE.md</code> for detailed enterprise setup instructions</li>
</ul>
<p>GitHub Copilot example with gateway:</p>
<pre><code>{
  "github.copilot.mcp.servers": {
    "risk-register": {
      "url": "https://your-gateway.ngrok.io/api/mcp",
      "auth": {
        "type": "bearer",
        "token": "ramcp_YOUR_TOKEN_HERE"
      }
    }
  }
}</code></pre>

<p><strong>Available tools:</strong></p>
<ul>
<li><code>search_sharepoint_catalog</code> — Full-text search with operators</li>
<li><code>list_sharepoint_projects</code> — Browse projects with pagination</li>
<li><code>get_sharepoint_project</code> — Get detailed file structure and metadata</li>
<li><code>list_sharepoint_sources</code> — List all catalog sources</li>
<li><code>get_sharepoint_source</code> — Get source details</li>
<li><code>search_sharepoint_tags</code> — Find organizational tags</li>
<li><code>get_sharepoint_catalog_stats</code> — Overview statistics</li>
</ul>

<p><strong>Token management:</strong></p>
<ul>
<li><strong>View tokens</strong> — See all active and revoked tokens at <a href="admin/mcp.php">Admin → MCP / AI</a></li>
<li><strong>Revoke tokens</strong> — Click Revoke to instantly disable a token</li>
<li><strong>Check usage</strong> — See when each token was last used</li>
<li><strong>Scopes</strong> — Tokens have read scope by default (search and list). Write scope reserved for future features.</li>
<li><strong>User binding</strong> — Each token belongs to a specific user and inherits their permissions</li>
</ul>

<p><strong>Usage examples (what to ask your AI assistant):</strong></p>

<p><em>Basic Search:</em></p>
<ul>
<li>"Search the SharePoint catalog for projects containing 'encore'"</li>
<li>"Find projects with 'architecture' in the name"</li>
<li>"Search for any project or file mentioning 'database migration'"</li>
<li>"Look for folders related to 'risk assessment'"</li>
</ul>

<p><em>Search by File Type:</em></p>
<ul>
<li>"Find all projects that have PDF files"</li>
<li>"Search for projects containing Visio diagrams"</li>
<li>"Show me projects with Excel spreadsheets (XLSX files)"</li>
<li>"Find projects that include PowerPoint presentations"</li>
<li>"Which projects have Word documents?"</li>
</ul>

<p><em>Search by Person:</em></p>
<ul>
<li>"What projects were modified by John Smith?"</li>
<li>"Find all folders created by Jane Doe"</li>
<li>"Show me projects last modified by the architecture team"</li>
<li>"Which projects has Sarah worked on recently?"</li>
</ul>

<p><em>Search by Tags:</em></p>
<ul>
<li>"Find projects tagged as 'priority'"</li>
<li>"Show me all projects with the 'completed' tag"</li>
<li>"Search for projects tagged 'infrastructure' or 'security'"</li>
<li>"What tags are available in the catalog?"</li>
<li>"List the most commonly used tags"</li>
</ul>

<p><em>Advanced Search with Operators:</em></p>
<ul>
<li>"Find projects with 'network' but exclude 'legacy'"</li>
<li>"Search for exact phrase 'data center migration'"</li>
<li>"Find projects with extension:pdf AND tag:priority"</li>
<li>"Show me projects in the path 'drawings' folder"</li>
<li>"Search for person:'Smith, John' AND ext:docx"</li>
</ul>

<p><em>Browse and Explore:</em></p>
<ul>
<li>"List all SharePoint catalog sources available"</li>
<li>"Show me the first 10 projects in the default catalog"</li>
<li>"What's in the Public catalog versus Private catalog?"</li>
<li>"Get detailed information about Project Alpha including all files"</li>
<li>"Show me the folder structure for 'Infrastructure Upgrade' project"</li>
</ul>

<p><em>Statistics and Overview:</em></p>
<ul>
<li>"Give me statistics about the SharePoint catalog"</li>
<li>"How many projects are in each catalog source?"</li>
<li>"When was each catalog last synced?"</li>
<li>"What's the total number of items across all catalogs?"</li>
</ul>

<p><em>Combining Multiple Queries:</em></p>
<ul>
<li>"Search for projects with PDF files, then show me the one modified most recently"</li>
<li>"Find all projects tagged 'active', then list those modified this month"</li>
<li>"Get catalog stats, then search for the largest project"</li>
<li>"List all sources, then search the Public catalog for 'design' projects"</li>
</ul>

<p><em>Project Details:</em></p>
<ul>
<li>"Show me all files in the 'Customer Portal' project"</li>
<li>"What's the folder structure of 'Infrastructure Upgrade'?"</li>
<li>"Get details about 'Q4 Planning' including file sizes and dates"</li>
<li>"List all documents in 'Architecture Review 2026' with their URLs"</li>
</ul>

<p><strong>Search operators reference:</strong></p>
<ul>
<li><code>tag:priority</code> — Filter by tag name</li>
<li><code>ext:pdf</code> — Filter by file extension (pdf, docx, xlsx, pptx, vsdx, etc.)</li>
<li><code>type:visio</code> — Filter by file type</li>
<li><code>person:"Last, First"</code> — Filter by person (Modified By or Created By)</li>
<li><code>path:drawings</code> — Filter by folder path</li>
<li><code>has:pdf</code> — Projects that contain at least one PDF</li>
<li><code>"exact phrase"</code> — Match exact text (use quotes)</li>
<li><code>-exclude</code> — Exclude term from results (minus sign)</li>
<li>Multiple terms — Combine operators: <code>tag:priority ext:pdf person:Smith</code></li>
</ul>

<p><strong>Security notes:</strong></p>
<ul>
<li>Tokens are user-bound and respect user permissions</li>
<li>Read-only by default (no modifications to catalog)</li>
<li>Optional expiration for automatic security</li>
<li>Revoke tokens instantly from admin interface</li>
<li>All token operations are logged in audit trail</li>
<li>Use HTTPS in production environments</li>
</ul>

<p><strong>Troubleshooting:</strong> If tokens are not working, verify the token is active (not revoked or expired) at <a href="admin/mcp.php">Admin → MCP / AI</a>, check your Cursor <code>mcp.json</code> has the correct Bearer token and URL (<code>http://localhost/riskregister/api/mcp</code>), then reload MCP servers in Cursor.</p>
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
<p>From an open project, open <strong>Actions → Share</strong> (project owners only). Create a public read-only link. You can copy the URL again anytime while the link is active.</p>
<ul>
<li>Anyone with the link can open the dashboard without signing in.</li>
<li>They cannot change responses, upload versions, or create new shares.</li>
<li>Revoke the link when it should stop working. Invalid or revoked tokens show “Share link unavailable”.</li>
<li>When SMTP is enabled under Admin → Email, use <strong>Email this link</strong> to send a branded message with the URL (optional note, up to 20 recipients).</li>
</ul>
<p>Tag or label shares in your own notes; treat the URL like a secret. Search engines are asked not to index these pages.</p>
HTML,
                ],
                [
                    'id' => 'share-catalog',
                    'title' => 'Share catalog and owners',
                    'html' => <<<'HTML'
<p>On SharePoint, administrators can create public links under <strong>Share catalog cards</strong> or <strong>Share project owner cards</strong>.</p>
<ul>
<li>Recipients browse and search the selected catalogs (or owners) without signing in — including live search operators where the public card allows them.</li>
<li>They cannot sync, edit folders, or open assessments.</li>
<li>Uncheck any catalog you want to keep private. A tag/label is required so you can tell links apart.</li>
<li>There is a maximum number of active links. You can copy any active link again from the list. Revoke when finished.</li>
<li>With SMTP enabled, each active copyable link has <strong>Email this link</strong> so you can send the public URL in a branded HTML email.</li>
</ul>
<p>Use an assessment share when someone needs the full dashboard; use a catalog or owners share when they only need to find folders or owners. Ticket Dossier has no public link. On the signed-in catalog, project rows also have <strong>QR</strong> so you can scan the SharePoint folder URL on a phone.</p>
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
<p>Open the sign-in page and choose <strong>Auto (LDAP, then local)</strong>, <strong>LDAP directory</strong>, or <strong>Local account</strong> when both are enabled. <strong>Remember me for 30 days</strong> keeps this browser signed in longer. Usernames that contain <code>@localhost</code> skip LDAP.</p>
<ul>
<li><strong>Local accounts</strong> store a password hash. New passwords need 8+ characters, one uppercase letter, one number, and one special character.</li>
<li><strong>Self-registration</strong> (if enabled under Authentication) stays pending until an administrator approves it.</li>
<li><strong>LDAP / Active Directory</strong> checks the password against the directory. Directory passwords are never stored here. Admins can enable auto-create / auto-update / auto-approve for directory sign-ins, and export or import LDAP settings.</li>
<li>Administrators can search LDAP, open live <strong>LDAP details</strong> (status, groups, org fields — never passwords), add a user, or <strong>preview and import a directory group</strong> (all members or a selected subset).</li>
</ul>
<p>The default first admin is <code>admin</code> / <code>admin123</code>. Change that password under Admin → Overview before exposing the app on a network. If that password is forgotten, see <a href="#forgot-superadmin-password">Forgot superadmin password</a>.</p>
HTML,
                ],
                [
                    'id' => 'admin',
                    'title' => 'Administration',
                    'html' => <<<'HTML'
<p>The <strong>Admin</strong> link appears in the top bar for administrators. Open <a href="admin/index.php">Admin overview</a>.</p>
<ul>
<li><strong>Users</strong> — create, approve, disable, promote, reset local passwords, bulk-select and delete, search LDAP, add or refresh a directory user, preview a group and import all or selected members, inspect LDAP details, review the audit log. LDAP details never include passwords.</li>
<li><strong>Authentication</strong> — turn local and LDAP on or off, registration toggle, LDAP auto-create / auto-update / auto-approve, configure directory servers, test the bind, export or import LDAP settings.</li>
<li><strong>Branding</strong> — brand title, subtitle, browser title, hero text (with <code>*accent*</code> preview), logo, logo size, favicon, footer.</li>
<li><strong>Email</strong> — see <a href="#email-smtp">Email (SMTP)</a> for Custom and Office 365 setup, test send, and emailing public links.</li>
<li><strong>SQLite</strong> — integrity check, VACUUM, ANALYZE, snapshots, restore of the main register database. Ticket Dossier uses a separate <code>ticketdetails.sqlite</code> under <code>database/</code> (not this admin page). Treat backup files as secrets if encryption is on.</li>
<li><strong>App updates</strong> — see <a href="#app-updates">App updates</a> for Releases, commits, and zip apply.</li>
<li><strong>SharePoint</strong> — jump to catalog admin (Tenant ID, Client ID, sync, import).</li>
</ul>
<p>Only administrators can open these pages. SharePoint folder management, sync, import, purge, tags, and archive controls are admin-gated as well. If you cannot sign in as superadmin, recover the password from the server with <a href="#forgot-superadmin-password">Forgot superadmin password</a> — there is no web reset form.</p>
HTML,
                ],
                [
                    'id' => 'forgot-superadmin-password',
                    'title' => 'Forgot superadmin password',
                    'html' => <<<'HTML'
<p>The superadmin password cannot be reset from the browser once you are locked out. Use the background CLI script on the server that hosts this app. It is <code>bin/reset_admin_password.php</code> — not a page under <code>public/</code>.</p>
<ol>
<li>Open a terminal on the server (Command Prompt or PowerShell on Windows, SSH on Linux).</li>
<li>Change to the application folder — the directory that contains <code>bin</code> and <code>public</code> (for example <code>C:\xampp\htdocs\RiskRegister</code>).</li>
<li>Run PHP against the script. The one-argument form finds the current superadmin and sets a new password:</li>
</ol>
<p><code>php bin/reset_admin_password.php "YourNewPassword!1"</code></p>
<p>On XAMPP Windows, if <code>php</code> is not on the PATH, use the PHP executable:</p>
<p><code>C:\xampp\php\php.exe bin\reset_admin_password.php "YourNewPassword!1"</code></p>
<p>To target a specific local username instead (creates that administrator if it does not exist):</p>
<p><code>php bin/reset_admin_password.php admin "YourNewPassword!1"</code></p>
<ul>
<li>Quote the password if it contains <code>!</code>, spaces, or other shell special characters.</li>
<li>The new password must be at least 8 characters, with one uppercase letter, one number, and one special character.</li>
<li>The script prints the username to sign in with. Open the sign-in page, choose <strong>Local account</strong> (or Auto), and use that username plus the password you just set.</li>
<li>After you are in, change the password again under <a href="admin/index.php">Admin → Overview</a>.</li>
<li>LDAP accounts are not reset here — change those passwords in Active Directory. This script only updates local accounts.</li>
<li>Do not copy the script into <code>public/</code> or open it in a browser.</li>
</ul>
<p>Print usage any time with <code>php bin/reset_admin_password.php --help</code>.</p>
HTML,
                ],
                [
                    'id' => 'email-smtp',
                    'title' => 'Email (SMTP)',
                    'html' => <<<'HTML'
<p>Administrators open <a href="admin/email.php">Admin → Email</a> to configure outbound SMTP used for test messages and for emailing public share links.</p>
<ul>
<li><strong>Custom SMTP</strong> — enter host, port, encryption (None / SSL / STARTTLS), and From address. Username and password are optional (leave blank for open / internal relays).</li>
<li><strong>Office 365 / Microsoft 365</strong> — fills <code>smtp.office365.com</code>, port <code>587</code>, and STARTTLS (same working path as LinkNest). Enable Authenticated SMTP for the mailbox. Username must be the full mailbox email. With MFA, use an app password. From should match that mailbox (or an allowed send-as address).</li>
<li><strong>Enable outbound email</strong>, save settings (password is encrypted at rest when used), then use <strong>Send test email</strong> to confirm delivery.</li>
<li>Leave the password blank when saving to keep the current secret.</li>
<li>Once enabled, assessment Share and SharePoint catalog/owners share panels show <strong>Email this link</strong>.</li>
</ul>
HTML,
                ],
                [
                    'id' => 'app-updates',
                    'title' => 'App updates',
                    'html' => <<<'HTML'
<p>Administrators open <a href="admin/updates.php">Admin → App updates</a> to check GitHub and apply a newer build. <strong>Git is not required</strong> on the server. The database folder (including Ticket Dossier storage), <code>uploads/</code>, and custom branding stay in place.</p>
<ul>
<li>Save a GitHub personal access token when the badge says token needed (classic <code>repo</code> scope for private repos).</li>
<li><strong>Check for updates</strong> lists newer <strong>GitHub Releases</strong>. If none are ahead, it also lists commits on the track branch (and the current git branch, when this folder is a checkout) after the installed version.</li>
<li><strong>Download and apply</strong> prefers a packaged <code>RiskRegister-*.zip</code> Release asset; otherwise it uses GitHub’s source zipball. PHP <code>curl</code> and <code>zip</code> must be enabled.</li>
<li>Installed version comes from <code>VERSION.json</code>. Keep the tab open until apply finishes and redirects — do not treat a garbled download as a failed page.</li>
<li>Admins also see a header <strong>bell</strong> when a newer build is available. The bell menu can turn <strong>toast</strong> and <strong>browser</strong> notifications on or off (browser alerts need localhost or HTTPS).</li>
</ul>
<p>Publishers package with <code>php bin/package_release.php vX.Y.Z</code> (or the GitHub Actions release workflow) so the zip includes <code>vendor/</code>. See the in-app notes on the App updates page for troubleshooting.</p>
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
