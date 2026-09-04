document.addEventListener('DOMContentLoaded', () => {
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
            if (willOpen) {
                setEditOpen(id, true);
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
    });

    const form = document.getElementById('template-upload-form');
    if (!(form instanceof HTMLFormElement) || form.dataset.disabled === '1') {
        return;
    }

    const nameInput = document.getElementById('template-name');
    const promptText = document.getElementById('ai-prompt-text');
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
