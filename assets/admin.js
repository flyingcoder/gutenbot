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

(function ($) {
    'use strict';

    var cfg = window.gutenbotIndex;
    if (!cfg || !cfg.runId) return;

    var wrap  = document.getElementById('gutenbot-index-progress-wrap');
    var bar   = document.getElementById('gutenbot-index-bar');
    var label = document.getElementById('gutenbot-index-progress-label');
    if (!wrap || !bar || !label) return;

    wrap.style.display = '';

    var failures = 0;

    function poll() {
        $.ajax({
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   { action: 'gutenbot_index_progress', nonce: cfg.nonce },
            success: function (res) {
                failures = 0;
                if (!res || !res.success) {
                    setTimeout(poll, 3000);
                    return;
                }
                var d = res.data;
                bar.value = d.pct;
                label.textContent = d.done + ' / ' + d.total + ' indexed (' + d.pct + '%)'
                    + (d.failed ? ' — ' + d.failed + ' failed' : '');
                if (d.running) {
                    setTimeout(poll, 2000);
                } else {
                    label.textContent = 'Indexing complete. ' + d.done + ' of ' + d.total + ' items processed.'
                        + (d.failed ? ' ' + d.failed + ' failed.' : '');
                    setTimeout(function () { location.reload(); }, 2000);
                }
            },
            error: function () {
                failures++;
                var delay = Math.min(failures * 3000, 15000);
                label.textContent = 'Server unreachable, retrying… (attempt ' + failures + ')';
                setTimeout(poll, delay);
            }
        });
    }

    poll();
}(jQuery));
