(function () {
    const form = document.getElementById('transfer-ownership-form');
    if (!form || window.__raTransferOwnershipInit) {
        return;
    }
    window.__raTransferOwnershipInit = true;

    const list = document.getElementById('transfer-ownership-list');
    const searchInput = document.getElementById('transfer-project-search');
    const emptyEl = document.getElementById('transfer-search-empty');
    const selectAllBtn = document.getElementById('transfer-select-all');
    const selectNoneBtn = document.getElementById('transfer-select-none');
    const modeInputs = Array.from(form.querySelectorAll('[data-transfer-mode]'));
    const checks = () => Array.from(form.querySelectorAll('.transfer-project-check'));
    const items = () => Array.from(form.querySelectorAll('.transfer-ownership-item'));

    const syncMode = () => {
        const mode = (form.querySelector('input[name="transfer_mode"]:checked')?.value) || 'selected';
        const selectedMode = mode === 'selected';
        checks().forEach((input) => {
            input.disabled = !selectedMode;
            if (!selectedMode) {
                input.checked = true;
            }
        });
        if (list) {
            list.classList.toggle('is-all-mode', !selectedMode);
        }
    };

    const applySearch = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        let visible = 0;
        items().forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const match = query === '' || haystack.includes(query);
            item.hidden = !match;
            if (match) {
                visible += 1;
            }
        });
        if (emptyEl) {
            emptyEl.hidden = !(query !== '' && visible === 0);
        }
    };

    modeInputs.forEach((input) => {
        input.addEventListener('change', syncMode);
    });
    selectAllBtn?.addEventListener('click', () => {
        checks().forEach((input) => {
            if (!input.disabled && !input.closest('.transfer-ownership-item')?.hidden) {
                input.checked = true;
            }
        });
    });
    selectNoneBtn?.addEventListener('click', () => {
        checks().forEach((input) => {
            if (!input.disabled) {
                input.checked = false;
            }
        });
    });
    searchInput?.addEventListener('input', applySearch);
    searchInput?.addEventListener('search', applySearch);

    form.addEventListener('submit', (event) => {
        const mode = (form.querySelector('input[name="transfer_mode"]:checked')?.value) || 'selected';
        const selected = checks().filter((input) => input.checked && !input.disabled);
        if (mode === 'selected' && selected.length === 0) {
            event.preventDefault();
            window.alert('Select at least one project, or choose “All of my projects”.');
            return;
        }
        const ownerSelect = form.querySelector('select[name="new_owner_user_id"]');
        const ownerLabel = ownerSelect?.selectedOptions?.[0]?.textContent?.trim() || 'the selected user';
        const countLabel = mode === 'all'
            ? 'ALL of your projects'
            : (selected.length === 1 ? '1 selected project' : selected.length + ' selected projects');
        const ok = window.confirm(
            'Transfer ownership of ' + countLabel + ' to ' + ownerLabel + '?\n\n'
            + 'They become the owner of every saved version for those projects. This cannot be undone by you afterward.'
        );
        if (!ok) {
            event.preventDefault();
        }
    });

    syncMode();
})();
