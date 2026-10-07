const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../src/widgets/assets/src/mobile-push/mobile-push.js'), 'utf8');
async function scenario(code, stale = false) {
    const listeners = {}, timers = [], sent = [];
    let deletes = 0, registrations = 0;
    const button = {hidden: true};
    const push = {
        addListener: async (name, fn) => { listeners[name] = fn; },
        checkPermissions: async () => ({receive: 'granted'}),
        register: async () => { listeners.registration({value: registrations++ === 0 || stale ? 'old-token' : 'fresh-token'}); },
        unregister: async () => { deletes++; },
    };
    const ctx = {
        window: {Capacitor: {isNativePlatform: () => true, getPlatform: () => 'android', Plugins: {PushNotifications: push}}, addEventListener() {}},
        document: {querySelector: selector => ({content: selector.includes('authenticated') ? '1' : 'test'}), querySelectorAll: () => [button], addEventListener() {}},
        localStorage: {getItem: () => null, setItem() {}},
        crypto: require('node:crypto').webcrypto, Uint8Array, URLSearchParams, URL,
        navigator: {userAgent: 'SkeekSMobile/1'}, yii: {getCsrfParam: () => '_csrf', getCsrfToken: () => 'test'},
        setTimeout: fn => { timers.push(fn); return timers.length; },
        fetch: async (_, options) => {
            sent.push(options.body.get('token'));
            return sent.length === 1 ? {status: 409, ok: false, json: async () => ({code})} : {status: 200, ok: true};
        },
    };
    vm.runInNewContext(source, ctx);
    for (let i = 0; i < 20; i++) {
        await new Promise(resolve => setImmediate(resolve));
        if (timers.length) timers.shift()();
    }
    return {deletes, registrations, sent, button, pending: timers.length};
}
(async () => {
    const recovered = await scenario('token_conflict');
    assert.equal(recovered.deletes, 1);
    assert.deepEqual(recovered.sent, ['old-token', 'fresh-token']);
    assert.equal(recovered.button.hidden, false);
    const stale = await scenario('token_conflict', true);
    assert.equal(stale.deletes, 1);
    assert.deepEqual(stale.sent, ['old-token']);
    assert.ok(stale.registrations <= 4);
    assert.equal(stale.pending, 0);
    assert.equal((await scenario('other_conflict')).deletes, 0);
    console.log('OK: native bridge token recovery, bounded stale-token retries, generic conflict isolation.');
})();
