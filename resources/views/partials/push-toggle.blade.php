{{--
    The "Alerts" button: turns browser push notifications on or off for this browser (D49).
    $variant: 'panel' for the staff portal top bar, 'portal' for the client area.
    A missing server key is shown to full administrators only; everyone else simply gets no button.
--}}
@auth
@php($variant = $variant ?? 'portal')
<style>
    .mtl-push { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; cursor: pointer;
        font-size: 13px; font-weight: 600; line-height: 1; border: 1px solid rgba(0,0,0,.14); background: rgba(0,0,0,.03); color: #4b5563; }
    .mtl-push[hidden] { display: none; }
    .mtl-push:hover { color: #111827; border-color: rgba(0,0,0,.25); }
    .mtl-push svg { width: 15px; height: 15px; }
    .mtl-push .bell-off { display: none; }
    .mtl-push[aria-pressed="true"] { color: #007bf8; border-color: rgba(0,123,248,.45); }
    .mtl-push[aria-pressed="true"] .bell-on { display: none; }
    .mtl-push[aria-pressed="true"] .bell-off { display: inline-flex; }
    .mtl-push:disabled { opacity: .6; cursor: default; }
    .dark .mtl-push--panel { color: #d1d5db; border-color: rgba(255,255,255,.16); background: rgba(255,255,255,.06); }
    .dark .mtl-push--panel:hover { color: #fff; }
    @media (max-width: 640px) { .mtl-push .push-label { display: none; } .mtl-push { padding: 6px 8px; } }
</style>

<button type="button" id="push-toggle" class="mtl-push mtl-push--{{ $variant }}" aria-pressed="false" hidden>
    <span class="bell-on">@svg('heroicon-o-bell')</span>
    <span class="bell-off">@svg('heroicon-s-bell-alert')</span>
    <span class="push-label">Alerts</span>
</button>

<script>
(function () {
    const btn = document.getElementById('push-toggle');
    if (!btn || btn.dataset.wired) return;
    btn.dataset.wired = '1';

    const vapidKey = @json(trim((string) config('push.vapid.public_key')));
    const csrf = @json(csrf_token());
    const isAdmin = @json((bool) auth()->user()->isFullAdministrator());
    const label = btn.querySelector('.push-label');
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!supported) return;

    navigator.serviceWorker.register('/sw.js').catch(() => {});

    function show(text, on, disabled) {
        label.textContent = text;
        btn.title = text;
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.disabled = !!disabled;
        btn.hidden = false;
    }

    if (!vapidKey) {
        if (isAdmin) {
            show('Alerts off: no push keys on the server', false, true);
            btn.title = 'Run php artisan mtl:vapid-keys, put both lines in .env, then php artisan optimize. Until then nobody can turn alerts on.';
        }
        return;
    }

    // iPhone and iPad deliver push only to a site added to the Home Screen, never to a Safari tab.
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isIOS && !standalone) { show('Add to Home Screen for alerts', false, true); return; }
    if (Notification.permission === 'denied') { show('Alerts blocked in browser settings', false, true); return; }

    function keyToArray(b) {
        const base64 = (b + '='.repeat((4 - b.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from([...atob(base64)].map(c => c.charCodeAt(0)));
    }
    const encode = (buf) => buf ? btoa(String.fromCharCode(...new Uint8Array(buf))) : null;

    async function enable() {
        // Asked only when the person clicks: browsers block sites that prompt unasked.
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            show(permission === 'denied' ? 'Alerts blocked in browser settings' : 'Alerts', false, permission === 'denied');
            return;
        }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription()
            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToArray(vapidKey) });
        const saved = await fetch(@json(route('push.subscribe')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ endpoint: sub.endpoint, p256dh: encode(sub.getKey('p256dh')), auth: encode(sub.getKey('auth')) }),
        });
        if (!saved.ok) { show('Could not save alerts, try again', false, false); return; }
        show('Alerts on', true);
    }

    async function disable() {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (!sub) { show('Alerts', false); return; }
        const endpoint = sub.endpoint;
        await sub.unsubscribe();
        await fetch(@json(route('push.unsubscribe')), {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ endpoint }),
        });
        show('Alerts', false);
    }

    btn.addEventListener('click', function () {
        const on = btn.getAttribute('aria-pressed') === 'true';
        btn.disabled = true;
        (on ? disable() : enable())
            .catch(() => show('Alerts unavailable', false, true))
            .finally(() => { if (btn.title !== 'Alerts unavailable') btn.disabled = false; });
    });

    // serviceWorker.ready never settles if the worker failed to install; show the button anyway.
    Promise.race([navigator.serviceWorker.ready, new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 5000))])
        .then((reg) => reg.pushManager.getSubscription())
        .then((sub) => { const on = !!sub && Notification.permission === 'granted'; show(on ? 'Alerts on' : 'Alerts', on); })
        .catch(() => show('Alerts', false));
})();
</script>
@endauth
