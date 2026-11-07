// Concurrent-candidate load test for the exam API (Target 95, 137, 244).
// Every virtual candidate behaves like a real one: loads the paper, answers a question
// every few seconds (batched autosave), sends a heartbeat every 15 s, and submits at the end.
//
//   php artisan exams:load-fixture --candidates=1000
//   node tools/loadtest.mjs http://127.0.0.1 storage/app/loadtest.json 300   (seconds)
import { readFileSync } from 'node:fs';

const [base = 'http://127.0.0.1', file = 'storage/app/loadtest.json', secondsArg = '120', thinkArg = '5'] = process.argv.slice(2);
const seconds = Number(secondsArg), think = Number(thinkArg) * 1000;
const fixture = JSON.parse(readFileSync(file, 'utf8'));
const options = JSON.parse(readFileSync(file + '.options', 'utf8'));

const stats = {};
const record = (name, ms, ok) => {
    const s = (stats[name] ??= { n: 0, fail: 0, t: [] });
    s.n++; if (!ok) s.fail++; s.t.push(ms);
};
async function call(name, token, path, method = 'GET', body) {
    const t = performance.now();
    try {
        const res = await fetch(`${base}/api/exam/attempt/${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body && JSON.stringify(body) });
        await res.arrayBuffer();
        record(name, performance.now() - t, res.ok);
        return res.status;
    } catch { record(name, performance.now() - t, false); return 0; }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const end = Date.now() + seconds * 1000;

async function candidate({ token, questions }, i) {
    await sleep(Math.random() * Math.min(30_000, seconds * 250)); // staggered arrival
    await call('paper', token, 'paper');
    let seq = 1, lastBeat = Date.now(), q = 0;
    while (Date.now() < end) {
        await sleep(think * (0.5 + Math.random()));
        const qid = questions[q++ % questions.length];
        const opts = options[qid];
        await call('answers', token, 'answers', 'POST', { answers: [{ question_id: qid, seq: seq++, flagged: false, response: { options: [opts[Math.floor(Math.random() * opts.length)]] } }] });
        if (Date.now() - lastBeat > 15_000) {
            lastBeat = Date.now();
            await call('heartbeat', token, 'heartbeat', 'POST', { status: 'active', events: Math.random() < 0.05 ? [{ type: 'focus_lost', at: new Date().toISOString() }] : [] });
        }
    }
    await call('submit', token, 'submit', 'POST', {});
}

const started = Date.now();
await Promise.all(fixture.candidates.map(candidate));
const pct = (a, p) => a[Math.min(a.length - 1, Math.floor((a.length * p) / 100))];
console.log(`\n${fixture.candidates.length} candidates, ${((Date.now() - started) / 1000).toFixed(0)} s`);
console.log('endpoint     requests  failed   req/s    p50     p95     p99     max  (ms)');
for (const [name, s] of Object.entries(stats)) {
    s.t.sort((a, b) => a - b);
    console.log(`${name.padEnd(12)} ${String(s.n).padStart(8)} ${String(s.fail).padStart(7)} ${(s.n / seconds).toFixed(1).padStart(7)} ${pct(s.t, 50).toFixed(0).padStart(6)} ${pct(s.t, 95).toFixed(0).padStart(7)} ${pct(s.t, 99).toFixed(0).padStart(7)} ${s.t.at(-1).toFixed(0).padStart(7)}`);
}
