(function () {
    'use strict';
    if (!window.performance || !window.wpiRum || Math.random() > Number(wpiRum.rate || 0)) { return; }

    var metrics = { route_group: wpiRum.route_group || 'frontend:other' };
    var token = null;
    var sent = false;
    var interactions = {};

    fetch(wpiRum.tokenEndpoint + '?route_group=' + encodeURIComponent(metrics.route_group), {
        credentials: 'omit', cache: 'no-store', headers: { 'Accept': 'application/json' }
    }).then(function (response) {
        if (!response.ok) { throw new Error('RUM token unavailable'); }
        return response.json();
    }).then(function (data) { token = data; }).catch(function () {});

    try {
        var navigation = performance.getEntriesByType('navigation')[0];
        if (navigation) { metrics.ttfb = Math.max(0, navigation.responseStart - navigation.requestStart); }
    } catch (error) {}

    function observe(type, callback, options) {
        try {
            if (!window.PerformanceObserver || (PerformanceObserver.supportedEntryTypes || []).indexOf(type) === -1) { return; }
            var observer = new PerformanceObserver(function (list) { list.getEntries().forEach(callback); });
            observer.observe(options || { type: type, buffered: true });
        } catch (error) {}
    }

    observe('paint', function (entry) { if (entry.name === 'first-contentful-paint') { metrics.fcp = entry.startTime; } });
    observe('largest-contentful-paint', function (entry) { metrics.lcp = entry.startTime; });
    observe('layout-shift', function (entry) { if (!entry.hadRecentInput) { metrics.cls = (metrics.cls || 0) + entry.value; } });
    observe('event', function (entry) {
        if (!entry.interactionId) { return; }
        interactions[entry.interactionId] = (interactions[entry.interactionId] || 0) + (entry.duration || 0);
        metrics.inp = Math.max.apply(Math, Object.keys(interactions).map(function (key) { return interactions[key]; }));
    }, { type: 'event', buffered: true, durationThreshold: 40 });

    function send() {
        if (sent || !token) { return; }
        sent = true;
        try {
            var body = JSON.stringify(Object.assign({}, metrics, token));
            if (navigator.sendBeacon) {
                navigator.sendBeacon(wpiRum.endpoint, new Blob([body], { type: 'application/json' }));
            } else {
                fetch(wpiRum.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' }).catch(function () {});
            }
        } catch (error) {}
    }

    addEventListener('pagehide', send, { once: true });
    addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } });
}());
