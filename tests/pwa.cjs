const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/manifest.webmanifest')));
assert.equal(manifest.start_url, '/login');
assert.equal(manifest.display, 'standalone');
for (const icon of manifest.icons) {
    const png = fs.readFileSync(path.join(root, 'public', icon.src));
    assert.equal(png.subarray(1, 4).toString(), 'PNG');
    assert.equal(`${png.readUInt32BE(16)}x${png.readUInt32BE(20)}`, icon.sizes);
}
const handlers = {};
const cached = [];
const network = [];
let offline = false;
const context = {
    URL, Promise,
    self: {location: {origin: 'https://erp.example'}, clients: {claim: async () => {}}, addEventListener: (type, fn) => handlers[type] = fn},
    caches: {open: async () => ({addAll: async urls => cached.push(...urls)}), keys: async () => [], match: async request => ({offline: true, path: request}), delete: async () => true},
    fetch: async request => { network.push(request.url); if (offline) throw new Error('offline'); return {network: true}; },
};
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/sw.js'), 'utf8'), context);
(async () => {
    let install;
    handlers.install({waitUntil: promise => install = promise});
    await install;
    assert(!cached.includes('/login') && !cached.includes('/app'));
    for (const file of cached) assert(fs.existsSync(path.join(root, 'public', file)));
    const fetchEvent = (url, mode = 'navigate', method = 'GET') => {
        let response;
        handlers.fetch({request: {url, mode, method}, respondWith: promise => response = promise});
        return response;
    };
    assert.equal((await fetchEvent('https://erp.example/app')).network, true);
    assert.equal(fetchEvent('https://erp.example/sesion/dispositivo/estado', 'cors'), undefined);
    assert.equal(fetchEvent('https://erp.example/login', 'cors', 'POST'), undefined);
    assert.equal(fetchEvent('https://erp.example/productos/privado.pdf', 'cors'), undefined);
    assert.equal(fetchEvent('https://other.example/app'), undefined);
    offline = true;
    assert.equal((await fetchEvent('https://erp.example/app')).path, '/offline.html');
    assert(!cached.includes('/app'));
    console.log('PWA: manifest, PNG dimensions, public cache allowlist, online/private/POST/cross-origin bypass and offline fallback OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
