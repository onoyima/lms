'use strict';
/**
 * Device identity and the signed protocol with the exam platform.
 *  - Each computer has an Ed25519 key pair, created on first run. The private key never leaves
 *    the machine and is encrypted with the operating system's key store (Electron safeStorage).
 *  - Requests to the exam API are signed: METHOD \n /path \n unix-seconds \n sha256(body)
 *    (file uploads sign the word "unsigned" instead of the body hash).
 *  - The platform's /.well-known/exam-platform.json is signed by the platform; its key is pinned on
 *    first use, so a look-alike server cannot impersonate it later.
 */
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

class Identity {
    constructor(dir, safeStorage) {
        this.file = path.join(dir, 'device.json');
        this.safe = safeStorage && safeStorage.isEncryptionAvailable() ? safeStorage : null;
        this.load();
    }

    load() {
        if (fs.existsSync(this.file)) {
            const d = JSON.parse(fs.readFileSync(this.file, 'utf8'));
            const pem = d.encrypted && this.safe ? this.safe.decryptString(Buffer.from(d.key, 'base64')) : d.key;
            this.deviceId = d.deviceId;
            this.privateKey = crypto.createPrivateKey(pem);
            this.pinnedServerKey = d.pinnedServerKey || null;
            return;
        }
        const { privateKey } = crypto.generateKeyPairSync('ed25519');
        this.deviceId = 'vtd-' + crypto.randomBytes(24).toString('hex');
        this.privateKey = privateKey;
        this.pinnedServerKey = null;
        this.save();
    }

    save() {
        const pem = this.privateKey.export({ type: 'pkcs8', format: 'pem' });
        const key = this.safe ? this.safe.encryptString(pem).toString('base64') : pem;
        fs.mkdirSync(path.dirname(this.file), { recursive: true });
        fs.writeFileSync(this.file, JSON.stringify({ deviceId: this.deviceId, key, encrypted: !!this.safe, pinnedServerKey: this.pinnedServerKey }), { mode: 0o600 });
    }

    /** Raw 32-byte public key, base64 — what the platform stores. */
    publicKeyRaw() {
        const der = crypto.createPublicKey(this.privateKey).export({ type: 'spki', format: 'der' });
        return der.subarray(der.length - 32).toString('base64');
    }

    sign(method, urlPath, body) {
        const ts = Math.floor(Date.now() / 1000);
        const bodyHash = body === 'unsigned' ? 'unsigned' : crypto.createHash('sha256').update(body || Buffer.alloc(0)).digest('hex');
        const message = `${method.toUpperCase()}\n/${urlPath.replace(/^\/+/, '')}\n${ts}\n${bodyHash}`;
        const signature = crypto.sign(null, Buffer.from(message), this.privateKey).toString('base64');
        const headers = { 'X-Platform-Device': this.deviceId, 'X-Platform-Timestamp': String(ts), 'X-Platform-Signature': signature };
        if (body === 'unsigned') headers['X-Platform-Body'] = 'unsigned';
        return headers;
    }

    /** Check the platform's signed description; pin its key the first time. */
    verifyWellKnown(doc) {
        if (!doc || doc.algorithm !== 'ed25519' || typeof doc.payload !== 'string') throw new Error('The server did not identify itself as an exam platform.');
        if (this.pinnedServerKey && this.pinnedServerKey !== doc.public_key) {
            throw new Error('This server\'s identity has changed since this computer was set up. Ask the ICT office before continuing.');
        }
        const raw = Buffer.from(doc.public_key, 'base64');
        if (raw.length !== 32) throw new Error('Bad server key.');
        const spki = Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), raw]);
        const ok = crypto.verify(null, Buffer.from(doc.payload), crypto.createPublicKey({ key: spki, format: 'der', type: 'spki' }), Buffer.from(doc.signature, 'base64'));
        if (!ok) throw new Error('The server\'s signature is not valid.');
        const payload = JSON.parse(doc.payload);
        if (!payload.channels || !payload.channels.includes('desktop')) throw new Error('This platform does not accept the desktop app.');
        if (!this.pinnedServerKey) {
            this.pinnedServerKey = doc.public_key;
            this.save();
        }
        return payload;
    }
}

module.exports = { Identity };
