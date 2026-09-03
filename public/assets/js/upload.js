document.addEventListener('DOMContentLoaded', () => {
    const drop = document.getElementById('file-drop');
    const zone = document.getElementById('file-drop-zone');
    const input = document.getElementById('assessment-file');
    const list = document.getElementById('file-drop-list');
    const status = document.getElementById('file-drop-status');
    const form = document.getElementById('upload-form');

    if (!(drop instanceof HTMLElement) || !(zone instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !(list instanceof HTMLElement)) {
        return;
    }

    const maxFiles = Math.max(1, Number.parseInt(drop.dataset.maxFiles || '10', 10) || 10);
    let syncing = false;

    const isXlsx = (file) => {
        const name = (file.name || '').toLowerCase();
        return name.endsWith('.xlsx');
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

    const currentFiles = () => Array.from(input.files || []);

    const assignFiles = (files) => {
        if (typeof DataTransfer === 'undefined') {
            return false;
        }
        const transfer = new DataTransfer();
        files.forEach((file) => transfer.items.add(file));
        syncing = true;
        input.files = transfer.files;
        syncing = false;
        return true;
    };

    const renderList = () => {
        const files = currentFiles();
        list.replaceChildren();
        list.hidden = files.length === 0;

        files.forEach((file, index) => {
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
                const next = currentFiles().filter((_, fileIndex) => fileIndex !== index);
                if (!assignFiles(next)) {
                    input.value = '';
                }
                renderList();
            });

            item.appendChild(label);
            item.appendChild(remove);
            list.appendChild(item);
        });
    };

    const addFiles = (fileList, { replace = false } = {}) => {
        const incoming = Array.from(fileList || []);
        if (incoming.length === 0) {
            return;
        }

        const accepted = incoming.filter(isXlsx);
        const skipped = incoming.length - accepted.length;
        const notices = [];

        if (skipped > 0) {
            notices.push(skipped === 1 ? '1 file was skipped. Only .xlsx workbooks are supported.' : `${skipped} files were skipped. Only .xlsx workbooks are supported.`);
        }

        const merged = replace ? [] : currentFiles();
        accepted.forEach((file) => {
            const exists = merged.some((current) => (
                current.name === file.name
                && current.size === file.size
                && current.lastModified === file.lastModified
            ));
            if (!exists) {
                merged.push(file);
            }
        });

        if (merged.length > maxFiles) {
            merged.length = maxFiles;
            notices.push(`You can upload up to ${maxFiles} workbooks at once.`);
        }

        if (replace) {
            if (!assignFiles(merged) && accepted.length === 0) {
                input.value = '';
            }
        } else if (accepted.length > 0 && !assignFiles(merged)) {
            notices.push('Your browser replaced the previous selection. Choose all workbooks together if you need more than one.');
        }

        setStatus(notices.join(' '));
        renderList();
    };

    const isFileDrag = (event) => {
        const types = event.dataTransfer?.types;
        if (!types) {
            return false;
        }
        return Array.from(types).includes('Files');
    };

    ['dragover', 'drop'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            if (!isFileDrag(event)) {
                return;
            }
            event.preventDefault();
        });
    });

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
        addFiles(event.dataTransfer?.files, { replace: false });
    }, true);

    input.addEventListener('change', () => {
        if (syncing) {
            return;
        }
        addFiles(input.files, { replace: true });
    });

    if (form instanceof HTMLFormElement) {
        form.addEventListener('submit', (event) => {
            if (currentFiles().length === 0) {
                event.preventDefault();
                setStatus('Choose or drop at least one .xlsx workbook.');
            }
        });
    }

    renderList();
});
