import { Head, router } from '@inertiajs/react';
import { Camera, Loader2, ShieldCheck, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Flash, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { frameToJpeg, loadFace, readFace } from '@/lib/face';

type Props = {
    profile: { status: string; enrolled_at: string | null; has_photo: boolean; has_id_photo: boolean; note: string | null } | null;
    retention: string;
};

/** Face enrolment: three captures a moment apart; only the numeric template and one photo are sent. */
export default function Enrol({ profile, retention }: Props) {
    const video = useRef<HTMLVideoElement>(null);
    const [stream, setStream] = useState<MediaStream | null>(null);
    const [consent, setConsent] = useState(false);
    const [state, setState] = useState<'idle' | 'loading' | 'ready' | 'capturing' | 'sending'>('idle');
    const [message, setMessage] = useState<string | null>(null);

    useEffect(() => () => stream?.getTracks().forEach((t) => t.stop()), [stream]);

    const start = async () => {
        setState('loading');
        setMessage(null);

        try {
            const [s] = await Promise.all([navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false }), loadFace()]);
            setStream(s);

            if (video.current) {
                video.current.srcObject = s;
                await video.current.play();
            }

            setState('ready');
        } catch {
            setState('idle');
            setMessage('The camera or face model could not start. Allow camera access and try again.');
        }
    };

    const capture = async () => {
        const v = video.current;

        if (!v) {
            return;
        }

        setState('capturing');
        const samples: number[][] = [];

        for (let i = 0; i < 8 && samples.length < 3; i++) {
            const r = await readFace(v, true);

            if (r.faces === 1 && r.descriptor && Math.abs(r.yaw ?? 1) < 0.25) {
                samples.push(r.descriptor);
            } else {
                setMessage(r.faces === 0 ? 'No face found — face the camera in good light.' : r.faces > 1 ? 'Only you should be in view.' : 'Look straight at the camera.');
            }

            await new Promise((res) => setTimeout(res, 700));
        }

        if (samples.length < 3) {
            setState('ready');

            return;
        }

        const photo = await frameToJpeg(v, 480);
        setState('sending');
        const fd = new FormData();
        fd.append('samples', JSON.stringify(samples));
        fd.append('consent', '1');

        if (photo) {
            fd.append('photo', photo, 'face.jpg');
        }

        router.post('/my/identity', fd, {
            forceFormData: true,
            onFinish: () => {
                setState('idle');
                stream?.getTracks().forEach((t) => t.stop());
                setStream(null);
            },
            onError: (e) => setMessage(Object.values(e).join(' ')),
        });
    };

    return (
        <>
            <Head title="Face identity" />
            <div className="flex max-w-3xl flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Your face" accent="for exams" lead="Exams that check identity compare your face with what you enrol here and with your official ID photo." />
                <Flash />
                {profile && (
                    <div className="surface flex flex-wrap items-center justify-between gap-3 p-5 text-sm">
                        <span className="flex items-center gap-3">
                            <ShieldCheck className="size-5 text-brand" /> Status <Status value={profile.status} />
                            {profile.has_photo && <img src="/my/identity/photo" alt="Your enrolled face" className="size-12 rounded-lg object-cover" />}
                        </span>
                        {profile.note && <span className="text-status-warning">{profile.note}</span>}
                        <Button variant="outline" size="sm" onClick={() => confirm('Delete your face data? Exams with identity checks will then need a proctor to check you.') && router.delete('/my/identity')}>
                            <Trash2 className="size-4" /> Delete my face data
                        </Button>
                    </div>
                )}
                <div className="surface grid gap-4 p-6">
                    <p className="eyebrow">{profile ? 'Enrol again' : 'Enrol'}</p>
                    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>Your camera's picture is analysed on this computer. Only a set of numbers describing your face and one photo are sent.</li>
                        <li>They are stored encrypted, used only to confirm it is you during exams, and shown only to you and the registry staff who verify identities.</li>
                        <li>{retention}</li>
                    </ul>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                        I agree to my face being used to confirm my identity in exams, as described above.
                    </label>
                    <video ref={video} muted playsInline className={`aspect-video w-full max-w-md rounded-xl bg-black object-cover ${stream ? '' : 'hidden'}`} aria-label="Camera preview" />
                    {message && <p className="text-sm text-status-warning">{message}</p>}
                    <div className="flex gap-2">
                        {!stream ? (
                            <Button disabled={!consent || state === 'loading'} onClick={start}>
                                {state === 'loading' ? <Loader2 className="size-4 animate-spin" /> : <Camera className="size-4" />} Start camera
                            </Button>
                        ) : (
                            <Button disabled={state !== 'ready'} onClick={capture}>
                                {state === 'capturing' || state === 'sending' ? <Loader2 className="size-4 animate-spin" /> : null} Capture my face
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

Enrol.layout = { breadcrumbs: [{ title: 'Face identity', href: '/my/identity' }] };
