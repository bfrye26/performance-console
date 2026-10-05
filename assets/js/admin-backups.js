(function () {
    'use strict';
    var config = window.pfcBackupAdmin || {};
    var form = document.getElementById('pfc-backup-create-form');
    var statusBox = document.getElementById('pfc-backup-progress');
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
            // A proxy, WAF or host error page is the most common production
            // failure here, and it is not JSON. Reading the body as JSON first
            // produced "Unexpected token '<'" instead of an actionable message.
            var type = response.headers.get('content-type') || '';
            if (type.indexOf('application/json') === -1) {
                throw new Error('The backup endpoint returned HTTP ' + response.status + ' with a non-JSON response. A security proxy, page cache or host error page may be intercepting REST requests.');
            }
            return response.text().then(function (text) {
                var json = null;
                try { json = text ? JSON.parse(text) : null; } catch (parseError) { json = null; }
                if (json === null) { throw new Error('The backup endpoint returned an empty or malformed response (HTTP ' + response.status + ').'); }
                if (!response.ok || json.code) {
                    throw new Error(json.message || 'Database backup request failed.');
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

    var MAX_CONSECUTIVE_STEPS = 20000;

    function enableControls() {
        Array.prototype.forEach.call(
            document.querySelectorAll('#pfc-backup-create-form button[type="submit"], [data-pfc-resume-backup], [data-pfc-verify-backup]'),
            function (control) { control.disabled = false; }
        );
    }

    function run(id, step) {
        step = Number(step || 0);
        if (step > MAX_CONSECUTIVE_STEPS) {
            // Stop rather than loop forever against an endpoint that never
            // advances. The export is resumable, so say so instead of hanging.
            show('<strong>Backup paused.</strong> The export did not reach a terminal state within ' + MAX_CONSECUTIVE_STEPS + ' steps. It is resumable: reload this page and use Resume.', 'error');
            enableControls();
            return Promise.resolve(null);
        }
        return request('/backups/' + Number(id) + '/step', {}).then(function (data) {
            renderProgress(data);
            if (data.status === 'running') {
                return new Promise(function (resolve) { window.setTimeout(resolve, 5); }).then(function () { return run(id, step + 1); });
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
                return data;
            }
            if (data.status === 'failed') {
                // Previously this fell through every branch, leaving the progress
                // box frozen and the button disabled with no way to retry.
                show('<strong>Backup failed:</strong> ' + escapeHtml(data.error || 'The export stopped without reporting a reason.') + ' The partial export is resumable.', 'error');
                enableControls();
                return data;
            }
            show('<strong>Unexpected backup state:</strong> ' + escapeHtml(data.status || 'unknown') + '. Reload this page to see the current state.', 'error');
            enableControls();
            return data;
        }).catch(function (error) {
            show('<strong>Backup stopped:</strong> ' + escapeHtml(error.message), 'error');
            enableControls();
            return null;
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (form.getAttribute('data-pfc-busy') === '1') { return; }
        form.setAttribute('data-pfc-busy', '1');
        var button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; }
        var scope = form.querySelector('[name="backup_scope"]');
        show('<strong>Starting database backup…</strong> The browser can remain on this page while Performance Console exports the database using adaptive high-throughput batches.', 'info');
        request('/backups', {scope: scope ? scope.value : 'wordpress'}).then(function (data) {
            renderProgress(data);
            return run(data.id);
        }).catch(function (error) {
            show('<strong>Backup could not start:</strong> ' + escapeHtml(error.message), 'error');
            if (button) { button.disabled = false; }
        }).then(function () { form.removeAttribute('data-pfc-busy'); });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-pfc-resume-backup]'), function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            run(button.getAttribute('data-pfc-resume-backup'), 0).then(function () {
                if (button.disabled) { button.disabled = false; }
            });
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-pfc-verify-backup]'), function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            var id = Number(button.getAttribute('data-pfc-verify-backup'));
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
