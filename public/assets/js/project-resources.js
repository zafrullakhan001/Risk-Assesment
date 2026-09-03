document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('project-resources');
    if (!root) {
        return;
    }

    const assessmentId = Number(document.body.dataset.assessmentId || '0');
    const csrfToken = document.body.dataset.csrfToken || '';
    const maxLinks = Number.parseInt(root.dataset.maxLinks || '10', 10);
    const maxDiagrams = Number.parseInt(root.dataset.maxDiagrams || '10', 10);

    const mermaidPreview = document.getElementById('mermaid-preview');
    const mermaidPreviewPanel = document.getElementById('mermaid-preview-panel');
    const mermaidPreviewNote = document.getElementById('mermaid-preview-note');
    const mermaidPreviewTitle = document.getElementById('mermaid-preview-title');
    const diagramsList = document.getElementById('mermaid-diagrams-list');
    const diagramsEmpty = document.getElementById('mermaid-diagrams-empty');
    const diagramsCount = document.getElementById('mermaid-diagrams-count');
    const diagramsSaveStatus = document.getElementById('diagrams-save-status');
    const btnDiagramAdd = document.getElementById('btn-diagram-add');
    const btnDiagramsSave = document.getElementById('btn-diagrams-save');
    const diagramRowTemplate = document.getElementById('mermaid-diagram-row-template');

    const linksList = document.getElementById('project-links-list');
    const linksEmpty = document.getElementById('project-links-empty');
    const linksCount = document.getElementById('project-links-count');
    const linksSaveStatus = document.getElementById('links-save-status');
    const btnLinkAdd = document.getElementById('btn-link-add');
    const btnLinksSave = document.getElementById('btn-links-save');
    const linkRowTemplate = document.getElementById('project-link-row-template');

    let previewTimer = null;
    let mermaidReady = false;
    let activeDiagramRow = null;

    const setStatus = (node, message, isError = false) => {
        if (!node) {
            return;
        }
        node.hidden = false;
        node.textContent = message;
        node.classList.toggle('is-error', isError);
    };

    const hideStatusLater = (node, delay = 1800) => {
        if (!node) {
            return;
        }
        window.setTimeout(() => {
            node.hidden = true;
        }, delay);
    };

    const initMermaid = () => {
        if (typeof mermaid === 'undefined' || mermaidReady) {
            return;
        }
        mermaid.initialize({
            startOnLoad: false,
            theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default',
            securityLevel: 'strict',
            flowchart: { htmlLabels: true },
        });
        mermaidReady = true;
    };

    const getRowSource = (row) => (row.querySelector('.mermaid-diagram-source')?.value || '').trim();
    const getRowTitle = (row) => (row.querySelector('.mermaid-diagram-title')?.value || '').trim();

    const renderMermaidPreview = async (row = activeDiagramRow) => {
        if (!mermaidPreview || !mermaidPreviewPanel) {
            return;
        }

        initMermaid();
        const source = row ? getRowSource(row) : '';
        const title = row ? getRowTitle(row) : '';
        const displaySource = source !== '' ? source : 'flowchart LR\n  A[Start] --> B[End]';

        if (mermaidPreviewTitle) {
            mermaidPreviewTitle.textContent = title !== '' ? `— 🗺️ ${title}` : '';
        }

        mermaidPreview.textContent = displaySource;
        mermaidPreview.removeAttribute('data-processed');

        if (mermaidPreviewNote) {
            if (!row) {
                mermaidPreviewNote.textContent = '👆 Select a diagram and click Preview';
            } else if (source === '') {
                mermaidPreviewNote.textContent = '✨ Showing starter diagram';
            } else {
                mermaidPreviewNote.textContent = '';
            }
        }

        if (typeof mermaid === 'undefined') {
            if (mermaidPreviewNote) {
                mermaidPreviewNote.textContent = 'Mermaid library failed to load';
            }
            return;
        }

        try {
            await mermaid.run({ nodes: [mermaidPreview] });
            mermaidPreviewPanel.classList.remove('has-error');
        } catch (error) {
            mermaidPreviewPanel.classList.add('has-error');
            if (mermaidPreviewNote) {
                mermaidPreviewNote.textContent = '⚠️ Syntax error — check diagram source';
            }
        }
    };

    const queuePreview = (row) => {
        activeDiagramRow = row;
        if (previewTimer) {
            window.clearTimeout(previewTimer);
        }
        previewTimer = window.setTimeout(() => {
            renderMermaidPreview(row);
        }, 450);
    };

    const openMermaidLive = (row) => {
        const source = row ? getRowSource(row) : '';
        const state = {
            code: source,
            mermaid: { theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default' },
            updateEditor: true,
            autoSync: true,
            updateDiagram: true,
        };
        const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(state))));
        window.open(`https://mermaid.live/edit#base64:${encoded}`, '_blank', 'noopener,noreferrer');
    };

    const countDiagramRows = () => diagramsList?.querySelectorAll('.mermaid-diagram-row').length || 0;

    const updateDiagramsUi = () => {
        const count = countDiagramRows();
        if (diagramsCount) {
            diagramsCount.textContent = `${count}/${maxDiagrams}`;
        }
        if (btnDiagramAdd) {
            btnDiagramAdd.disabled = count >= maxDiagrams || assessmentId <= 0;
        }
        if (diagramsEmpty) {
            diagramsEmpty.hidden = count > 0;
        }
    };

    const setActiveDiagramRow = (row) => {
        diagramsList?.querySelectorAll('.mermaid-diagram-row').forEach((node) => {
            node.classList.toggle('is-active', node === row);
        });
        activeDiagramRow = row;
    };

    const bindDiagramRow = (row) => {
        const sourceField = row.querySelector('.mermaid-diagram-source');
        sourceField?.addEventListener('input', () => {
            setActiveDiagramRow(row);
            queuePreview(row);
        });
        sourceField?.addEventListener('focus', () => setActiveDiagramRow(row));

        row.querySelector('.mermaid-diagram-preview')?.addEventListener('click', () => {
            setActiveDiagramRow(row);
            renderMermaidPreview(row);
        });

        row.querySelector('.mermaid-diagram-live')?.addEventListener('click', () => {
            openMermaidLive(row);
        });

        row.querySelector('.mermaid-diagram-remove')?.addEventListener('click', () => {
            const wasActive = row === activeDiagramRow;
            row.remove();
            updateDiagramsUi();
            if (wasActive) {
                const next = diagramsList?.querySelector('.mermaid-diagram-row');
                if (next) {
                    setActiveDiagramRow(next);
                    renderMermaidPreview(next);
                } else {
                    activeDiagramRow = null;
                    renderMermaidPreview(null);
                }
            }
        });
    };

    const addDiagramRow = () => {
        if (!diagramsList || !diagramRowTemplate || countDiagramRows() >= maxDiagrams) {
            return;
        }
        const fragment = diagramRowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('.mermaid-diagram-row');
        if (!row) {
            return;
        }
        diagramsList.appendChild(fragment);
        const added = diagramsList.lastElementChild;
        bindDiagramRow(added);
        updateDiagramsUi();
        setActiveDiagramRow(added);
        added?.querySelector('.mermaid-diagram-title')?.focus();
    };

    const collectDiagrams = () => {
        const diagrams = [];
        diagramsList?.querySelectorAll('.mermaid-diagram-row').forEach((row) => {
            const title = getRowTitle(row);
            const source = getRowSource(row);
            if (title === '' && source === '') {
                return;
            }
            diagrams.push({ title, source });
        });
        return diagrams;
    };

    const saveDiagrams = async () => {
        if (!assessmentId || assessmentId <= 0) {
            setStatus(diagramsSaveStatus, 'Save requires a stored assessment', true);
            return;
        }

        const diagrams = collectDiagrams();
        const body = new URLSearchParams({
            action: 'save_project_mermaid',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            diagrams: JSON.stringify(diagrams),
        });

        if (btnDiagramsSave) {
            btnDiagramsSave.disabled = true;
        }
        setStatus(diagramsSaveStatus, 'Saving…');

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Save failed');
            }
            setStatus(diagramsSaveStatus, 'Saved');
            hideStatusLater(diagramsSaveStatus);
        } catch (error) {
            setStatus(diagramsSaveStatus, error.message || 'Save failed', true);
        } finally {
            if (btnDiagramsSave) {
                btnDiagramsSave.disabled = false;
            }
        }
    };

    const countLinkRows = () => linksList?.querySelectorAll('.project-link-row').length || 0;

    const updateLinksUi = () => {
        const count = countLinkRows();
        if (linksCount) {
            linksCount.textContent = `${count}/${maxLinks}`;
        }
        if (btnLinkAdd) {
            btnLinkAdd.disabled = count >= maxLinks || assessmentId <= 0;
        }
        if (linksEmpty) {
            linksEmpty.hidden = count > 0;
        }
    };

    const syncLinkOpenButton = (row) => {
        const urlInput = row.querySelector('.project-link-url');
        const openBtn = row.querySelector('.project-link-open');
        if (!urlInput || !openBtn) {
            return;
        }
        const url = urlInput.value.trim();
        const valid = /^https?:\/\//i.test(url);
        if (valid) {
            openBtn.href = url;
            openBtn.hidden = false;
        } else {
            openBtn.href = '#';
            openBtn.hidden = true;
        }
    };

    const bindLinkRow = (row) => {
        row.querySelectorAll('.project-link-label, .project-link-url').forEach((input) => {
            input.addEventListener('input', () => syncLinkOpenButton(row));
        });
        row.querySelector('.project-link-remove')?.addEventListener('click', () => {
            row.remove();
            updateLinksUi();
        });
        syncLinkOpenButton(row);
    };

    const addLinkRow = () => {
        if (!linksList || !linkRowTemplate || countLinkRows() >= maxLinks) {
            return;
        }
        const fragment = linkRowTemplate.content.cloneNode(true);
        if (!fragment.querySelector('.project-link-row')) {
            return;
        }
        linksList.appendChild(fragment);
        bindLinkRow(linksList.lastElementChild);
        updateLinksUi();
        linksList.lastElementChild?.querySelector('.project-link-label')?.focus();
    };

    const collectLinks = () => {
        const links = [];
        linksList?.querySelectorAll('.project-link-row').forEach((row) => {
            const label = row.querySelector('.project-link-label')?.value.trim() || '';
            const url = row.querySelector('.project-link-url')?.value.trim() || '';
            if (label === '' && url === '') {
                return;
            }
            links.push({ label, url });
        });
        return links;
    };

    const saveLinks = async () => {
        if (!assessmentId || assessmentId <= 0) {
            setStatus(linksSaveStatus, 'Save requires a stored assessment', true);
            return;
        }

        const links = collectLinks();
        const body = new URLSearchParams({
            action: 'save_project_links',
            csrf_token: csrfToken,
            assessment_id: String(assessmentId),
            links: JSON.stringify(links),
        });

        if (btnLinksSave) {
            btnLinksSave.disabled = true;
        }
        setStatus(linksSaveStatus, 'Saving…');

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Save failed');
            }
            setStatus(linksSaveStatus, 'Saved');
            hideStatusLater(linksSaveStatus);
        } catch (error) {
            setStatus(linksSaveStatus, error.message || 'Save failed', true);
        } finally {
            if (btnLinksSave) {
                btnLinksSave.disabled = false;
            }
        }
    };

    diagramsList?.querySelectorAll('.mermaid-diagram-row').forEach((row) => bindDiagramRow(row));
    updateDiagramsUi();

    linksList?.querySelectorAll('.project-link-row').forEach((row) => bindLinkRow(row));
    updateLinksUi();

    btnDiagramAdd?.addEventListener('click', addDiagramRow);
    btnDiagramsSave?.addEventListener('click', saveDiagrams);
    btnLinkAdd?.addEventListener('click', addLinkRow);
    btnLinksSave?.addEventListener('click', saveLinks);

    const projectPanel = document.querySelector('.dash-panel[data-panel="project"]');
    const onPanelVisible = () => {
        const first = diagramsList?.querySelector('.mermaid-diagram-row');
        if (first && !activeDiagramRow) {
            setActiveDiagramRow(first);
            renderMermaidPreview(first);
        } else {
            renderMermaidPreview(activeDiagramRow);
        }
    };

    if (projectPanel) {
        const observer = new MutationObserver(() => {
            if (!projectPanel.hidden) {
                onPanelVisible();
            }
        });
        observer.observe(projectPanel, { attributes: true, attributeFilter: ['hidden'] });
        if (!projectPanel.hidden) {
            onPanelVisible();
        }
    } else {
        onPanelVisible();
    }
});
