(function () {
    'use strict';

    var dialog = document.getElementById('dossier-field-editor');
    if (!dialog) return;

    var form = dialog.querySelector('form');
    var label = document.getElementById('field-editor-label');
    var pathInput = document.getElementById('field-editor-path');
    var valueInput = document.getElementById('field-editor-value');
    var cancel = document.getElementById('field-editor-cancel');

    function closeEditor() {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        document.documentElement.classList.remove('field-editor-open');
        document.body.classList.remove('field-editor-open');
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.field-edit-pencil[data-field-path]');
        if (!button) return;

        event.preventDefault();
        label.textContent = button.getAttribute('data-field-label') || 'Dossier field';
        pathInput.value = button.getAttribute('data-field-path') || '';
        valueInput.value = button.getAttribute('data-field-value') || '';
        valueInput.rows = button.getAttribute('data-field-multiline') === '1' ? 10 : 3;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        document.documentElement.classList.add('field-editor-open');
        document.body.classList.add('field-editor-open');
        window.setTimeout(function () {
            valueInput.focus();
            valueInput.setSelectionRange(valueInput.value.length, valueInput.value.length);
        }, 0);
    });

    cancel.addEventListener('click', closeEditor);
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) closeEditor();
    });
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
        closeEditor();
    });
    dialog.addEventListener('close', function () {
        document.documentElement.classList.remove('field-editor-open');
        document.body.classList.remove('field-editor-open');
    });
    form.addEventListener('submit', function () {
        form.querySelector('button[type="submit"]').disabled = true;
    });
})();
