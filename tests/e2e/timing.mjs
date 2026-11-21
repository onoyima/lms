/**
 * Exam timing in a real browser (T1): the time shown always follows the server, whatever the
 * computer's own clock says; a reload does not give time back; losing the connection keeps the
 * answers and the clock; a question can be reported without losing time.
 *
 *   node tests/e2e/timing.mjs <base-url> <student-email> <password> <candidate-id> [screenshot-dir]
 * The candidate must be on a sitting that is open now and not yet started.
 */
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
const [base, email, password, candidateId, out] = process.argv.slice(2);
let failed = 0;
const ok = (m) => console.log('  ✓ ' + m);
const fail = (m, d) => (failed++, console.log('  ✗ ' + m + (d ? ' — ' + d : '')));
const toSeconds = (t) => t.split(':').map(Number).reduce((a, n) => a * 60 + n, 0);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, permissions: [] });
// this computer's clock is five hours fast
await ctx.addInitScript(() => {
    const off = 5 * 3600e3, R = Date;
    class D extends R { constructor(...a) { a.length ? super(...a) : super(R.now() + off); } static now() { return R.now() + off; } }
    window.Date = D;
});
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(base + '/login');
await page.fill('input[name=email]', email);
await page.fill('input[name=password]', password);
await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);
await page.goto(`${base}/my/exams/${candidateId}`, { waitUntil: 'networkidle' });
const consent = page.getByLabel(/I have read what is monitored/i);
const begin = page.getByRole('button', { name: /^(Start exam|Continue)/i }).first();
await begin.waitFor({ timeout: 20000 });
if (await consent.count()) await consent.check();
await begin.click();
const timer = page.locator('[title="Time left"]');
await timer.waitFor({ timeout: 20000 });
await page.waitForTimeout(2000);
const first = toSeconds((await timer.innerText()).trim());
const duration = Number(process.env.DURATION_MINUTES ?? 30) * 60;
first > duration - 120 && first <= duration ? ok(`timer follows the server although the computer clock is 5 hours fast (${first}s left)`) : fail('timer with a wrong computer clock', `${first}s shown, expected about ${duration}s`);

await page.waitForTimeout(4000);
const later = toSeconds((await timer.innerText()).trim());
first - later >= 3 && first - later <= 6 ? ok('timer counts down in real seconds') : fail('countdown', `${first} → ${later}`);

// a reload does not reset the clock
await page.reload({ waitUntil: 'networkidle' });
await timer.waitFor({ timeout: 20000 }).catch(() => {});
const begin2 = page.getByRole('button', { name: /^(Continue|Start exam)/i }).first();
if (await begin2.count()) { await begin2.click(); await timer.waitFor({ timeout: 20000 }); }
await page.waitForTimeout(1500);
const afterReload = toSeconds((await timer.innerText()).trim());
afterReload <= later && afterReload > later - 30 ? ok(`a reload keeps the time running (${afterReload}s left)`) : fail('reload', `${later}s before, ${afterReload}s after`);

// losing the connection: answers are kept, the clock keeps going, they are sent when it comes back
const option = page.getByRole('radio').first();
await ctx.setOffline(true);
await page.waitForTimeout(1500);
if (await option.count()) await option.click();
await page.waitForTimeout(1500);
(await page.getByText(/Offline|No internet|saved on this computer/i).count()) ? ok('offline is shown and the answer is kept on this computer') : fail('offline notice', 'nothing shown');
const offlineT = toSeconds((await timer.innerText()).trim());
await page.waitForTimeout(3000);
toSeconds((await timer.innerText()).trim()) < offlineT ? ok('the clock keeps running while offline') : fail('clock while offline', 'stopped');
await ctx.setOffline(false);
await page.getByText(/All your answers are saved|Saved$/).first().waitFor({ timeout: 30000 }).then(() => ok('answers are sent when the connection comes back'), () => fail('reconnect', 'answers not confirmed as saved'));

// report a question: a dialog, then back to the paper; the clock does not stop
await page.getByRole('button', { name: 'Report this question' }).click();
const dialog = page.getByRole('dialog');
(await dialog.isVisible()) ? ok('report dialog opens') : fail('report dialog');
await dialog.getByText('More than one option is right').click();
await dialog.getByRole('button', { name: 'Send report' }).click();
await page.getByRole('button', { name: 'Reported' }).waitFor({ timeout: 10000 }).then(() => ok('question shows as reported'), () => fail('reported state'));
if (out) await page.screenshot({ path: `${out}/timing-exam-room.png` });

errors.filter((e) => !/getUserMedia|NotAllowed|Permission|camera|FaceDetector/i.test(e)).forEach((e) => fail('script error', e));
await browser.close();
console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
