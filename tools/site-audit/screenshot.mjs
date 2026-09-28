// Captures desktop/mobile reference screenshots and computed styles of the
// live site (or any base URL, for later comparison against the Laravel build).
//
// Usage: node tools/site-audit/screenshot.mjs [baseUrl] [outDir]
//   defaults: https://mtouchlegal.com  docs/reference-screenshots
//
// Uses the locally installed Chrome/Edge (no browser download).

import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const base = (process.argv[2] ?? 'https://mtouchlegal.com').replace(/\/$/, '');
const outDir = process.argv[3] ?? 'docs/reference-screenshots';

const pages = [
    ['home', '/'],
    ['about', '/about/'],
    ['offering', '/offering/'],
    ['contact', '/contact/'],
    ['blog', '/blog/'],
    ['terms-and-conditions', '/terms-and-conditions/'],
    ['log-in', '/log-in/'],
    ['register', '/register/'],
    ['password-reset', '/password-reset/'],
    ['post-hiring-your-first-staff', '/hiring-your-first-staff-the-employment-contract-mistakes-that-cost-millions/'],
    ['post-virtual-law-firm', '/the-rise-of-the-virtual-law-firm-why-your-nigerian-sme-needs-a-lawyer-not-a-lawsuit/'],
    ['tag-archive', '/tag/sme/'],
];

const viewports = {
    desktop: { width: 1440, height: 900 },
    mobile: { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
};

const executablePath = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find((p) => fs.existsSync(p));

fs.mkdirSync(outDir, { recursive: true });
const browser = await chromium.launch({ executablePath });
const styles = {};
const log = [];

for (const [vpName, vp] of Object.entries(viewports)) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: vp.isMobile ?? false, hasTouch: vp.hasTouch ?? false, deviceScaleFactor: vp.deviceScaleFactor ?? 1 });
    // Keep third-party chat bubbles out of reference captures.
    await ctx.route(/tawk\.to/, (r) => r.abort());
    const page = await ctx.newPage();

    for (const [name, p] of pages) {
        try {
            const res = await page.goto(base + p, { waitUntil: 'networkidle', timeout: 60000 });
            // Trigger lazy-loaded images/animations.
            await page.evaluate(async () => {
                // Elementor entrance animations fire on intersection; scroll slowly so every section reaches its final state.
                for (let y = 0; y < document.body.scrollHeight; y += 250) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 250)); }
                await new Promise((r) => setTimeout(r, 1500));
                window.scrollTo(0, 0);
            });
            await page.waitForTimeout(1500);
            const stillHidden = await page.locator(".elementor-invisible").count();
            if (stillHidden) log.push(`WARN ${vpName} ${name}: ${stillHidden} elements still animation-hidden`);
            const file = path.join(outDir, `${name}-${vpName}.png`);
            await page.screenshot({ path: file, fullPage: true });
            log.push(`${res?.status()} ${vpName} ${base + p} -> ${file}`);

            if (vpName === 'mobile' && name === 'home') {
                const toggle = page.locator('.menu-toggle, .ast-mobile-menu-trigger-minimal, [aria-label*="menu" i]').first();
                if (await toggle.count()) {
                    await toggle.click();
                    await page.waitForTimeout(600);
                    await page.screenshot({ path: path.join(outDir, 'home-mobile-menu-open.png') });
                }
            }

            styles[`${name}-${vpName}`] = await page.evaluate(() => {
                const pick = (el) => {
                    if (!el) return null;
                    const s = getComputedStyle(el);
                    return { text: el.textContent.trim().slice(0, 60), fontFamily: s.fontFamily, fontSize: s.fontSize, fontWeight: s.fontWeight, lineHeight: s.lineHeight, color: s.color, background: s.backgroundColor, backgroundImage: s.backgroundImage.slice(0, 160), padding: s.padding, borderRadius: s.borderRadius, textTransform: s.textTransform, letterSpacing: s.letterSpacing };
                };
                const out = { body: pick(document.body), header: pick(document.querySelector('#masthead')), navLink: pick(document.querySelector('#masthead .menu-link')), footer: pick(document.querySelector('#colophon')) };
                document.querySelectorAll('[data-elementor-type] h1, [data-elementor-type] h2, [data-elementor-type] h3, [data-elementor-type] h4, .elementor-button, .elementor-widget-text-editor, .elementor-icon-box-title, .elementor-icon-box-description, .elementor-image-box-title, .elementor-image-box-description, .hfe-infocard-title, .hfe-infocard-text, .e-con, .elementor-section').forEach((el, i) => {
                    const r = el.getBoundingClientRect();
                    out[`${i}:${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join('.')}`] = { ...pick(el), y: Math.round(r.top + scrollY), h: Math.round(r.height), w: Math.round(r.width) };
                });
                return out;
            });
        } catch (e) {
            log.push(`ERROR ${vpName} ${base + p}: ${e.message.split('\n')[0]}`);
        }
    }
    await ctx.close();
}

await browser.close();
fs.writeFileSync(path.join(outDir, 'computed-styles.json'), JSON.stringify(styles, null, 2));
fs.writeFileSync(path.join(outDir, 'capture-log.txt'), `captured ${new Date().toISOString()} from ${base}\n` + log.join('\n') + '\n');
console.log(log.join('\n'));
