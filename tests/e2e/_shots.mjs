import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
const [base, email, out, ...paths] = process.argv.slice(2);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
const p = await ctx.newPage();
await p.goto(base + '/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', 'ChangeMe!2026');
await Promise.all([p.waitForURL((u) => !u.pathname.startsWith('/login')), p.click('button[type=submit]')]);
for (const path of paths) { await p.goto(base + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(400); await p.screenshot({ path: `${out}/${path.replace(/\W+/g, '_')}.png`, fullPage: false }); }
await b.close();
