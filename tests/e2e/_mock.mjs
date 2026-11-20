import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
const [dir, ...names] = process.argv.slice(2);
const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
for (const n of names) { await p.goto(`file://${dir}/${n}.html`); await p.waitForTimeout(300); await p.screenshot({ path: `${dir}/${n}.png` }); }
await b.close();
