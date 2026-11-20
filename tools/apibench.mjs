// Throughput of the exam API hot path: many candidates' tokens, N requests in flight at once.
//   node tools/apibench.mjs http://127.0.0.1 storage/app/loadtest.json 30 40   (seconds, concurrency)
import { readFileSync } from 'node:fs';

const [base = 'http://127.0.0.1', file = 'storage/app/loadtest.json', secArg = '30', concArg = '40', kind = 'mixed'] = process.argv.slice(2);
const fx = JSON.parse(readFileSync(file, 'utf8'));
const opts = JSON.parse(readFileSync(file + '.options', 'utf8'));
const end = Date.now() + Number(secArg) * 1000;
const t = [];
let fail = 0, codes = {}, i = 0, seq = 1000;

async function one() {
    const c = fx.candidates[i++ % fx.candidates.length];
    const save = kind === 'answers' || (kind === 'mixed' && Math.random() < 0.6);
    const qid = c.questions[Math.floor(Math.random() * c.questions.length)];
    const body = save
        ? { answers: [{ question_id: qid, seq: seq++, flagged: false, response: { options: [opts[qid][0]] } }] }
        : { status: 'active', events: [] };
    const s = performance.now();
    try {
        const r = await fetch(`${base}/api/exam/attempt/${save ? 'answers' : 'heartbeat'}`, { method: 'POST', headers: { Authorization: `Bearer ${c.token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        await r.arrayBuffer();
        codes[r.status] = (codes[r.status] ?? 0) + 1;
        if (!r.ok) fail++;
    } catch { fail++; codes.err = (codes.err ?? 0) + 1; }
    t.push(performance.now() - s);
}
const started = Date.now();
await Promise.all(Array.from({ length: Number(concArg) }, async () => { while (Date.now() < end) await one(); }));
t.sort((a, b) => a - b);
const p = (q) => t[Math.min(t.length - 1, Math.floor(t.length * q))].toFixed(0);
console.log(`${t.length} requests in ${((Date.now() - started) / 1000).toFixed(1)} s = ${(t.length / ((Date.now() - started) / 1000)).toFixed(0)} req/s · failed ${fail} · p50 ${p(0.5)} p95 ${p(0.95)} p99 ${p(0.99)} ms · ${JSON.stringify(codes)}`);
