// Built-in meeting room in real browsers (fake cameras): a lecturer, a student and a guest with no account
// meet, see and hear each other, chat, and the lecturer ends the meeting. Run like smoke.mjs (demo data only).
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.argv[2] || 'http://127.0.0.1:8000').replace(/\/$/, '');
const password = process.env.E2E_PASSWORD || 'ChangeMe!2026';
const results = [];
const check = (name, ok, detail = '') => results.push({ name, ok: Boolean(ok), detail });

const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'] });
const person = async (email) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, permissions: ['camera', 'microphone'] });
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', (e) => page.errors.push(e.message));
    if (email) {
        await page.goto(base + '/login');
        await page.fill('input[name=email]', email);
        await page.fill('input[name=password]', password);
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
    }

    return page;
};
/** Remote tiles connected and showing moving pictures. */
const flowing = (page, n) => page.waitForFunction((n) => {
    const tiles = [...document.querySelectorAll('[data-peer-tile]:not([data-peer-tile="me"])')];
    return tiles.length >= n && tiles.every((t) => t.dataset.conn === 'connected' && (t.querySelector('video')?.videoWidth ?? 0) > 0);
}, n, { timeout: 30000 }).then(() => true).catch(() => false);

try {
    // the lecturer schedules a built-in meeting starting now and opens the room
    const lecturer = await person('lecturer@veritas.local');
    await lecturer.goto(base + '/meetings/create', { waitUntil: 'networkidle' });
    const title = `Built-in room check ${Date.now()}`;
    await lecturer.getByLabel('Title').fill(title);
    const now = await lecturer.evaluate(() => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); });
    await lecturer.getByLabel('Starts').fill(now);
    await lecturer.getByLabel('Where the meeting runs').selectOption('builtin');
    // a course the demo student is registered for
    const courseSelect = lecturer.locator('select').filter({ has: lecturer.locator('option', { hasText: /^CHM 101/ }) }).first();
    const course = await courseSelect.evaluate((sel) => [...sel.options].find((o) => o.text.startsWith('CHM 101'))?.value);
    await courseSelect.selectOption(course);
    await Promise.all([lecturer.waitForURL(/\/meetings\/[0-9a-f-]{36}$/), lecturer.getByRole('button', { name: 'Schedule' }).click()]);
    const meetingUrl = lecturer.url();
    await lecturer.getByRole('button', { name: 'Make a guest link' }).click();
    await lecturer.waitForLoadState('networkidle');
    const guestUrl = await lecturer.getByLabel('Guest link').inputValue();
    check('guest link made', guestUrl.includes('/join/'), guestUrl);
    await lecturer.getByRole('button', { name: /Start the meeting|Join/ }).first().click();
    await lecturer.waitForURL(/\/room$/, { timeout: 20000 }).catch(async () => {
        await lecturer.screenshot({ path: '/tmp/e2eshots/room-join-fail.png' });
        throw new Error('lecturer did not reach the room: ' + lecturer.url() + ' ' + (await lecturer.locator('main').innerText()).slice(0, 400));
    });
    check('lecturer is in the built-in room', await lecturer.locator('[data-peer-tile="me"]').count() === 1);

    // a student joins from the meeting page
    const student = await person('student@veritas.local');
    await student.goto(meetingUrl, { waitUntil: 'networkidle' });
    if (!(await student.getByRole('button', { name: 'Join' }).count())) {
        throw new Error('student page: ' + student.url() + ' ' + (await student.locator('body').innerText()).slice(0, 600));
    }
    await student.getByRole('button', { name: 'Join' }).click();
    await student.waitForURL(/\/room$/, { timeout: 20000 });
    check('lecturer and student see and hear each other', (await flowing(lecturer, 1)) && (await flowing(student, 1)));

    // a guest with no account uses the link, waits, and is let in
    const guest = await person(null);
    await guest.goto(guestUrl, { waitUntil: 'networkidle' });
    await guest.getByLabel('Your name').fill('Prof. Okafor');
    await Promise.all([guest.waitForURL(/\/room$/), guest.getByRole('button', { name: 'Join the meeting' }).click()]);
    await guest.getByText('a host will let you in').waitFor({ timeout: 10000 });
    if (process.env.E2E_SHOTS) {
        await guest.screenshot({ path: `${process.env.E2E_SHOTS}/meeting-waiting.png` });
    }
    check('guest waits in the waiting room', true);
    await lecturer.getByRole('button', { name: /waiting to join/ }).click({ timeout: 10000 });
    await lecturer.getByRole('button', { name: 'Let in' }).click();
    check('all three connected (mesh)', (await flowing(guest, 2)) && (await flowing(lecturer, 2)) && (await flowing(student, 2)));
    if (process.env.E2E_SHOTS) {
        await lecturer.screenshot({ path: `${process.env.E2E_SHOTS}/meeting-room.png` });
        await guest.screenshot({ path: `${process.env.E2E_SHOTS}/meeting-room-guest.png` });
    }
    check('guest is named as a guest', (await lecturer.locator('[data-peer-tile]').allInnerTexts()).some((t) => t.includes('Prof. Okafor (guest)')));

    // chat, mute by the host
    await student.getByRole('button', { name: 'Chat' }).click();
    await student.getByLabel('Message').fill('Good morning, sir');
    await student.getByRole('button', { name: 'Send' }).click();
    await lecturer.getByRole('button', { name: 'Chat' }).click();
    check('chat reaches others', await lecturer.getByText('Good morning, sir').waitFor({ timeout: 10000 }).then(() => true).catch(() => false));
    await lecturer.getByRole('button', { name: 'People' }).click();
    await lecturer.getByRole('button', { name: 'Mute everyone' }).click();
    check('host can mute everyone', await student.getByRole('button', { name: 'Turn microphone on' }).waitFor({ timeout: 10000 }).then(() => true).catch(() => false));

    // camera off and on, and the others keep the connection
    await student.getByRole('button', { name: 'Turn camera off' }).click();
    await student.getByRole('button', { name: 'Turn camera on' }).click();
    check('camera off/on keeps the call', await flowing(lecturer, 2));

    // the lecturer ends it for everyone
    lecturer.once('dialog', (d) => d.accept());
    await lecturer.getByRole('button', { name: 'End for everyone' }).click();
    check('everyone sees the meeting has ended', await student.getByText('The meeting has ended.').waitFor({ timeout: 15000 }).then(() => true).catch(() => false)
        && await guest.getByText('The meeting has ended.').waitFor({ timeout: 15000 }).then(() => true).catch(() => false));
    await lecturer.goto(meetingUrl, { waitUntil: 'networkidle' });
    const att = await lecturer.locator('section:has-text("Attendance") tbody tr').allInnerTexts();
    check('attendance lists lecturer, student and guest', att.length >= 3 && att.some((r) => r.includes('Prof. Okafor')), att.join(' | '));
    for (const p of [lecturer, student, guest]) {
        p.errors.forEach((e) => check('no script errors', false, e));
    }
} finally {
    await browser.close();
}

for (const r of results) {
    console.log(`${r.ok ? '✓' : '✗'} ${r.name}${r.detail && !r.ok ? ` — ${r.detail}` : ''}`);
}
process.exit(results.every((r) => r.ok) ? 0 : 1);
