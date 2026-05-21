(function ($) {
    'use strict';

    var zone  = document.getElementById('gutenbot-drop-zone');
    var input = document.getElementById('gutenbot-file-input');
    var list  = document.getElementById('gutenbot-file-list');

    if (!zone || !input || !list) return;

    zone.addEventListener('click', function () { input.click(); });

    zone.addEventListener('dragover', function (e) {
        e.preventDefault();
        zone.classList.add('dragover');
    });

    zone.addEventListener('dragleave', function () {
        zone.classList.remove('dragover');
    });

    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('dragover');
        renderFileList(e.dataTransfer.files);
        // Transfer dropped files to the file input (best-effort via DataTransfer API).
        if (typeof DataTransfer !== 'undefined') {
            var dt = new DataTransfer();
            Array.from(e.dataTransfer.files).forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        }
    });

    input.addEventListener('change', function () {
        renderFileList(input.files);
    });

    function renderFileList(files) {
        list.innerHTML = '';
        Array.from(files).forEach(function (f) {
            var li = document.createElement('li');
            li.textContent = f.name + ' (' + formatSize(f.size) + ')';
            list.appendChild(li);
        });
    }

    function formatSize(bytes) {
        if (bytes < 1024)      return bytes + ' B';
        if (bytes < 1048576)   return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
}(jQuery));
