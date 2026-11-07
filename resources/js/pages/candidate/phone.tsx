import { Head } from '@inertiajs/react';
import { Camera, CheckCircle2, Loader2, SwitchCamera } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { sendJson } from '@/components/platform/ui';
import { publish } from '@/lib/live';

/**
 * The candidate's phone as a second camera. Opened from a one-time code on the exam screen;
 * no sign-in on the phone, and the phone can only send its camera — it cannot see anything.
 */
export default function Phone({ code, valid }: { code: string; valid: boolean }) {
    const video = useRef<HTMLVideoElement>(null);
    const [state, setState] = useState<'ready' | 'starting' | 'live' | 'lost' | 'error'>(valid ? 'ready' : 'error');
    const [message, setMessage] = useState<string>(valid ? '' : 'This link was used or has expired. Make a new code on the exam screen.');
    const [facing, setFacing] = useState<'environment' | 'user'>('environment');
    const room = useRef<{ disconnect: () => Promise<void> } | null>(null);
    const stream = useRef<MediaStream | null>(null);

    useEffect(() => () => {
        room.current?.disconnect();
        stream.current?.getTracks().forEach((t) => t.stop());
    }, []);

    const start = async () => {
        setState('starting');

        try {
            stream.current = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: 1280, height: 720 }, audio: false });

            if (video.current) {
                video.current.srcObject = stream.current;
                await video.current.play().catch(() => undefined);
            }

            const res = await sendJson<{ url?: string; token?: string; message?: string }>(`/phone/${code}/token`, 'POST');

            if (!res.ok || !res.data.url || !res.data.token) {
                throw new Error(res.data.message ?? 'The exam did not accept this phone.');
            }

            room.current = await publish(res.data.url, res.data.token, [{ track: stream.current.getVideoTracks()[0], source: 'camera' }], (q) => setState(q === 'lost' ? 'lost' : 'live'), () => setState('lost'));
            setState('live');
            // keep the screen awake while filming
            (navigator as Navigator & { wakeLock?: { request: (t: string) => Promise<unknown> } }).wakeLock?.request('screen').catch(() => undefined);
        } catch (e) {
            setState('error');
            setMessage(e instanceof Error ? e.message : 'The camera could not start.');
        }
    };

    return (
        <>
            <Head title="Phone camera" />
            <div className="flex min-h-screen flex-col items-center justify-center gap-5 bg-[#0b0d10] p-6 text-center text-white">
                <Camera className="size-8 text-emerald-400" />
                <h1 className="text-xl font-semibold">Second camera</h1>
                <video ref={video} muted playsInline className={`w-full max-w-sm rounded-2xl bg-black ${state === 'ready' || state === 'error' ? 'hidden' : ''}`} />
                {state === 'ready' && (
                    <>
                        <p className="max-w-sm text-sm text-white/70">Place your phone to the side so it shows you, your desk and your computer screen. Keep it plugged in. The phone only sends video; it cannot see anything.</p>
                        <label className="flex items-center gap-2 text-sm">
                            <SwitchCamera className="size-4" />
                            <select className="rounded-lg bg-white/10 px-2 py-1" value={facing} onChange={(e) => setFacing(e.target.value as 'environment' | 'user')}>
                                <option value="environment">Back camera</option>
                                <option value="user">Front camera</option>
                            </select>
                        </label>
                        <button onClick={start} className="rounded-2xl bg-emerald-500 px-6 py-3 font-medium text-black">Start camera</button>
                    </>
                )}
                {state === 'starting' && <Loader2 className="size-6 animate-spin" />}
                {state === 'live' && <p className="flex items-center gap-2 text-sm text-emerald-400"><CheckCircle2 className="size-4" /> Connected. Leave this page open until your exam ends.</p>}
                {state === 'lost' && <p className="text-sm text-amber-400">Connection lost — reconnecting. If it does not come back, make a new code on the exam screen.</p>}
                {state === 'error' && <p className="max-w-sm text-sm text-red-400">{message}</p>}
            </div>
        </>
    );
}
