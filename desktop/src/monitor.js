'use strict';
/**
 * Exam-time environment checks (Target 28-31, 104, 113, 115, 163, 191, 196-197).
 * Everything here is reported to the platform as events for a person to review; nothing is
 * blocked on suspicion alone except what the exam rules say.
 *
 *  - displays: more than one screen, a screen added during the exam, and the connection type
 *    (HDMI/DisplayPort/wireless Miracast) where the operating system reports it;
 *  - USB storage plugged in;
 *  - running inside a virtual machine;
 *  - remote-access or screen-sharing software running (TeamViewer, AnyDesk, RDP, VNC, Zoom, OBS…).
 */
const { execFile } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');

const run = (cmd, args, timeout = 8000) =>
    new Promise((resolve) => execFile(cmd, args, { timeout, windowsHide: true, maxBuffer: 4 * 1024 * 1024 }, (err, out) => resolve(err ? '' : String(out))));
const ps = (script) => run('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', script]);

// process name fragments (lower case) → event type
const REMOTE = ['teamviewer', 'anydesk', 'rustdesk', 'remoting_host', 'chrome remote desktop', 'parsec', 'splashtop', 'logmein', 'screenconnect', 'connectwise',
    'ammyy', 'supremo', 'winvnc', 'tvnserver', 'vncserver', 'x11vnc', 'rdpclip', 'ultravnc', 'nomachine', 'nxserver', 'dwservice', 'meshagent'];
const CAPTURE = ['obs64', 'obs32', 'obs', 'zoom', 'ms-teams', 'teams', 'discord', 'skype', 'webexmta', 'slack', 'loom', 'bandicam', 'camtasia', 'sharex', 'streamlabs', 'xsplit', 'manycam'];
const VM_MARKERS = /vmware|virtualbox|vbox|qemu|kvm|hyper-v|virtual machine|parallels|xen|bochs|bhyve/i;

// Windows: D3DKMDT_VIDEO_OUTPUT_TECHNOLOGY values
const OUTPUT = { 0: 'VGA', 4: 'DVI', 5: 'HDMI', 6: 'LVDS', 10: 'DisplayPort', 11: 'DisplayPort', 15: 'Miracast (wireless)', 16: 'Indirect wired', 17: 'Indirect virtual', 2147483648: 'Internal' };

async function processes() {
    const out = process.platform === 'win32' ? await run('tasklist', ['/fo', 'csv', '/nh']) : await run('ps', ['-A', '-o', 'comm=']);
    return out.split(/\r?\n/).map((l) => l.replace(/^"([^"]+)".*/, '$1').split(/[\\/]/).pop().toLowerCase().replace(/\.exe$/, '')).filter(Boolean);
}

async function displayLinks() {
    if (process.platform !== 'win32') return [];
    const out = await ps('Get-CimInstance -Namespace root\\wmi -ClassName WmiMonitorConnectionParams | ForEach-Object { $_.VideoOutputTechnology }');
    return out.split(/\r?\n/).map((v) => v.trim()).filter(Boolean).map((v) => OUTPUT[Number(v)] || `type ${v}`);
}

async function usbStorage() {
    if (process.platform === 'win32') {
        const out = await ps("Get-CimInstance Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | ForEach-Object { $_.Model }");
        return out.split(/\r?\n/).map((s) => s.trim()).filter(Boolean);
    }
    if (process.platform === 'darwin') {
        const out = await run('system_profiler', ['SPUSBDataType', '-detailLevel', 'mini']);
        return /Removable Media:\s*Yes/i.test(out) ? ['USB storage'] : [];
    }
    const out = await run('lsblk', ['-J', '-o', 'NAME,TRAN,MODEL']);
    try {
        return (JSON.parse(out).blockdevices || []).filter((d) => d.tran === 'usb').map((d) => d.model || d.name);
    } catch {
        return [];
    }
}

async function virtualMachine() {
    let text = os.cpus().map((c) => c.model).join(' ');
    if (process.platform === 'win32') {
        text += await ps('$c = Get-CimInstance Win32_ComputerSystem; "$($c.Manufacturer) $($c.Model)"; (Get-CimInstance Win32_BIOS).SerialNumber');
    } else if (process.platform === 'darwin') {
        text += await run('sysctl', ['-n', 'machdep.cpu.features', 'hw.model']);
        text += (await run('ioreg', ['-l'])).match(/"manufacturer" = <"[^"]*">/gi)?.join(' ') || '';
    } else {
        for (const f of ['/sys/class/dmi/id/product_name', '/sys/class/dmi/id/sys_vendor']) {
            try { text += ' ' + fs.readFileSync(f, 'utf8'); } catch { /* not readable */ }
        }
        const virt = (await run('systemd-detect-virt', [])).trim();
        if (virt && virt !== 'none') text += ' ' + virt;
    }
    const m = text.match(VM_MARKERS) || (/\bVMM\b/.test(text) ? ['hypervisor'] : null);
    return m ? m[0] : null;
}

/** Runs the checks; calls report(type, data) once per new finding. */
function startMonitor(screen, report, everyMs = 15000, shouldClose = () => false) {
    const seen = new Set();
    const once = (key, type, data) => {
        if (!seen.has(key)) {
            seen.add(key);
            report(type, data);
        }
    };
    const displays = () => {
        const n = screen.getAllDisplays().length;
        if (n > 1) once(`displays-${n}`, 'multiple_displays', { count: n });
    };
    screen.on('display-added', () => { seen.clear(); report('display_change', { change: 'added', count: screen.getAllDisplays().length }); displays(); });
    screen.on('display-removed', () => report('display_change', { change: 'removed', count: screen.getAllDisplays().length }));

    if (process.platform === 'win32' && process.env.SESSIONNAME && /^RDP-/i.test(process.env.SESSIONNAME)) {
        once('rdp-session', 'remote_desktop', { tool: 'Remote Desktop session' });
    }
    virtualMachine().then((vm) => vm && once('vm', 'vm_detected', { marker: vm }));

    const tick = async () => {
        displays();
        const links = await displayLinks();
        for (const l of links) {
            if (/HDMI|DisplayPort|VGA|DVI|Miracast|wireless/i.test(l) && links.length > 1) once(`link-${l}`, /Miracast|wireless/i.test(l) ? 'wireless_display' : 'external_display', { connection: l });
        }
        for (const d of await usbStorage()) once(`usb-${d}`, 'usb_device', { device: d });
        const procs = await processes();
        for (const name of REMOTE) {
            const found = procs.filter((p) => p.includes(name));
            if (found.length) { once(`remote-${name}`, 'remote_desktop', { tool: name }); if (shouldClose()) await closeApps(found, report); }
        }
        for (const name of CAPTURE) {
            const found = procs.filter((p) => p === name || p.startsWith(name + ' '));
            if (found.length) { once(`capture-${name}`, 'screen_capture_app', { tool: name }); if (shouldClose()) await closeApps(found, report); }
        }
    };
    tick();
    const timer = setInterval(tick, everyMs);
    return () => clearInterval(timer);
}

/**
 * Close banned apps while an exam that asks for it is open (Target 120). Only names from the
 * REMOTE and CAPTURE lists reach here, and only exact process names are passed to the system.
 */
async function closeApps(names, report) {
    for (const name of new Set(names)) {
        if (!/^[a-z0-9 ._-]{2,60}$/.test(name)) continue;
        if (process.platform === 'win32') await run('taskkill', ['/F', '/T', '/IM', `${name}.exe`]);
        else await run('pkill', ['-x', name]);
        report('app_closed', { tool: name });
    }
}

module.exports = { startMonitor, closeApps, virtualMachine, usbStorage, displayLinks, processes, REMOTE, CAPTURE };
