import { AlertTriangle, Battery, Camera, CheckCircle2, Globe, Keyboard, Laptop, Loader2, Maximize, MicVocal, Monitor, MonitorUp, ScanFace, ShieldCheck, Video, Volume2, Wifi } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type CheckKey = 'browser' | 'network' | 'device' | 'power' | 'screen' | 'fullscreen' | 'keyboard' | 'camera' | 'face' | 'microphone' | 'speakers' | 'screen_share' | 'secure_app' | 'room_scan';
type State = { ok: boolean | null; note?: string; detail?: string };
export type Compat = { name: string; minimum: number | null; match: string; status: string; note: string };

const META: Record<CheckKey, { label: string; icon: LucideIcon; ask: string }> = {
    browser: { label: 'Browser', icon: Globe, ask: 'Checking your browser…' },
    network: { label: 'Internet connection', icon: Wifi, ask: 'Measuring your connection…' },
    device: { label: 'Computer', icon: Laptop, ask: 'Checking your device…' },
    power: { label: 'Power', icon: Battery, ask: 'Checking your battery…' },
    screen: { label: 'Screen', icon: Monitor, ask: 'Checking your display…' },
    fullscreen: { label: 'Full screen', icon: Maximize, ask: 'Press Test to open full screen for a moment.' },
    keyboard: { label: 'Keyboard', icon: Keyboard, ask: 'Type the sentence shown.' },
    camera: { label: 'Camera', icon: Camera, ask: 'Allow the camera when your browser asks.' },
    face: { label: 'Face', icon: ScanFace, ask: 'Look at the camera in good light.' },
    microphone: { label: 'Microphone', icon: MicVocal, ask: 'Say your full name out loud.' },
    speakers: { label: 'Sound', icon: Volume2, ask: 'Play the sound and say how many beeps you heard.' },
    screen_share: { label: 'Screen sharing', icon: MonitorUp, ask: 'Share your whole screen when asked, then it stops again.' },
    secure_app: { label: 'Secure exam app', icon: ShieldCheck, ask: 'Open this exam in the desktop exam app or Safe Exam Browser.' },
    room_scan: { label: 'Room scan', icon: Video, ask: 'Right after you start: show your room and desk to the camera.' },
};

const PHRASE = 'I will write this exam honestly';

/**
 * The pre-exam checks the exam's setter chose. Automatic ones run by themselves; the others ask the
 * candidate to do something small (speak, type, listen). The Start button only works when every one
 * has passed; the server checks the list again.
 */
export function PreChecks({ checks, compat, onChange }: { checks: CheckKey[]; compat: Compat[]; onChange: (passed: Record<string, boolean>, details: Record<string, string>) => void }) {
    const [state, setState] = useState<Record<string, State>>({});
    const [typed, setTyped] = useState('');
    const [beeps, setBeeps] = useState<number | null>(null);
    const [level, setLevel] = useState(0);
    const video = useRef<HTMLVideoElement>(null);
    const cam = useRef<MediaStream | null>(null);
    const mic = useRef<MediaStream | null>(null);
    const want = useCallback((k: CheckKey) => checks.includes(k), [checks]);
    const set = useCallback((k: CheckKey, s: State) => setState((prev) => ({ ...prev, [k]: s })), []);

    // tell the page what has passed so far
    useEffect(() => {
        const passed: Record<string, boolean> = {};
        const details: Record<string, string> = {};

        for (const k of checks) {
            passed[k] = k === 'room_scan' ? true : state[k]?.ok === true;

            if (state[k]?.detail) {
                details[k] = state[k].detail!;
            }
        }

        onChange(passed, details);
    }, [state, checks, onChange]);

    // automatic checks
    useEffect(() => {
        const timer = window.setTimeout(() => run(), 0);
        const run = () => {
        const ua = navigator.userAgent;

        if (want('browser')) {
            const rule = compat.find((b) => new RegExp(b.match).test(ua));
            const key = rule?.match.split('|')[0].replace('/', '') ?? '';
            const version = Number(new RegExp(`${key}/(\\d+)`).exec(ua)?.[1] ?? 0);
            const old = !!rule && rule.minimum !== null && version > 0 && version < rule.minimum;
            const ok = window.isSecureContext && 'fetch' in window && rule?.status !== 'unsupported' && !old;
            set('browser', { ok, detail: `${rule?.name ?? 'Browser'} ${version || ''}`.trim(),
                note: !window.isSecureContext ? 'Use the https address or the desktop app.' : rule?.status === 'unsupported' ? 'This browser cannot run exams. Use Chrome, Edge or Firefox, or the desktop exam app.' : old ? `Update to version ${rule!.minimum} or later.` : `${rule?.name ?? 'Your browser'} ${version || ''}`.trim() });
        }

        if (want('device')) {
            const phone = /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || Math.max(window.screen.width, window.screen.height) < 1000;
            set('device', { ok: !phone, detail: `${window.screen.width}×${window.screen.height}`, note: phone ? 'Use a laptop or desktop computer for this exam.' : 'Laptop or desktop computer' });
        }

        if (want('screen')) {
            const extended = (window.screen as Screen & { isExtended?: boolean }).isExtended;
            const small = window.screen.width < 1024;
            set('screen', { ok: !extended && !small, detail: `${window.screen.width}×${window.screen.height}${extended ? ', extended' : ''}`,
                note: extended ? 'Disconnect extra monitors or projectors, then press Check again.' : small ? 'The screen is too small to read the paper.' : 'One display' });
        }

        if (want('secure_app')) {
            const secure = !!(window as unknown as { examDesktop?: unknown }).examDesktop || /SEB\//.test(ua);
            set('secure_app', { ok: secure, note: secure ? 'Secure app detected' : 'Open this exam from the desktop exam app or Safe Exam Browser (the button below).' });
        }

        if (want('power')) {
            const nav = navigator as Navigator & { getBattery?: () => Promise<{ charging: boolean; level: number }> };

            if (!nav.getBattery) {
                set('power', { ok: true, note: 'No battery reported (desktop computer)' });
            } else {
                nav.getBattery().then((b) => set('power', { ok: b.charging || b.level >= 0.5, detail: `${Math.round(b.level * 100)}%${b.charging ? ' charging' : ''}`,
                    note: b.charging ? `Charging (${Math.round(b.level * 100)}%)` : b.level >= 0.5 ? `Battery ${Math.round(b.level * 100)}%` : `Battery ${Math.round(b.level * 100)}%: plug in your charger.` }))
                    .catch(() => set('power', { ok: true, note: 'Battery not reported' }));
            }
        }

        if (want('network')) {
            (async () => {
                const times: number[] = [];

                for (let i = 0; i < 3; i++) {
                    const t = performance.now();
                    const ok = await fetch('/up', { cache: 'no-store' }).then((r) => r.ok).catch(() => false);

                    if (!ok) {
                        set('network', { ok: false, note: 'The exam server cannot be reached. Check your internet connection.' });

                        return;
                    }

                    times.push(performance.now() - t);
                }

                const ms = Math.round(times.sort((a, b) => a - b)[1]);
                set('network', { ok: ms < 2000, detail: `${ms} ms`, note: ms < 2000 ? `Good (${ms} ms)` : `Very slow (${ms} ms). Move closer to the router or use another network.` });
            })();
        }
        };

        return () => window.clearTimeout(timer);
    }, [want, compat, set]);

    // camera (and face): preview and measure the picture
    useEffect(() => {
        if (!want('camera') && !want('face') && !want('room_scan')) {
            return;
        }

        let stop = false;
        (async () => {
            try {
                cam.current = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
            } catch {
                set('camera', { ok: false, note: 'Allow the camera in your browser (the camera icon in the address bar), then reload.' });
                set('face', { ok: false, note: 'Needs the camera.' });

                return;
            }

            if (video.current) {
                video.current.srcObject = cam.current;
                await video.current.play().catch(() => undefined);
            }

            const canvas = document.createElement('canvas');
            canvas.width = 160;
            canvas.height = 120;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });

            for (let i = 0; i < 30 && !stop; i++) {
                await new Promise((r) => setTimeout(r, 400));

                if (!video.current || !ctx || video.current.readyState < 2) {
                    continue;
                }

                ctx.drawImage(video.current, 0, 0, 160, 120);
                const px = ctx.getImageData(0, 0, 160, 120).data;
                let sum = 0;
                let sq = 0;

                for (let j = 0; j < px.length; j += 4) {
                    const y = 0.299 * px[j] + 0.587 * px[j + 1] + 0.114 * px[j + 2];
                    sum += y;
                    sq += y * y;
                }

                const n = px.length / 4;
                const mean = sum / n;
                const spread = Math.sqrt(sq / n - mean * mean);
                const good = mean > 35 && spread > 12;
                set('camera', { ok: good, detail: `light ${Math.round(mean)}`, note: good ? 'Working' : mean <= 35 ? 'Too dark, or the camera is covered.' : 'The picture is blank. Check the camera is not covered.' });

                if (good) {
                    break;
                }
            }

            if (want('face') && !stop && video.current) {
                const { loadFace, readFace } = await import('@/lib/face');
                await loadFace().catch(() => null);

                for (let i = 0; i < 20 && !stop; i++) {
                    const r = await readFace(video.current, false).catch(() => null);
                    const ok = r?.faces === 1;
                    set('face', { ok, note: !r ? 'Looking for your face…' : r.faces === 0 ? 'No face seen. Sit in front of the camera, in good light.' : r.faces > 1 ? 'More than one face. Only you should be in view.' : 'One face, clearly visible' });

                    if (ok) {
                        break;
                    }

                    await new Promise((res) => setTimeout(res, 700));
                }
            }
        })();

        return () => {
            stop = true;
            cam.current?.getTracks().forEach((t) => t.stop());
        };
    }, [want, set]);

    // microphone: a level meter, passes once the candidate has spoken
    useEffect(() => {
        if (!want('microphone')) {
            return;
        }

        let raf = 0;
        let ctx: AudioContext | null = null;
        let spoken = 0;
        (async () => {
            try {
                mic.current = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                set('microphone', { ok: false, note: 'Allow the microphone in your browser, then reload.' });

                return;
            }

            ctx = new AudioContext();
            const an = ctx.createAnalyser();
            an.fftSize = 512;
            ctx.createMediaStreamSource(mic.current).connect(an);
            const buf = new Uint8Array(an.fftSize);
            let last = performance.now();
            const tick = (t: number) => {
                an.getByteTimeDomainData(buf);
                let s = 0;

                for (const v of buf) {
                    s += ((v - 128) / 128) ** 2;
                }

                const rms = Math.sqrt(s / buf.length);
                setLevel(Math.min(1, rms * 6));

                if (rms > 0.04) {
                    spoken += t - last;
                }

                last = t;

                if (spoken > 700) {
                    set('microphone', { ok: true, note: 'Your voice was heard' });

                    return;
                }

                raf = requestAnimationFrame(tick);
            };
            raf = requestAnimationFrame(tick);
        })();

        return () => {
            cancelAnimationFrame(raf);
            void ctx?.close();
            mic.current?.getTracks().forEach((t) => t.stop());
        };
    }, [want, set]);

    const playBeeps = () => {
        const n = 2 + Math.floor(Math.random() * 3);
        setBeeps(n);
        const ctx = new AudioContext();

        for (let i = 0; i < n; i++) {
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.frequency.value = 660;
            g.gain.setValueAtTime(0.0001, ctx.currentTime + i * 0.6);
            g.gain.exponentialRampToValueAtTime(0.4, ctx.currentTime + i * 0.6 + 0.03);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + i * 0.6 + 0.3);
            o.connect(g).connect(ctx.destination);
            o.start(ctx.currentTime + i * 0.6);
            o.stop(ctx.currentTime + i * 0.6 + 0.32);
        }

        set('speakers', { ok: null, note: 'How many beeps did you hear?' });
    };

    const testFullscreen = async () => {
        try {
            await document.documentElement.requestFullscreen();
            await new Promise((r) => setTimeout(r, 300));
            await document.exitFullscreen();
            set('fullscreen', { ok: true, note: 'Full screen works' });
        } catch {
            set('fullscreen', { ok: false, note: 'Full screen was blocked. Use another browser or the desktop exam app.' });
        }
    };

    const testShare = async () => {
        try {
            const s = await navigator.mediaDevices.getDisplayMedia({ video: { displaySurface: 'monitor' } as MediaTrackConstraints, audio: false });
            const surface = (s.getVideoTracks()[0]?.getSettings() as MediaTrackSettings & { displaySurface?: string }).displaySurface;
            s.getTracks().forEach((t) => t.stop());
            const ok = !surface || surface === 'monitor';
            set('screen_share', { ok, detail: surface ?? 'unknown', note: ok ? 'Whole screen can be shared' : 'Share your entire screen, not a window or tab. Press Test again.' });
        } catch {
            set('screen_share', { ok: false, note: 'Screen sharing was refused. Press Test again and choose your entire screen.' });
        }
    };

    const done = checks.filter((k) => k === 'room_scan' || state[k]?.ok === true).length;

    return (
        <div className="grid gap-3">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Before you start</p>
                <span className="text-xs text-muted-foreground tabular-nums">{done} of {checks.length} ready</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-foreground/[.07]"><div className="h-full rounded-full bg-[var(--live)] transition-[width] duration-500" style={{ width: `${(done / Math.max(1, checks.length)) * 100}%` }} /></div>
            {(want('camera') || want('face')) && (
                <video ref={video} muted playsInline className="aspect-video w-full rounded-xl bg-black/80 object-cover" aria-label="Your camera" />
            )}
            <ul className="grid gap-1.5">
                {checks.map((k) => {
                    const m = META[k];
                    const s = state[k];
                    const ok = k === 'room_scan' ? null : s?.ok;

                    return (
                        <li key={k} className={`rounded-xl border p-3 text-sm transition-colors ${ok === true ? 'border-status-good/30 bg-status-good/[.06]' : ok === false ? 'border-status-critical/30 bg-status-critical/[.05]' : 'border-border bg-card/60'}`}>
                            <div className="flex items-center gap-2.5">
                                <m.icon className="size-4 shrink-0 text-muted-foreground" />
                                <span className="flex-1 font-medium">{m.label}</span>
                                {ok === true ? <CheckCircle2 className="size-4 text-status-good" /> : ok === false ? <AlertTriangle className="size-4 text-status-critical" /> : k === 'room_scan' ? null : <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                            </div>
                            <p className="mt-0.5 pl-6.5 text-xs text-muted-foreground">{s?.note ?? m.ask}</p>
                            {k === 'microphone' && ok !== true && (
                                <div className="mt-2 ml-6.5 h-1.5 overflow-hidden rounded-full bg-foreground/[.07]"><div className="h-full bg-[var(--live)] transition-[width] duration-75" style={{ width: `${level * 100}%` }} /></div>
                            )}
                            {k === 'keyboard' && ok !== true && (
                                <div className="mt-2 ml-6.5 grid gap-1">
                                    <span className="text-xs">Type: <b>{PHRASE}</b></span>
                                    <input className="h-9 rounded-lg border border-input bg-card px-2.5 text-sm outline-none focus:border-brand" value={typed} aria-label="Type the sentence"
                                        onChange={(e) => {
                                            setTyped(e.target.value);

                                            if (e.target.value.trim().toLowerCase() === PHRASE.toLowerCase()) {
                                                set('keyboard', { ok: true, note: 'Keyboard works' });
                                            }
                                        }} onPaste={(e) => e.preventDefault()} />
                                </div>
                            )}
                            {k === 'speakers' && ok !== true && (
                                <div className="mt-2 ml-6.5 flex flex-wrap items-center gap-2">
                                    <button type="button" onClick={playBeeps} className="rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-[0_2px_6px_-3px_rgb(16_26_51/.3)] transition hover:border-brand hover:shadow-[0_8px_16px_-10px_rgb(16_26_51/.45)]">Play sound</button>
                                    {beeps !== null && [2, 3, 4].map((n) => (
                                        <button key={n} type="button" className="rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-[0_2px_6px_-3px_rgb(16_26_51/.3)] transition hover:border-brand hover:shadow-[0_8px_16px_-10px_rgb(16_26_51/.45)]"
                                            onClick={() => set('speakers', n === beeps ? { ok: true, note: 'Sound works' } : { ok: false, note: 'Not quite. Turn the volume up and play it again.' })}>{n} beeps</button>
                                    ))}
                                </div>
                            )}
                            {k === 'fullscreen' && ok !== true && <button type="button" onClick={testFullscreen} className="mt-2 ml-6.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-[0_2px_6px_-3px_rgb(16_26_51/.3)] transition hover:border-brand hover:shadow-[0_8px_16px_-10px_rgb(16_26_51/.45)]">Test</button>}
                            {k === 'screen_share' && ok !== true && <button type="button" onClick={testShare} className="mt-2 ml-6.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-[0_2px_6px_-3px_rgb(16_26_51/.3)] transition hover:border-brand hover:shadow-[0_8px_16px_-10px_rgb(16_26_51/.45)]">Test</button>}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * Room scan, right after starting: a 20-second recording while the candidate turns the camera (or
 * laptop) slowly around the room and over the desk. It is kept as evidence; the paper opens after.
 */
export function RoomScan({ token, onDone }: { token: string; onDone: () => void }) {
    const video = useRef<HTMLVideoElement>(null);
    const [phase, setPhase] = useState<'ready' | 'recording' | 'sending' | 'failed'>('ready');
    const [left, setLeft] = useState(20);

    const run = async () => {
        let stream: MediaStream;

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
        } catch {
            setPhase('failed');

            return;
        }

        if (video.current) {
            video.current.srcObject = stream;
            await video.current.play().catch(() => undefined);
        }

        const type = ['video/webm;codecs=vp8', 'video/webm', 'video/mp4'].find((t) => MediaRecorder.isTypeSupported?.(t)) ?? '';
        const rec = new MediaRecorder(stream, { mimeType: type, videoBitsPerSecond: 400000 });
        const parts: Blob[] = [];
        rec.ondataavailable = (e) => e.data.size && parts.push(e.data);
        setPhase('recording');
        rec.start();

        for (let s = 20; s > 0; s--) {
            setLeft(s);
            await new Promise((r) => setTimeout(r, 1000));
        }

        await new Promise<void>((r) => {
            rec.onstop = () => r();
            rec.stop();
        });
        stream.getTracks().forEach((t) => t.stop());
        setPhase('sending');
        const blob = new Blob(parts, { type: rec.mimeType || 'video/webm' });
        const fd = new FormData();
        fd.append('kind', 'room_scan');
        fd.append('file', blob, blob.type.includes('mp4') ? 'room.mp4' : 'room.webm');

        for (let i = 0; i < 5; i++) {
            const res = await fetch('/api/exam/attempt/evidence', { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, body: fd }).catch(() => null);

            if (res?.ok) {
                onDone();

                return;
            }

            await new Promise((r) => setTimeout(r, 2000 * (i + 1)));
        }

        setPhase('failed');
    };

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-[var(--deep)]/90 p-4 backdrop-blur-sm">
            <div className="w-full max-w-lg rounded-3xl bg-card p-6 text-foreground shadow-2xl">
                <p className="text-sm text-muted-foreground">One more step</p>
                <h2 className="text-2xl font-semibold">Show your room and desk</h2>
                <p className="mt-2 text-sm text-muted-foreground">When you press Start scan, turn your camera (or laptop) slowly all the way around the room, then show your desk and the floor around it. It takes 20 seconds and is kept with your exam for the invigilators.</p>
                <video ref={video} muted playsInline className="mt-4 aspect-video w-full rounded-2xl bg-black object-cover" />
                <div className="mt-4 flex items-center justify-between">
                    <span className="text-sm text-muted-foreground" role="status">
                        {phase === 'recording' ? `Recording… ${left}s` : phase === 'sending' ? 'Sending…' : phase === 'failed' ? 'That did not work. Check the camera and your connection, then try again.' : 'Ready when you are.'}
                    </span>
                    <button type="button" disabled={phase === 'recording' || phase === 'sending'} onClick={() => void run()}
                        className="inline-flex h-11 items-center gap-2 rounded-xl bg-brand px-5 font-semibold text-brand-foreground disabled:opacity-60">
                        {phase === 'recording' || phase === 'sending' ? <Loader2 className="size-4 animate-spin" /> : <Video className="size-4" />}
                        {phase === 'failed' ? 'Try again' : 'Start scan'}
                    </button>
                </div>
            </div>
        </div>
    );
}
