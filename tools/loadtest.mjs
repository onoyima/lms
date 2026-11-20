// Concurrent-candidate load test for the exam API (Target 95, 137, 244).
// Every virtual candidate behaves like the real exam room: loads the paper, saves each answer
// shortly after choosing it (with the check-in riding along when one is due), checks in on its
// own only when nothing was saved for a while, follows the server's pace hint, and submits when
// time is up — everyone at the same deadline, spread over a few seconds as the exam room does.
//
//   php artisan exams:load-fixture --candidates=1000
//   node tools/loadtest.mjs http://127.0.0.1 storage/app/loadtest.json 300 20   (seconds, seconds per answer)
//   add "old" as a 5th argument to replay the previous client (separate check-in every 15 s)
import { readFileSync } from 'node:fs';

const [base = 'http://127.0.0.1', file = 'storage/app/loadtest.json', secondsArg = '120', thinkArg = '20', mode = 'new'] = process.argv.slice(2);
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
        const res = await fetch(`${base}/api/exam/attempt/${path}`, { method, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body && JSON.stringify(body), signal: AbortSignal.timeout(60_000) });
        const data = await res.json().catch(() => ({}));
        record(name, performance.now() - t, res.ok);
        return { status: res.status, data };
    } catch { record(name, performance.now() - t, false); return { status: 0, data: {} }; }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const deadline = Date.now() + seconds * 1000;
let paceSeen = { 1: 0, 2: 0, 3: 0, 4: 0 };

async function candidate({ token, questions }) {
    await sleep(Math.random() * Math.min(30_000, seconds * 250)); // staggered arrival
    // like the exam room: keep trying while the server is busy at the start
    let p = await call('paper', token, 'paper');
    for (let n = 1; mode !== 'old' && p.status !== 200 && n < 10; n++) {
        await sleep(Math.min(15000, 1500 * n) + Math.random() * 1000);
        p = await call('paper_retry', token, 'paper');
    }
    if (p.status !== 200) record('paper_never', 0, false);
    let seq = 1, lastBeat = 0, q = 0, pace = 1;
    const beatEvery = 15_000;
    const pending = new Map();
    const beatBody = () => ({ status: 'active', events: Math.random() < 0.05 ? [{ type: 'focus_lost', at: new Date().toISOString() }] : [] });
    const note = (data) => {
        if (data?.attempt) { lastBeat = Date.now(); pace = Math.max(1, Math.min(4, Number(data.attempt.pace ?? 1))); paceSeen[pace]++; }
    };
    while (Date.now() < deadline - 2000) {
        // think, but check in on time if nothing else is sent
        const next = Date.now() + think * (0.5 + Math.random());
        while (Date.now() < Math.min(next, deadline - 2000)) {
            await sleep(1000);
            if (mode === 'old' ? Date.now() - lastBeat > beatEvery : Date.now() - lastBeat > beatEvery * pace) {
                const r = await call('heartbeat', token, 'heartbeat', 'POST', beatBody());
                lastBeat = Date.now();
                note(r.data);
            }
        }
        if (Date.now() >= deadline - 2000) break;
        const qid = questions[q++ % questions.length];
        const opts = options[qid];
        pending.set(qid, { question_id: qid, seq: seq++, flagged: false, response: { options: [opts[Math.floor(Math.random() * opts.length)]] } });
        await sleep(800 * (mode === 'old' ? 1 : pace));
        const withBeat = mode !== 'old' && Date.now() - lastBeat > beatEvery / 2;
        const r = await call('answers', token, 'answers', 'POST', { answers: [...pending.values()], ...(withBeat ? beatBody() : {}) });
        if (r.status === 200) { pending.clear(); note(r.data); }
    }
    // time is up: everyone at once, spread over up to 15 s (the exam room's jitter: half the 30 s grace)
    await sleep(Math.max(0, deadline - Date.now()) + (mode === 'old' ? 0 : Math.random() * 15000));
    let r = await call('submit', token, 'submit', 'POST', { answers: [...pending.values()] });
    for (let i = 0; i < 3 && r.status !== 200 && r.status !== 409; i++) {
        await sleep(3000);
        r = await call('submit_retry', token, 'submit', 'POST', { answers: [...pending.values()] });
    }
    if (r.status !== 200 && r.status !== 409) record('lost_submit', 0, false);
}

const started = Date.now();
await Promise.all(fixture.candidates.map(candidate));
const pct = (a, p) => a[Math.min(a.length - 1, Math.floor((a.length * p) / 100))];
const total = Object.values(stats).reduce((n, s) => n + s.n, 0);
console.log(`\n${fixture.candidates.length} candidates, ${mode} client, ${((Date.now() - started) / 1000).toFixed(0)} s, ${total} requests = ${(total / fixture.candidates.length / seconds).toFixed(3)} requests per candidate per second`);
console.log('endpoint       requests  failed   req/s    p50     p95     p99     max  (ms)');
for (const [name, s] of Object.entries(stats)) {
    s.t.sort((a, b) => a - b);
    console.log(`${name.padEnd(14)} ${String(s.n).padStart(8)} ${String(s.fail).padStart(7)} ${(s.n / seconds).toFixed(1).padStart(7)} ${pct(s.t, 50).toFixed(0).padStart(6)} ${pct(s.t, 95).toFixed(0).padStart(7)} ${pct(s.t, 99).toFixed(0).padStart(7)} ${s.t.at(-1).toFixed(0).padStart(7)}`);
}
console.log('server pace replies (1 = normal … 4 = very busy):', JSON.stringify(paceSeen));
