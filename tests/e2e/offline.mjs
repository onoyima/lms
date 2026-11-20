// No exam is lost to a bad connection or a power cut. In a real browser:
//  1. the network goes off mid-exam: a red notice shows, the candidate keeps answering;
//  2. the page is reloaded while still offline: it reopens from this computer with the answers;
//  3. the network comes back: everything written offline reaches the server;
//  4. the browser is killed (as in a power cut) with an unsent answer, stays off ~40 s, and comes back:
//     the answer is still there and is sent, and the time off is given back;
//  5. leaving the exam screen gives "Warning 1 of 5".
//   NODE_PATH="$(npm root -g)" node tests/e2e/offline.mjs http://127.0.0.1:8200
// Makes its own candidate and exam (tests/e2e/_offline_fixture.php). Local test databases only.
import { execFileSync, execSync } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.argv[2] ?? 'http://127.0.0.1:8200').replace(/\/$/, '');
const out = process.env.E2E_SHOTS || '';
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const php = (...args) => JSON.parse(execFileSync('php', [path.join(root, 'tests/e2e/_offline_fixture.php'), ...args], { cwd: root }).toString());
const passes = [];
const failures = [];
const ok = (n) => (passes.push(n), console.log('  ✓ ' + n));
const fail = (n, why) => (failures.push(`${n}: ${why}`), console.log('  ✗ ' + n + ' — ' + why));
const check = (cond, n, why) => (cond ? ok(n) : fail(n, why));
const shot = async (page, name) => out && page.screenshot({ path: `${out}/offline_${name}.png` });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const fx = php();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'exam-offline-'));
const launch = () => chromium.launchPersistentContext(profile, { viewport: { width: 1280, height: 860 } });
const answered = async (page) => Number((await page.getByText(/\d+ answered/).first().textContent()).match(/(\d+) answered/)[1]);
const pick = async (page, n) => {
    await page.getByRole('button', { name: new RegExp(`^Question ${n}(,|$)`) }).first().click(); // question grid
    await page.getByText(`Question ${n}: which is ${n}?`).waitFor();
    await page.getByRole('radio').nth(1).click();
};

let ctx = await launch();
try {
    let page = ctx.pages()[0] ?? (await ctx.newPage());
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(base + '/login');
    await page.fill('input[name=email]', fx.email);
    await page.fill('input[name=password]', fx.password);
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);

    await page.goto(`${base}/my/exams/${fx.candidate}`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Start exam' }).click();
    await page.getByText('Question 1: which is 1?').waitFor({ timeout: 20000 });
    await pick(page, 1);
    await page.getByText('Saved', { exact: true }).waitFor({ timeout: 15000 });
    check((await php('check', String(fx.candidate))).answers === 1, 'online answer reaches the server', 'not saved');
    await sleep(1500); // the service worker keeps the page and its files

    // 1. network off
    await ctx.setOffline(true);
    await pick(page, 2);
    await pick(page, 3);
    const banner = page.getByText('No connection to the exam server.');
    await banner.waitFor({ timeout: 15000 }).then(() => ok('red notice when the connection is lost'), () => fail('offline notice', 'not shown'));
    await shot(page, '1_offline');
    check((await answered(page)) === 3, 'candidate keeps answering offline', `answered ${await answered(page)}`);
    check((await php('check', String(fx.candidate))).answers === 1, 'offline answers wait on this computer', 'reached the server while offline?');

    // 2. reload while offline
    page.removeAllListeners('dialog');
    page.on('dialog', (d) => d.accept());
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
    const resume = page.getByRole('button', { name: 'Continue my exam' }).or(page.getByRole('button', { name: 'Continue exam' })).first();
    await resume.waitFor({ timeout: 20000 }).then(() => ok('the exam page reopens without a connection'), () => fail('offline reload', 'page did not open'));
    await shot(page, '2_reloaded_offline');
    await resume.click().catch(() => {});
    await page.getByText(/Question \d: which is/).first().waitFor({ timeout: 20000 }).catch(() => {});
    check((await answered(page).catch(() => 0)) === 3, 'answers written offline are still there after the reload', `answered ${await answered(page).catch(() => 0)}`);
    await pick(page, 4).catch((e) => fail('answer after offline reload', e.message.split('\n')[0]));

    // 3. back online
    await ctx.setOffline(false);
    await page.getByText(/Connected again/).waitFor({ timeout: 20000 }).then(() => ok('"Connected again" when the network returns'), () => fail('back online', 'no notice'));
    await sleep(4000);
    const synced = await php('check', String(fx.candidate));
    check(synced.answers === 4, 'everything written offline reaches the server', `server has ${synced.answers} of 4`);
    await shot(page, '3_back_online');

    // 5. strike: leaving the exam screen (the first seconds after opening, while the browser asks for
    // permissions, do not count, so wait past them)
    await sleep(12000);
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await sleep(300);
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event('focus'));
    });
    await page.getByText(/Warning 1 of 5/).waitFor({ timeout: 10000 }).then(() => ok('leaving the screen shows "Warning 1 of 5"'), () => fail('strike', 'no warning shown'));
    await shot(page, '5_strike');
    await page.getByRole('button', { name: 'Return to my exam' }).click().catch(() => {});
    await sleep(7000);
    check((await php('check', String(fx.candidate))).violations === 1, 'the warning is counted on the server', 'not counted');

    // 4. power cut: an unsent answer, then the browser dies without closing the page
    await ctx.setOffline(true);
    await pick(page, 5);
    await sleep(1500);
    errors.filter((e) => !/getUserMedia|NotAllowed|Permission/i.test(e)).forEach((e) => fail('script error', e));
    const pids = execSync(`pgrep -f -- "${profile}" || true`).toString().trim().split(/\s+/).filter(Boolean);
    pids.forEach((p) => {
        try {
            process.kill(Number(p), 'SIGKILL');
        } catch {
            // already gone
        }
    });
    ok(`browser killed with an unsent answer (${pids.length} processes)`);
    await sleep(40000);

    ctx = await launch();
    page = ctx.pages()[0] ?? (await ctx.newPage());
    await page.goto(`${base}/my/exams/${fx.candidate}`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Continue exam' }).click({ timeout: 20000 });
    await page.getByText(/Question \d: which is/).first().waitFor({ timeout: 20000 });
    await sleep(6000);
    const after = await php('check', String(fx.candidate));
    check(after.answers === 5, 'the answer written just before the power cut survives and is sent', `server has ${after.answers} of 5`);
    check(after.device_off_seconds >= 30 && after.device_off_seconds <= 90, 'the time the computer was off is given back', `credited ${after.device_off_seconds} s`);
    check((await answered(page)) === 5, 'the paper shows every answer after the restart', `answered ${await answered(page)}`);
    await shot(page, '4_after_power_cut');
    await ctx.close();

    // 6. time runs out while the computer is offline: nothing more can be written, the answers wait on
    //    the computer, and are delivered when the connection returns
    const sx = php('short');
    ctx = await launch();
    page = ctx.pages()[0] ?? (await ctx.newPage());
    await page.goto(base + '/login');
    if (page.url().includes('/login')) {
        await page.fill('input[name=email]', sx.email);
        await page.fill('input[name=password]', sx.password);
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
    } else {
        await page.context().clearCookies();
        await page.goto(base + '/login');
        await page.fill('input[name=email]', sx.email);
        await page.fill('input[name=password]', sx.password);
        await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
    }
    await page.goto(`${base}/my/exams/${sx.candidate}`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Start exam' }).click();
    await page.getByText('Question 1: which is 1?').waitFor({ timeout: 20000 });
    await pick(page, 1);
    await sleep(2000);
    await ctx.setOffline(true);
    await pick(page, 2);
    await page.getByText('Time is up.').waitFor({ timeout: 90000 });
    await shot(page, '6_time_up_offline');
    check(await page.getByText(/Your answers are saved on this computer and will be sent automatically/).count() > 0, 'time up while offline: the candidate is told the answers are safe', 'no message');
    await ctx.setOffline(false);
    await page.getByText(/submitted|Submitted|received/i).first().waitFor({ timeout: 40000 }).catch(() => {});
    await sleep(3000);
    const end = await php('check', String(sx.candidate));
    check(end.answers === 2 && ['submitted', 'auto_submitted'].includes(end.status), 'the answer written offline before time-up is kept and the exam is submitted', JSON.stringify(end));
    await shot(page, '6_done');
} catch (e) {
    fail('run', e.message.split('\n').slice(0, 2).join(' | '));
} finally {
    await ctx.close().catch(() => {});
    fs.rmSync(profile, { recursive: true, force: true });
}

console.log(`\n${passes.length} passed, ${failures.length} failed`);
failures.forEach((f) => console.log('  ✗ ' + f));
process.exit(failures.length ? 1 : 0);
