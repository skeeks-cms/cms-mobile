(function () {
    'use strict';
    if (window.skeeksMobilePush) return;
    const capacitor = window.Capacitor;
    if (!capacitor || !capacitor.isNativePlatform() || capacitor.getPlatform() !== 'android') return;
    const push = capacitor.Plugins && capacitor.Plugins.PushNotifications;
    const endpoint = document.querySelector('meta[name="skeeks-push-register"]');
    const app = document.querySelector('meta[name="skeeks-push-app"]');
    const authenticated = document.querySelector('meta[name="skeeks-push-authenticated"]');
    if (!push || !endpoint || !app) return;
    const signedIn = authenticated && authenticated.content === '1';
    let busy = false;
    let token = null;
    let retry = null;
    let attempts = 0;
    let rejectedToken = null;
    let rotationAttempts = 0;
    const hex = () => Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    let installation;
    try {
        installation = JSON.parse(localStorage.getItem('skeeks.mobile.installation.v1') || 'null');
        if (!installation || !/^[a-f0-9]{64}$/.test(installation.id) || !/^[a-f0-9]{64}$/.test(installation.secret)) {
            installation = {id: hex(), secret: hex()};
            localStorage.setItem('skeeks.mobile.installation.v1', JSON.stringify(installation));
        }
    } catch (_) { return; }
    async function sync(permission) {
        if (!signedIn) return;
        const data = new URLSearchParams({
            app_id: app.content, installation_id: installation.id,
            installation_secret: installation.secret, platform: 'android',
            app_version: (navigator.userAgent.match(/SkeekSMobile\/([^\s]+)/) || [,'unknown'])[1],
            permission: permission,
        });
        if (token && permission === 'granted') data.set('token', token);
        data.set(yii.getCsrfParam(), yii.getCsrfToken());
        const response = await fetch(endpoint.content, {method: 'POST', credentials: 'same-origin', body: data});
        if (response.status === 401 || response.status === 403) {
            await push.unregister();
            return;
        }
        if (response.status === 409) {
            const error = await response.json();
            if (error.code === 'token_conflict' && rotationAttempts === 0) {
                rejectedToken = token;
                token = null;
                rotationAttempts = 1;
                await push.unregister();
                // Capacitor Android resolves unregister before Firebase deleteToken completes.
                setTimeout(() => start(false), 1000);
                return;
            }
        }
        if (!response.ok) throw new Error('registration_failed');
        attempts = 0;
    }
    function failed() {
        if (retry || attempts >= 4) return;
        retry = setTimeout(() => { retry = null; start(false); }, Math.min(60000, 3000 * (2 ** attempts++)));
    }
    async function start(ask) {
        if (busy || !signedIn) return;
        busy = true;
        try {
            let result = await push.checkPermissions();
            if (ask && ['prompt', 'prompt-with-rationale'].includes(result.receive)) result = await push.requestPermissions();
            if (result.receive === 'granted') {
                await push.register();
            } else {
                token = null;
                await sync(result.receive === 'denied' ? 'denied' : 'prompt');
            }
        } catch (_) { failed(); } finally { busy = false; }
    }
    window.skeeksMobilePush = {enable: () => start(true)};
    document.querySelectorAll('[data-sx-enable-push]').forEach(button => { button.hidden = false; });
    Promise.all([
        push.addListener('registration', result => {
            if (result.value === rejectedToken) {
                if (rotationAttempts++ < 3) setTimeout(() => start(false), 1000 * rotationAttempts);
                return;
            }
            token = result.value;
            sync('granted').catch(failed);
        }),
        push.addListener('registrationError', failed),
        push.addListener('pushNotificationActionPerformed', result => {
            const route = result.notification.data && result.notification.data.route;
            if (typeof route !== 'string' || !route.startsWith('/') || route.startsWith('//') || /[\\\x00-\x20]/.test(route)) return;
            const url = new URL(route, location.origin);
            if (url.origin === location.origin) location.assign(url.href);
        }),
    ]).then(() => start(false)).catch(failed);
    window.addEventListener('online', () => { attempts = 0; start(false); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { attempts = 0; start(false); } });
    document.addEventListener('click', event => { if (event.target.closest('[data-sx-enable-push]')) start(true); });
})();
