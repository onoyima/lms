'use strict';
/**
 * Secure Exam — the secure desktop app (Target 26-31, 75, 104, 113, 163, 191, 196-197).
 *
 *  - One full-screen kiosk window that only shows the institution's platform.
 *  - The window's contents are hidden from screenshot and screen-recording tools.
 *  - Developer tools, reload, new windows, other sites and downloads are blocked.
 *  - Every exam request is signed with this computer's Ed25519 key; the platform only accepts
 *    approved computers, and an exam started here can only continue here.
 *  - Hardware and software checks (screens, HDMI/wireless display, USB, VMs, remote access)
 *    are reported to the exam as events for proctors to review.
 *  - The app can only be closed when no exam is running (or with the exam's quit password).
 */
const { app, BrowserWindow, dialog, ipcMain, safeStorage, screen, session } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const { Identity } = require('./identity');
const { startMonitor } = require('./monitor');

const DEV = process.env.EXAM_DESKTOP_DEV === '1';
const configFile = () => path.join(app.getPath('userData'), 'config.json');
const readConfig = () => { try { return JSON.parse(fs.readFileSync(configFile(), 'utf8')); } catch { return null; } };
const writeConfig = (c) => fs.writeFileSync(configFile(), JSON.stringify(c, null, 2), { mode: 0o600 });

let win = null;
let identity = null;
let origin = null;          // e.g. https://exams.example.edu.ng
let inExam = false;         // the exam page says an attempt is open
let closeApps = false;      // the exam asks the app to close remote-control and capture apps
let stopMonitor = null;
const bodies = new Map();   // request id → body bytes, for signing

if (!app.requestSingleInstanceLock()) {
    app.quit();
}
app.commandLine.appendSwitch('disable-features', 'HardwareMediaKeyHandling');
app.on('second-instance', () => win && (win.restore(), win.focus()));

function lockDown(w) {
    w.setContentProtection(true); // screenshots/recordings of this window come out black
    w.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    w.webContents.on('will-navigate', (e, url) => { if (!url.startsWith(origin + '/') && url !== origin && !isSsoUrl(url)) e.preventDefault(); });
    w.webContents.on('will-redirect', (e, url) => { if (!url.startsWith(origin + '/') && !isSsoUrl(url)) e.preventDefault(); });
    w.webContents.on('before-input-event', (e, input) => {
        const k = input.key.toLowerCase();
        const mod = input.control || input.meta;
        const blocked = (!DEV && (k === 'f12' || (mod && input.shift && ['i', 'j', 'c'].includes(k)))) || k === 'f5' || (mod && ['r', 'p', 's', 'o', 'n', 't', 'w', 'q'].includes(k)) || (input.alt && k === 'f4' && inExam);
        if (blocked) e.preventDefault();
    });
    if (!DEV) w.webContents.on('devtools-opened', () => { w.webContents.closeDevTools(); report('devtools_open', {}); });
    w.on('blur', () => inExam && report('focus_lost', { source: 'desktop' }));
    w.on('leave-full-screen', () => { if (inExam) { report('fullscreen_exit', { source: 'desktop' }); w.setFullScreen(true); } });
    w.on('close', (e) => {
        if (!inExam) return;
        e.preventDefault();
        dialog.showMessageBox(w, { type: 'warning', message: 'Your exam is still open.', detail: 'Submit your exam first. If you need to leave, ask the invigilator.', buttons: ['Back to the exam'] });
    });
}

// The institution may use single sign-on; allow its identity provider pages during sign-in only.
let ssoHosts = [];
const isSsoUrl = (url) => { try { return !inExam && ssoHosts.includes(new URL(url).host); } catch { return false; } };

function report(type, data) {
    if (win && !win.isDestroyed()) win.webContents.send('desktop:event', { type, data });
}

function signRequests() {
    const ses = session.defaultSession;
    const filter = { urls: [origin + '/*'] };
    ses.webRequest.onBeforeRequest(filter, (d, cb) => {
        if (d.uploadData && d.uploadData.length) {
            // JSON bodies arrive as bytes; uploads with files cannot be hashed here, so they are marked "unsigned"
            const allBytes = d.uploadData.every((p) => p.bytes);
            bodies.set(d.id, allBytes ? Buffer.concat(d.uploadData.map((p) => p.bytes)) : 'unsigned');
        }
        cb({});
    });
    ses.webRequest.onBeforeSendHeaders(filter, (d, cb) => {
        const u = new URL(d.url);
        const exam = /^\/api\/exam\/attempt\//.test(u.pathname) || /^\/my\/exams\/\d+\/start$/.test(u.pathname);
        if (exam) {
            const body = bodies.get(d.id);
            bodies.delete(d.id);
            Object.assign(d.requestHeaders, identity.sign(d.method, u.pathname, body === undefined ? '' : body), { 'X-Platform-Client': 'desktop/' + app.getVersion() });
        }
        cb({ requestHeaders: d.requestHeaders });
    });
    ses.on('will-download', (e) => e.preventDefault());
    ses.setPermissionRequestHandler((wc, permission, cb, details) => {
        const ours = (details.requestingUrl || '').startsWith(origin + '/');
        cb(ours && ['media', 'display-capture', 'fullscreen', 'clipboard-sanitized-write'].includes(permission));
    });
    ses.setDisplayMediaRequestHandler((req, cb) => {
        // the exam may ask for the whole screen; give it the primary screen only, never a single window
        const { desktopCapturer } = require('electron');
        desktopCapturer.getSources({ types: ['screen'] }).then((s) => cb({ video: s[0] }));
    });
}

async function connect(url) {
    const base = new URL(url);
    if (base.protocol !== 'https:' && !DEV && !/^(localhost|127\.0\.0\.1|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(base.hostname)) {
        throw new Error('The platform address must use https (or be on the campus network).');
    }
    const res = await fetch(new URL('/.well-known/exam-platform.json', base));
    if (!res.ok) throw new Error('This address is not an exam platform.');
    const payload = identity.verifyWellKnown(await res.json());
    const enrol = await fetch(new URL('/api/devices/enrol', base), {
        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ device_id: identity.deviceId, public_key: identity.publicKeyRaw(), label: require('node:os').hostname().slice(0, 100) }),
    });
    const status = enrol.ok ? (await enrol.json()).status : 'error';
    return { origin: base.origin, institution: payload.institution, status, ssoHosts: payload.sso_hosts || [] };
}

function setupWindow(message) {
    const w = new BrowserWindow({ width: 560, height: 520, resizable: false, title: 'Secure Exam — set up', webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true } });
    w.removeMenu();
    w.loadFile(path.join(__dirname, 'setup.html'), { query: { device: identity.deviceId, message: message || '' } });
    return w;
}

function examWindow() {
    win = new BrowserWindow({
        fullscreen: !DEV, kiosk: !DEV, autoHideMenuBar: true, backgroundColor: '#0b0d10', title: 'Secure Exam',
        webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true, nodeIntegration: false, spellcheck: false, devTools: DEV },
    });
    win.removeMenu();
    lockDown(win);
    win.loadURL(origin + '/my/exams');
    stopMonitor = startMonitor(screen, report, 15000, () => closeApps);
}

ipcMain.handle('desktop:setup', async (_e, url) => {
    try {
        const c = await connect(url);
        writeConfig({ origin: c.origin, ssoHosts: c.ssoHosts });
        return { ok: true, ...c, deviceId: identity.deviceId };
    } catch (err) {
        return { ok: false, message: err.message };
    }
});
ipcMain.handle('desktop:continue', () => {
    const c = readConfig();
    if (!c) return false;
    origin = c.origin;
    ssoHosts = c.ssoHosts || [];
    signRequests();
    BrowserWindow.getAllWindows().forEach((w) => w.close());
    examWindow();
    return true;
});
ipcMain.on('desktop:exam-state', (_e, state, opts) => { inExam = state === 'open'; closeApps = inExam && Boolean(opts && opts.closeApps); });
ipcMain.handle('desktop:info', () => ({ version: app.getVersion(), deviceId: identity.deviceId, platform: process.platform }));
ipcMain.on('desktop:found', (_e, type, data) => { if (['camera_virtual'].includes(type)) report(type, data); });
ipcMain.on('desktop:quit', () => { if (!inExam) app.quit(); });

app.whenReady().then(async () => {
    identity = new Identity(app.getPath('userData'), safeStorage);
    const c = readConfig();
    if (!c) return setupWindow();
    origin = c.origin;
    ssoHosts = c.ssoHosts || [];
    try {
        const status = (await connect(origin)).status;
        if (status !== 'approved') return setupWindow(status === 'pending' ? 'This computer is waiting for approval by the ICT office.' : 'Could not register this computer.');
    } catch (err) {
        return setupWindow(err.message);
    }
    signRequests();
    examWindow();
});
app.on('web-contents-created', (_e, wc) => wc.on('will-attach-webview', (e) => e.preventDefault()));
app.on('window-all-closed', () => { stopMonitor && stopMonitor(); app.quit(); });
