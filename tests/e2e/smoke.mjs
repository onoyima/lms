// End-to-end browser checks (real Chromium) against a running copy of the platform with the demo data.
//
//   ONLY on a local or staging copy seeded with:  php artisan db:seed --class=DemoSeeder   (never on the live database)
//   npm i -D playwright@1 (once, or use a global install) and: npx playwright install chromium
//   node tests/e2e/smoke.mjs http://127.0.0.1:8000            (E2E_PASSWORD defaults to the demo password)
//
// For each role it signs in, opens that role's pages, and fails on: an HTTP error, a JavaScript error,
// a page wider than a phone screen. It then walks the main journeys: a lecturer schedules a live class,
// an examiner opens an exam's score board, a student enters an exam, answers, zooms, opens the submit
// confirmation (and backs out, so the demo attempt stays open).
import { createRequire } from 'node:module';

// playwright from the project or a global install (NODE_PATH="$(npm root -g)")
const { chromium } = createRequire(import.meta.url)('playwright');

const base = (process.argv[2] || 'http://127.0.0.1:8000').replace(/\/$/, '');
const password = process.env.E2E_PASSWORD || 'ChangeMe!2026';
const out = process.env.E2E_SHOTS || '';
const failures = [];
const passes = [];
const ok = (name) => passes.push(name);
const fail = (name, why) => failures.push(`${name}: ${why}`);

const ROLES = {
    'admin@veritas.local': ['/dashboard', '/admin/people', '/admin/academics', '/admin/reports', '/admin/policy', '/admin/audit', '/exams', '/exam-board', '/exam-calendar', '/question-bank', '/meetings', '/meeting-reports', '/venues', '/exam-network', '/notifications', '/help'],
    'lecturer@veritas.local': ['/dashboard', '/learn/courses', '/learn/calendar', '/learn/grades', '/messages', '/meetings', '/meetings/create', '/meeting-reports', '/exam-board', '/marking', '/question-bank/import'],
    'student@veritas.local': ['/dashboard', '/learn/courses', '/learn/calendar', '/learn/registration', '/my/exams', '/my/results', '/messages', '/meetings', '/notifications', '/settings/profile'],
    'examofficer@veritas.local': ['/dashboard', '/exams', '/exam-board', '/results/1', '/proctor'],
};

async function signIn(browser, email) {
    const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 }, permissions: ['camera', 'microphone'] });
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', (e) => page.errors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text()) && page.errors.push(m.text()));
    await page.goto(base + '/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type=submit]')]);

    return { ctx, page };
}

async function open(page, path, name) {
    page.errors.length = 0;
    const res = await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(200);
    if (!res || res.status() >= 400) {
        return fail(name, `HTTP ${res?.status()}`);
    }
    if (page.errors.length) {
        return fail(name, page.errors[0]);
    }
    if (out) {
        await page.screenshot({ path: `${out}/${name.replace(/[^a-z0-9]+/gi, '_')}.png` });
    }
    ok(name);
}

// a fake camera and microphone, as a student's laptop would have
const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
try {
    for (const [email, pages] of Object.entries(ROLES)) {
        const { ctx, page } = await signIn(browser, email);
        for (const p of pages) {
            await open(page, p, `${email.split('@')[0]} ${p}`);
        }
        // phone width: no sideways scrolling on the dashboard
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(base + '/dashboard', { waitUntil: 'networkidle' });
        (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)) ? fail(`${email} phone`, 'page wider than the screen') : ok(`${email} phone width`);
        await ctx.close();
    }

    // lecturer schedules a live class with room options
    {
        const { ctx, page } = await signIn(browser, 'lecturer@veritas.local');
        await page.goto(base + '/meetings/create', { waitUntil: 'networkidle' });
        const title = `E2E class ${Date.now()}`;
        await page.getByLabel('Title').fill(title);
        await page.getByLabel('Screen layout').selectOption('PRESENTATION_FOCUS');
        await page.getByLabel('Polls').check();
        await Promise.all([page.waitForURL(/\/meetings\/[0-9a-f-]{36}$/, { timeout: 15000 }), page.getByRole('button', { name: 'Schedule' }).click()]);
        (await page.getByRole('heading', { name: title }).isVisible()) ? ok('lecturer schedules a live class') : fail('lecturer schedules a live class', 'meeting page did not show it');
        (await page.getByText('Guest link (people without an account)').isVisible()) ? ok('guest link panel shown to host') : fail('guest link panel', 'missing');
        await ctx.close();
    }

    // examiner: score board with the mark distribution
    {
        const { ctx, page } = await signIn(browser, 'admin@veritas.local');
        await page.goto(base + '/exam-board', { waitUntil: 'networkidle' });
        const first = page.locator('a[href^="/exam-board/"]').first();
        if (await first.count()) {
            await Promise.all([page.waitForURL(/\/exam-board\/\d+/), first.click()]);
            await page.waitForLoadState('networkidle');
            (await page.locator('svg').count()) > 0 ? ok('exam board shows the distribution chart') : fail('exam board chart', 'no chart');
            const xlsx = page.locator('a[href*="export?format=xlsx"]').first();
            if (await xlsx.count()) {
                const [dl] = await Promise.all([page.waitForEvent('download'), xlsx.click()]);
                (await dl.suggestedFilename()).endsWith('.xlsx') ? ok('score download is .xlsx') : fail('score download', dl.suggestedFilename());
            }
        } else {
            fail('exam board', 'no exams listed');
        }
        await ctx.close();
    }

    // student: enter an exam, answer, zoom, open the submit confirmation and back out
    {
        const { ctx, page } = await signIn(browser, 'student@veritas.local');
        await page.goto(base + '/my/exams', { waitUntil: 'networkidle' });
        const start = page.getByRole('link', { name: /^(Start|Re-attempt|Continue|Open|Go to exam room)/ }).or(page.getByRole('button', { name: /^(Start|Re-attempt|Continue)/ })).first();
        if (await start.count()) {
            await start.click();
            await page.waitForLoadState('networkidle');
            const begin = page.getByRole('button', { name: /^(Start exam|Continue|Re-attempt)/i }).first();
            await begin.waitFor({ timeout: 15000 }).catch(() => {}); // appears once the system check has run
            const consent = page.getByLabel(/I have read what is monitored/i);
            if (await consent.count()) {
                await consent.check();
            }
            if (await begin.count()) {
                await begin.click();
                await page.waitForTimeout(2500);
            }
            const option = page.getByRole('radio').or(page.getByRole('checkbox', { name: /^A/ })).first();
            await option.waitFor({ timeout: 20000 }).catch(() => {});
            if (await option.count()) {
                await option.click();
                await page.waitForTimeout(500);
                (await page.getByText(/[1-9]\d* answered/).count()) ? ok('student answers a question') : fail('student answers', 'answer count did not change');
                await page.getByRole('button', { name: 'Zoom in' }).click();
                (await page.getByText('110%').count()) ? ok('student zooms the paper') : fail('zoom', 'size did not change');
                await page.getByRole('button', { name: 'Zoom out' }).click();
                await page.getByRole('button', { name: /^Submit exam/ }).click();
                const dialog = page.getByRole('dialog');
                (await dialog.isVisible()) ? ok('submit asks for confirmation') : fail('submit confirmation', 'no dialog');
                (await dialog.getByText(/have not answered these questions/i).count()) ? ok('confirmation lists unanswered questions') : fail('confirmation', 'no unanswered list');
                await dialog.getByRole('button', { name: 'Go back to my exam' }).click();
                (await dialog.count()) === 0 ? ok('student goes back to the paper') : fail('go back', 'dialog stayed open');
            } else {
                if (out) {
                    await page.screenshot({ path: `${out}/student_exam_fail.png` });
                }
                fail('student exam', `no question on screen at ${page.url()} (the demo sitting may be closed or need a PIN)`);
            }
            page.errors.filter((e) => !/getUserMedia|NotAllowedError|Permission|camera|FaceDetector/i.test(e)).forEach((e) => fail('exam room script error', e));
        } else {
            ok('student exam skipped: the demo exam was already taken (re-seed the demo data to walk it again)');
        }
        await ctx.close();
    }
} finally {
    await browser.close();
}

console.log(`\n${passes.length} passed, ${failures.length} failed`);
failures.forEach((f) => console.log('  ✗ ' + f));
process.exit(failures.length ? 1 : 0);
