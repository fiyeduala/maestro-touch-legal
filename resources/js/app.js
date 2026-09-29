// Public-site behaviour only. Kept dependency-free so pages stay light on mobile data.

function initMobileMenu() {
    const toggle = document.querySelector('[data-menu-toggle]');
    const panel = document.getElementById('mobile-menu');
    if (!toggle || !panel) return;

    const setOpen = (open) => {
        toggle.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
        toggle.querySelector('[data-icon-open]')?.classList.toggle('hidden', open);
        toggle.querySelector('[data-icon-close]')?.classList.toggle('hidden', !open);
    };

    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
    });
    window.matchMedia('(min-width: 922px)').addEventListener('change', (e) => e.matches && setOpen(false));
}

document.addEventListener('DOMContentLoaded', initMobileMenu);

// Portal matter conversation: polls for new messages while the tab is visible, backing off when
// nothing arrives. Without JavaScript the form posts normally and the page reloads.
function initChat() {
    const root = document.querySelector('[data-chat]');
    if (!root) return;
    const list = root.querySelector('[data-chat-list]');
    const form = root.querySelector('[data-chat-form]');
    const error = root.querySelector('[data-chat-error]');
    const MIN = 15000;
    const MAX = 60000;
    let lastId = Number(root.dataset.lastId || 0);
    let delay = MIN;
    let timer = null;
    let busy = false;

    const schedule = () => {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(poll, delay);
    };

    async function poll() {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(`${root.dataset.pollUrl}?after=${lastId}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                return; // signed out or access removed: stop polling
            }
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            if (data.html) {
                root.querySelector('[data-chat-empty]')?.remove();
                list.insertAdjacentHTML('beforeend', data.html);
                delay = MIN;
            } else {
                delay = Math.min(MAX, delay * 1.5);
            }
            lastId = Math.max(lastId, Number(data.last_id || 0));
            for (const id of data.read_ids || []) {
                const receipt = document.querySelector(`#message-${id} [data-receipt]`);
                if (receipt) receipt.textContent = 'Seen by the firm';
            }
            schedule();
        } catch {
            delay = MAX;
            schedule();
        } finally {
            busy = false;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            delay = MIN;
            poll();
        }
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        error.classList.add('hidden');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (response.status === 419) {
                form.submit(); // session expired: fall back to a normal post
                return;
            }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const first = data.errors ? Object.values(data.errors)[0][0] : data.message;
                error.textContent = first || 'Your message could not be sent. Please try again.';
                error.classList.remove('hidden');
                return;
            }
            form.reset();
            delay = MIN;
            await poll();
        } catch {
            error.textContent = 'Your message could not be sent. Check your connection and try again.';
            error.classList.remove('hidden');
        } finally {
            button.disabled = false;
        }
    });

    schedule();
}

document.addEventListener('DOMContentLoaded', initChat);

// Print buttons on invoices, receipts and statements (no inline script; see [data-printable] in app.css).
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-print]')) {
        window.print();
    }
});
