(function () {
    'use strict';
    var config = window.wpiBackupAdmin || {};
    var form = document.getElementById('wpi-backup-create-form');
    var statusBox = document.getElementById('wpi-backup-progress');
    if (!form || !config.restRoot || !config.nonce) { return; }

    function request(path, body) {
        return fetch(config.restRoot.replace(/\/$/, '') + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || (json && json.code)) {
                    var message = json && json.message ? json.message : 'Database backup request failed.';
                    throw new Error(message);
                }
                return json;
            });
        });
    }

    function bytes(value) {
        var n = Number(value || 0);
        if (!isFinite(n) || n <= 0) { return '0 B'; }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
        return (n / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + units[i];
    }

    function show(text, type) {
        if (!statusBox) { return; }
        statusBox.hidden = false;
        statusBox.className = 'notice inline ' + (type === 'error' ? 'notice-error' : type === 'success' ? 'notice-success' : 'notice-info');
        statusBox.innerHTML = '<p>' + text + '</p>';
    }

    function renderProgress(data) {
        var current = data.current_table ? ' Current table: <code>' + escapeHtml(data.current_table) + '</code>' : '';
        if (current && data.table_progress !== null && typeof data.table_progress !== 'undefined') {
            current += ' (' + Number(data.table_progress || 0).toFixed(1) + '% est.)';
        }
        if (current) { current += '.'; }
        var speed = '';
        if (Number(data.last_step_rows || 0) > 0 && Number(data.last_step_ms || 0) > 0) {
            speed = ' Last step: ' + Number(data.last_step_rows).toLocaleString() + ' rows in ' + (Number(data.last_step_ms) / 1000).toFixed(1) + 's';
            if (Number(data.rows_per_second || 0) > 0) { speed += ' (' + Math.round(Number(data.rows_per_second)).toLocaleString() + ' rows/s'; }
            if (Number(data.bytes_per_second || 0) > 0) { speed += ', ' + bytes(data.bytes_per_second) + '/s'; }
            if (Number(data.rows_per_second || 0) > 0) { speed += ')'; }
            speed += '.';
        }
        var batch = Number(data.batch_rows || 0) > 0 ? ' Adaptive batch: ' + Number(data.batch_rows).toLocaleString() + ' rows.' : '';
        show('<strong>Backup #' + Number(data.id) + ':</strong> ~' + Number(data.progress || 0).toFixed(1) + '% complete, ' + Number(data.tables_done || 0) + '/' + Number(data.table_count || 0) + ' tables, ' + Number(data.row_count || 0).toLocaleString() + ' rows, ' + bytes(data.size_bytes) + '.' + current + speed + batch, 'info');
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"]/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[char];
        });
    }

    function run(id) {
        return request('/backups/' + Number(id) + '/step', {}).then(function (data) {
            renderProgress(data);
            if (data.status === 'running') {
                return new Promise(function (resolve) { window.setTimeout(resolve, 5); }).then(function () { return run(id); });
            }
            if (data.status === 'ready_verify') {
                show('<strong>Export complete.</strong> Verifying completion marker and calculating SHA-256 checksum…', 'info');
                return request('/backups/' + Number(id) + '/verify', {}).then(function (verified) {
                    show('<strong>Backup verified.</strong> ' + bytes(verified.size_bytes) + ', SHA-256 ' + escapeHtml(verified.sha256) + '. Reloading backup list…', 'success');
                    window.setTimeout(function () { window.location.reload(); }, 700);
                    return verified;
                });
            }
            if (data.status === 'verified') {
                show('<strong>Backup is verified.</strong> Reloading backup list…', 'success');
                window.setTimeout(function () { window.location.reload(); }, 500);
            }
            return data;
        }).catch(function (error) {
            show('<strong>Backup stopped:</strong> ' + escapeHtml(error.message), 'error');
            var button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = false; }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; }
        var scope = form.querySelector('[name="backup_scope"]');
        show('<strong>Starting database backup…</strong> The browser can remain on this page while WPI exports the database using adaptive high-throughput batches.', 'info');
        request('/backups', {scope: scope ? scope.value : 'wordpress'}).then(function (data) {
            renderProgress(data);
            return run(data.id);
        }).catch(function (error) {
            show('<strong>Backup could not start:</strong> ' + escapeHtml(error.message), 'error');
            if (button) { button.disabled = false; }
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-wpi-resume-backup]'), function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            run(button.getAttribute('data-wpi-resume-backup'));
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-wpi-verify-backup]'), function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            var id = Number(button.getAttribute('data-wpi-verify-backup'));
            show('<strong>Verifying backup #' + id + '…</strong>', 'info');
            request('/backups/' + id + '/verify', {}).then(function () {
                show('<strong>Backup verified.</strong> Reloading backup list…', 'success');
                window.setTimeout(function () { window.location.reload(); }, 500);
            }).catch(function (error) {
                show('<strong>Verification failed:</strong> ' + escapeHtml(error.message), 'error');
                button.disabled = false;
            });
        });
    });
}());
