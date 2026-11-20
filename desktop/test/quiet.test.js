const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { Quiet } = require('../src/quiet');

function fake(initial) {
    const calls = [];
    let value = initial;
    return {
        calls,
        get value() { return value; },
        impl: {
            read: async () => value,
            quiet: async () => { calls.push('quiet'); value = false; },
            restore: async (prev) => { calls.push('restore:' + prev); value = prev; },
        },
    };
}

test('notifications are switched off for the exam and the previous setting comes back', async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'quiet-'));
    const f = fake(true);
    const q = new Quiet(dir, 'test', f.impl);
    await q.start();
    assert.strictEqual(f.value, false);
    assert.ok(fs.existsSync(path.join(dir, 'quiet.json')));
    await q.stop();
    assert.strictEqual(f.value, true);
    assert.ok(!fs.existsSync(path.join(dir, 'quiet.json')));
});

test('a user who had notifications off keeps them off', async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'quiet-'));
    const f = fake(false);
    const q = new Quiet(dir, 'test', f.impl);
    await q.start();
    await q.stop();
    assert.strictEqual(f.value, false);
});

test('after a power cut during an exam the next start puts the setting back', async () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'quiet-'));
    const f = fake(true);
    await new Quiet(dir, 'test', f.impl).start(); // computer goes off here
    assert.strictEqual(f.value, false);
    await new Quiet(dir, 'test', f.impl).recover();
    assert.strictEqual(f.value, true);
});
