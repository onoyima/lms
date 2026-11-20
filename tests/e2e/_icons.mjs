import { readFileSync, writeFileSync } from 'node:fs';
const names = process.argv.slice(3);
const out = {};
for (const n of names) {
  const src = readFileSync(`node_modules/lucide-react/dist/esm/icons/${n}.js`, 'utf8');
  const m = src.match(/const __iconNode = (\[[\s\S]*?\]);\nconst/);
  const node = eval(m[1]);
  out[n] = node.map(([tag, attrs]) => `<${tag} ${Object.entries(attrs).filter(([k]) => k !== 'key').map(([k, v]) => `${k}="${v}"`).join(' ')}/>`).join('');
}
writeFileSync(process.argv[2], 'window.ICONS=' + JSON.stringify(out));
