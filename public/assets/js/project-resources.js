document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('project-resources');
    if (!root) {
        return;
    }

    const assessmentId = Number(document.body.dataset.assessmentId || '0');
    const csrfToken = document.body.dataset.csrfToken || '';
    const isReadOnly = document.body.dataset.readonly === '1' || root.dataset.readonly === '1';
    const maxLinks = Number.parseInt(root.dataset.maxLinks || '10', 10);
    const maxDiagrams = Number.parseInt(root.dataset.maxDiagrams || '10', 10);
    const maxPictures = Number.parseInt(root.dataset.maxPictures || '10', 10);

    if (isReadOnly) {
        // View-only: keep preview / open link / picture viewer; skip all save handlers below by early return after wiring view actions.
    }

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

    const picturesList = document.getElementById('project-pictures-list');
    const picturesEmpty = document.getElementById('project-pictures-empty');
    const picturesCount = document.getElementById('project-pictures-count');
    const picturesSaveStatus = document.getElementById('pictures-save-status');
    const btnPictureBrowse = document.getElementById('btn-picture-browse');
    const btnPicturesSave = document.getElementById('btn-pictures-save');
    const pictureFileInput = document.getElementById('project-picture-file');
    const pictureDropzone = document.getElementById('project-picture-dropzone');
    const pictureCardTemplate = document.getElementById('project-picture-card-template');
    const pictureFileListWrap = document.getElementById('project-picture-filelist-wrap');
    const pictureFileList = document.getElementById('project-picture-filelist');
    const pictureFileRowTemplate = document.getElementById('project-picture-file-row-template');
    const pictureViewer = document.getElementById('project-picture-viewer');
    const pictureViewerTitle = document.getElementById('project-picture-viewer-title');
    const pictureViewerFilename = document.getElementById('project-picture-viewer-filename');
    const pictureViewerImage = document.getElementById('project-picture-viewer-image');
    const pictureViewerClose = document.getElementById('project-picture-viewer-close');
    const picturesSection = document.getElementById('project-pictures');
    const pictureViewSwitcher = document.getElementById('project-picture-view-switcher');
    const pictureViewStorageKey = `project-picture-view:${assessmentId || 'draft'}`;
    let pictureView = 'files';

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

    const countPictureCards = () => picturesList?.querySelectorAll('.project-picture-card').length || 0;

    const applyPictureView = () => {
        const count = countPictureCards();
        const showFiles = pictureView === 'files' && count > 0;
        const showCards = pictureView === 'cards' && count > 0;
        if (picturesSection) {
            picturesSection.dataset.pictureView = pictureView;
        }
        if (pictureFileListWrap) {
            pictureFileListWrap.hidden = !showFiles;
            pictureFileListWrap.setAttribute('aria-hidden', showFiles ? 'false' : 'true');
        }
        if (picturesList) {
            picturesList.hidden = !showCards;
            picturesList.setAttribute('aria-hidden', showCards ? 'false' : 'true');
        }
        if (picturesEmpty) {
            picturesEmpty.hidden = count > 0;
        }
        if (btnPicturesSave) {
            btnPicturesSave.hidden = pictureView !== 'cards' || assessmentId <= 0;
        }
        pictureViewSwitcher?.querySelectorAll('[data-picture-view]').forEach((button) => {
            const active = button.dataset.pictureView === pictureView;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    };

    const setPictureView = (view) => {
        pictureView = view === 'cards' ? 'cards' : 'files';
        try {
            window.sessionStorage.setItem(pictureViewStorageKey, pictureView);
        } catch (error) {
            // Ignore storage failures and keep the in-memory view.
        }
        applyPictureView();
    };

    const updatePicturesUi = () => {
        const count = countPictureCards();
        if (picturesCount) {
            picturesCount.textContent = `${count}/${maxPictures}`;
        }
        const atLimit = count >= maxPictures || assessmentId <= 0;
        if (btnPictureBrowse) {
            btnPictureBrowse.disabled = atLimit;
        }
        if (pictureFileInput) {
            pictureFileInput.disabled = atLimit;
        }
        if (pictureDropzone) {
            pictureDropzone.classList.toggle('is-disabled', atLimit);
            pictureDropzone.setAttribute('aria-disabled', atLimit ? 'true' : 'false');
        }
        syncPictureFileList();
        applyPictureView();
    };

    const pictureDisplayName = (card) => {
        const filename = (card.dataset.filename || card.querySelector('.project-picture-filename')?.textContent || '').trim();
        const title = card.querySelector('.project-picture-title')?.value.trim() || '';
        return filename || title || 'Picture';
    };

    const openPictureViewer = (source) => {
        const viewUrl = source?.dataset.viewUrl || '';
        if (!viewUrl || !pictureViewer || !pictureViewerImage) {
            return;
        }
        const filename = (source.dataset.filename || source.querySelector('.project-picture-file-name')?.textContent || '').trim();
        const title = (source.querySelector('.project-picture-title')?.value || source.querySelector('.project-picture-file-title')?.textContent || '').trim()
            || filename
            || 'Picture';
        if (pictureViewerTitle) {
            pictureViewerTitle.textContent = title;
        }
        if (pictureViewerFilename) {
            pictureViewerFilename.textContent = filename && filename !== title ? filename : (filename || '');
            pictureViewerFilename.hidden = pictureViewerFilename.textContent === '';
        }
        pictureViewerImage.alt = filename || title;
        pictureViewerImage.src = viewUrl;
        if (typeof pictureViewer.showModal === 'function') {
            pictureViewer.showModal();
        } else {
            pictureViewer.setAttribute('open', '');
        }
    };

    const bindPictureFileRow = (row) => {
        row.querySelector('.project-picture-file-open')?.addEventListener('click', () => openPictureViewer(row));
    };

    const syncPictureFileList = () => {
        if (!pictureFileList || !pictureFileRowTemplate) {
            return;
        }
        const cards = picturesList ? Array.from(picturesList.querySelectorAll('.project-picture-card')) : [];
        pictureFileList.querySelectorAll('.project-picture-file-row').forEach((row) => row.remove());
        cards.forEach((card) => {
            const fragment = pictureFileRowTemplate.content.cloneNode(true);
            const row = fragment.querySelector('.project-picture-file-row');
            if (!row) {
                return;
            }
            const viewUrl = card.dataset.viewUrl || '';
            const filename = pictureDisplayName(card);
            const title = card.querySelector('.project-picture-title')?.value.trim() || '';
            const format = card.querySelector('.project-picture-format')?.textContent.trim() || 'PNG';
            row.dataset.pictureId = card.dataset.pictureId || '';
            row.dataset.viewUrl = viewUrl;
            row.dataset.filename = filename;
            const nameNode = row.querySelector('.project-picture-file-name');
            if (nameNode) {
                nameNode.textContent = filename;
            }
            const formatNode = row.querySelector('.project-picture-format');
            if (formatNode) {
                formatNode.textContent = format;
            }
            let titleNode = row.querySelector('.project-picture-file-title');
            if (title && title !== filename) {
                if (!titleNode) {
                    titleNode = document.createElement('span');
                    titleNode.className = 'project-picture-file-title';
                    row.querySelector('.project-picture-file-open')?.appendChild(titleNode);
                }
                titleNode.textContent = title;
                titleNode.hidden = false;
            } else if (titleNode) {
                titleNode.remove();
            }
            const openBtn = row.querySelector('.project-picture-file-open');
            if (viewUrl) {
                openBtn?.removeAttribute('disabled');
            } else {
                openBtn?.setAttribute('disabled', '');
            }
            pictureFileList.appendChild(fragment);
            bindPictureFileRow(pictureFileList.lastElementChild);
        });
    };

    const closePictureViewer = () => {
        if (!pictureViewer) {
            return;
        }
        if (typeof pictureViewer.close === 'function' && pictureViewer.open) {
            pictureViewer.close();
        } else {
            pictureViewer.removeAttribute('open');
        }
        if (pictureViewerImage) {
            pictureViewerImage.removeAttribute('src');
        }
    };

    const fillPictureCard = (card, picture, viewUrl) => {
        card.dataset.pictureId = String(picture.id || '');
        card.dataset.viewUrl = viewUrl;
        const titleField = card.querySelector('.project-picture-title');
        if (titleField && picture.title) {
            titleField.value = picture.title;
        }
        const format = card.querySelector('.project-picture-format');
        if (format) {
            format.textContent = picture.mime_type === 'image/jpeg' ? 'JPG' : 'PNG';
        }
        if (picture.original_filename) {
            card.dataset.filename = picture.original_filename;
        }
        const filename = card.querySelector('.project-picture-filename');
        if (filename && picture.original_filename) {
            filename.textContent = picture.original_filename;
            filename.title = `View ${picture.original_filename}`;
            filename.hidden = false;
        }
        const thumbBtn = card.querySelector('.project-picture-thumb-btn');
        const viewBtn = card.querySelector('.project-picture-view');
        if (viewUrl) {
            thumbBtn?.removeAttribute('disabled');
            viewBtn?.removeAttribute('disabled');
            let thumb = card.querySelector('.project-picture-thumb');
            if (!thumb || thumb.tagName !== 'IMG') {
                const img = document.createElement('img');
                img.className = 'project-picture-thumb';
                img.alt = '';
                img.loading = 'lazy';
                thumb?.replaceWith(img);
                if (!thumb && thumbBtn) {
                    thumbBtn.replaceChildren(img);
                }
                thumb = img;
            }
            thumb.src = viewUrl;
        }
    };

    const bindPictureCard = (card) => {
        card.querySelector('.project-picture-view')?.addEventListener('click', () => openPictureViewer(card));
        card.querySelector('.project-picture-thumb-btn')?.addEventListener('click', () => openPictureViewer(card));
        card.querySelector('.project-picture-filename-open')?.addEventListener('click', () => openPictureViewer(card));
        card.querySelector('.project-picture-remove')?.addEventListener('click', async () => {
            const pictureId = Number.parseInt(card.dataset.pictureId || '0', 10);
            if (!pictureId || assessmentId <= 0) {
                card.remove();
                updatePicturesUi();
                return;
            }
            if (!window.confirm('Delete this picture from the assessment?')) {
                return;
            }
            try {
                const body = new URLSearchParams({
                    action: 'delete_project_picture',
                    csrf_token: csrfToken,
                    assessment_id: String(assessmentId),
                    picture_id: String(pictureId),
                });
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body,
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Delete failed');
                }
                card.remove();
                updatePicturesUi();
                setStatus(picturesSaveStatus, 'Picture deleted');
                hideStatusLater(picturesSaveStatus);
            } catch (error) {
                setStatus(picturesSaveStatus, error.message || 'Delete failed', true);
            }
        });
    };

    const addPictureCard = (picture, viewUrl) => {
        if (!picturesList || !pictureCardTemplate) {
            return;
        }
        const fragment = pictureCardTemplate.content.cloneNode(true);
        const card = fragment.querySelector('.project-picture-card');
        if (!card) {
            return;
        }
        fillPictureCard(card, picture, viewUrl);
        picturesList.appendChild(fragment);
        const added = picturesList.lastElementChild;
        bindPictureCard(added);
        updatePicturesUi();
        return added;
    };

    const uploadPictureFile = async (file) => {
        if (!assessmentId || assessmentId <= 0) {
            setStatus(picturesSaveStatus, 'Save requires a stored assessment', true);
            return;
        }
        if (countPictureCards() >= maxPictures) {
            setStatus(picturesSaveStatus, `A project can have at most ${maxPictures} pictures.`, true);
            return;
        }
        const looksLikeImage = (file.type && file.type.startsWith('image/'))
            || /\.(jpe?g|png|gif|webp|bmp|avif)$/i.test(file.name || '');
        if (!file || !looksLikeImage || /svg|icon/i.test(file.type || '')) {
            setStatus(picturesSaveStatus, 'Only picture files can be uploaded.', true);
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            setStatus(picturesSaveStatus, `${file.name} is larger than 2 MB.`, true);
            return;
        }

        const body = new FormData();
        body.append('action', 'upload_project_picture');
        body.append('csrf_token', csrfToken);
        body.append('assessment_id', String(assessmentId));
        body.append('title', file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim());
        body.append('picture', file);

        setStatus(picturesSaveStatus, `Uploading ${file.name}…`);
        const response = await fetch('index.php', {
            method: 'POST',
            body,
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || `Unable to upload ${file.name}`);
        }
        addPictureCard(payload.picture, payload.view_url);
    };

    const handlePictureFiles = async (fileList) => {
        const files = Array.from(fileList || []).filter((file) => {
            const type = file.type || '';
            const name = file.name || '';
            if (/svg|icon/i.test(type) || /\.svg$/i.test(name)) {
                return false;
            }
            return type.startsWith('image/') || /\.(jpe?g|png|gif|webp|bmp|avif)$/i.test(name);
        });
        if (files.length === 0) {
            setStatus(picturesSaveStatus, 'Drop JPG, PNG, or another picture file.', true);
            return;
        }

        const remaining = Math.max(0, maxPictures - countPictureCards());
        if (remaining <= 0) {
            setStatus(picturesSaveStatus, `A project can have at most ${maxPictures} pictures.`, true);
            return;
        }

        const toUpload = files.slice(0, remaining);
        if (files.length > remaining) {
            setStatus(picturesSaveStatus, `Only ${remaining} more picture${remaining === 1 ? '' : 's'} can be added.`, true);
        }

        if (btnPictureBrowse) {
            btnPictureBrowse.disabled = true;
        }
        try {
            for (const file of toUpload) {
                await uploadPictureFile(file);
            }
            setStatus(picturesSaveStatus, toUpload.length === 1 ? 'Picture saved' : `${toUpload.length} pictures saved`);
            hideStatusLater(picturesSaveStatus);
        } catch (error) {
            setStatus(picturesSaveStatus, error.message || 'Upload failed', true);
        } finally {
            updatePicturesUi();
            if (pictureFileInput) {
                pictureFileInput.value = '';
            }
        }
    };

    const savePictureTitles = async () => {
        if (!assessmentId || assessmentId <= 0) {
            setStatus(picturesSaveStatus, 'Save requires a stored assessment', true);
            return;
        }

        const pictures = [];
        picturesList?.querySelectorAll('.project-picture-card').forEach((card) => {
            const id = Number.parseInt(card.dataset.pictureId || '0', 10);
            if (id <= 0) {
                return;
            }
            pictures.push({
                id,
                title: card.querySelector('.project-picture-title')?.value.trim() || '',
            });
        });

        if (btnPicturesSave) {
            btnPicturesSave.disabled = true;
        }
        setStatus(picturesSaveStatus, 'Saving titles…');

        try {
            const body = new URLSearchParams({
                action: 'save_project_picture_titles',
                csrf_token: csrfToken,
                assessment_id: String(assessmentId),
                pictures: JSON.stringify(pictures),
            });
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Save failed');
            }
            setStatus(picturesSaveStatus, 'Titles saved');
            hideStatusLater(picturesSaveStatus);
            syncPictureFileList();
        } catch (error) {
            setStatus(picturesSaveStatus, error.message || 'Save failed', true);
        } finally {
            if (btnPicturesSave) {
                btnPicturesSave.disabled = false;
            }
        }
    };

    diagramsList?.querySelectorAll('.mermaid-diagram-row').forEach((row) => bindDiagramRow(row));
    updateDiagramsUi();

    linksList?.querySelectorAll('.project-link-row').forEach((row) => bindLinkRow(row));
    updateLinksUi();

    picturesList?.querySelectorAll('.project-picture-card').forEach((card) => bindPictureCard(card));
    try {
        const storedView = window.sessionStorage.getItem(pictureViewStorageKey);
        if (storedView === 'files' || storedView === 'cards') {
            pictureView = storedView;
        }
    } catch (error) {
        pictureView = 'files';
    }
    updatePicturesUi();
    pictureViewSwitcher?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-picture-view]');
        if (!button) {
            return;
        }
        setPictureView(button.dataset.pictureView || 'files');
    });

    btnDiagramAdd?.addEventListener('click', addDiagramRow);
    btnDiagramsSave?.addEventListener('click', saveDiagrams);
    btnLinkAdd?.addEventListener('click', addLinkRow);
    btnLinksSave?.addEventListener('click', saveLinks);
    btnPictureBrowse?.addEventListener('click', () => pictureFileInput?.click());
    btnPicturesSave?.addEventListener('click', savePictureTitles);
    pictureFileInput?.addEventListener('change', () => handlePictureFiles(pictureFileInput.files));

    pictureDropzone?.addEventListener('click', () => {
        if (!pictureDropzone.classList.contains('is-disabled')) {
            pictureFileInput?.click();
        }
    });
    pictureDropzone?.addEventListener('keydown', (event) => {
        if ((event.key === 'Enter' || event.key === ' ') && !pictureDropzone.classList.contains('is-disabled')) {
            event.preventDefault();
            pictureFileInput?.click();
        }
    });
    ['dragenter', 'dragover'].forEach((eventName) => {
        pictureDropzone?.addEventListener(eventName, (event) => {
            event.preventDefault();
            if (!pictureDropzone.classList.contains('is-disabled')) {
                pictureDropzone.classList.add('is-dragover');
            }
        });
    });
    ['dragleave', 'dragend'].forEach((eventName) => {
        pictureDropzone?.addEventListener(eventName, () => {
            pictureDropzone.classList.remove('is-dragover');
        });
    });
    pictureDropzone?.addEventListener('drop', (event) => {
        event.preventDefault();
        pictureDropzone.classList.remove('is-dragover');
        if (pictureDropzone.classList.contains('is-disabled')) {
            return;
        }
        handlePictureFiles(event.dataTransfer?.files);
    });

    pictureViewerClose?.addEventListener('click', closePictureViewer);
    pictureViewer?.addEventListener('click', (event) => {
        if (event.target === pictureViewer) {
            closePictureViewer();
        }
    });
    pictureViewer?.addEventListener('close', () => {
        if (pictureViewerImage) {
            pictureViewerImage.removeAttribute('src');
        }
    });

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
