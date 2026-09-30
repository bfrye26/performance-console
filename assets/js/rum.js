(function () {
    'use strict';
    if (!window.performance || !window.wpiRum || !window.webVitals || Math.random() >= Number(wpiRum.rate || 0)) { return; }

    var metrics = {};
    var token = null;
    var sent = false;
    var pending = false;
    var wantsSend = false;
    var generation = 0;

    function getToken() {
        if (pending || sent) { return; }
        pending = true;
        var startedGeneration = generation;
        fetch(wpiRum.tokenEndpoint + '?route_group=' + encodeURIComponent(wpiRum.route_group), {
            credentials: 'omit', cache: 'no-store', keepalive: true, headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('RUM token unavailable'); }
            return response.json();
        }).then(function (data) {
            if (startedGeneration !== generation) { return; }
            pending = false;
            token = data;
            if (wantsSend) { send(); }
        }).catch(function () { if (startedGeneration === generation) { pending = false; } });
    }

    function collect(metric) {
        var name = metric.name.toLowerCase();
        if (['ttfb', 'fcp', 'lcp', 'cls', 'inp'].indexOf(name) !== -1 && Number.isFinite(metric.value)) {
            metrics[name] = metric.value;
        }
    }

    // Keep the vendor's lifecycle, interaction and session-window algorithms intact.
    ['onTTFB', 'onFCP', 'onLCP', 'onCLS', 'onINP'].forEach(function (name) {
        webVitals[name](collect, { reportAllChanges: true });
    });

    function send() {
        if (sent || !Object.keys(metrics).length) { return; }
        wantsSend = true;
        if (!token || Number(token.expires) * 1000 <= Date.now()) { getToken(); return; }
        try {
            var body = JSON.stringify(Object.assign({}, token, metrics, { metric_version: 2 }));
            var queued = navigator.sendBeacon && navigator.sendBeacon(wpiRum.endpoint, new Blob([body], { type: 'application/json' }));
            if (!queued) {
                fetch(wpiRum.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' }).catch(function () {});
            }
            sent = true;
        } catch (error) {}
    }

    getToken();
    setInterval(function () {
        if (!sent && document.visibilityState === 'visible') { getToken(); }
    }, 8 * 60 * 1000);
    // Batch after the vendor's synchronous hidden-event handlers flush observers.
    addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { Promise.resolve().then(send); }
        else if (!sent && (!token || Number(token.expires) * 1000 <= Date.now())) { getToken(); }
    });
    addEventListener('pagehide', function () { Promise.resolve().then(send); });
    addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        generation++;
        metrics = {}; token = null; sent = false; pending = false; wantsSend = false;
        getToken();
    });
}());
