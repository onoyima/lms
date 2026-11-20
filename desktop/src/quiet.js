/*
 * Quiet mode while an exam is open: no pop-up notifications from other apps, the system or a
 * linked phone appear over the exam. The previous setting is saved first and put back when the
 * exam closes, and again on the next start if the computer went off during the exam.
 *
 *  - Windows: turns off toast notifications for the signed-in user (the "Notifications" switch in
 *    Settings, which also covers Phone Link), then restores it.
 *  - Linux (GNOME): hides notification banners, then restores them.
 *  - macOS: the exam window sits above notification banners (screen-saver level); the app's kiosk
 *    mode already hides the Dock and menu bar and blocks app switching.
 *
 * Nothing here needs administrator rights, and nothing is changed outside the user's own settings.
 */
const { execFile } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const WIN_KEY = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\PushNotifications';

function run(cmd, args) {
    return new Promise((resolve) => {
        execFile(cmd, args, { windowsHide: true, timeout: 8000 }, (err, stdout) => resolve(err ? null : String(stdout)));
    });
}

async function readWindows() {
    const out = await run('reg', ['query', WIN_KEY, '/v', 'ToastEnabled']);
    const m = out && /ToastEnabled\s+REG_DWORD\s+0x([0-9a-f]+)/i.exec(out);
    return m ? parseInt(m[1], 16) : 1; // missing value means notifications are on
}

const platforms = {
    win32: {
        read: readWindows,
        quiet: () => run('reg', ['add', WIN_KEY, '/v', 'ToastEnabled', '/t', 'REG_DWORD', '/d', '0', '/f']),
        restore: (prev) => run('reg', ['add', WIN_KEY, '/v', 'ToastEnabled', '/t', 'REG_DWORD', '/d', String(prev ? 1 : 0), '/f']),
    },
    linux: {
        read: async () => {
            const out = await run('gsettings', ['get', 'org.gnome.desktop.notifications', 'show-banners']);
            return out === null ? null : out.trim() !== 'false';
        },
        quiet: () => run('gsettings', ['set', 'org.gnome.desktop.notifications', 'show-banners', 'false']),
        restore: (prev) => run('gsettings', ['set', 'org.gnome.desktop.notifications', 'show-banners', prev ? 'true' : 'false']),
    },
};

class Quiet {
    constructor(dir, platform = process.platform, impl = platforms[platform]) {
        this.file = path.join(dir, 'quiet.json');
        this.impl = impl || null;
        this.on = false;
    }

    /** Put back a setting left changed when the computer went off during an exam. */
    async recover() {
        let saved = null;
        try { saved = JSON.parse(fs.readFileSync(this.file, 'utf8')); } catch { return; }
        if (this.impl && saved && saved.prev !== null && saved.prev !== undefined) {
            await this.impl.restore(saved.prev);
        }
        try { fs.unlinkSync(this.file); } catch { /* already gone */ }
    }

    async start() {
        if (this.on || !this.impl) { this.on = true; return; }
        this.on = true;
        const prev = await this.impl.read();
        if (prev === null) return; // this desktop has no such setting
        fs.writeFileSync(this.file, JSON.stringify({ prev, at: new Date().toISOString() }), { mode: 0o600 });
        await this.impl.quiet();
    }

    async stop() {
        if (!this.on) return;
        this.on = false;
        await this.recover();
    }
}

module.exports = { Quiet, platforms };
