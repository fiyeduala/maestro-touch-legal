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
