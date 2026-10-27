// Web Push: registers the service worker (also makes SIAKAD installable)
// and provides the "pop-ups on this device" switch on the Notifications page.

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const send = (method, body) => fetch('/push-subscriptions', {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    body: JSON.stringify(body),
}).then((response) => {
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
});

const toKey = (base64) => {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
};

const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pushDevice', (endpoints = []) => ({
        // unconfigured | unsupported | install | denied | off | on
        state: 'off',
        busy: false,
        failed: false,

        async init() {
            const key = document.querySelector('meta[name="push-key"]')?.content;

            if (!key) return (this.state = 'unconfigured');
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                // iPhone/iPad support push only for SIAKAD added to the Home Screen.
                return (this.state = isIos() && !isInstalled() ? 'install' : 'unsupported');
            }
            if (Notification.permission === 'denied') return (this.state = 'denied');

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            // On for this person only if the server has this browser under their name.
            this.state = subscription && endpoints.includes(subscription.endpoint) ? 'on' : 'off';
        },

        async enable() {
            this.busy = true;
            this.failed = false;

            try {
                if ((await Notification.requestPermission()) !== 'granted') {
                    this.state = Notification.permission === 'denied' ? 'denied' : 'off';
                    return;
                }

                const registration = await navigator.serviceWorker.ready;
                const subscription = (await registration.pushManager.getSubscription())
                    ?? (await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: toKey(document.querySelector('meta[name="push-key"]').content),
                    }));

                const encoding = (PushManager.supportedContentEncodings ?? ['aes128gcm']).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
                await send('POST', { ...subscription.toJSON(), contentEncoding: encoding });
                this.state = 'on';
            } catch {
                this.failed = true;
            } finally {
                this.busy = false;
            }
        },

        async disable() {
            this.busy = true;
            this.failed = false;

            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                if (subscription) {
                    await send('DELETE', { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                this.state = 'off';
            } catch {
                this.failed = true;
            } finally {
                this.busy = false;
            }
        },
    }));
});
