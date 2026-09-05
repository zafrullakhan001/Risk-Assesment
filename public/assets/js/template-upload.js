document.addEventListener('DOMContentLoaded', () => {
    let mermaidApi = null;
    let mermaidReady = false;
    let mermaidLoading = null;

    const DIRECTIONS = ['TB', 'TD', 'BT', 'LR', 'RL'];

    const applyMermaidOptions = (source, layout, direction) => {
        let code = (source || '').trim();
        if (code === '') {
            return '';
        }

        const useElk = String(layout || '').toLowerCase() === 'elk';
        let dir = String(direction || 'TB').toUpperCase();
        if (!DIRECTIONS.includes(dir)) {
            dir = 'TB';
        }

        code = code.replace(/^---\s*\nconfig:\s*\n(?:[ \t].*\n)*---\s*\n?/u, '');
        code = code.replace(/^%%\{init:[\s\S]*?\}%%\s*/u, '');
        code = code.trim();

        const keyword = useElk ? 'flowchart-elk' : 'flowchart';
        if (/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im.test(code)) {
            code = code.replace(/^(flowchart(?:-elk)?|graph)\s+(TB|TD|BT|LR|RL)\b/im, `${keyword} ${dir}`);
        } else if (/^(flowchart(?:-elk)?|graph)\b/im.test(code)) {
            code = code.replace(/^(flowchart(?:-elk)?|graph)\b/im, `${keyword} ${dir}`);
        } else {
            code = `${keyword} ${dir}\n${code}`;
        }

        if (useElk) {
            code = `---\nconfig:\n  layout: elk\n---\n${code}`;
        }

        return code;
    };

    const ensureMermaid = async () => {
        if (mermaidReady && mermaidApi) {
            return true;
        }
        if (mermaidLoading) {
            return mermaidLoading;
        }

        mermaidLoading = (async () => {
            const [{ default: mermaid }, elkModule] = await Promise.all([
                import('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs'),
                import('https://cdn.jsdelivr.net/npm/@mermaid-js/layout-elk@0/dist/mermaid-layout-elk.esm.min.mjs'),
            ]);
            const elkLayouts = elkModule.default || elkModule;
            if (typeof mermaid.registerLayoutLoaders === 'function' && elkLayouts) {
                mermaid.registerLayoutLoaders(elkLayouts);
            }
            mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default',
                htmlLabels: true,
                markdownAutoWrap: true,
                flowchart: {
                    htmlLabels: true,
                    curve: 'linear',
                    wrappingWidth: 200,
                },
            });
            mermaidApi = mermaid;
            window.mermaid = mermaid;
            mermaidReady = true;
            return true;
        })().catch((error) => {
            mermaidLoading = null;
            console.error('Unable to load Mermaid/ELK', error);
            return false;
        });

        return mermaidLoading;
    };

    const getOptionValue = (root, kind) => {
        const input = root?.querySelector(`[data-mermaid-${kind}]`);
        if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
            return input.value;
        }
        const active = root?.querySelector(`[data-mermaid-${kind}-btn].is-active`);
        if (active instanceof HTMLElement) {
            return active.getAttribute(`data-mermaid-${kind}-btn`) || '';
        }
        return kind === 'layout' ? 'default' : 'TB';
    };

    const setOptionValue = (root, kind, value) => {
        if (!(root instanceof HTMLElement)) {
            return;
        }
        const input = root.querySelector(`[data-mermaid-${kind}]`);
        if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
            input.value = value;
        }
        root.querySelectorAll(`[data-mermaid-${kind}-btn]`).forEach((button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }
            button.classList.toggle('is-active', button.getAttribute(`data-mermaid-${kind}-btn`) === value);
        });
    };

    const renderGuideMermaid = async (panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        const hosts = panel.querySelectorAll('.template-guide-mermaid-host');
        if (hosts.length === 0) {
            return;
        }
        const ready = await ensureMermaid();
        if (!ready || !mermaidApi) {
            return;
        }

        const nodes = [];
        hosts.forEach((host) => {
            if (!(host instanceof HTMLElement)) {
                return;
            }
            const section = host.closest('.template-guide-section-mermaid, .template-mermaid-fs-shell')
                || panel;
            const options = section.querySelector('[data-mermaid-options]');
            const base = host.getAttribute('data-mermaid-base-source')
                || host.getAttribute('data-mermaid-source')
                || '';
            const layout = getOptionValue(options, 'layout');
            const direction = getOptionValue(options, 'direction');
            const source = applyMermaidOptions(base, layout, direction);
            host.setAttribute('data-mermaid-source', source);

            let node = host.querySelector('.template-guide-mermaid-diagram');
            if (!(node instanceof HTMLElement)) {
                node = document.createElement('pre');
                node.className = 'mermaid template-guide-mermaid-diagram';
                host.replaceChildren(node);
            }
            node.textContent = source;
            node.removeAttribute('data-processed');
            nodes.push(node);
        });

        try {
            await mermaidApi.run({ nodes });
            hosts.forEach((host) => {
                if (host instanceof HTMLElement && typeof window.TemplateMermaidViewer?.schedulePatchMermaidLabels === 'function') {
                    window.TemplateMermaidViewer.schedulePatchMermaidLabels(host);
                }
            });
        } catch (_error) {
            // Keep source visible if Mermaid syntax fails.
        }
    };

    const openMermaidLive = (source) => {
        const state = {
            code: (source || '').trim(),
            mermaid: { theme: document.documentElement.dataset.theme === 'indigo' ? 'dark' : 'default' },
            updateEditor: true,
            autoSync: true,
            updateDiagram: true,
        };
        const encoded = btoa(unescape(encodeURIComponent(JSON.stringify(state))));
        window.open(`https://mermaid.live/edit#base64:${encoded}`, '_blank', 'noopener,noreferrer');
    };

    const getMermaidSourceFromContext = (element) => {
        if (!(element instanceof HTMLElement)) {
            return '';
        }
        const section = element.closest('.template-guide-section-mermaid');
        const host = section?.querySelector('.template-guide-mermaid-host');
        return (host instanceof HTMLElement ? (host.getAttribute('data-mermaid-source') || '') : '').trim();
    };

    const syncMermaidCodePanels = (source) => {
        const text = (source || '').trim();
        document.querySelectorAll('[data-mermaid-code-pre]').forEach((pre) => {
            if (pre instanceof HTMLElement) {
                pre.textContent = text;
            }
        });
    };

    const copyMermaidCode = async (button) => {
        const source = getMermaidSourceFromContext(button);
        if (source === '') {
            return;
        }
        const label = button.textContent || 'Copy code';
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(source);
            } else {
                const area = document.createElement('textarea');
                area.value = source;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.left = '-9999px';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                document.body.removeChild(area);
            }
            button.textContent = '✅ Copied';
            window.setTimeout(() => {
                button.textContent = label;
            }, 1400);
        } catch (_error) {
            button.textContent = 'Copy failed';
            window.setTimeout(() => {
                button.textContent = label;
            }, 1400);
        }
    };

    const toggleMermaidCodePanel = (button) => {
        const source = getMermaidSourceFromContext(button);
        syncMermaidCodePanels(source);

        const section = button.closest('.template-guide-section-mermaid');
        const panel = section?.querySelector('[data-mermaid-code-panel]');
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        const willShow = panel.hidden;
        panel.hidden = !willShow;
        button.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        button.textContent = willShow ? '🙈 Hide code' : '📄 View code';
        const pre = panel.querySelector('[data-mermaid-code-pre]');
        if (pre instanceof HTMLElement) {
            pre.textContent = source;
        }
    };

    const bindMermaidOptionControls = (root = document) => {
        root.querySelectorAll('[data-mermaid-options]').forEach((options) => {
            if (!(options instanceof HTMLElement) || options.dataset.bound === '1') {
                return;
            }
            options.dataset.bound = '1';

            const syncFromControls = () => {
                const layout = getOptionValue(options, 'layout');
                const direction = getOptionValue(options, 'direction');

                const fieldset = options.closest('fieldset, .template-guide-section-mermaid, form');
                const textarea = fieldset?.querySelector('textarea[data-mermaid-source-field], textarea[name="mermaid_source"]');
                if (textarea instanceof HTMLTextAreaElement) {
                    textarea.value = applyMermaidOptions(textarea.value, layout, direction);
                }

                const host = fieldset?.querySelector('.template-guide-mermaid-host');
                if (host instanceof HTMLElement) {
                    const dialog = host.closest('dialog.template-guide-dialog');
                    if (dialog instanceof HTMLElement) {
                        renderGuideMermaid(dialog).then(() => {
                            const section = host.closest('.template-guide-section-mermaid');
                            const pre = section?.querySelector('[data-mermaid-code-pre]');
                            if (pre instanceof HTMLElement) {
                                pre.textContent = host.getAttribute('data-mermaid-source') || '';
                            }
                        });
                    }
                }
            };

            options.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }
                const layoutBtn = target.closest('[data-mermaid-layout-btn]');
                const directionBtn = target.closest('[data-mermaid-direction-btn]');
                if (layoutBtn instanceof HTMLElement) {
                    event.preventDefault();
                    setOptionValue(options, 'layout', layoutBtn.getAttribute('data-mermaid-layout-btn') || 'default');
                    syncFromControls();
                }
                if (directionBtn instanceof HTMLElement) {
                    event.preventDefault();
                    setOptionValue(options, 'direction', directionBtn.getAttribute('data-mermaid-direction-btn') || 'TB');
                    syncFromControls();
                }
            });
        });
    };

    const setEditOpen = (id, open) => {
        const panel = document.getElementById(`template-edit-${id}`);
        const button = document.querySelector(`[data-template-edit-open="${id}"]`);
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        panel.hidden = !open;
        if (button instanceof HTMLButtonElement) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (open) {
            const nameInput = panel.querySelector('input[name="template_name"]');
            if (nameInput instanceof HTMLInputElement) {
                nameInput.focus();
                nameInput.select();
            }
        }
    };

    const closeAllGuideDialogs = () => {
        document.querySelectorAll('dialog.template-guide-dialog[open]').forEach((dialog) => {
            if (dialog instanceof HTMLDialogElement) {
                dialog.close();
            }
        });
        document.querySelectorAll('[data-template-guide-open]').forEach((other) => {
            if (other instanceof HTMLButtonElement) {
                other.setAttribute('aria-expanded', 'false');
            }
        });
    };

    const setGuideOpen = (id, open) => {
        const dialog = document.getElementById(`template-guide-${id}`);
        const button = document.querySelector(`[data-template-guide-open="${id}"]`);
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }

        if (open) {
            closeAllGuideDialogs();
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
            if (button instanceof HTMLButtonElement) {
                button.setAttribute('aria-expanded', 'true');
            }
            renderGuideMermaid(dialog);
            const closeBtn = dialog.querySelector('[data-template-guide-close]');
            if (closeBtn instanceof HTMLButtonElement) {
                closeBtn.focus();
            }
            return;
        }

        if (dialog.open) {
            dialog.close();
        }
        if (button instanceof HTMLButtonElement) {
            button.setAttribute('aria-expanded', 'false');
        }
    };

    bindMermaidOptionControls();

    document.querySelectorAll('[data-template-mermaid-fullscreen]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const section = button.closest('.template-guide-section-mermaid');
            if (!(section instanceof HTMLElement)) {
                return;
            }
            const host = section.querySelector('.template-guide-mermaid-host');
            const options = section.querySelector('[data-mermaid-options]');
            const titleEl = section.querySelector('.template-guide-section-head strong');
            const base = (host instanceof HTMLElement
                ? (host.getAttribute('data-mermaid-base-source') || host.getAttribute('data-mermaid-source') || '')
                : '');
            const layout = getOptionValue(options, 'layout');
            const direction = getOptionValue(options, 'direction');
            const source = applyMermaidOptions(base, layout, direction);
            const title = titleEl?.textContent?.replace(/^📐\s*/, '').trim() || 'Mermaid diagram';
            if (typeof window.TemplateMermaidViewer?.openFullscreen === 'function') {
                window.TemplateMermaidViewer.openFullscreen(source, { title });
            }
        });
    });

    document.querySelectorAll('[data-template-edit-open]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-template-edit-open') || '';
            const panel = document.getElementById(`template-edit-${id}`);
            const willOpen = panel instanceof HTMLElement ? panel.hidden : true;
            document.querySelectorAll('.template-edit-row').forEach((row) => {
                if (row instanceof HTMLElement) {
                    row.hidden = true;
                }
            });
            document.querySelectorAll('[data-template-edit-open]').forEach((other) => {
                if (other instanceof HTMLButtonElement) {
                    other.setAttribute('aria-expanded', 'false');
                }
            });
            closeAllGuideDialogs();
            if (willOpen) {
                setEditOpen(id, true);
            }
        });
    });

    document.querySelectorAll('[data-template-guide-open]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-template-guide-open') || '';
            const dialog = document.getElementById(`template-guide-${id}`);
            const isOpen = dialog instanceof HTMLDialogElement && dialog.open;
            if (isOpen) {
                setGuideOpen(id, false);
                return;
            }
            setEditOpen(id, false);
            setGuideOpen(id, true);
        });
    });

    document.querySelectorAll('[data-template-guide-close]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-template-guide-close') || '';
            setGuideOpen(id, false);
        });
    });

    document.querySelectorAll('[data-template-mermaid-view-code]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            toggleMermaidCodePanel(button);
        });
    });

    document.querySelectorAll('[data-template-mermaid-copy-code]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            copyMermaidCode(button);
        });
    });

    document.querySelectorAll('[data-template-mermaid-live]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const section = button.closest('.template-guide-section-mermaid');
            const host = section?.querySelector('.template-guide-mermaid-host');
            const source = (host instanceof HTMLElement
                ? host.getAttribute('data-mermaid-source')
                : '') || '';
            openMermaidLive(source);
        });
    });

    document.querySelectorAll('dialog.template-guide-dialog').forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', () => {
            const id = (dialog.id || '').replace(/^template-guide-/, '');
            const button = document.querySelector(`[data-template-guide-open="${id}"]`);
            if (button instanceof HTMLButtonElement) {
                button.setAttribute('aria-expanded', 'false');
            }
        });
    });

    document.querySelectorAll('[data-template-edit-cancel]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            const id = button.getAttribute('data-template-edit-cancel') || '';
            const form = document.querySelector(`[data-template-edit-form="${id}"]`);
            if (form instanceof HTMLFormElement) {
                form.reset();
            }
            setEditOpen(id, false);
        });
    });

    document.querySelectorAll('[data-template-edit-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const clearBox = form.querySelector('input[name="clear_prompt"]');
        const promptFile = form.querySelector('input[name="ai_prompt_file"]');
        const promptText = form.querySelector('textarea[name="ai_prompt_text"]');
        const syncClear = () => {
            if (!(clearBox instanceof HTMLInputElement)) {
                return;
            }
            const hasReplacement = (
                (promptFile instanceof HTMLInputElement && (promptFile.files?.length || 0) > 0)
                || (promptText instanceof HTMLTextAreaElement && promptText.value.trim() !== '')
            );
            if (hasReplacement) {
                clearBox.checked = false;
            }
        };
        if (promptFile instanceof HTMLInputElement) {
            promptFile.addEventListener('change', syncClear);
        }
        if (promptText instanceof HTMLTextAreaElement) {
            promptText.addEventListener('input', syncClear);
        }

        const clearMermaid = form.querySelector('input[name="clear_mermaid"]');
        const mermaidTitle = form.querySelector('input[name="mermaid_title"]');
        const mermaidSource = form.querySelector('textarea[name="mermaid_source"]');
        const syncMermaidClear = () => {
            if (!(clearMermaid instanceof HTMLInputElement)) {
                return;
            }
            const hasMermaidEdit = (
                (mermaidTitle instanceof HTMLInputElement && mermaidTitle.value.trim() !== '')
                || (mermaidSource instanceof HTMLTextAreaElement && mermaidSource.value.trim() !== '')
            );
            if (hasMermaidEdit && (
                (mermaidTitle instanceof HTMLInputElement && mermaidTitle.defaultValue !== mermaidTitle.value)
                || (mermaidSource instanceof HTMLTextAreaElement && mermaidSource.defaultValue !== mermaidSource.value)
            )) {
                // Keep clear available; only auto-uncheck when typing over an empty clear intent.
            }
            if (clearMermaid.checked && hasMermaidEdit) {
                const titleChanged = mermaidTitle instanceof HTMLInputElement
                    && mermaidTitle.value.trim() !== ''
                    && mermaidTitle.value !== mermaidTitle.defaultValue;
                const sourceChanged = mermaidSource instanceof HTMLTextAreaElement
                    && mermaidSource.value.trim() !== ''
                    && mermaidSource.value !== mermaidSource.defaultValue;
                if (titleChanged || sourceChanged) {
                    clearMermaid.checked = false;
                }
            }
        };
        if (mermaidTitle instanceof HTMLInputElement) {
            mermaidTitle.addEventListener('input', syncMermaidClear);
        }
        if (mermaidSource instanceof HTMLTextAreaElement) {
            mermaidSource.addEventListener('input', syncMermaidClear);
        }
    });

    const bindMultiImageList = (inputId, listId, statusId, maxFiles = 5) => {
        const input = document.getElementById(inputId);
        const list = document.getElementById(listId);
        const status = document.getElementById(statusId);
        if (!(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) {
            return;
        }

        const formatSize = (bytes) => {
            if (bytes < 1024) {
                return `${bytes} B`;
            }
            if (bytes < 1024 * 1024) {
                return `${(bytes / 1024).toFixed(1)} KB`;
            }
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        };

        const setStatus = (message) => {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            if (!message) {
                status.hidden = true;
                status.textContent = '';
                return;
            }
            status.hidden = false;
            status.textContent = message;
        };

        const renderList = () => {
            const files = Array.from(input.files || []);
            list.replaceChildren();
            list.hidden = files.length === 0;
            files.forEach((file) => {
                const item = document.createElement('li');
                item.className = 'file-drop-item';
                const label = document.createElement('span');
                label.textContent = file.name;
                const size = document.createElement('small');
                size.textContent = formatSize(file.size);
                label.appendChild(size);
                item.appendChild(label);
                list.appendChild(item);
            });
        };

        input.addEventListener('change', () => {
            const files = Array.from(input.files || []);
            const allowed = files.filter((file) => /\.(jpe?g|png)$/i.test(file.name || ''));
            if (files.length > allowed.length) {
                setStatus('Only JPG and PNG images are supported.');
            } else if (allowed.length > maxFiles) {
                setStatus(`Choose up to ${maxFiles} images.`);
            } else {
                setStatus(allowed.length > 0 ? `${allowed.length} image${allowed.length === 1 ? '' : 's'} selected.` : '');
            }
            renderList();
        });
    };

    bindMultiImageList('template-images', 'template-images-list', 'template-images-status', 5);

    const form = document.getElementById('template-upload-form');
    if (!(form instanceof HTMLFormElement) || form.dataset.disabled === '1') {
        return;
    }

    const nameInput = document.getElementById('template-name');
    let autoSubmitTimer = null;

    const scheduleAutoUpload = () => {
        window.clearTimeout(autoSubmitTimer);
        autoSubmitTimer = window.setTimeout(() => {
            const workbook = document.getElementById('workbook-file');
            if (!(workbook instanceof HTMLInputElement) || !workbook.files || workbook.files.length === 0) {
                return;
            }
            if (nameInput instanceof HTMLInputElement && nameInput.value.trim() === '') {
                return;
            }
            form.requestSubmit();
        }, 700);
    };

    const formatSize = (bytes) => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        if (bytes < 1024 * 1024) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const isFileDrag = (event) => {
        const types = event.dataTransfer?.types;
        if (!types) {
            return false;
        }
        return Array.from(types).includes('Files');
    };

    const bindDrop = ({
        dropId,
        zoneId,
        inputId,
        listId,
        statusId,
        accept,
        onFile,
    }) => {
        const drop = document.getElementById(dropId);
        const zone = document.getElementById(zoneId);
        const input = document.getElementById(inputId);
        const list = document.getElementById(listId);
        const status = document.getElementById(statusId);

        if (
            !(drop instanceof HTMLElement)
            || !(zone instanceof HTMLElement)
            || !(input instanceof HTMLInputElement)
            || !(list instanceof HTMLElement)
        ) {
            return;
        }

        let syncing = false;

        const setStatus = (message) => {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            if (!message) {
                status.hidden = true;
                status.textContent = '';
                return;
            }
            status.hidden = false;
            status.textContent = message;
        };

        const assignFile = (file) => {
            if (!file || typeof DataTransfer === 'undefined') {
                return false;
            }
            const transfer = new DataTransfer();
            transfer.items.add(file);
            syncing = true;
            input.files = transfer.files;
            syncing = false;
            return true;
        };

        const renderList = () => {
            const file = input.files?.[0] || null;
            list.replaceChildren();
            list.hidden = !file;
            if (!file) {
                return;
            }

            const item = document.createElement('li');
            item.className = 'file-drop-item';

            const label = document.createElement('span');
            label.textContent = file.name;
            const size = document.createElement('small');
            size.textContent = formatSize(file.size);
            label.appendChild(size);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'file-drop-remove';
            remove.setAttribute('aria-label', `Remove ${file.name}`);
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                input.value = '';
                setStatus('');
                renderList();
            });

            item.appendChild(label);
            item.appendChild(remove);
            list.appendChild(item);
        };

        const matchesAccept = (file) => {
            const name = (file.name || '').toLowerCase();
            return accept.some((ext) => name.endsWith(ext));
        };

        const takeFile = (fileList, { fromDrop = false } = {}) => {
            const incoming = Array.from(fileList || []);
            if (incoming.length === 0) {
                return;
            }

            const match = incoming.find(matchesAccept);
            if (!match) {
                setStatus(`Only ${accept.join(', ')} files are supported.`);
                return;
            }

            if (incoming.length > 1) {
                setStatus(`Using ${match.name}. Extra files were ignored.`);
            } else {
                setStatus('');
            }

            if (!assignFile(match) && fromDrop) {
                setStatus('Your browser could not attach the dropped file. Use Browse instead.');
                return;
            }

            if (typeof onFile === 'function') {
                onFile(match);
            }
            renderList();
        };

        const setDragover = (active) => {
            drop.classList.toggle('is-dragover', active);
        };

        zone.addEventListener('dragenter', (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            event.preventDefault();
            setDragover(true);
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        }, true);

        zone.addEventListener('dragover', (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            event.preventDefault();
            setDragover(true);
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        }, true);

        zone.addEventListener('dragleave', (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            const next = event.relatedTarget;
            if (next instanceof Node && zone.contains(next)) {
                return;
            }
            setDragover(false);
        }, true);

        zone.addEventListener('drop', (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            setDragover(false);
            takeFile(event.dataTransfer?.files, { fromDrop: true });
        }, true);

        input.addEventListener('change', () => {
            if (syncing) {
                return;
            }
            takeFile(input.files, { fromDrop: false });
        });

        renderList();
    };

    ['dragover', 'drop'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            event.preventDefault();
        });
    });

    bindDrop({
        dropId: 'workbook-drop',
        zoneId: 'workbook-drop-zone',
        inputId: 'workbook-file',
        listId: 'workbook-drop-list',
        statusId: 'workbook-drop-status',
        accept: ['.xlsx'],
        onFile: (file) => {
            if (!(nameInput instanceof HTMLInputElement)) {
                return;
            }
            if (nameInput.value.trim() === '') {
                const base = (file.name || '').replace(/\.xlsx$/i, '').trim();
                if (base !== '') {
                    nameInput.value = base;
                }
            }
            scheduleAutoUpload();
        },
    });

    bindDrop({
        dropId: 'prompt-drop',
        zoneId: 'prompt-drop-zone',
        inputId: 'ai-prompt-file',
        listId: 'prompt-drop-list',
        statusId: 'prompt-drop-status',
        accept: ['.txt', '.md', '.prompt'],
        onFile: () => {
            const workbook = document.getElementById('workbook-file');
            if (workbook instanceof HTMLInputElement && workbook.files && workbook.files.length > 0) {
                scheduleAutoUpload();
            }
        },
    });

    form.addEventListener('submit', (event) => {
        const workbook = document.getElementById('workbook-file');
        if (!(workbook instanceof HTMLInputElement) || !workbook.files || workbook.files.length === 0) {
            event.preventDefault();
            const status = document.getElementById('workbook-drop-status');
            if (status instanceof HTMLElement) {
                status.hidden = false;
                status.textContent = 'Choose or drop an .xlsx workbook.';
            }
        }
    });
});
