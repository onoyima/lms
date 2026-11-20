// Every page, for every kind of user, on a phone, a tablet, a laptop and a wide screen:
//  - nothing may make the page scroll sideways;
//  - text and controls keep a margin from the screen edges (at least 12 px on phones).
//   NODE_PATH="$(npm root -g)" node tests/e2e/responsive.mjs http://127.0.0.1:8200
//   RESP_WIDTHS=360,768 RESP_ONLY=/exams  (optional filters)   E2E_SHOTS=dir (screenshots of problems)
// Uses the demo data (php artisan db:seed --class=DemoSeeder). Never run against the live site.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.argv[2] ?? 'http://127.0.0.1:8200').replace(/\/$/, '');
const out = process.env.E2E_SHOTS || '';
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const ids = JSON.parse(execFileSync('php', [path.join(root, 'tests/e2e/_ids.php')], { cwd: root }).toString());
const widths = (process.env.RESP_WIDTHS ?? '360,768,1024,1440').split(',').map(Number);
const only = process.env.RESP_ONLY ?? '';
const password = process.env.E2E_PASSWORD || 'ChangeMe!2026';

const ROLES = {
    'platform@platform.local': ['/platform', '/platform/institutions', `/platform/institutions/${ids.tenant}`, '/platform/plans', '/platform/coupons', '/platform/payments', '/platform/applications',
        '/platform/modules', '/platform/health', '/platform/security', '/platform/audit', '/platform/admins', '/platform/announcements', '/platform/branding', '/platform/conferencing', '/platform/settings'],
    'admin@veritas.local': ['/dashboard', '/admin/people', '/admin/academics', '/admin/reports', '/admin/policy', '/admin/audit', '/admin/roles', '/admin/branding', '/admin/integrations', '/admin/sso',
        '/admin/system-health', '/admin/maintenance', '/admin/mail-log', '/admin/exceptions', '/admin/identity', '/admin/access-review', '/admin/conferencing', '/admin/safe-exam-browser', '/admin/shop', '/admin/ai', '/admin/sms', '/certificates',
        '/institution/billing', '/exams', '/exams/new', `/exams/${ids.exam}`, `/exam-groups/${ids.group}`, `/sessions/${ids.session}`, `/sessions/${ids.session}/ready`, '/exam-board', `/exam-board/${ids.exam}`,
        '/exam-calendar', '/question-bank', '/question-bank/import', '/question-bank/questions/create', `/question-bank/questions/${ids.question}/edit`, `/results/${ids.exam}`, `/results/${ids.exam}/analytics`,
        '/marking', '/proctor', `/proctor/sessions/${ids.session}`, '/proctor/shifts', '/incidents', '/venues', '/exam-network', '/meetings', '/meetings/create', '/meeting-reports',
        '/notifications', '/messages', '/help', '/templates', '/settings/profile', '/settings/security', '/settings/appearance', '/settings/notifications'],
    'lecturer@veritas.local': ['/dashboard', '/learn/courses', `/learn/courses/${ids.offering}`, '/learn/calendar', '/learn/grades', '/learn/announcements', '/learn/search', '/marking', '/meetings', '/messages'],
    'student@veritas.local': ['/dashboard', '/learn/courses', '/learn/calendar', '/learn/registration', '/my/exams', `/my/exams/${ids.candidate}`, '/my/results', '/my/exceptions', '/my/identity',
        '/safe-exam-browser', '/shop', '/my/test-series', '/my/certificates', '/messages', '/meetings', '/notifications', '/help', '/settings/profile'],
};
const PUBLIC = ['/', '/login', '/forgot-password', '/login/code', '/shop'];

const problems = [];
let checked = 0;

/** In the page: does anything scroll sideways, and which content sits on the screen edge? */
function measure() {
    const vw = document.documentElement.clientWidth;
    const min = vw < 640 ? 12 : 16;
    const overflow = document.documentElement.scrollWidth - vw;
    const name = (el) => {
        const t = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);

        return `${el.tagName.toLowerCase()}${el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').filter(Boolean).slice(0, 3).join('.') : ''}${t ? ` "${t}"` : ''}`;
    };
    const visible = (el) => {
        const s = getComputedStyle(el);
        if (s.visibility === 'hidden' || s.display === 'none' || Number(s.opacity) === 0) {
            return false;
        }
        // clipped by a scrolling parent (tables, timelines): that parent is checked instead
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const ps = getComputedStyle(p);
            if (/(auto|scroll|hidden|clip)/.test(ps.overflowX)) {
                const r = p.getBoundingClientRect();
                const e = el.getBoundingClientRect();
                if (e.left < r.left - 1 || e.right > r.right + 1) {
                    return false;
                }
            }
            if (p.matches('[data-sidebar="sidebar"],[role="dialog"],[data-slot="sheet-content"]')) {
                return !p.matches('[data-sidebar="sidebar"]') || false;
            }
        }

        return true;
    };
    const wide = [];
    const edge = [];
    // leaf content: text, inputs, buttons, images
    for (const el of document.body.querySelectorAll('h1,h2,h3,p,a,button,input,select,textarea,label,li,td,th,img,svg,span')) {
        const r = el.getBoundingClientRect();
        // hidden on purpose far off screen (a spam trap field, a skip link)
        if (r.width < 2 || r.height < 2 || r.bottom < 0 || r.right < -500 || !visible(el)) {
            continue;
        }
        if (el.closest('[data-sidebar="sidebar"]') || el.closest('[data-full-bleed]')) {
            continue;
        }
        if (r.right > vw + 1) {
            wide.push(name(el) + ` right=${Math.round(r.right)}`);
        } else if (el.matches('h1,h2,h3,p,a,button,input,select,textarea,label,img') && (r.left < min - 0.5 || r.right > vw - min + 0.5) && getComputedStyle(el).position !== 'fixed') {
            edge.push(name(el) + ` ${Math.round(r.left)}–${Math.round(vw - r.right)}`);
        }
    }

    return { overflow, wide: [...new Set(wide)].slice(0, 4), edge: [...new Set(edge)].slice(0, 4) };
}

async function check(page, who, pagePath, width) {
    if (only && !pagePath.startsWith(only)) {
        return;
    }
    await page.setViewportSize({ width, height: width < 640 ? 800 : 900 });
    const res = await page.goto(base + pagePath, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => null);
    if (!res || res.status() >= 400) {
        problems.push(`${who} ${pagePath} @${width}: HTTP ${res?.status() ?? 'no answer'}`);

        return;
    }
    await page.waitForTimeout(150);
    const m = await page.evaluate(measure);
    checked++;
    if (m.overflow > 1 || m.wide.length || m.edge.length) {
        const bits = [m.overflow > 1 ? `scrolls sideways by ${m.overflow}px` : '', m.wide.length ? `too wide: ${m.wide.join('; ')}` : '', m.edge.length ? `no margin: ${m.edge.join('; ')}` : ''].filter(Boolean);
        problems.push(`${who.split('@')[0]} ${pagePath} @${width}: ${bits.join(' | ')}`);
        if (out) {
            await page.screenshot({ path: `${out}/resp_${who.split('@')[0]}_${pagePath.replace(/[^a-z0-9]+/gi, '_')}_${width}.png`, fullPage: false });
        }
    }
}

const browser = await chromium.launch();
try {
    {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        for (const p of PUBLIC) {
            for (const w of widths) {
                await check(page, 'guest', p, w);
            }
        }
        await ctx.close();
    }
    for (const [email, pages] of Object.entries(ROLES)) {
        const ctx = await browser.newContext({ permissions: ['camera', 'microphone'] });
        const page = await ctx.newPage();
        await page.goto(base + '/login');
        await page.fill('input[name=email]', email);
        await page.fill('input[name=password]', password);
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type=submit]')]).catch(() => problems.push(`${email}: could not sign in`));
        for (const p of pages) {
            for (const w of widths) {
                await check(page, email, p, w);
            }
        }
        await ctx.close();
    }
} finally {
    await browser.close();
}

console.log(`${checked} page views checked at ${widths.join(', ')} px`);
problems.forEach((p) => console.log('  ✗ ' + p));
console.log(problems.length ? `\n${problems.length} problems` : '\nno problems');
process.exit(problems.length ? 1 : 0);
