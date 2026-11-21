import { Head } from '@inertiajs/react';
import { CheckCircle2, Loader2, RefreshCw, TriangleAlert, XCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Test = { key: string; label: string };
type Result = { key: string; ok: boolean; warn?: boolean; detail: string };

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

/** Each test returns ok / warn and a short, plain explanation. */
async function runTest(key: string, code: string, serverTime: string): Promise<Result> {
    try {
        switch (key) {
            case 'browser': {
                const ok = typeof fetch === 'function' && 'Promise' in window && 'IntersectionObserver' in window && typeof structuredClone === 'function';
                const m = navigator.userAgent.match(/(Chrome|Firefox|Edg|Version)\/(\d+)/);

                return { key, ok, detail: ok ? `${m ? `${m[1].replace('Edg', 'Edge').replace('Version', 'Safari')} ${m[2]}` : 'Supported browser'}` : 'This browser is too old. Install a current Chrome, Edge or Firefox.' };
            }
            case 'storage': {
                localStorage.setItem('__check', '1');
                localStorage.removeItem('__check');
                const est = navigator.storage?.estimate ? await navigator.storage.estimate() : null;
                const free = est?.quota && est.usage !== undefined ? est.quota - est.usage : null;
                const low = free !== null && free < 50 * 1024 * 1024;

                return { key, ok: true, warn: low, detail: low ? 'Little browser storage left; clear the browser data.' : 'Answers can be kept on this computer' };
            }
            case 'server': {
                const times: number[] = [];

                for (let i = 0; i < 5; i++) {
                    const t = performance.now();
                    const r = await fetch(`/check/${code}/payload?ping=${i}`, { method: 'HEAD', cache: 'no-store' });

                    if (!r.ok) {
                        return { key, ok: false, detail: `The exam server answered with an error (${r.status})` };
                    }

                    times.push(performance.now() - t);
                }

                const ms = Math.round(times.sort((a, b) => a - b)[2]);

                return { key, ok: ms < 1500, warn: ms >= 400, detail: `Answers in ${ms} ms${ms >= 400 ? ' (slow)' : ''}` };
            }
            case 'speed': {
                const t = performance.now();
                const r = await fetch(`/check/${code}/payload?t=${Date.now()}`, { cache: 'no-store' });
                const bytes = (await r.arrayBuffer()).byteLength;
                const kbps = Math.round((bytes * 8) / ((performance.now() - t) / 1000) / 1000);

                return { key, ok: kbps >= 256, warn: kbps < 1000, detail: `${kbps >= 1000 ? `${(kbps / 1000).toFixed(1)} Mbit/s` : `${kbps} kbit/s`}${kbps < 256 ? ': too slow for exams' : ''}` };
            }
            case 'clock': {
                const diff = Math.round(Math.abs(Date.now() - new Date(serverTime).getTime()) / 1000);

                return { key, ok: true, warn: diff > 120, detail: diff > 120 ? `Clock is ${Math.round(diff / 60)} minutes out; the exam uses the server's clock, but fix it` : 'Clock is right' };
            }
            case 'screen': {
                const w = window.screen.width;
                const h = window.screen.height;

                return { key, ok: w >= 1024 && h >= 600, warn: w < 1280, detail: `${w} × ${h}${w < 1024 ? ': too small' : ''}` };
            }
            case 'fullscreen': {
                const ok = !!document.documentElement.requestFullscreen && document.fullscreenEnabled;

                return { key, ok, detail: ok ? 'Full screen is available' : 'This browser cannot go full screen' };
            }
            case 'camera':
            case 'microphone': {
                const stream = await navigator.mediaDevices.getUserMedia(key === 'camera' ? { video: true } : { audio: true });
                const track = stream.getTracks()[0];
                const label = track?.label || (key === 'camera' ? 'Camera' : 'Microphone');
                stream.getTracks().forEach((t) => t.stop());

                return { key, ok: !!track, detail: label };
            }
            case 'secure_app': {
                const ua = navigator.userAgent;
                const ok = /SEB\//.test(ua) || /ExamsDesktop/i.test(ua);

                return { key, ok, detail: ok ? (/SEB\//.test(ua) ? 'Safe Exam Browser' : 'Exam desktop app') : 'Open this link in Safe Exam Browser or the exam app' };
            }
        }

        return { key, ok: false, detail: 'Unknown test' };
    } catch (e) {
        const msg = e instanceof Error ? e.message : String(e);

        return { key, ok: false, detail: key === 'camera' || key === 'microphone' ? `Not available (${msg.includes('Permission') || msg.includes('NotAllowed') ? 'permission refused' : 'no device found'})` : 'Failed: '.concat(msg.slice(0, 120)) };
    }
}

/** The page a lab computer opens: it tests itself and reports to the exams office, with no sign-in. */
export default function CheckRun({ check, server_time }: { check: { name: string; code: string; open: boolean; tests: Test[] }; server_time: string }) {
    const [label, setLabel] = useState(() => {
        try {
            return localStorage.getItem('computer-label') ?? '';
        } catch {
            return '';
        }
    });
    const [results, setResults] = useState<Record<string, Result | 'running'>>({});
    const [state, setState] = useState<'idle' | 'running' | 'done' | 'error'>('idle');
    const [report, setReport] = useState<{ status: string; ip: string } | null>(null);

    const run = useCallback(async () => {
        setState('running');
        setReport(null);
        const out: Result[] = [];

        for (const t of check.tests) {
            setResults((r) => ({ ...r, [t.key]: 'running' }));
            const res = await runTest(t.key, check.code, server_time);
            out.push(res);
            setResults((r) => ({ ...r, [t.key]: res }));
            await wait(150);
        }

        try {
            localStorage.setItem('computer-label', label);
        } catch {
            /* private window */
        }

        const res = await fetch(`/check/${check.code}/report`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ label: label || null, results: out }) }).catch(() => null);

        if (res?.ok) {
            setReport(await res.json());
            setState('done');
        } else {
            setState('error');
        }
    }, [check.code, check.tests, label, server_time]);

    useEffect(() => {
        if (check.open) {
            const t = window.setTimeout(() => void run(), 600);

            return () => window.clearTimeout(t);
        }
        // run once, when the page opens
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const failed = check.tests.filter((t) => typeof results[t.key] === 'object' && !(results[t.key] as Result).ok).length;

    return (
        <>
            <Head title={`Computer check · ${check.name}`} />
            <main className="min-h-screen bg-[#f6f7f4] px-4 py-10 text-[#14211b]">
                <div className="mx-auto grid max-w-xl gap-6">
                    <div>
                        <p className="text-sm text-[#4d5a53]">Computer check</p>
                        <h1 className="text-3xl font-bold tracking-tight">{check.name}</h1>
                    </div>
                    {!check.open ? (
                        <p className="rounded-xl border border-[#dbe0da] bg-white p-5">This check has closed. Ask the exams office for a new link.</p>
                    ) : (
                        <>
                            <label className="grid gap-1 text-sm">
                                Computer name or seat (optional)
                                <input className="h-11 rounded-lg border border-[#dbe0da] bg-white px-3 text-base" placeholder="e.g. Lab 2 · B14" value={label} maxLength={60} onChange={(e) => setLabel(e.target.value)} />
                            </label>
                            <ul className="grid gap-2">
                                {check.tests.map((t) => {
                                    const r = results[t.key];

                                    return (
                                        <li key={t.key} className="flex items-start gap-3 rounded-xl border border-[#dbe0da] bg-white p-3">
                                            {r === 'running' ? <Loader2 className="mt-0.5 size-5 animate-spin text-[#4d5a53]" /> : !r ? <span className="mt-1 size-4 rounded-full border-2 border-[#cfd6d1]" /> : r.ok ? (r.warn ? <TriangleAlert className="mt-0.5 size-5 text-[#b45309]" /> : <CheckCircle2 className="mt-0.5 size-5 text-[#0e6b4f]" />) : <XCircle className="mt-0.5 size-5 text-[#b42318]" />}
                                            <span className="min-w-0"><b className="block font-medium">{t.label}</b>{r && r !== 'running' && <span className="text-sm text-[#4d5a53]">{r.detail}</span>}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                            {state === 'done' && report && (
                                <div role="status" className={`rounded-xl p-5 text-white ${failed ? 'bg-[#b42318]' : 'bg-[#0e6b4f]'}`}>
                                    <p className="text-xl font-bold">{failed ? 'This computer is not ready' : 'This computer is ready'}</p>
                                    <p className="mt-1 text-white/85">Sent to the exams office. This computer&apos;s address: <span className="font-mono">{report.ip}</span></p>
                                </div>
                            )}
                            {state === 'error' && <p className="rounded-xl bg-[#b42318] p-4 text-white">The result could not be sent. Check the network cable or Wi-Fi, then test again.</p>}
                            <button disabled={state === 'running'} onClick={() => void run()} className="flex w-fit items-center gap-2 rounded-full bg-[#14211b] px-5 py-2.5 font-medium text-white disabled:opacity-50">
                                <RefreshCw className="size-4" /> {state === 'running' ? 'Testing…' : 'Test again'}
                            </button>
                        </>
                    )}
                </div>
            </main>
        </>
    );
}
