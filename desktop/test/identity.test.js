'use strict';
const test = require('node:test');
const assert = require('node:assert');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { Identity } = require('../src/identity');

const dir = () => fs.mkdtempSync(path.join(os.tmpdir(), 'vt-'));

test('device keys persist and the raw public key is 32 bytes', () => {
    const d = dir();
    const a = new Identity(d, null);
    const b = new Identity(d, null);
    assert.equal(a.deviceId, b.deviceId);
    assert.equal(Buffer.from(a.publicKeyRaw(), 'base64').length, 32);
    assert.equal(a.publicKeyRaw(), b.publicKeyRaw());
});

test('signatures verify with the platform check (PHP libsodium)', { skip: !hasPhp() }, () => {
    const id = new Identity(dir(), null);
    const body = Buffer.from('{"pin":null,"consent":true}');
    const h = id.sign('post', '/my/exams/12/start', body);
    const msg = `POST\n/my/exams/12/start\n${h['X-Platform-Timestamp']}\n${crypto.createHash('sha256').update(body).digest('hex')}`;
    const php = `echo sodium_crypto_sign_verify_detached(base64_decode($argv[1]), base64_decode($argv[2]), base64_decode($argv[3])) ? 'yes' : 'no';`;
    const out = execFileSync('php', ['-r', php, h['X-Platform-Signature'], Buffer.from(msg).toString('base64'), id.publicKeyRaw()]).toString();
    assert.equal(out, 'yes');
    const u = id.sign('POST', 'api/exam/attempt/evidence', 'unsigned');
    assert.equal(u['X-Platform-Body'], 'unsigned');
});

test('the platform key is pinned on first use and a different key is refused', () => {
    const id = new Identity(dir(), null);
    const server = crypto.generateKeyPairSync('ed25519');
    const raw = (k) => { const der = k.export({ type: 'spki', format: 'der' }); return der.subarray(der.length - 32).toString('base64'); };
    const doc = (keys) => {
        const payload = JSON.stringify({ version: 1, channels: ['web', 'desktop', 'seb'], institution: { name: 'Example University' } });
        return { payload, algorithm: 'ed25519', public_key: raw(keys.publicKey), signature: crypto.sign(null, Buffer.from(payload), keys.privateKey).toString('base64') };
    };
    assert.equal(id.verifyWellKnown(doc(server)).institution.name, 'Example University');
    const tampered = doc(server);
    tampered.payload = tampered.payload.replace('Example University', 'Evil');
    assert.throws(() => id.verifyWellKnown(tampered), /signature/);
    assert.throws(() => id.verifyWellKnown(doc(crypto.generateKeyPairSync('ed25519'))), /changed/);
});

function hasPhp() {
    try { execFileSync('php', ['-v']); return true; } catch { return false; }
}
