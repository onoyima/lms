// Setting up an exam category in a real browser: New exam category → name → add an objective and a
// viva exam → Create → the category page → students for the whole category → open a step → downloads.
//   NODE_PATH="$(npm root -g)" node tests/e2e/exam-setup.mjs http://127.0.0.1:8200
// Uses the demo data (php artisan db:seed --class=DemoSeeder). Never run against the live site.
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.argv[2] ?? 'http://127.0.0.1:8200').replace(/\/$/, '');
const out = process.env.E2E_SHOTS || '';
const passes = [];
const failures = [];
const ok = (n) => (passes.push(n), console.log('  ✓ ' + n));
const fail = (n, why) => (failures.push(`${n}: ${why}`), console.log('  ✗ ' + n + ' — ' + why));
const shot = async (page, name) => out && page.screenshot({ path: `${out}/setup_${name}.png`, fullPage: true });

const browser = await chromium.launch();
try {
    const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(base + '/login');
    await page.fill('input[name=email]', 'examofficer@veritas.local');
    await page.fill('input[name=password]', 'ChangeMe!2026');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')]);

    await page.goto(base + '/dashboard', { waitUntil: 'networkidle' });
    await page.getByRole('link', { name: /New exam/ }).first().click();
    await page.waitForURL(/\/exams\/new/);
    await page.getByLabel('Category name').fill('Pharmacy Board Exam');
    await page.getByRole('button', { name: /^Objective Multiple choice/ }).click();
    await page.getByRole('button', { name: /^Viva Oral exam/ }).click();
    const when = new Date(Date.now() + 86400000);
    const local = new Date(when.getTime() - when.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    await page.locator('input[type=datetime-local]').first().fill(local);
    await page.locator('input[type=datetime-local]').nth(1).fill(local);
    await shot(page, 'new');
    await page.getByRole('button', { name: 'Create category' }).click();
    await page.waitForURL(/\/exam-groups\/\d+/, { timeout: 15000 });
    (await page.getByRole('heading', { name: 'Pharmacy Board Exam' }).count()) ? ok('category created with its exams') : fail('create', 'no overview');
    (await page.getByRole('heading', { name: 'Objective' }).count()) && (await page.getByRole('heading', { name: 'Viva' }).count()) ? ok('both exams listed') : fail('exams', 'missing');

    await page.getByRole('button', { name: 'Add students' }).first().click();
    await page.getByLabel(/Matric numbers or e-mails/).first().check().catch(() => page.getByText('Matric numbers or e-mails').first().click());
    await page.getByRole('textbox', { name: /Matric numbers or e-mails/ }).fill('student@veritas.local');
    await page.getByRole('button', { name: 'Add to the category' }).click();
    await page.getByText(/added to the category/).waitFor({ timeout: 10000 });
    ok('students added to the whole category');
    (await page.getByText('1 assigned').count()) >= 2 ? ok('each exam shows its student') : fail('students', 'counts not updated');
    (await page.getByText('Combined results').count()) && (await page.getByRole('link', { name: 'Excel' }).count()) ? ok('combined results downloads offered') : fail('combined results', 'missing');
    await shot(page, 'overview');

    await page.getByRole('link', { name: /Next: add questions/ }).first().click();
    await page.waitForURL(/\/exams\/\d+\?tab=paper/);
    (await page.getByText('Section A').count()) ? ok('next step opens the paper on its questions tab') : fail('next step', 'paper tab not shown');
    (await page.getByRole('link', { name: 'Pharmacy Board Exam' }).count()) ? ok('exam links back to its category') : fail('back link', 'missing');
    errors.length ? fail('script errors', errors[0]) : ok('no script errors');
    await ctx.close();
} catch (e) {
    fail('run', e.message.split('\n').slice(0, 2).join(' | '));
} finally {
    await browser.close();
}
console.log(`\n${passes.length} passed, ${failures.length} failed`);
process.exit(failures.length ? 1 : 0);
