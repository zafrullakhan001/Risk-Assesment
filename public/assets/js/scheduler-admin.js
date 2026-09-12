(function () {
    const root = document.getElementById('scheduler-root');
    if (!root || window.__raSchedulerAdminInit) {
        return;
    }
    window.__raSchedulerAdminInit = true;

    const busy = document.getElementById('scheduler-busy');
    const busyLabel = document.getElementById('scheduler-busy-label');

    const setBusy = (on, label) => {
        if (!busy) {
            return;
        }
        busy.hidden = !on;
        root.classList.toggle('is-busy', !!on);
        if (busyLabel && label) {
            busyLabel.textContent = label;
        }
        root.querySelectorAll('button[type="submit"]').forEach((btn) => {
            if (on) {
                btn.dataset.prevDisabled = btn.disabled ? '1' : '0';
                btn.disabled = true;
            } else if (btn.dataset.prevDisabled === '0') {
                btn.disabled = false;
            }
        });
    };

    root.querySelectorAll('form[method="post"]').forEach((form) => {
        form.addEventListener('submit', () => {
            const label = form.getAttribute('data-busy-label') || 'Working…';
            setBusy(true, label);
        });
    });
})();
