(() => {
  const dialog = document.getElementById('sp-source-delete-dialog');
  const form = document.getElementById('sp-source-delete-form');
  const keyInput = document.getElementById('sp-source-delete-key');
  const confirmInput = document.getElementById('sp-source-delete-confirm');
  const submitBtn = document.getElementById('sp-source-delete-submit');
  if (!(dialog instanceof HTMLDialogElement) || !form || !keyInput || !confirmInput || !submitBtn) {
    return;
  }

  const nameEl = dialog.querySelector('[data-delete-name]');
  const countEl = dialog.querySelector('[data-delete-count]');
  const phraseEl = dialog.querySelector('[data-delete-phrase]');
  const cancelBtn = dialog.querySelector('[data-delete-cancel]');
  let expected = '';

  const syncSubmit = () => {
    submitBtn.disabled = confirmInput.value.trim() !== expected;
  };

  const closeDialog = () => {
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
    }
    confirmInput.value = '';
    expected = '';
    submitBtn.disabled = true;
  };

  const openDialog = (button) => {
    expected = (button.getAttribute('data-title') || '').trim();
    keyInput.value = button.getAttribute('data-source-key') || '';
    confirmInput.value = '';
    if (nameEl) nameEl.textContent = expected;
    if (phraseEl) phraseEl.textContent = expected;
    if (countEl) countEl.textContent = button.getAttribute('data-item-count') || '0';
    submitBtn.disabled = true;
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
    confirmInput.focus();
  };

  document.querySelectorAll('.sharepoint-source-delete-btn').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openDialog(button);
    });
  });

  confirmInput.addEventListener('input', syncSubmit);
  confirmInput.addEventListener('paste', () => {
    window.setTimeout(syncSubmit, 0);
  });

  cancelBtn?.addEventListener('click', (event) => {
    event.preventDefault();
    closeDialog();
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      closeDialog();
    }
  });

  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeDialog();
  });

  form.addEventListener('submit', (event) => {
    if (confirmInput.value.trim() !== expected || keyInput.value.trim() === '') {
      event.preventDefault();
      submitBtn.disabled = true;
    }
  });
})();
