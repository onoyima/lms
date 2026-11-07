'use strict';
/** The only bridge between the exam page and the app: events out, exam state in. No Node access. */
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('examDesktop', {
    onEvent: (cb) => {
        const listener = (_e, ev) => cb(ev.type, ev.data);
        ipcRenderer.on('desktop:event', listener);
        return () => ipcRenderer.removeListener('desktop:event', listener);
    },
    examState: (state, opts) => ipcRenderer.send('desktop:exam-state', state === 'open' ? 'open' : 'closed', { closeApps: Boolean(opts && opts.closeApps) }),
    info: () => ipcRenderer.invoke('desktop:info'),
    setup: (url) => ipcRenderer.invoke('desktop:setup', String(url)),
    continue: () => ipcRenderer.invoke('desktop:continue'),
    quit: () => ipcRenderer.send('desktop:quit'),
});

// Virtual cameras (OBS, ManyCam, Snap…) can feed a recording instead of the real person.
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(async () => {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const fake = devices.filter((d) => d.kind === 'videoinput' && /virtual|obs|manycam|snap camera|xsplit|e2esoft|droidcam|epoccam|camtwist/i.test(d.label));
            if (fake.length) ipcRenderer.send('desktop:found', 'camera_virtual', { labels: fake.map((d) => d.label) });
        } catch { /* camera not allowed yet */ }
    }, 4000);
});
