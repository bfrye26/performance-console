const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/rum.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function setup({ beacon = true, late = false } = {}) {
    const callbacks = {}, events = {}, requests = [], beacons = [];
    let tokenResolve;
    const tokenData = () => ({ nonce: 'nonce', token: 'token', expires: Date.now() / 1000 + 600, route_group: 'frontend:home' });
    const context = {
        performance: {}, pfcRum: { rate: 1, route_group: 'frontend:home', endpoint: '/rum', tokenEndpoint: '/token' },
        webVitals: {}, document: { visibilityState: 'visible' }, Blob, Date, Promise,
        // The token-refresh scheduler uses timers. A real browser always has them;
        // the sandbox needs them present (as no-ops) so the promise chain that
        // assigns the token is not broken by a ReferenceError.
        setTimeout: () => 0, clearTimeout: () => {},
        navigator: { sendBeacon(url, body) { beacons.push(body); return beacon; } },
        fetch(url, options) {
            requests.push({ url, options });
            if (url === '/rum') return Promise.resolve({ ok: true });
            if (late) return new Promise(resolve => { tokenResolve = () => resolve({ ok: true, json: () => Promise.resolve(tokenData()) }); });
            return Promise.resolve({ ok: true, json: () => Promise.resolve(tokenData()) });
        },
        addEventListener(name, callback) { (events[name] ||= []).push(callback); },
        setInterval() {},
    };
    for (const name of ['TTFB', 'FCP', 'LCP', 'CLS', 'INP']) context.webVitals['on' + name] = (cb, options) => {
        assert.equal(options.reportAllChanges, true);
        callbacks[name] = cb;
    };
    context.window = context;
    vm.runInNewContext(source, context);
    return { context, requests, beacons, callbacks, resolveToken: () => tokenResolve(),
        emit(name, data = {}) { for (const cb of events[name] || []) cb(data); },
        metric(name, value) { callbacks[name]({ name, value, entries: [{ secret: 'must not be transmitted' }] }); },
    };
}

test('uses all vendor metrics, sends only safe scalars and versions the payload', async () => {
    const h = setup(); await flush();
    assert.equal(Object.keys(h.callbacks).length, 5);
    h.metric('INP', 120); h.metric('CLS', 0); h.emit('pagehide'); await flush();
    const body = JSON.parse(await h.beacons[0].text());
    assert.equal(body.inp, 120); assert.equal(body.cls, 0); assert.equal(body.metric_version, 2);
    assert.equal(body.entries, undefined); assert.equal(body.lcp, undefined);
    h.emit('pagehide'); await flush(); assert.equal(h.beacons.length, 1);
});

test('falls back to keepalive fetch when sendBeacon declines the payload', async () => {
    const h = setup({ beacon: false }); await flush();
    h.metric('LCP', 2500); h.emit('pagehide'); await flush();
    const request = h.requests.find(r => r.url === '/rum');
    assert.equal(request.options.keepalive, true); assert.equal(request.options.credentials, 'omit');
});

test('a token arriving after hidden still sends the collected metrics', async () => {
    const h = setup({ late: true }); h.metric('FCP', 400); h.emit('pagehide'); await flush();
    assert.equal(h.beacons.length, 0); h.resolveToken(); await flush();
    assert.equal(h.beacons.length, 1);
});

test('bfcache restore starts a new sample without stale metric values', async () => {
    const h = setup(); await flush(); h.metric('INP', 300); h.emit('pagehide'); await flush();
    h.emit('pageshow', { persisted: true }); await flush();
    h.metric('CLS', 0.02); h.emit('pagehide'); await flush();
    const body = JSON.parse(await h.beacons[1].text());
    assert.equal(body.inp, undefined); assert.equal(body.cls, 0.02);
});

test('does not fabricate zero measurements on unsupported browsers', async () => {
    const h = setup(); await flush(); h.emit('pagehide'); await flush();
    assert.equal(h.beacons.length, 0);
});

test('a host without timer functions still sends (token refresh must not break the send path)', async () => {
    // Regression guard: scheduleRefresh() runs inside the token promise chain, so
    // a missing setTimeout/clearTimeout used to throw there, silently dropping the
    // visit's only beacon. Delete the timers to prove the send path survives.
    const h = setup(); await flush();
    delete h.context.setTimeout;
    delete h.context.clearTimeout;
    h.metric('LCP', 1800); h.emit('pagehide'); await flush();
    assert.equal(h.beacons.length, 1);
    const body = JSON.parse(await h.beacons[0].text());
    assert.equal(body.lcp, 1800);
});

test('honours doNotTrack and Global Privacy Control before collecting anything', async () => {
    for (const signal of [{ doNotTrack: '1' }, { globalPrivacyControl: true }]) {
        const callbacks = {}, requests = [], beacons = [];
        const context = {
            performance: {}, pfcRum: { endpoint: '/rum', tokenEndpoint: '/token', route_group: 'frontend:home' },
            webVitals: {}, document: { visibilityState: 'visible' }, Blob, Date, Promise,
            setTimeout: () => 0, clearTimeout: () => {},
            navigator: Object.assign({ sendBeacon(url, body) { beacons.push(body); return true; } }, signal),
            fetch(url) { requests.push(url); return Promise.resolve({ ok: true, json: () => Promise.resolve({}) }); },
            addEventListener() {}, setInterval() {},
        };
        for (const name of ['TTFB', 'FCP', 'LCP', 'CLS', 'INP']) context.webVitals['on' + name] = cb => { callbacks[name] = cb; };
        context.window = context;
        vm.runInNewContext(source, context);
        await flush();
        assert.equal(Object.keys(callbacks).length, 0, 'no observers registered when opted out');
        assert.equal(requests.length, 0, 'no token request when opted out');
        assert.equal(beacons.length, 0);
    }
});
