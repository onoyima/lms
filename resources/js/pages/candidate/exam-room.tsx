import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Camera, CheckCircle2, ChevronLeft, ChevronRight, Clock, Flag, Hand, LayoutGrid, Loader2, Send, Shield, Wifi, WifiOff, X, ZoomIn, ZoomOut } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { PreChecks, RoomScan } from '@/components/exam/pre-checks';
import type { CheckKey } from '@/components/exam/pre-checks';
import { formatClock, sendJson } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Toaster } from '@/components/ui/sonner';
import { ExamStore, keepExamPageOffline } from '@/lib/exam-store';
import type { Snapshot, StoredAnswer } from '@/lib/exam-store';
import { frameToJpeg, loadFace, readFace } from '@/lib/face';
import { publish } from '@/lib/live';
import { PieceRecorder, RecordingUploader } from '@/lib/recorder';
import type { RecordPlan } from '@/lib/recorder';

/* ─── Types ───────────────────────────────────────────────────────────── */
type Option = { id: number; label: string; html: string };
type MatchItem = { key: string; html: string };
type PaperQuestion = {
    id: number; number: number; type: string; mark: number; stem_html: string; options: Option[]; matching?: { left: MatchItem[]; right: MatchItem[] } | null;
    blanks?: number[] | null; file?: { kinds: string[]; max_mb: number } | null; passage_id?: number | null;
};
type Paper = { exam: { title: string; instructions: string | null; type: string }; sections: { id: number; title: string; instructions: string | null; answer_any: number | null; questions: PaperQuestion[] }[]; passages?: Record<string, { title: string; html: string } | null> };
type Uploader = (questionId: number, file: File) => Promise<{ id: number; name: string; size: number } | string>;
const FILE_EXT: Record<string, string> = { image: '.jpg,.jpeg,.png,.webp,.heic', pdf: '.pdf', document: '.doc,.docx,.odt,.rtf,.txt', spreadsheet: '.xls,.xlsx,.ods,.csv', slides: '.ppt,.pptx,.odp', code: '.zip,.py,.java,.c,.cpp,.js,.ts,.php,.r,.m,.ipynb' };
type Response = { options?: number[]; text?: string; number?: number; pairs?: Record<string, string>; tf?: Record<string, boolean>; order?: number[]; blanks?: Record<string, string>; file?: { id: number; name: string; size: number } } | null;
type Answer = { response: Response; flagged: boolean; seq: number; at?: number };
type AttemptState = { id: number; status: string; remaining_seconds: number; deadline_at: string; server_time: string; answered: number; violations: number; session_paused: boolean; grace_seconds?: number; pace?: number; power_cut_left?: number; violation_threshold?: number; violation_action?: string };
type Message = { id: number; kind: string; body: string };
type Policy = Record<string, boolean | number | string>;
type Props = {
    candidate: { id: number; seat: string | null; extra_time: number; accommodations: Record<string, unknown>; consent_given: boolean; attempts_used: number; max_attempts: number; has_open_attempt: boolean; can_reattempt: boolean };
    exam: { title: string; type: string; instructions: string | null; duration: number; channels: string[]; needs_pin: boolean; proctoring: Policy; starts_at: string; venue: string | null; practice: boolean; notice?: string; compat?: { name: string; minimum: number | null; match: string; status: string; note: string }[] };
};

const CLOSED = ['submitted', 'auto_submitted', 'terminated'];

/**
 * The exam room. One page for every channel: in a browser it talks to the exam API
 * directly; inside the desktop exam app or Safe Exam Browser the shell adds its own
 * signed headers to the same requests. Answers are queued on this device and survive
 * reloads, crashes and network loss (Target 24-26, 98, 132-133).
 */
export default function ExamRoom(props: Props) {
    const [phase, setPhase] = useState<'check' | 'exam' | 'done'>('check');
    const [token, setToken] = useState<string | null>(null);
    const [startedOffline, setStartedOffline] = useState(false);
    const [receipt, setReceipt] = useState<{ submitted_at?: string; hash?: string; answered?: number; score?: { raw: number; max: number } | null; reason?: string } | null>(null);
    // recording pieces still waiting to be sent; the uploader outlives the exam screen so nothing is left behind
    const [waiting, setWaiting] = useState(0);
    const uploader = useMemo(() => (token ? new RecordingUploader(props.candidate.id, () => token, setWaiting) : null), [token, props.candidate.id]);
    useEffect(() => {
        uploader?.start();

        return () => uploader?.stop();
    }, [uploader]);

    // what this computer kept of an exam under way: the paper, the clock and every answer
    const store = useMemo(() => new ExamStore(props.candidate.id), [props.candidate.id]);
    const [snap, setSnap] = useState<Snapshot | null>(null);
    // the computer went off (power cut, crash, flat battery) while the exam was open: the gap is reported
    // so the time is given back; closing the page on purpose gives nothing back
    const [off, setOff] = useState<OffReport | null>(null);
    useEffect(() => {
        keepExamPageOffline();
        void store.load().then((loaded) => {
            const to = Date.now() + (loaded.session?.clockOffset ?? 0);

            if (loaded.session && loaded.exit !== 'clean' && loaded.aliveAt && to - loaded.aliveAt >= 20000) {
                setOff({ from: Math.round(loaded.aliveAt), to: Math.round(to), kind: 'off' });
            }

            setSnap(loaded);
        });
    }, [store]);

    return (
        <>
            <Head title={props.exam.title} />
            {phase === 'check' && <SystemCheck {...props} stored={snap} off={off} onStarted={(t, offline) => (setToken(t), setStartedOffline(offline), setPhase('exam'))} />}
            {phase === 'exam' && token && snap && (
                <Exam {...props} token={token} uploader={uploader} store={store} snap={snap} off={startedOffline ? off : null} startedOffline={startedOffline}
                    onFinished={(r) => (setReceipt(r), setPhase('done'))} />
            )}
            {phase === 'done' && <Done receipt={receipt} waiting={waiting} />}
            <Toaster />
        </>
    );
}

type OffReport = { from: number; to: number; kind: 'off' | 'asleep' };

type DesktopBridge = { onEvent: (cb: (type: string, data?: Record<string, unknown>) => void) => () => void; examState: (s: 'open' | 'closed', opts?: { closeApps?: boolean }) => void };
const desktop = typeof window !== 'undefined' ? (window as unknown as { examDesktop?: DesktopBridge }).examDesktop : undefined;

/* ─── Pre-exam checks, consent, PIN (Target 80, 127-129, 175, 202-203) ───────── */
function SystemCheck({ candidate, exam, stored, off, onStarted }: Props & { stored: Snapshot | null; off: OffReport | null; onStarted: (token: string, offline: boolean) => void }) {
    const p = exam.proctoring;
    const [checks, setChecks] = useState<{ label: string; ok: boolean | null; note?: string }[]>([]);
    const [consent, setConsent] = useState(candidate.consent_given);
    const [pin, setPin] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
    const streamRef = useRef<MediaStream | null>(null);
    // an exam already under way on this computer (or on the server) continues without repeating the checks
    const kept = stored?.session ?? null;
    // a copy left on this computer from an attempt that has since closed: the server asks for the full start
    const [fresh, setFresh] = useState(false);
    const resuming = (candidate.has_open_attempt || !!kept) && !fresh;
    const preList = useMemo(() => (Array.isArray(p.pre_checks) ? (p.pre_checks as unknown as CheckKey[]) : []), [p.pre_checks]);
    const showPre = !resuming && preList.length > 0;
    const [passed, setPassed] = useState<{ checks: Record<string, boolean>; details: Record<string, string> }>({ checks: {}, details: {} });
    const onPre = useCallback((c: Record<string, boolean>, d: Record<string, string>) => setPassed({ checks: c, details: d }), []);
    const preDone = !showPre || preList.every((k) => passed.checks[k]);

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => (window.removeEventListener('online', up), window.removeEventListener('offline', down));
    }, []);

    useEffect(() => {
        if (showPre) {
            return; // the chosen pre-exam checks below do this, one by one
        }

        (async () => {
            const list: { label: string; ok: boolean | null; note?: string }[] = [];
            list.push({ label: 'Secure connection (needed for camera)', ok: window.isSecureContext, note: window.isSecureContext ? undefined : 'Use the https address or the desktop app.' });
            list.push({ label: 'Modern browser', ok: 'fetch' in window && 'IntersectionObserver' in window });
            const t = performance.now();
            // browser against the institution's compatibility list (Target 169)
            const ua = navigator.userAgent;
            const rule = (exam.compat ?? []).find((b) => new RegExp(b.match).test(ua));

            if (rule) {
                const key = rule.match.split('|')[0].replace('/', '');
                const version = Number(new RegExp(`${key}/(\\d+)`).exec(ua)?.[1] ?? 0);
                const old = rule.minimum !== null && version > 0 && version < rule.minimum;
                list.push({ label: `Browser: ${rule.name}${version ? ` ${version}` : ''}`, ok: rule.status === 'unsupported' ? false : rule.status === 'limited' || old ? null : true,
                    note: rule.status === 'unsupported' ? 'This browser cannot run exams. Use Chrome, Edge or Firefox, or the desktop exam app.' : old ? `Update to version ${rule.minimum} or later.` : rule.note || undefined });
            }

            const up = await fetch('/up', { cache: 'no-store' }).then((r) => r.ok).catch(() => false);
            list.push({ label: `Server reachable${up ? ` (${Math.round(performance.now() - t)} ms)` : ''}`, ok: up ? true : kept ? null : false,
                note: !up && kept ? 'Not connected. You can carry on with the exam saved on this computer.' : undefined });

            if (p.require_fullscreen) {
                list.push({ label: 'Full screen available', ok: !!document.documentElement.requestFullscreen });
            }

            if (p.detect_multiple_displays) {
                const extended = (window.screen as Screen & { isExtended?: boolean }).isExtended;
                list.push({ label: 'Single display', ok: extended === undefined ? null : !extended, note: extended ? 'Disconnect extra monitors or projectors before starting.' : extended === undefined ? 'Your browser cannot report this; the invigilator may check.' : undefined });
            }

            if (p.webcam || p.face_detection || p.live_video || p.record_video || p.microphone || (p.identity_check && p.identity_check !== 'none') || p.gaze_tracking) {
                try {
                    streamRef.current = await navigator.mediaDevices.getUserMedia({ video: !!(p.webcam || p.face_detection || p.live_video || p.record_video || (p.identity_check && p.identity_check !== 'none') || p.gaze_tracking), audio: !!p.microphone });
                    list.push({ label: 'Camera / microphone allowed', ok: true });
                } catch {
                    list.push({ label: 'Camera / microphone allowed', ok: false, note: 'Allow camera access in your browser, then reload.' });
                }
            }

            setChecks(list);
        })();

        return () => streamRef.current?.getTracks().forEach((t) => t.stop());
    }, [p, exam.compat, showPre, kept]);

    const blocking = !showPre && checks.some((c) => c.ok === false && !c.label.startsWith('Single display'));

    const carryOnOffline = () => {
        if (kept) {
            streamRef.current?.getTracks().forEach((t) => t.stop());
            onStarted(kept.token, true);
        }
    };

    const start = async () => {
        setBusy(true);
        setError(null);

        if (p.require_fullscreen) {
            await document.documentElement.requestFullscreen?.().catch(() => undefined);
        }

        let res: { ok: boolean; status: number; data: { token?: string; message?: string; reason?: string } };

        try {
            res = await sendJson(`/my/exams/${candidate.id}/start`, 'POST', {
                pin: pin || null, consent,
                ...(showPre ? { checks: passed.checks, check_details: passed.details } : {}),
                ...(resuming && off ? { off } : {}),
            });
        } catch {
            res = { ok: false, status: 0, data: {} };
        }

        setBusy(false);

        if (res.ok && res.data.token) {
            streamRef.current?.getTracks().forEach((t) => t.stop());
            onStarted(res.data.token, false);
        } else if ((res.status === 0 || res.status >= 502) && kept) {
            // the server cannot be reached: carry on with the exam kept on this computer
            carryOnOffline();
        } else {
            if (['pre_checks', 'consent_required', 'pin_required'].includes(res.data.reason ?? '') && resuming) {
                setFresh(true);
            }

            setError(res.status === 0 ? 'Cannot reach the exam server. Check the connection, then try again.' : res.data.message ?? 'Could not start the exam.');
        }
    };

    return (
        <div className="min-h-screen bg-background">
            {kept && !online && (
                <div role="alert" className="flex flex-wrap items-center justify-center gap-3 bg-[#b91c1c] px-4 py-3 text-sm font-medium text-white">
                    <WifiOff className="size-4" /> No internet connection. Your exam and answers are saved on this computer.
                    <button onClick={carryOnOffline} className="rounded-lg bg-white px-3 py-1.5 font-semibold text-[#b91c1c]">Continue my exam</button>
                </div>
            )}
            <div className="mx-auto grid max-w-5xl gap-8 px-6 py-12 lg:grid-cols-[1fr_380px]">
                <div className="grid content-start gap-6">
                    <p className="eyebrow">{exam.practice ? 'Practice exam' : 'Exam room'}</p>
                    <h1 className="font-sans text-3xl font-semibold tracking-tight md:text-4xl">{exam.title}</h1>
                    <p className="text-muted-foreground">
                        {exam.duration + candidate.extra_time} minutes{candidate.extra_time ? ` (includes ${candidate.extra_time} extra)` : ''} · {exam.venue ?? 'online'}
                        {candidate.seat ? ` · seat ${candidate.seat}` : ''}
                    </p>
                    {exam.instructions && <div className="surface p-5 text-sm leading-relaxed whitespace-pre-wrap">{exam.instructions}</div>}
                    {exam.notice && <div role="note" className="rounded-2xl border border-status-warning/40 bg-status-warning/10 p-5 text-sm leading-relaxed whitespace-pre-wrap">{exam.notice}</div>}
                    <div className="surface p-5 text-sm leading-relaxed">
                        <p className="eyebrow mb-3 flex items-center gap-2"><Shield className="size-3.5" /> What is monitored</p>
                        <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                            {(p.detect_tab_switch || p.detect_focus_loss) && <li>Leaving the exam screen (another tab, window or app) gives you a warning.</li>}
                            {p.require_fullscreen && <li>The exam must stay in full screen. Leaving full screen gives you a warning.</li>}
                            {p.block_copy_paste && <li>Copy and paste are disabled.</li>}
                            {p.detect_multiple_displays && <li>Extra monitors or screen projection are reported.</li>}
                            {p.webcam && <li>Your camera takes a picture every {String(p.snapshot_seconds)} seconds.</li>}
                            {p.face_detection && <li>Missing or extra faces are flagged for a person to review.</li>}
                            {p.identity_check === 'photo_match' && <li>Your face is compared with your enrolled face and ID photo when you start{Number(p.identity_recheck_minutes) > 0 ? ' and from time to time during the exam' : ''}. A mismatch is checked by a person, never decided by the computer alone.</li>}
                            {p.identity_check === 'proctor_review' && <li>A photo is taken when you start so a proctor can confirm who you are.</li>}
                            {p.gaze_tracking && <li>Turning away from the screen for a long time is noted for review.</li>}
                            {p.microphone && <li>Your microphone is on.</li>}
                            {p.screen_share && <li>You will be asked to share your screen.</li>}
                            {(p.record_video || p.record_screen) && <li>Your {[p.record_video && 'camera', p.record_video && p.microphone && 'microphone', p.record_screen && 'screen'].filter(Boolean).join(' and ')} {p.record_video && p.microphone ? 'are' : 'is'} recorded for the whole exam, for authorised staff to review.</li>}
                            {p.live_video && <li>Proctors may watch your camera live.</li>}
                            <li>Your answers are saved on this computer as you write and sent to the server automatically. If the internet goes off, keep writing: nothing is lost.</li>
                            <li>If the computer goes off (power cut), come back to this page: your answers are still here and the lost time is given back.</li>
                            <li>
                                After {String(p.violation_threshold)} warnings the exam is {p.violation_action === 'submit' ? 'submitted automatically' : p.violation_action === 'lock' ? 'locked until an administrator or invigilator unlocks it' : 'reported'}.
                            </li>
                            <li>Recordings and images are kept only for the period set by your institution, then deleted.</li>
                        </ul>
                    </div>
                </div>
                <aside className="surface grid content-start gap-4 p-6">
                    {showPre ? (
                        <PreChecks checks={preList} compat={exam.compat ?? []} onChange={onPre} />
                    ) : (
                        <>
                            <p className="eyebrow">{resuming ? 'Continue your exam' : 'System check'}</p>
                            <ul className="space-y-2 text-sm">
                                {checks.length === 0 && <li className="flex items-center gap-2 text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Checking…</li>}
                                {checks.map((c) => (
                                    <li key={c.label}>
                                        <span className={`flex items-center gap-2 ${c.ok === false ? 'text-status-critical' : c.ok ? 'text-status-good' : 'text-status-warning'}`}>
                                            {c.ok === false ? <AlertTriangle className="size-4" /> : <CheckCircle2 className="size-4" />}
                                            {c.label}
                                        </span>
                                        {c.note && <span className="ml-6 block text-xs text-muted-foreground">{c.note}</span>}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    {exam.needs_pin && !resuming && (
                        <label className="grid gap-1 text-sm">
                            <span className="font-medium">Exam PIN</span>
                            <input className="h-11 rounded-xl border border-input bg-background px-3 font-mono text-lg tracking-widest" inputMode="numeric" autoComplete="off" value={pin} onChange={(e) => setPin(e.target.value)} />
                        </label>
                    )}
                    {p.require_consent && !candidate.consent_given && !resuming && (
                        <label className="flex items-start gap-2 text-sm">
                            <input type="checkbox" className="mt-1" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
                            I have read what is monitored and agree to it for this exam.
                        </label>
                    )}
                    {error && <p className="rounded-xl bg-status-critical/10 p-3 text-sm text-status-critical">{error}</p>}
                    {candidate.max_attempts > 1 && !resuming && (
                        <p className="text-xs text-muted-foreground">
                            {candidate.attempts_used === 0 ? `You may attempt this exam ${candidate.max_attempts} times.` : `You have used ${candidate.attempts_used} of ${candidate.max_attempts} attempts.`}
                        </p>
                    )}
                    {showPre && !preDone && <p className="text-xs text-muted-foreground">Start becomes available when every check above has passed.</p>}
                    <button
                        onClick={start}
                        disabled={busy || blocking || !preDone || (!resuming && !!p.require_consent && !consent && !candidate.consent_given)}
                        className={`h-12 rounded-2xl font-medium transition hover:brightness-110 disabled:opacity-40 ${candidate.can_reattempt && !resuming ? 'bg-[#b45309] font-semibold text-white' : 'bg-brand text-brand-foreground'}`}
                    >
                        {busy ? 'Starting…' : resuming ? 'Continue exam' : candidate.can_reattempt ? `Re-attempt (${candidate.attempts_used + 1} of ${candidate.max_attempts})` : 'Start exam'}
                    </button>
                    {exam.channels.includes('seb') && (
                        <a href={`/my/exams/${candidate.id}/seb`} className="text-center text-xs text-muted-foreground underline-offset-4 hover:underline">
                            Open in Safe Exam Browser instead
                        </a>
                    )}
                    {exam.channels.includes('seb') && (
                        <a href="/safe-exam-browser" className="-mt-2 text-center text-xs text-muted-foreground underline-offset-4 hover:underline">
                            Get Safe Exam Browser
                        </a>
                    )}
                </aside>
            </div>
        </div>
    );
}

/* ─── The exam itself ─────────────────────────────────────────────────── */
function Exam({ candidate, exam, token, uploader, store, snap, off, startedOffline, onFinished }: Props & { token: string; uploader: RecordingUploader | null; store: ExamStore; snap: Snapshot; off: OffReport | null; startedOffline: boolean; onFinished: (r: Record<string, unknown> & { reason?: string }) => void }) {
    const [paper, setPaper] = useState<Paper | null>(null);
    const [answers, setAnswers] = useState<Record<number, Answer>>({});
    const [index, setIndex] = useState(0);
    const [state, setState] = useState<AttemptState | null>(null);
    const [settings, setSettings] = useState<Record<string, unknown>>({});
    // "online" means the exam server answered the last request (not only that the computer has a network)
    const [online, setOnline] = useState(!startedOffline && navigator.onLine);
    const [backOnline, setBackOnline] = useState(false);
    const [pending, setPending] = useState(0);
    const [roomScan, setRoomScan] = useState(false);
    const [strike, setStrike] = useState<{ count: number; why: string } | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [blockedReason, setBlockedReason] = useState<string | null>(null);
    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const zoomKey = `exam:zoom:${candidate.id}`;
    const [fontScale, setFontScaleState] = useState(() => {
        const saved = Number(localStorage.getItem(zoomKey));

        return saved >= 0.8 && saved <= 2 ? saved : Number(candidate.accommodations?.font_scale ?? 1);
    });
    const setFontScale = (v: number) => {
        const next = Math.round(Math.min(2, Math.max(0.8, v)) * 10) / 10;
        setFontScaleState(next);
        localStorage.setItem(zoomKey, String(next));
    };
    const [showNavigator, setShowNavigator] = useState(false);
    const [timerNotice, setTimerNotice] = useState<{ text: string; tone: 'half' | 'final' } | null>(null);
    const [contrast, setContrast] = useState(Boolean(candidate.accommodations?.high_contrast));
    const [warningToast, setWarningToast] = useState<string | null>(null);
    // "Report this question": the question being reported, those already reported, and reports still to send
    const [reporting, setReporting] = useState<PaperQuestion | null>(null);
    const [reported, setReported] = useState<Record<number, true>>({});
    const unsentReports = useRef<{ question_id: number; reason: string; note: string }[]>([]);

    const seq = useRef(0);
    const queue = useRef<Record<number, Answer>>({});
    const all = useRef<Record<number, Answer>>({});
    const offlineSince = useRef<number | null>(null);
    // a gap in which this computer was off or asleep, still to be reported to the server (time is given back)
    const pendingOff = useRef<OffReport | null>(off);
    const clock = useRef(snap.session?.clockOffset ?? 0);
    const events = useRef<{ type: string; at: string; data?: Record<string, unknown> }[]>([]);
    const [clockOffset, setClockOffset] = useState(0);
    const submitRef = useRef<() => void>(() => undefined);
    const finished = useRef(false);
    const timeUp = useRef(false);
    const submitTries = useRef(0);
    const [submitting, setSubmitting] = useState(false);
    const [timeIsUp, setTimeIsUp] = useState(false);
    const recorders = useRef<PieceRecorder[]>([]);
    const [recState, setRecState] = useState<'off' | 'recording' | 'failed'>('off');
    const policy = exam.proctoring;
    const auth = useMemo(() => ({ Authorization: `Bearer ${token}` }), [token]);

    const reach = useCallback((ok: boolean) => {
        if (!ok && offlineSince.current === null) {
            offlineSince.current = Date.now();
        }

        if (ok && offlineSince.current !== null) {
            offlineSince.current = null;
            setBackOnline(true);
        }

        setOnline(ok);
    }, []);

    const api = useCallback(async <T,>(path: string, method = 'GET', body?: unknown): Promise<{ ok: boolean; status: number; data: T }> => {
        try {
            const res = await fetch(`/api/exam/attempt/${path}`, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...auth },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            const data = (await res.json().catch(() => ({}))) as T;
            // a proxy or a restarting server answers 502-504: that is not the exam server yet
            reach(res.status < 502 || res.status > 504);

            return { ok: res.ok, status: res.status, data };
        } catch {
            reach(false);

            return { ok: false, status: 0, data: {} as T };
        }
    }, [auth, reach]);

    /** Sends a question report; if the connection is down it waits and goes with the next try. */
    const sendReports = useCallback(async () => {
        while (unsentReports.current.length) {
            const r = unsentReports.current[0];
            const res = await api('report', 'POST', r);

            if (res.status === 0 || res.status >= 500) {
                return; // offline: try again shortly
            }

            unsentReports.current.shift();
        }
    }, [api]);
    useEffect(() => {
        const t = window.setInterval(() => void sendReports(), 15000);

        return () => window.clearInterval(t);
    }, [sendReports]);

    const finish = useCallback((r: Record<string, unknown> & { reason?: string }) => {
        if (finished.current) {
            return;
        }

        finished.current = true;
        recorders.current.forEach((r) => void r.stop());
        desktop?.examState('closed');
        void store.clear();
        document.exitFullscreen?.().catch(() => undefined);
        onFinished(r);
    }, [onFinished, store]);

    const handleStatus = useCallback((status: number, data: { reason?: string; message?: string }) => {
        if (status === 409 && data.reason === 'session_moved') {
            setBlockedReason(data.message ?? 'This exam was opened elsewhere.');
        } else if (status === 409) {
            finish({ reason: 'closed' });
        } else if (status === 423) {
            setBlockedReason(data.message ?? 'Paused. Your answers are safe.');
        } else if (status === 401) {
            setBlockedReason('Your exam session expired. Go back to My exams and open it again.');
        }
    }, [finish]);

    const record = useCallback((type: string, data?: Record<string, unknown>) => {
        events.current.push({ type, at: new Date().toISOString(), data });
    }, []);

    /* The desktop exam app reports hardware checks (screens, HDMI/wireless, USB, VMs, remote access) and keeps the window locked while the exam is open */
    useEffect(() => {
        if (!desktop) {
            return;
        }

        desktop.examState('open', { closeApps: !!policy.close_banned_apps });
        const off = desktop.onEvent((type, data) => record(type, data));

        return () => {
            off();
        };
    }, [record, policy.close_banned_apps]);

    /* Noise alerts: the microphone level is measured on this computer; only "noise for N seconds" is reported (Target 31) */
    useEffect(() => {
        if (!policy.microphone) {
            return;
        }

        let stop = () => {};
        let own: MediaStream | null = null;
        let cancelled = false;
        (async () => {
            const s = (own = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false } }).catch(() => null));

            if (s && !cancelled) {
                const { watchNoise } = await import('@/lib/noise');
                stop = watchNoise(s, (level, seconds) => record('noise_detected', { level, seconds }));
            }
        })();

        return () => {
            cancelled = true;
            stop();
            own?.getTracks().forEach((t) => t.stop());
        };
    }, [policy.microphone, record]);

    /* Load the paper. Anything written on this computer and not yet on the server is kept (newest wins).
       When the server cannot be reached, the copy kept on this computer opens instead and the exam goes on. */
    const kept = snap.session;
    const openKept = useCallback((): boolean => {
        if (!kept) {
            return false;
        }

        const saved = { ...kept.state } as AttemptState;

        // time given back here for a power cut, until the server confirms it
        if (pendingOff.current && saved.status === 'in_progress') {
            const left = Number(saved.power_cut_left ?? 0);
            const gap = Math.max(0, Math.floor((pendingOff.current.to - pendingOff.current.from) / 1000));
            const credit = Math.min(gap, left);
            saved.deadline_at = new Date(new Date(saved.deadline_at).getTime() + credit * 1000).toISOString();
            saved.power_cut_left = left - credit;
        }

        const merged: Record<number, Answer> = { ...(snap.answers as Record<number, Answer>) };

        for (const [qid, a] of Object.entries(snap.queue as Record<number, Answer>)) {
            if (!merged[Number(qid)] || a.seq >= merged[Number(qid)].seq) {
                merged[Number(qid)] = a;
            }

            queue.current[Number(qid)] = a;
        }

        all.current = merged;
        clock.current = kept.clockOffset;
        seq.current = Math.max(seq.current, Date.now() % 1_000_000_000, ...Object.values(merged).map((a) => a.seq + 1));
        setPaper(kept.paper as Paper);
        setAnswers(merged);
        setSettings(kept.settings);
        setState(saved);
        setClockOffset(kept.clockOffset);
        setPending(Object.keys(queue.current).length);

        return true;
    }, [kept, snap]);

    const loadPaper = useCallback(async (cancelled: () => boolean) => {
        let res = await api<{ attempt: AttemptState; paper: Paper; answers: Record<number, Answer>; settings: Record<string, unknown>; reason?: string }>('paper');
        let usingKept = false;

        // everyone opens the paper at the same moment: if the server is busy or the network blips, keep trying;
        // with a copy on this computer the candidate writes from it meanwhile
        for (let n = 1; !cancelled() && !res.ok && (res.status === 0 || res.status === 429 || res.status >= 500); n++) {
            if (!usingKept && kept && kept.attemptId) {
                usingKept = openKept();
            }

            if (!usingKept) {
                setWarningToast('Connecting to the exam server… your paper will appear in a moment.');
            }

            await new Promise((r) => setTimeout(r, Math.min(15000, 1500 * n) + Math.random() * 1000));
            res = await api('paper');
        }

        if (cancelled()) {
            return;
        }

        if (res.status === 428 && res.data.reason === 'room_scan') {
            setRoomScan(true);

            return;
        }

        if (!res.ok) {
            handleStatus(res.status, res.data as { reason?: string });

            return;
        }

        setWarningToast(null);

        // answers kept on this computer for this same attempt (not one from an earlier attempt)
        const sameAttempt = !kept || kept.attemptId === res.data.attempt.id;
        const local: Record<number, Answer> = sameAttempt ? { ...(snap.answers as Record<number, Answer>), ...(snap.queue as Record<number, Answer>) } : {};
        const merged: Record<number, Answer> = { ...res.data.answers };

        for (const src of [local, all.current, queue.current]) {
            for (const [qid, a] of Object.entries(src)) {
                if (!merged[Number(qid)] || a.seq > merged[Number(qid)].seq) {
                    merged[Number(qid)] = a;
                    queue.current[Number(qid)] = a;
                }
            }
        }

        const offset = new Date(res.data.attempt.server_time).getTime() - Date.now();
        all.current = merged;
        clock.current = offset;
        seq.current = Math.max(seq.current, Date.now() % 1_000_000_000, ...Object.values(merged).map((a) => a.seq + 1));
        paperRef.current = res.data.paper;
        setPaper(res.data.paper);
        setAnswers(merged);
        setSettings(res.data.settings);
        setState(res.data.attempt);
        setClockOffset(offset);
        setPending(Object.keys(queue.current).length);
        store.saveSession({ candidateId: candidate.id, attemptId: res.data.attempt.id, token, paper: res.data.paper, state: res.data.attempt, settings: res.data.settings, clockOffset: offset, savedAt: Date.now() + offset });
        store.saveAnswers(all.current as Record<number, StoredAnswer>, queue.current as Record<number, StoredAnswer>);
    }, [api, handleStatus, kept, snap, store, token, candidate.id, openKept]);

    useEffect(() => {
        let cancelled = false;
        const t = window.setTimeout(() => void loadPaper(() => cancelled), 0);

        return () => {
            cancelled = true;
            window.clearTimeout(t);
        };
    }, [loadPaper]);

    const lastBeat = useRef(0);
    // the latest clock from the server, kept on this computer too (so a restart offline shows the right time)
    const keepState = useRef<(a: AttemptState) => void>(() => undefined);
    const beatNow = useRef<() => void>(() => undefined);
    // warnings given on this screen; the server's count (state.violations) is the one that counts
    const strikesHere = useRef(0);
    // the exam itself opens a browser prompt (screen share, camera choice): not a departure
    const expectedBlur = useRef(0);
    const allowPrompt = useCallback((ms: number) => {
        expectedBlur.current = Date.now() + ms;
    }, []);
    const paperRef = useRef<unknown>(kept?.paper ?? null);
    const pace = useRef(1);
    const applyBeat = useCallback((data: { attempt: AttemptState; messages?: Message[] }) => {
        lastBeat.current = Date.now();
        pace.current = Math.max(1, Math.min(4, Number(data.attempt.pace ?? 1)));
        setState(data.attempt);
        clock.current = new Date(data.attempt.server_time).getTime() - Date.now();
        setClockOffset(clock.current);

        if (data.messages) {
            setMessages(data.messages);
        }

        keepState.current(data.attempt);

        if (CLOSED.includes(data.attempt.status)) {
            finish({ reason: data.attempt.status });
        }

        setBlockedReason(data.attempt.status === 'locked' ? 'Your exam is locked after too many warnings. An administrator or invigilator must unlock it before you can continue. Your answers are safe.' : data.attempt.status === 'paused' || data.attempt.session_paused ? 'The exam is paused. The clock is stopped and your answers are safe.' : null);
    }, [finish]);

    /* Flush queued answers (also on a timer and when the network returns) */
    const flush = useCallback(async () => {
        const items = Object.entries(queue.current).map(([qid, a]) => ({ question_id: Number(qid), seq: a.seq, flagged: a.flagged, response: a.response, at: a.at }));

        if (!items.length) {
            return;
        }

        const offReport = pendingOff.current;

        // the check-in rides along with the save when one is due soon, so a separate one is not needed
        const withBeat = Date.now() - lastBeat.current > (Number(settings.heartbeat_seconds ?? 15) * 1000) / 2;
        const batch = withBeat ? events.current.splice(0, 50) : [];
        const res = await api<{ saved: number[]; remaining: number; reason?: string; message?: string; attempt?: AttemptState; messages?: Message[]; credited?: number }>('answers', 'POST', {
            answers: items,
            ...(offReport ? { off: offReport } : {}),
            ...(withBeat ? { status: !navigator.onLine ? 'offline' : document.hidden ? 'blur' : 'active', events: batch } : {}),
        });

        if (!res.ok && batch.length) {
            events.current.unshift(...batch);
        }

        if (res.ok && pendingOff.current === offReport) {
            pendingOff.current = null;
        }

        if (res.ok && res.data.attempt) {
            applyBeat({ attempt: res.data.attempt, messages: res.data.messages });
        }

        // the exam moved to another computer: what this one kept has been offered to it; stop here
        if (res.status === 409 && res.data.reason === 'session_moved') {
            for (const id of res.data.saved ?? []) {
                delete queue.current[id];
            }

            store.saveQueue(queue.current as Record<number, StoredAnswer>);
            setPending(Object.keys(queue.current).length);
        }

        if (res.ok) {
            for (const id of [...res.data.saved, ...((res.data as { rejected?: number[] }).rejected ?? [])]) {
                const sent = items.find((i) => i.question_id === id);

                if (sent && queue.current[id]?.seq === sent.seq) {
                    delete queue.current[id];
                }
            }

            store.saveQueue(queue.current as Record<number, StoredAnswer>);
            setPending(Object.keys(queue.current).length);
        } else {
            handleStatus(res.status, res.data);
        }
    }, [api, handleStatus, store, settings.heartbeat_seconds, applyBeat]);

    useEffect(() => {
        const t = window.setInterval(flush, Number(settings.autosave_seconds ?? 10) * 1000);
        const onOnline = () => void flush();
        window.addEventListener('online', onOnline);

        return () => (window.clearInterval(t), window.removeEventListener('online', onOnline));
    }, [flush, settings.autosave_seconds]);

    const answer = (q: PaperQuestion, response: Response, flagged?: boolean) => {
        if (timeUp.current || finished.current) {
            return; // time is up: nothing more can be written
        }

        // the moment it was written (server clock) goes with it, so it counts even if it arrives late
        const a: Answer = { response, flagged: flagged ?? answers[q.id]?.flagged ?? false, seq: ++seq.current, at: Math.round(Date.now() + clock.current) };
        setAnswers((prev) => ({ ...prev, [q.id]: a }));
        queue.current[q.id] = a;
        all.current = { ...all.current, [q.id]: a };
        store.saveAnswers(all.current as Record<number, StoredAnswer>, queue.current as Record<number, StoredAnswer>);
        setPending(Object.keys(queue.current).length);
        // a busy server asks for fewer, larger saves; the answer is already kept on this device
        window.setTimeout(flush, 800 * pace.current);
    };

    /* Heartbeat: status + proctoring events, receives status changes and messages. Skipped when a save
       carried it recently; a busy server stretches the interval (pace) and answers stay safe on the device. */
    useEffect(() => {
        const every = Number(settings.heartbeat_seconds ?? 15) * 1000;
        const beat = async (force = false) => {
            if (!force && Date.now() - lastBeat.current < every * pace.current - 1000) {
                return;
            }

            const status = !navigator.onLine ? 'offline' : document.hidden ? 'blur' : 'active';
            const batch = events.current.splice(0, 50);
            const offReport = pendingOff.current;
            const res = await api<{ attempt: AttemptState; messages: Message[] }>('heartbeat', 'POST', { status, events: batch, ...(offReport ? { off: offReport } : {}) });

            if (res.ok && pendingOff.current === offReport) {
                pendingOff.current = null;
            }

            if (!res.ok) {
                events.current.unshift(...batch);
                handleStatus(res.status, res.data as { reason?: string });

                return;
            }

            applyBeat(res.data);
        };
        void beat(true);
        const t = window.setInterval(() => void beat(), Math.min(every, 5000));
        beatNow.current = () => void beat(true);

        return () => window.clearInterval(t);
    }, [api, applyBeat, handleStatus, settings.heartbeat_seconds]);

    /* Keep the clock on this computer, note that the page is alive (every 5 s), and spot a computer that
       was asleep or frozen: a gap of more than 30 s in which the page was on screen is reported and the
       time is given back (up to the exam's limit). Time while the page was hidden is never given back. */
    useEffect(() => {
        keepState.current = (a) => store.saveState(a, clock.current);
    }, [store]);
    useEffect(() => {
        let last = Date.now();
        let visible = !document.hidden;
        store.alive(last + clock.current);
        const t = window.setInterval(() => {
            const now = Date.now();

            if (now - last > 30000 && visible && !finished.current) {
                const from = Math.round(last + clock.current);
                const to = Math.round(now + clock.current);
                pendingOff.current = pendingOff.current ? { from: Math.min(pendingOff.current.from, from), to, kind: pendingOff.current.kind } : { from, to, kind: 'asleep' };
                // until the server confirms, move the clock here as well
                setState((st) => {
                    if (!st || st.status !== 'in_progress') {
                        return st;
                    }

                    const credit = Math.min(Math.floor((to - from) / 1000), Number(st.power_cut_left ?? 0));

                    return { ...st, deadline_at: new Date(new Date(st.deadline_at).getTime() + credit * 1000).toISOString(), power_cut_left: Number(st.power_cut_left ?? 0) - credit };
                });
                beatNow.current();
            }

            last = now;
            visible = !document.hidden;

            if (!finished.current) {
                store.alive(now + clock.current);
            }
        }, 5000);
        const onHide = () => store.leftCleanly();
        const onShow = () => store.alive(Date.now() + clock.current);
        window.addEventListener('pagehide', onHide);
        window.addEventListener('pageshow', onShow);

        return () => (window.clearInterval(t), window.removeEventListener('pagehide', onHide), window.removeEventListener('pageshow', onShow));
    }, [store]);

    /* Back online: send everything kept on this computer straight away and say so */
    useEffect(() => {
        if (!backOnline) {
            return;
        }

        void flush();
        beatNow.current();
        const t = window.setTimeout(() => setBackOnline(false), 6000);

        return () => window.clearTimeout(t);
    }, [backOnline, flush]);

    /* Browser proctoring detectors (Target 70-74, 125, 128) */
    useEffect(() => {
        /* Strikes. Leaving the exam screen (another tab, window or app, or out of full screen) is one
           warning per departure, however it happens. After the exam's limit (5 by default) the exam is
           locked until an administrator or invigilator unlocks it. A short blur (a permission prompt, the
           browser's own dialogs) is not a departure; nor is anything in the first seconds of the exam,
           while the browser asks for the camera. */
        let away: string | null = null;
        let blurTimer = 0;
        const quietUntil = Date.now() + 15000;
        const leave = (why: string, type: string) => {
            if (finished.current || timeUp.current || Date.now() < quietUntil || Date.now() < expectedBlur.current) {
                return;
            }

            if (away) {
                record('focus_lost', { also: type }); // same departure: logged, not another warning

                return;
            }

            away = why;
            record(type);
            strikesHere.current += 1;
            beatNow.current(); // tell the server now, so the limit applies at once
        };
        const back = () => {
            window.clearTimeout(blurTimer);

            if (away && !document.hidden) {
                setStrike({ count: strikesHere.current, why: away });
                away = null;
            }
        };
        const onVisibility = () => {
            if (document.hidden) {
                if (policy.detect_tab_switch) {
                    leave('You left the exam page.', 'tab_hidden');
                }
            } else {
                back();
            }
        };
        const onBlur = () => {
            if (!policy.detect_focus_loss) {
                return;
            }

            window.clearTimeout(blurTimer);
            blurTimer = window.setTimeout(() => !document.hasFocus() && leave('You switched to another window or app.', 'screen_left'), 1500);
        };
        const onFocus = () => back();
        const onFullscreen = () => {
            if (policy.require_fullscreen && !document.fullscreenElement && !finished.current) {
                leave('You left full screen.', 'fullscreen_exit');
                setWarningToast('Please return to full screen.');
            }
        };
        const block = (type: string) => (e: Event) => {
            if (policy.block_copy_paste) {
                e.preventDefault();
                record(type);
            }
        };
        const onCopy = block('copy_blocked');
        const onPaste = block('paste_blocked');
        const onContext = (e: Event) => policy.block_copy_paste && (e.preventDefault(), record('right_click'));
        const onKey = (e: KeyboardEvent) => {
            const k = e.key.toLowerCase();
            const combo = (e.ctrlKey || e.metaKey) && ['c', 'v', 'x', 'p', 's', 'u', 'a'].includes(k);

            if (policy.block_shortcuts && (combo || k === 'f12' || k === 'printscreen')) {
                if (!(k === 'a' && (e.target as HTMLElement)?.tagName === 'TEXTAREA')) {
                    e.preventDefault();
                    record('shortcut_blocked', { key: k });
                }
            }
        };
        const onOffline = () => (reach(false), record('network_offline'));
        const scr = window.screen as Screen & { isExtended?: boolean; addEventListener?: (t: string, f: () => void) => void; removeEventListener?: (t: string, f: () => void) => void };
        const onScreen = () => {
            if (policy.detect_multiple_displays) {
                record(scr.isExtended ? 'multiple_displays' : 'display_change', { width: window.screen.width, height: window.screen.height });
            }
        };

        if (policy.detect_multiple_displays && scr.isExtended) {
            record('multiple_displays');
        }

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        document.addEventListener('fullscreenchange', onFullscreen);
        document.addEventListener('copy', onCopy);
        document.addEventListener('cut', onCopy);
        document.addEventListener('paste', onPaste);
        document.addEventListener('contextmenu', onContext);
        document.addEventListener('keydown', onKey);
        window.addEventListener('offline', onOffline);
        scr.addEventListener?.('change', onScreen);
        const onUnload = (e: BeforeUnloadEvent) => {
            if (!finished.current) {
                e.preventDefault();
            }
        };
        window.addEventListener('beforeunload', onUnload);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
            window.clearTimeout(blurTimer);
            document.removeEventListener('fullscreenchange', onFullscreen);
            document.removeEventListener('copy', onCopy);
            document.removeEventListener('cut', onCopy);
            document.removeEventListener('paste', onPaste);
            document.removeEventListener('contextmenu', onContext);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('offline', onOffline);
            scr.removeEventListener?.('change', onScreen);
            window.removeEventListener('beforeunload', onUnload);
        };
    }, [policy, record, reach]);

    /* Webcam snapshots + face presence (Target 8-11, 34, 112, 116-120) */
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const camStream = useRef<MediaStream | null>(null);
    // the <video> may mount after the camera starts (it appears once the paper loads), so attach on mount too
    const attachVideo = useCallback((el: HTMLVideoElement | null) => {
        videoRef.current = el;

        if (el && camStream.current && el.srcObject !== camStream.current) {
            el.srcObject = camStream.current;
            el.play().catch(() => undefined);
        }
    }, []);
    const [identityNote, setIdentityNote] = useState<{ ok: boolean; text: string } | null>(null);
    const needsIdentity = !!policy.identity_check && policy.identity_check !== 'none';
    useEffect(() => {
        if (!(policy.webcam || policy.face_detection || needsIdentity || policy.gaze_tracking || policy.live_video || policy.record_video)) {
            return;
        }

        let stream: MediaStream | null = null;
        let timer = 0;
        let idTimer = 0;
        let gazeTimer = 0;
        let stopped = false;
        // the face model is served by this platform; if it cannot load, checks fall back to proctors
        const faceReady = policy.face_detection || needsIdentity || policy.gaze_tracking ? loadFace().then(() => true).catch(() => false) : Promise.resolve(false);

        const identityCheck = async (kind: 'entry' | 'periodic', tries = 3): Promise<void> => {
            // wait (up to 15 s) for the camera to produce frames
            for (let i = 0; i < 30 && !stopped && (!videoRef.current || videoRef.current.readyState < 2); i++) {
                await new Promise((r) => setTimeout(r, 500));
            }

            const v = videoRef.current;

            if (stopped || !v || v.readyState < 2) {
                setIdentityNote({ ok: false, text: 'Your camera is not sending a picture. A proctor will check your identity.' });

                return;
            }

            const reading = (await faceReady) ? await readFace(v, true).catch(() => null) : null;
            const fd = new FormData();
            fd.append('kind', kind);
            fd.append('faces', String(reading?.faces ?? 0));

            if (reading?.descriptor) {
                fd.append('descriptor', JSON.stringify(reading.descriptor));
            }

            const photo = await frameToJpeg(v);

            if (photo) {
                fd.append('photo', photo, 'identity.jpg');
            }

            const res = await fetch('/api/exam/attempt/identity', { method: 'POST', headers: { Accept: 'application/json', ...auth }, body: fd }).catch(() => null);
            const body = res?.ok ? ((await res.json()) as { result: string; message: string }) : null;

            if (!body) {
                return;
            }

            if (kind === 'entry' || body.result !== 'match') {
                setIdentityNote({ ok: body.result === 'match', text: body.message });
            }

            // at entry, give the candidate a few chances to face the camera properly
            if (kind === 'entry' && tries > 1 && (body.result === 'no_face' || body.result === 'multiple_faces')) {
                window.setTimeout(() => identityCheck(kind, tries - 1), 6000);
            }
        };

        (async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
            } catch {
                record('camera_off');

                return;
            }

            const track = stream.getVideoTracks()[0];
            track.addEventListener('ended', () => record('camera_off'));
            track.addEventListener('mute', () => record('camera_obstructed'));

            camStream.current = stream;
            attachVideo(videoRef.current);

            const settingsAtStart = track.getSettings().deviceId;
            const snap = async () => {
                const v = videoRef.current;

                if (!v || v.readyState < 2) {
                    return;
                }

                if (track.getSettings().deviceId !== settingsAtStart) {
                    record('camera_changed');
                }

                const canvas = document.createElement('canvas');
                canvas.width = 320;
                canvas.height = 240;
                const ctx = canvas.getContext('2d');
                ctx?.drawImage(v, 0, 0, canvas.width, canvas.height);

                if (ctx) {
                    // a black or frozen-uniform image usually means the lens is covered
                    const px = ctx.getImageData(0, 0, 320, 240).data;
                    let sum = 0;

                    for (let i = 0; i < px.length; i += 400) {
                        sum += px[i] + px[i + 1] + px[i + 2];
                    }

                    if (sum / (px.length / 400) < 30) {
                        record('camera_obstructed');
                    }
                }

                if (policy.face_detection && (await faceReady)) {
                    const reading = await readFace(canvas, false).catch(() => null);

                    if (reading?.faces === 0) {
                        record('face_absent');
                    } else if (reading && reading.faces > 1) {
                        record('multiple_faces', { faces: reading.faces });
                    }
                }

                if (policy.webcam) {
                    canvas.toBlob(async (blob) => {
                        if (!blob) {
                            return;
                        }

                        const fd = new FormData();
                        fd.append('kind', 'snapshot');
                        fd.append('file', blob, 'snapshot.jpg');
                        fd.append('captured_at', new Date().toISOString());
                        await fetch('/api/exam/attempt/evidence', { method: 'POST', headers: { Accept: 'application/json', ...auth }, body: fd }).catch(() => undefined);
                    }, 'image/jpeg', 0.7);
                }
            };

            if (policy.webcam || policy.face_detection) {
                await snap();
                timer = window.setInterval(snap, Math.max(10, Number(policy.snapshot_seconds ?? 60)) * 1000);
            }

            if (needsIdentity) {
                window.setTimeout(() => identityCheck('entry'), 1500);
                const every = Number(policy.identity_recheck_minutes ?? 0);

                if (every > 0) {
                    // a random offset so the timing cannot be predicted
                    idTimer = window.setInterval(() => window.setTimeout(() => identityCheck('periodic', 1), Math.random() * 60000), every * 60000);
                }
            }

            if (policy.gaze_tracking && (await faceReady)) {
                let away = 0;
                let quietUntil = 0;
                gazeTimer = window.setInterval(async () => {
                    const v = videoRef.current;

                    if (!v || v.readyState < 2 || Date.now() < quietUntil) {
                        return;
                    }

                    const r = await readFace(v, false).catch(() => null);
                    const looking = r && r.faces > 0 && r.yaw !== null && r.pitch !== null && Math.abs(r.yaw) < 0.35 && Math.abs(r.pitch) < 0.4;
                    away = looking || !r || r.faces === 0 ? 0 : away + 1; // no face is reported separately

                    if (away >= 3) {
                        record('gaze_away', { yaw: r?.yaw, pitch: r?.pitch });
                        away = 0;
                        quietUntil = Date.now() + 60000;
                    }
                }, 3000);
            }
        })();

        return () => {
            stopped = true;
            window.clearInterval(timer);
            window.clearInterval(idTimer);
            window.clearInterval(gazeTimer);
            stream?.getTracks().forEach((t) => t.stop());
            camStream.current = null;
        };
    }, [attachVideo, auth, needsIdentity, policy, record]);

    /* Screen sharing (Target 5, 36, 104) */
    useEffect(() => {
        if (!policy.screen_share) {
            return;
        }

        let stream: MediaStream | null = null;
        allowPrompt(60000); // the browser's screen picker is open
        navigator.mediaDevices.getDisplayMedia?.({ video: true, audio: false }).then((s) => {
            allowPrompt(2000);
            stream = s;
            screenStream.current = s;
            const track = s.getVideoTracks()[0];
            const surface = (track.getSettings() as MediaTrackSettings & { displaySurface?: string }).displaySurface;

            if (surface && surface !== 'monitor') {
                record('screen_share_stopped', { reason: `shared a ${surface}, not the whole screen` });
            }

            track.addEventListener('ended', () => record('screen_share_stopped'));
        }).catch(() => record('screen_share_stopped', { reason: 'refused' }));

        return () => {
            stream?.getTracks().forEach((t) => t.stop());
            screenStream.current = null;
        };
    }, [policy.screen_share, record, allowPrompt]);

    /* Live video, audio and screen to proctors through the platform's media server (Target 4-7, 35-36, 182) */
    const screenStream = useRef<MediaStream | null>(null);
    const liveRoom = useRef<Awaited<ReturnType<typeof publish>> | null>(null);
    const [liveState, setLiveState] = useState<'off' | 'connecting' | 'live' | 'poor' | 'lost'>('off');
    const wantsLive = !!(policy.live_video || policy.record_video || policy.record_screen);
    useEffect(() => {
        if (!wantsLive) {
            return;
        }

        let stopped = false;
        let room: Awaited<ReturnType<typeof publish>> | null = null;
        let mic: MediaStream | null = null;
        let lastPoor = 0;
        let watch: number | null = null;
        let browserRecording = false;
        // no media server: record camera (and screen) here and upload the pieces as the exam goes on
        const recordInBrowser = (plan: RecordPlan) => {
            if (browserRecording) {
                return;
            }

            browserRecording = true;

            if (!uploader || !PieceRecorder.supported()) {
                setRecState('failed');
                record('recording_failed', { reason: 'browser_cannot_record' });

                return;
            }

            const current: { camera: { stream: MediaStream | null; rec: PieceRecorder | null }; screen: { stream: MediaStream | null; rec: PieceRecorder | null } } = {
                camera: { stream: null, rec: null },
                screen: { stream: null, rec: null },
            };
            const ensure = async () => {
                if (stopped || finished.current) {
                    return;
                }

                const wanted: ('camera' | 'screen')[] = [...(plan.camera ? ['camera' as const] : []), ...(plan.screen ? ['screen' as const] : [])];

                for (const kind of wanted) {
                    const source = kind === 'camera' ? camStream.current : screenStream.current;
                    const live = source?.getVideoTracks().some((t) => t.readyState === 'live');
                    const slot = current[kind];

                    if (!source || !live || (slot.rec?.active && slot.stream === source)) {
                        continue;
                    }

                    await slot.rec?.stop();
                    let stream = source;

                    if (kind === 'camera' && plan.audio) {
                        mic ??= await navigator.mediaDevices.getUserMedia({ audio: true }).catch(() => null);
                        stream = new MediaStream([...source.getVideoTracks(), ...(mic?.getAudioTracks() ?? [])]);
                    }

                    const rec = new PieceRecorder(kind, stream, kind === 'camera' ? plan.camera_kbps : plan.screen_kbps, plan.chunk_seconds, uploader, (why) => {
                        setRecState('failed');
                        record('recording_failed', { kind, reason: why });
                    });
                    rec.start();
                    slot.stream = source;
                    slot.rec = rec;
                    recorders.current = [...recorders.current.filter((r) => r.active), rec];
                    setRecState(rec.active ? 'recording' : 'failed');
                }
            };
            void ensure();
            // a camera or screen that was switched or shared again starts a new part of the recording
            watch = window.setInterval(() => void ensure(), 5000);
        };
        const connect = async (attempt = 1): Promise<void> => {
            // wait for the camera (and the shared screen if the exam asks for it)
            for (let i = 0; i < 40 && !stopped && (!camStream.current || (policy.screen_share && !screenStream.current)); i++) {
                await new Promise((r) => setTimeout(r, 500));
            }

            if (stopped) {
                return;
            }

            const res = await api<{ enabled: boolean; url?: string; token?: string; sources?: string[]; record?: RecordPlan | null }>('media-token', 'POST');

            if (res.ok && !res.data.enabled && res.data.record) {
                recordInBrowser(res.data.record);

                return;
            }

            if (!res.ok || !res.data.enabled || !res.data.url || !res.data.token) {
                return; // not set up: snapshots carry on as before
            }

            const sources = res.data.sources ?? [];
            const tracks: { track: MediaStreamTrack; source: 'camera' | 'microphone' | 'screen_share' }[] = [];
            const cam = camStream.current?.getVideoTracks()[0];

            if (cam && sources.includes('camera')) {
                tracks.push({ track: cam, source: 'camera' });
            }

            if (sources.includes('microphone')) {
                mic ??= await navigator.mediaDevices.getUserMedia({ audio: true }).catch(() => null);
                const a = mic?.getAudioTracks()[0];

                if (a) {
                    tracks.push({ track: a, source: 'microphone' });
                } else {
                    record('microphone_off');
                }
            }

            const scr = screenStream.current?.getVideoTracks()[0];

            if (scr && sources.includes('screen_share')) {
                tracks.push({ track: scr, source: 'screen_share' });
            }

            setLiveState('connecting');

            try {
                room = await publish(res.data.url, res.data.token, tracks, (q) => {
                    setLiveState(q === 'poor' ? 'poor' : q === 'lost' ? 'lost' : 'live');

                    if ((q === 'poor' || q === 'lost') && Date.now() - lastPoor > 120000) {
                        lastPoor = Date.now();
                        record('media_poor', { quality: q });
                    }
                }, () => {
                    if (!stopped) {
                        setLiveState('lost');
                        record('media_disconnected');
                        // the media server is gone or the network dropped: keep trying for as long as the exam is open,
                        // so video and recording carry on (as a new part) when the connection comes back
                        window.setTimeout(() => connect(attempt + 1), Math.min(30000, 5000 * attempt));
                    }
                });
                liveRoom.current = room;
                setLiveState('live');
            } catch {
                setLiveState('lost');

                // the media server cannot be reached: record here instead, so nothing is lost
                if (attempt >= 2 && res.data.record) {
                    recordInBrowser(res.data.record);
                }

                if (!stopped) {
                    window.setTimeout(() => connect(attempt + 1), Math.min(30000, 5000 * attempt));
                }
            }
        };
        connect();

        return () => {
            stopped = true;
            room?.disconnect();

            if (watch) {
                window.clearInterval(watch);
            }

            recorders.current.forEach((r) => void r.stop());
            mic?.getTracks().forEach((t) => t.stop());
        };
    }, [api, policy.screen_share, record, wantsLive, uploader]);

    /* A second camera plugged in by USB, pointed at the desk (Target 113-114) */
    const usbStream = useRef<MediaStream | null>(null);
    const [usbCams, setUsbCams] = useState<{ id: string; label: string }[] | null>(null);
    const [usbOn, setUsbOn] = useState<string | null>(null);
    const listUsbCams = async () => {
        const mainId = camStream.current?.getVideoTracks()[0]?.getSettings().deviceId;
        const all = await navigator.mediaDevices.enumerateDevices().catch(() => []);
        setUsbCams(all.filter((d) => d.kind === 'videoinput' && d.deviceId && d.deviceId !== mainId).map((d, i) => ({ id: d.deviceId, label: d.label || `Camera ${i + 2}` })));
    };
    const pickUsbCam = async (id: string, label: string) => {
        usbStream.current?.getTracks().forEach((t) => t.stop());
        allowPrompt(30000);
        const s = await navigator.mediaDevices.getUserMedia({ video: { deviceId: { exact: id }, width: 640, height: 360 } }).catch(() => null);
        allowPrompt(2000);
        const track = s?.getVideoTracks()[0];

        if (!s || !track) {
            setUsbOn(null);

            return;
        }

        usbStream.current = s;
        setUsbOn(label);
        record('second_camera_linked', { kind: 'usb', label });
        track.onended = () => {
            setUsbOn(null);
            record('second_camera_lost', { kind: 'usb', label });
        };

        if (liveRoom.current) {
            const { publishExtra } = await import('@/lib/live');
            await publishExtra(liveRoom.current, track, 'usb_camera').catch(() => record('second_camera_lost', { kind: 'usb', reason: 'publish_failed' }));
        }
    };
    useEffect(() => () => usbStream.current?.getTracks().forEach((t) => t.stop()), []);

    /* Phone as a second camera (Target 111-124) */
    const [phone, setPhone] = useState<{ url: string; qr: string } | null>(null);
    const linkPhone = async () => {
        const res = await api<{ url: string; qr: string }>('phone-link', 'POST');

        if (res.ok) {
            setPhone(res.data);
        }
    };

    /* Server-authoritative countdown */
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 500);

        return () => window.clearInterval(t);
    }, []);
    const remaining = state ? (state.status === 'in_progress' && !state.session_paused ? Math.max(0, (new Date(state.deadline_at).getTime() - (now + clockOffset)) / 1000) : state.remaining_seconds) : 0;

    /* Time warnings: once at half time, then at 5 minutes and 1 minute left (kept across reloads) */
    const totalSeconds = (exam.duration + candidate.extra_time) * 60;
    const timerPhase: 'normal' | 'half' | 'final' | 'last' = !state ? 'normal' : remaining <= 60 ? 'last' : remaining <= 300 ? 'final' : remaining <= totalSeconds / 2 ? 'half' : 'normal';
    const noticesShown = useRef<Set<string>>(new Set());
    useEffect(() => {
        if (!state || state.status !== 'in_progress' || remaining <= 0) {
            return;
        }

        const key = `exam:timer:${state.id}`;

        if (noticesShown.current.size === 0) {
            noticesShown.current = new Set(JSON.parse(localStorage.getItem(key) ?? '[]') as string[]);
        }

        const fire = (id: string, text: string, tone: 'half' | 'final') => {
            if (noticesShown.current.has(id)) {
                return;
            }

            noticesShown.current.add(id);
            localStorage.setItem(key, JSON.stringify([...noticesShown.current]));
            setTimerNotice({ text, tone });
        };

        if (remaining <= 60) {
            fire('1m', 'One minute left. Your exam will be submitted automatically when time runs out.', 'final');
        } else if (remaining <= 300) {
            fire('5m', `About 5 minutes left. Check any unanswered questions.`, 'final');
        } else if (remaining <= totalSeconds / 2) {
            fire('half', `Half of your time has gone. ${formatClock(remaining)} left.`, 'half');
        }
    }, [remaining, state, totalSeconds]);
    useEffect(() => {
        if (!timerNotice) {
            return;
        }

        const t = window.setTimeout(() => setTimerNotice(null), 8000);

        return () => window.clearTimeout(t);
    }, [timerNotice]);

    /* A file answer goes up straight away (it needs a connection); the answer then saves as usual */
    const uploadFile = useCallback<Uploader>(async (questionId, file) => {
        const fd = new FormData();
        fd.append('question_id', String(questionId));
        fd.append('file', file);

        try {
            const res = await fetch('/api/exam/attempt/file', { method: 'POST', headers: { Accept: 'application/json', ...auth }, body: fd });
            const data = (await res.json().catch(() => ({}))) as { id?: number; name?: string; size?: number; message?: string };
            reach(true);

            return res.ok && data.id ? { id: data.id, name: data.name ?? file.name, size: data.size ?? file.size } : data.message ?? 'The file could not be sent.';
        } catch {
            reach(false);

            return 'No connection: the file could not be sent. Keep it, and try again when the connection is back.';
        }
    }, [auth, reach]);

    const submit = useCallback(async () => {
        const items = Object.entries(queue.current).map(([qid, a]) => ({ question_id: Number(qid), seq: a.seq, flagged: a.flagged, response: a.response, at: a.at }));
        const offReport = pendingOff.current;
        setSubmitting(true);
        const res = await api<{ receipt: Record<string, unknown>; score: { raw: number; max: number } | null; attempt: AttemptState; reason?: string }>('submit', 'POST', { answers: items, ...(offReport ? { off: offReport } : {}) });

        if (res.ok) {
            finish({ ...res.data.receipt, score: res.data.score });
        } else if (res.status === 0 || res.status === 429 || res.status >= 500) {
            // not sent: everything stays on this computer and is sent the moment the server answers
            submitTries.current += 1;
            window.setTimeout(() => submitRef.current(), Math.min(10000, 2000 * submitTries.current));
        } else {
            setSubmitting(false);
            handleStatus(res.status, res.data as { reason?: string });
        }
    }, [api, finish, handleStatus]);

    useEffect(() => {
        submitRef.current = submit;
    }, [submit]);

    // Time up: submit once. Thousands of candidates reach the deadline together, so each waits a
    // random moment inside the server's grace period; the server accepts answers until then.
    useEffect(() => {
        if (state && state.status === 'in_progress' && remaining <= 0 && !finished.current && !timeUp.current) {
            timeUp.current = true;
            setTimeIsUp(true);
            setConfirmSubmit(false);
            const spread = Math.min(15000, Math.max(0, Number(state.grace_seconds ?? 30) * 500));
            window.setTimeout(() => submitRef.current(), Math.random() * spread);
        }
    }, [remaining, state]);

    useEffect(() => {
        if (!warningToast) {
            return;
        }

        const t = window.setTimeout(() => setWarningToast(null), 4000);

        return () => window.clearTimeout(t);
    }, [warningToast]);

    const questions = useMemo(() => paper?.sections.flatMap((s) => s.questions.map((q) => ({ ...q, section: s }))) ?? [], [paper]);
    const q = questions[index];
    const answeredCount = questions.filter((x) => isAnswered(answers[x.id]?.response)).length;

    if (roomScan) {
        return <RoomScan token={token} onDone={() => (setRoomScan(false), void loadPaper(() => false))} />;
    }

    if (!paper || !q) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-white text-[#0b0d10]">
                {blockedReason ? <p className="max-w-md text-center">{blockedReason}</p> : <Loader2 className="size-6 animate-spin" />}
            </div>
        );
    }

    const flaggedCount = questions.filter((x) => answers[x.id]?.flagged).length;
    const unanswered = questions.filter((x) => !isAnswered(answers[x.id]?.response));
    const clockClass = {
        normal: contrast ? 'bg-white text-black' : 'bg-[#0b0d10] text-white',
        half: 'bg-[#b45309] text-white',
        final: 'bg-[#b91c1c] text-white font-bold',
        last: 'bg-[#b91c1c] text-white font-bold animate-pulse',
    }[timerPhase];
    const camOn = !!(policy.webcam || policy.face_detection || needsIdentity || policy.gaze_tracking || policy.live_video || policy.record_video);
    const questionGrid = (
        <div className="grid grid-cols-6 gap-1.5">
            {questions.map((x, i) => (
                <button
                    key={x.id}
                    onClick={() => (setIndex(i), setShowNavigator(false))}
                    className={`relative aspect-square rounded-lg border text-xs tabular-nums ${i === index ? 'ring-2 ring-brand ring-offset-1' : ''} ${isAnswered(answers[x.id]?.response) ? 'border-brand bg-brand text-brand-foreground' : contrast ? 'border-white/30' : 'border-black/15 bg-white'}`}
                    aria-label={`Question ${x.number}${isAnswered(answers[x.id]?.response) ? ', answered' : ''}${answers[x.id]?.flagged ? ', flagged' : ''}`}
                >
                    {x.number}
                    {answers[x.id]?.flagged && <span className="absolute -top-1 -right-1 size-2.5 rounded-full bg-[#d97706]" />}
                </button>
            ))}
        </div>
    );

    return (
        <div className={`flex h-dvh flex-col overflow-hidden select-none ${contrast ? 'bg-black text-white' : 'exam-surface'}`} style={{ fontSize: '16px' }}>
            <header className={`flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b px-4 py-2.5 md:px-6 ${contrast ? 'border-white/30 bg-black' : 'border-black/10 bg-white'}`}>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[15px] font-semibold">{paper.exam.title}</p>
                    <p className="text-xs opacity-60">
                        Question {index + 1} of {questions.length} · {answeredCount} answered
                    </p>
                </div>
                <div className="flex items-center gap-1 rounded-xl border border-current/15 p-1" role="group" aria-label="Zoom">
                    <button className="rounded-lg p-1.5 hover:bg-black/5 disabled:opacity-30" onClick={() => setFontScale(fontScale - 0.1)} disabled={fontScale <= 0.8} aria-label="Zoom out" title="Zoom out">
                        <ZoomOut className="size-4" />
                    </button>
                    <button className="min-w-12 rounded-lg px-1 text-xs tabular-nums hover:bg-black/5" onClick={() => setFontScale(1)} title="Back to normal size">
                        {Math.round(fontScale * 100)}%
                    </button>
                    <button className="rounded-lg p-1.5 hover:bg-black/5 disabled:opacity-30" onClick={() => setFontScale(fontScale + 0.1)} disabled={fontScale >= 2} aria-label="Zoom in" title="Zoom in">
                        <ZoomIn className="size-4" />
                    </button>
                </div>
                <div className="flex items-center gap-2 text-sm">
                    <span className={`hidden items-center gap-1 sm:flex ${online ? 'opacity-60' : 'text-status-critical'}`} title={online ? 'Connected' : 'Offline'}>
                        {online ? <Wifi className="size-4" /> : <WifiOff className="size-4" />}
                        {!online ? 'Offline' : pending > 0 ? `${pending} saving` : 'Saved'}
                    </span>
                    {camOn && <video ref={attachVideo} muted playsInline className="h-9 w-12 rounded-md bg-black object-cover" aria-label="Your camera" />}
                    <button className="rounded-lg border border-current/20 px-2 py-1.5 text-xs" onClick={() => setContrast(!contrast)}>Contrast</button>
                    <button className="rounded-lg border border-current/20 p-1.5 lg:hidden" onClick={() => setShowNavigator(true)} aria-label="All questions"><LayoutGrid className="size-4" /></button>
                    <span className={`flex items-center gap-1.5 rounded-xl px-3 py-1.5 font-mono text-base tabular-nums transition-colors ${clockClass}`} aria-live="polite" title="Time left">
                        <Clock className="size-4" />
                        {formatClock(remaining)}
                    </span>
                    <button onClick={() => setConfirmSubmit(true)} className="flex items-center gap-1.5 rounded-xl bg-brand px-4 py-2 font-semibold text-brand-foreground hover:brightness-110">
                        <Send className="size-4" /> Submit exam
                    </button>
                </div>
            </header>

            {!online && (
                <div role="alert" className="flex shrink-0 items-start gap-3 bg-[#b91c1c] px-4 py-2.5 text-sm text-white md:px-6">
                    <WifiOff className="mt-0.5 size-4 shrink-0" />
                    <p>
                        <b className="font-semibold">No connection to the exam server.</b> Keep writing: every answer is saved on this computer
                        {pending > 0 ? ` (${pending} waiting to be sent)` : ''} and is sent automatically when the connection comes back.
                        Do not close this page or switch off the computer.
                    </p>
                </div>
            )}
            {online && backOnline && (
                <div role="status" className="flex shrink-0 items-center gap-2 bg-[#15803d] px-4 py-2 text-sm font-medium text-white md:px-6">
                    <Wifi className="size-4" /> Connected again. {pending > 0 ? `Sending ${pending} answer(s)…` : 'All your answers are saved on the server.'}
                </div>
            )}

            {timerNotice && (
                <div role="alert" className={`flex shrink-0 items-center justify-between gap-3 px-4 py-2 text-sm font-medium text-white md:px-6 ${timerNotice.tone === 'half' ? 'bg-[#b45309]' : 'bg-[#b91c1c]'}`}>
                    <span className="flex items-center gap-2"><Clock className="size-4" /> {timerNotice.text}</span>
                    <button onClick={() => setTimerNotice(null)} aria-label="Dismiss"><X className="size-4" /></button>
                </div>
            )}

            <div className="mx-auto grid min-h-0 w-full max-w-7xl flex-1 lg:grid-cols-[1fr_250px]">
                <main className="flex min-h-0 flex-col">
                    <div className="min-h-0 flex-1 overflow-y-auto px-4 py-5 md:px-8" style={{ fontSize: `${fontScale * 18}px` }}>
                        {q.section.instructions && index === questions.findIndex((x) => x.section.id === q.section.id) && (
                            <p className="mb-4 rounded-xl bg-black/5 p-3 text-[0.85em] opacity-80">{q.section.instructions}</p>
                        )}
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-[0.75em] font-medium opacity-60">
                                {q.section.title} · Question {q.number} · {q.mark} mark{q.mark === 1 ? '' : 's'}
                            </p>
                            <button type="button" onClick={() => setReporting(q)} className={`flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-medium ${reported[q.id] ? 'border-[#d97706]/50 bg-[#d97706]/10 text-[#b45309]' : contrast ? 'border-white/30 hover:bg-white/10' : 'border-black/15 bg-white hover:bg-black/5'}`}>
                                <AlertTriangle className="size-3.5" /> {reported[q.id] ? 'Reported' : 'Report this question'}
                            </button>
                        </div>
                        {q.passage_id && paper.passages?.[String(q.passage_id)] && (
                            <details open className={`mt-3 rounded-2xl border p-4 text-[0.92em] ${contrast ? 'border-white/30' : 'border-black/10 bg-black/[.025]'}`}>
                                <summary className="cursor-pointer font-semibold">{paper.passages[String(q.passage_id)]!.title}</summary>
                                <RichContent html={paper.passages[String(q.passage_id)]!.html} className="mt-2 max-h-[45vh] overflow-y-auto leading-relaxed" />
                            </details>
                        )}
                        <RichContent html={q.type === 'fill_blank' ? q.stem_html.replace(/\[\[(\d{1,2})\]\]/g, '<span class="blank-chip">($1)</span>') : q.stem_html} className="mt-3 leading-relaxed" />
                        <div className="mt-5">
                            <QuestionInput q={q} value={answers[q.id]?.response ?? null} onChange={(r) => answer(q, r)} contrast={contrast} upload={uploadFile} />
                        </div>
                    </div>
                    <div className={`flex shrink-0 flex-wrap items-center justify-between gap-3 border-t px-4 py-3 md:px-8 ${contrast ? 'border-white/30' : 'border-black/10 bg-white'}`}>
                        <button disabled={index === 0} onClick={() => setIndex(index - 1)} className="flex items-center gap-1 rounded-xl border border-current/20 px-4 py-2 disabled:opacity-30">
                            <ChevronLeft className="size-4" /> Previous
                        </button>
                        <button onClick={() => answer(q, answers[q.id]?.response ?? null, !answers[q.id]?.flagged)} className={`flex items-center gap-1 rounded-xl px-4 py-2 ${answers[q.id]?.flagged ? 'bg-[#d97706]/20' : 'border border-current/20'}`}>
                            <Flag className="size-4" /> {answers[q.id]?.flagged ? 'Flagged' : 'Flag for review'}
                        </button>
                        {index < questions.length - 1 ? (
                            <button onClick={() => setIndex(index + 1)} className={`flex items-center gap-1 rounded-xl px-5 py-2 ${contrast ? 'bg-white text-black' : 'bg-[#0b0d10] text-white'}`}>
                                Next <ChevronRight className="size-4" />
                            </button>
                        ) : (
                            <button onClick={() => setConfirmSubmit(true)} className="rounded-xl bg-brand px-5 py-2 font-medium text-brand-foreground">
                                Review & submit
                            </button>
                        )}
                    </div>
                </main>

                <aside className={`hidden min-h-0 content-start gap-3 overflow-y-auto border-l px-4 py-5 lg:grid ${contrast ? 'border-white/30' : 'border-black/10'}`}>
                    <p className="text-xs font-medium opacity-60">Questions</p>
                    {questionGrid}
                    <p className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] opacity-60">
                        <span className="flex items-center gap-1"><span className="size-2.5 rounded-sm bg-brand" /> answered</span>
                        <span className="flex items-center gap-1"><span className="size-2.5 rounded-full bg-[#d97706]" /> flagged</span>
                    </p>
                    <button
                        onClick={async () => {
                            const body = prompt('Describe the problem (the invigilator will see this):');

                            if (body) {
                                await api('help', 'POST', { body });
                                setWarningToast('Help request sent.');
                            }
                        }}
                        className="flex items-center justify-center gap-1 rounded-xl border border-current/15 px-4 py-2 text-sm opacity-80 hover:opacity-100"
                    >
                        <Hand className="size-4" /> I need help
                    </button>
                    {camOn && <p className="flex items-center gap-1 text-xs opacity-60"><Camera className="size-3.5" /> Camera on</p>}
                    {recState !== 'off' && (
                        <p className={`flex items-center gap-1 text-xs ${recState === 'recording' ? 'opacity-60' : 'text-status-warning'}`}>
                            {recState === 'recording' ? <><span className="size-2 rounded-full bg-status-critical" aria-hidden /> Recording</> : 'This browser cannot record — the proctor has been told'}
                        </p>
                    )}
                    {wantsLive && liveState !== 'off' && (
                        <p className={`text-xs ${liveState === 'live' ? 'opacity-60' : 'text-status-warning'}`}>
                            {liveState === 'live' ? 'Live to proctors' : liveState === 'connecting' ? 'Connecting video…' : liveState === 'poor' ? 'Weak video connection' : 'Video connection lost — retrying'}
                        </p>
                    )}
                    {policy.second_camera && (
                        <div className="grid gap-2 text-xs">
                            {phone ? (
                                <>
                                    <p>Scan with your phone, then place it to show you, your desk and your screen.</p>
                                    <div className="w-40 rounded-lg bg-white p-2" dangerouslySetInnerHTML={{ __html: phone.qr }} />
                                    <button className="text-left underline opacity-70" onClick={linkPhone}>New code</button>
                                </>
                            ) : (
                                <button className="rounded-lg border border-current/20 px-3 py-2" onClick={linkPhone}>Connect phone camera</button>
                            )}
                            {usbOn ? (
                                <p className="opacity-70">Second camera: {usbOn}</p>
                            ) : usbCams === null ? (
                                <button className="text-left underline opacity-70" onClick={listUsbCams}>Use a USB camera instead</button>
                            ) : usbCams.length === 0 ? (
                                <p className="opacity-70">No other camera found. Plug it in, then <button className="underline" onClick={listUsbCams}>look again</button>.</p>
                            ) : (
                                <div className="grid gap-1">
                                    {usbCams.map((c) => <button key={c.id} className="rounded-lg border border-current/20 px-3 py-1.5 text-left" onClick={() => pickUsbCam(c.id, c.label)}>{c.label}</button>)}
                                </div>
                            )}
                        </div>
                    )}
                    {identityNote && (
                        <p role="status" className={`rounded-lg p-2 text-xs ${identityNote.ok ? 'bg-status-good/15 text-status-good' : 'bg-status-warning/15 text-status-warning'}`}>
                            {identityNote.text}
                        </p>
                    )}
                </aside>
            </div>

            {showNavigator && (
                <div className="fixed inset-0 z-40 flex items-end justify-center bg-black/50 p-4 lg:hidden" onClick={() => setShowNavigator(false)}>
                    <div className="w-full max-w-md rounded-2xl bg-white p-5 text-[#0b0d10]" onClick={(e) => e.stopPropagation()}>
                        <div className="mb-3 flex items-center justify-between">
                            <p className="font-medium">All questions</p>
                            <button onClick={() => setShowNavigator(false)} aria-label="Close"><X className="size-5" /></button>
                        </div>
                        {questionGrid}
                    </div>
                </div>
            )}

            {warningToast && <div className="fixed bottom-20 left-1/2 z-40 -translate-x-1/2 rounded-xl bg-[#0b0d10] px-4 py-3 text-sm text-white shadow-xl" role="status">{warningToast}</div>}

            {messages.length > 0 && (
                <Modal>
                    <p className={`text-sm font-semibold ${messages[0].kind === 'warning' ? 'text-status-critical' : 'opacity-60'}`}>{messages[0].kind === 'warning' ? 'Warning from the invigilator' : 'Message from the invigilator'}</p>
                    <p className="mt-3 text-lg">{messages[0].body}</p>
                    <button
                        className="mt-6 w-full rounded-xl bg-[#0b0d10] py-3 text-white"
                        onClick={async () => {
                            await api(`messages/${messages[0].id}/ack`, 'POST');
                            setMessages(messages.slice(1));
                        }}
                    >
                        I understand
                    </button>
                </Modal>
            )}

            {strike && !blockedReason && (
                <Modal>
                    <div className="flex items-center gap-3">
                        <span className="grid size-11 shrink-0 place-items-center rounded-full bg-[#b91c1c] text-white"><AlertTriangle className="size-5" /></span>
                        <div>
                            <p className="text-sm font-semibold text-[#b91c1c]">Warning {Math.min(Math.max(state?.violations ?? 0, strike.count), Number(state?.violation_threshold ?? policy.violation_threshold ?? 5))} of {String(state?.violation_threshold ?? policy.violation_threshold ?? 5)}</p>
                            <p className="text-lg font-semibold">{strike.why}</p>
                        </div>
                    </div>
                    <p className="mt-4 text-sm opacity-80">
                        Stay on the exam screen until you submit. {(state?.violation_action ?? policy.violation_action) === 'submit'
                            ? 'At the last warning your exam is submitted automatically.'
                            : (state?.violation_action ?? policy.violation_action) === 'lock'
                                ? 'At the last warning your exam is locked, and only an administrator or invigilator can unlock it.'
                                : 'Every warning is reported to the exams office.'}
                    </p>
                    <button className="mt-6 w-full rounded-xl bg-[#0b0d10] py-3 font-semibold text-white" onClick={() => (setStrike(null), policy.require_fullscreen && document.documentElement.requestFullscreen?.().catch(() => undefined))} autoFocus>
                        Return to my exam
                    </button>
                </Modal>
            )}

            {(timeIsUp || submitting) && !blockedReason && (
                <Modal>
                    {online ? <Loader2 className="size-6 animate-spin" /> : <WifiOff className="size-6 text-[#b91c1c]" />}
                    <p className="mt-3 text-lg font-semibold">{timeIsUp ? 'Time is up.' : 'Submitting your exam…'}</p>
                    <p className="mt-2 text-sm opacity-80">
                        {online
                            ? 'Sending your answers to the server…'
                            : 'There is no connection to the exam server right now. Your answers are saved on this computer and will be sent automatically as soon as the connection comes back. Keep this page open and do not switch off the computer.'}
                    </p>
                    {pending > 0 && <p className="mt-2 text-xs opacity-60">{pending} answer(s) waiting to be sent.</p>}
                </Modal>
            )}

            {blockedReason && (
                <Modal>
                    <AlertTriangle className="size-6 text-status-warning" />
                    <p className="mt-3 text-lg">{blockedReason}</p>
                    {blockedReason.includes('expired') || blockedReason.includes('elsewhere') || blockedReason.includes('another') ? (
                        <Link href="/my/exams" className="mt-6 block rounded-xl bg-[#0b0d10] py-3 text-center text-white">Back to My exams</Link>
                    ) : (
                        <p className="mt-4 text-sm opacity-60">This message closes by itself when you can continue.</p>
                    )}
                </Modal>
            )}

            {reporting && (
                <ReportQuestion
                    number={reporting.number}
                    onCancel={() => setReporting(null)}
                    onSend={(reason, note) => {
                        unsentReports.current.push({ question_id: reporting.id, reason, note });
                        setReported((r) => ({ ...r, [reporting.id]: true }));
                        setReporting(null);
                        setWarningToast('Thank you. The examiners will look at this question after the exam. Answer it as best you can.');
                        void sendReports();
                    }}
                />
            )}

            {confirmSubmit && (
                <Modal wide>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-xl font-semibold">Submit your exam?</h2>
                            <p className="mt-1 text-sm opacity-70">Once submitted, you cannot change any answer.</p>
                        </div>
                        <span className={`flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1 font-mono text-sm tabular-nums ${clockClass}`}><Clock className="size-3.5" />{formatClock(remaining)}</span>
                    </div>
                    <dl className="mt-5 grid grid-cols-3 overflow-hidden rounded-xl border border-black/10 text-center">
                        <div className="p-3">
                            <dt className="text-xs opacity-60">Answered</dt>
                            <dd className="text-2xl font-semibold tabular-nums">{answeredCount}<span className="text-sm font-normal opacity-50">/{questions.length}</span></dd>
                        </div>
                        <div className={`border-x border-black/10 p-3 ${unanswered.length ? 'bg-[#fef3c7]' : ''}`}>
                            <dt className="text-xs opacity-60">Not answered</dt>
                            <dd className={`text-2xl font-semibold tabular-nums ${unanswered.length ? 'text-[#92400e]' : ''}`}>{unanswered.length}</dd>
                        </div>
                        <div className="p-3">
                            <dt className="text-xs opacity-60">Flagged</dt>
                            <dd className="text-2xl font-semibold tabular-nums">{flaggedCount}</dd>
                        </div>
                    </dl>
                    {unanswered.length > 0 ? (
                        <div className="mt-4">
                            <p className="text-sm font-medium text-[#92400e]">You have not answered these questions:</p>
                            <div className="mt-2 flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
                                {unanswered.map((x) => (
                                    <button key={x.id} onClick={() => (setIndex(questions.indexOf(x)), setConfirmSubmit(false))} className="min-w-9 rounded-md border border-[#d97706]/50 px-2 py-1 text-sm tabular-nums hover:bg-[#fef3c7]">
                                        {x.number}
                                    </button>
                                ))}
                            </div>
                            <p className="mt-1 text-xs opacity-60">Click a number to go to that question.</p>
                        </div>
                    ) : (
                        <p className="mt-4 flex items-center gap-2 text-sm text-[#15803d]"><CheckCircle2 className="size-4" /> Every question has an answer.</p>
                    )}
                    {pending > 0 && <p className="mt-3 text-xs opacity-70">{pending} answer(s) are still being saved — they are sent with your submission.</p>}
                    <div className="mt-6 grid grid-cols-2 gap-3">
                        <button className="rounded-xl border border-black/20 py-3 font-medium" onClick={() => setConfirmSubmit(false)} autoFocus>Go back to my exam</button>
                        <button className="rounded-xl bg-brand py-3 font-semibold text-brand-foreground" onClick={() => (setConfirmSubmit(false), submit())}>Yes, submit now</button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

function isAnswered(r: Response | undefined): boolean {
    return !!r && ((r.options?.length ?? 0) > 0 || (r.text ?? '').trim() !== '' || r.number !== undefined || Object.keys(r.pairs ?? {}).length > 0 || Object.keys(r.tf ?? {}).length > 0 || (r.order?.length ?? 0) > 0 || Object.keys(r.blanks ?? {}).length > 0 || !!r.file);
}

function QuestionInput({ q, value, onChange, contrast, upload }: { q: PaperQuestion; value: Response; onChange: (r: Response) => void; contrast: boolean; upload: Uploader }) {
    if (q.type === 'ordering') {
        return <OrderingInput q={q} value={value} onChange={onChange} contrast={contrast} />;
    }

    if (q.type === 'fill_blank') {
        const given = value?.blanks ?? {};

        return (
            <div className="grid gap-3">
                <p className="text-[0.8em] opacity-60">Write the missing word or number for each numbered blank.</p>
                {(q.blanks ?? []).map((n) => (
                    <label key={n} className="flex flex-wrap items-center gap-3">
                        <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-[0.8em] font-semibold ${contrast ? 'bg-white/20' : 'bg-black/5'}`}>{n}</span>
                        <input className={`h-12 min-w-0 flex-1 rounded-xl border-2 bg-transparent px-3 sm:max-w-md ${given[String(n)] ? 'border-brand' : contrast ? 'border-white/30' : 'border-black/15'}`}
                            value={given[String(n)] ?? ''} aria-label={`Blank ${n}`} spellCheck={false}
                            onChange={(e) => {
                                const next = { ...given, [String(n)]: e.target.value };

                                if (!e.target.value) {
                                    delete next[String(n)];
                                }

                                onChange(Object.keys(next).length ? { blanks: next } : null);
                            }} />
                    </label>
                ))}
            </div>
        );
    }

    if (q.type === 'file_upload') {
        return <FileAnswer q={q} value={value} onChange={onChange} contrast={contrast} upload={upload} />;
    }

    if (['single_choice', 'true_false', 'multiple_choice'].includes(q.type)) {
        const multi = q.type === 'multiple_choice';
        const chosen = value?.options ?? [];

        return (
            <div className="grid gap-3" role={multi ? 'group' : 'radiogroup'}>
                {multi && <p className="text-[0.8em] opacity-60">Choose all that apply.</p>}
                {q.options.map((o) => {
                    const on = chosen.includes(o.id);

                    return (
                        <button
                            key={o.id}
                            role={multi ? 'checkbox' : 'radio'}
                            aria-checked={on}
                            onClick={() => onChange({ options: multi ? (on ? chosen.filter((x) => x !== o.id) : [...chosen, o.id]) : [o.id] })}
                            className={`flex items-start gap-4 rounded-2xl border-2 p-4 text-left transition ${on ? 'border-brand bg-brand-soft' : contrast ? 'border-white/30' : 'border-black/10 hover:border-black/30'}`}
                        >
                            <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-[0.8em] font-semibold ${on ? 'bg-brand text-brand-foreground' : contrast ? 'bg-white/20' : 'bg-black/5'}`}>{o.label}</span>
                            <RichContent html={o.html} className="flex-1 pt-0.5" />
                        </button>
                    );
                })}
                {chosen.length > 0 && (
                    <button className="w-fit text-[0.75em] underline opacity-50" onClick={() => onChange(null)}>
                        Clear answer
                    </button>
                )}
            </div>
        );
    }

    if (q.type === 'multi_true_false') {
        const tf = value?.tf ?? {};
        const set = (id: number, v: boolean | null) => {
            const next = { ...tf };

            if (v === null) {
                delete next[String(id)];
            } else {
                next[String(id)] = v;
            }

            onChange(Object.keys(next).length ? { tf: next } : null);
        };

        return (
            <div className="grid gap-3">
                <p className="text-[0.8em] opacity-60">Mark each statement True or False.</p>
                {q.options.map((o) => {
                    const v = tf[String(o.id)];

                    return (
                        <div key={o.id} className={`flex flex-wrap items-center gap-3 rounded-2xl border-2 p-3 md:flex-nowrap ${v !== undefined ? 'border-brand/60' : contrast ? 'border-white/30' : 'border-black/10'}`}>
                            <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-[0.8em] font-semibold ${contrast ? 'bg-white/20' : 'bg-black/5'}`}>{o.label}</span>
                            <RichContent html={o.html} className="min-w-0 flex-1" />
                            <span className="flex shrink-0 gap-2" role="radiogroup" aria-label={`Statement ${o.label}`}>
                                {[true, false].map((b) => (
                                    <button key={String(b)} role="radio" aria-checked={v === b} onClick={() => set(o.id, v === b ? null : b)}
                                        className={`min-w-20 rounded-xl border-2 px-3 py-2 text-[0.85em] font-semibold transition ${v === b ? 'border-brand bg-brand text-brand-foreground' : contrast ? 'border-white/30' : 'border-black/15 hover:border-black/40'}`}>
                                        {b ? 'True' : 'False'}
                                    </button>
                                ))}
                            </span>
                        </div>
                    );
                })}
                {Object.keys(tf).length > 0 && (
                    <button className="w-fit text-[0.75em] underline opacity-50" onClick={() => onChange(null)}>
                        Clear answer
                    </button>
                )}
            </div>
        );
    }

    if (q.type === 'matching' && q.matching) {
        const pairs = value?.pairs ?? {};

        return (
            <div className="grid gap-3">
                <p className="text-[0.8em] opacity-60">Match each item on the left with one on the right.</p>
                {q.matching.left.map((l) => (
                    <div key={l.key} className={`grid items-center gap-3 rounded-2xl border-2 p-3 md:grid-cols-[1fr_minmax(200px,40%)] ${contrast ? 'border-white/30' : 'border-black/10'}`}>
                        <RichContent html={l.html} />
                        <select
                            className={`h-12 rounded-xl border-2 bg-transparent px-3 ${pairs[l.key] ? 'border-brand' : contrast ? 'border-white/30' : 'border-black/15'}`}
                            value={pairs[l.key] ?? ''}
                            onChange={(e) => {
                                const next = { ...pairs, [l.key]: e.target.value };

                                if (!e.target.value) {
                                    delete next[l.key];
                                }

                                onChange(Object.keys(next).length ? { pairs: next } : null);
                            }}
                            aria-label={`Match for item ${l.key}`}
                        >
                            <option value="">Choose…</option>
                            {q.matching!.right.map((r, i) => <option key={r.key} value={r.key}>{String.fromCharCode(65 + i)}. {r.html.replace(/<[^>]+>/g, '')}</option>)}
                        </select>
                    </div>
                ))}
                <div className="grid gap-1 rounded-xl bg-black/5 p-3 text-[0.85em]">
                    {q.matching.right.map((r, i) => <div key={r.key} className="flex gap-2"><strong>{String.fromCharCode(65 + i)}.</strong><RichContent html={r.html} /></div>)}
                </div>
            </div>
        );
    }

    if (q.type === 'numeric') {
        return (
            <input
                inputMode="decimal"
                className="h-14 w-60 rounded-2xl border-2 border-black/15 bg-transparent px-4 font-mono text-[1.1em]"
                value={value?.number ?? ''}
                onChange={(e) => onChange(e.target.value === '' ? null : { number: Number(e.target.value.replace(',', '.')) })}
                aria-label="Your numeric answer"
            />
        );
    }

    const text = value?.text ?? '';

    return (
        <div>
            <textarea
                className={`w-full rounded-2xl border-2 bg-transparent p-4 leading-relaxed ${q.type === 'essay' ? 'min-h-80' : 'min-h-24'} ${contrast ? 'border-white/30' : 'border-black/15'}`}
                value={text}
                spellCheck={false}
                onChange={(e) => onChange({ text: e.target.value })}
                aria-label="Your answer"
            />
            <p className="mt-1 text-right text-[0.7em] opacity-50">{text.trim() ? text.trim().split(/\s+/).length : 0} words</p>
        </div>
    );
}

/** Put in order: move items up and down (works with keyboard and touch, no dragging needed). */
function OrderingInput({ q, value, onChange, contrast }: { q: PaperQuestion; value: Response; onChange: (r: Response) => void; contrast: boolean }) {
    const ids = value?.order ?? q.options.map((o) => o.id);
    const byId = new Map(q.options.map((o) => [o.id, o]));
    const move = (i: number, d: -1 | 1) => {
        const j = i + d;

        if (j < 0 || j >= ids.length) {
            return;
        }

        const next = [...ids];
        [next[i], next[j]] = [next[j], next[i]];
        onChange({ order: next });
    };

    return (
        <div className="grid gap-2">
            <p className="text-[0.8em] opacity-60">Put the items in the right order: first at the top. Use the arrows to move them.</p>
            <ol className="grid gap-2">
                {ids.map((id, i) => (
                    <li key={id} className={`flex items-center gap-3 rounded-2xl border-2 p-3 ${value?.order ? 'border-brand/50' : contrast ? 'border-white/30' : 'border-black/10'}`}>
                        <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-[0.8em] font-semibold ${contrast ? 'bg-white/20' : 'bg-black/5'}`}>{i + 1}</span>
                        <RichContent html={byId.get(id)?.html ?? ''} className="min-w-0 flex-1" />
                        <span className="flex shrink-0 gap-1">
                            <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)} className="grid size-10 place-items-center rounded-xl border-2 border-current/15 disabled:opacity-25">↑</button>
                            <button type="button" aria-label="Move down" disabled={i === ids.length - 1} onClick={() => move(i, 1)} className="grid size-10 place-items-center rounded-xl border-2 border-current/15 disabled:opacity-25">↓</button>
                        </span>
                    </li>
                ))}
            </ol>
            {!value?.order && <button type="button" className="w-fit text-[0.8em] underline opacity-70" onClick={() => onChange({ order: ids })}>This order is my answer</button>}
        </div>
    );
}

/** Hand in a file (a drawing, a photo of working, a report). Needs a connection to send it. */
function FileAnswer({ q, value, onChange, contrast, upload }: { q: PaperQuestion; value: Response; onChange: (r: Response) => void; contrast: boolean; upload: Uploader }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const accept = (q.file?.kinds ?? ['image', 'pdf', 'document']).map((k) => FILE_EXT[k] ?? '').filter(Boolean).join(',');

    return (
        <div className={`grid gap-3 rounded-2xl border-2 border-dashed p-5 ${contrast ? 'border-white/30' : 'border-black/15'}`}>
            {value?.file ? (
                <p className="text-[0.9em]">Handed in: <b>{value.file.name}</b> ({Math.max(1, Math.round(value.file.size / 1024))} KB)</p>
            ) : (
                <p className="text-[0.85em] opacity-70">Choose the file to hand in{q.file ? ` (up to ${q.file.max_mb} MB)` : ''}.</p>
            )}
            <label className={`inline-flex w-fit cursor-pointer items-center gap-2 rounded-xl px-4 py-2.5 text-[0.9em] font-semibold ${busy ? 'opacity-60' : ''} ${contrast ? 'bg-white text-black' : 'bg-[#0b0d10] text-white'}`}>
                {busy ? 'Sending…' : value?.file ? 'Replace the file' : 'Choose a file'}
                <input type="file" className="sr-only" accept={accept} disabled={busy} onChange={async (e) => {
                    const f = e.target.files?.[0];
                    e.target.value = '';

                    if (!f) {
                        return;
                    }

                    setBusy(true);
                    setError(null);
                    const r = await upload(q.id, f);
                    setBusy(false);

                    if (typeof r === 'string') {
                        setError(r);
                    } else {
                        onChange({ file: r });
                    }
                }} />
            </label>
            {error && <p role="alert" className="text-[0.85em] text-[#b91c1c]">{error}</p>}
        </div>
    );
}

const REPORT_REASONS: [string, string][] = [
    ['wrong_key', 'The right answer is not among the options, or looks wrongly marked'],
    ['two_answers', 'More than one option is right'],
    ['no_answer', 'None of the options is right'],
    ['unclear', 'Typing error or unclear wording'],
    ['missing_picture', 'A picture or equation does not show'],
    ['other', 'Something else'],
];

/** "Report this question": the student says what is wrong; it takes no exam time and does not change their answer. */
function ReportQuestion({ number, onCancel, onSend }: { number: number; onCancel: () => void; onSend: (reason: string, note: string) => void }) {
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');

    return (
        <Modal wide>
            <h2 className="text-xl font-semibold">Report question {number}</h2>
            <p className="mt-1 text-sm opacity-70">The examiners check every report after the exam. If the question is faulty, everyone&apos;s marks are put right. Your exam time keeps running, so answer the question as best you can.</p>
            <fieldset className="mt-4 grid gap-2">
                <legend className="sr-only">What is wrong</legend>
                {REPORT_REASONS.map(([k, label]) => (
                    <label key={k} className={`flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm ${reason === k ? 'border-brand bg-brand/5' : 'border-black/10 hover:bg-black/[.03]'}`}>
                        <input type="radio" name="report-reason" className="mt-0.5" checked={reason === k} onChange={() => setReason(k)} />
                        {label}
                    </label>
                ))}
            </fieldset>
            <label className="mt-3 grid gap-1 text-sm">
                Anything to add (optional)
                <textarea className="min-h-20 rounded-xl border border-black/15 p-2.5" maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Option C and D are the same" />
            </label>
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onCancel} className="rounded-xl border border-black/15 px-4 py-2">Cancel</button>
                <button type="button" disabled={!reason} onClick={() => onSend(reason, note.trim())} className="rounded-xl bg-brand px-5 py-2 font-medium text-brand-foreground disabled:opacity-50">Send report</button>
            </div>
        </Modal>
    );
}

function Modal({ children, wide = false }: { children: React.ReactNode; wide?: boolean }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className={`w-full ${wide ? 'max-w-lg' : 'max-w-md'} rounded-2xl bg-white p-6 text-[#0b0d10] shadow-2xl md:p-8`}>{children}</div>
        </div>
    );
}

function Done({ receipt, waiting = 0 }: { receipt: { submitted_at?: string; hash?: string; answered?: number; score?: { raw: number; max: number } | null; reason?: string } | null; waiting?: number }) {
    const pct = receipt?.score && Number(receipt.score.max) > 0 ? Math.round((Number(receipt.score.raw) / Number(receipt.score.max)) * 1000) / 10 : null;

    return (
        <div className="flex min-h-screen items-center justify-center bg-background p-6">
            <div className="surface w-full max-w-lg p-8 text-center md:p-10">
                <CheckCircle2 className="mx-auto size-10 text-brand" />
                <h1 className="mt-4 text-2xl font-semibold">Your exam has been submitted</h1>
                <p className="mt-1 text-sm text-muted-foreground">{receipt?.reason === 'timeout' || receipt?.reason === 'auto_submitted' ? 'Time ran out, so your answers were submitted for you.' : 'Your answers are saved. You are still signed in.'}</p>
                {receipt?.score && (
                    <div className="mt-6 rounded-xl border border-border p-5">
                        <p className="text-sm text-muted-foreground">Your score</p>
                        <p className="mt-1 text-4xl font-semibold tabular-nums">
                            {Number(receipt.score.raw)}<span className="text-xl font-normal text-muted-foreground"> / {Number(receipt.score.max)}</span>
                        </p>
                        {pct !== null && <p className="mt-1 text-lg tabular-nums">{pct}%</p>}
                    </div>
                )}
                {receipt?.hash && (
                    <p className="mt-6 text-xs text-muted-foreground">
                        Receipt {receipt.submitted_at && new Date(receipt.submitted_at).toLocaleString()} · {receipt.answered} answers
                        <br />
                        <span className="font-mono break-all">{receipt.hash}</span>
                    </p>
                )}
                {waiting > 0 && (
                    <p className="mt-6 flex items-center justify-center gap-2 rounded-lg bg-status-warning/10 p-3 text-sm" role="status">
                        <Loader2 className="size-4 animate-spin" /> Sending the rest of your exam recording ({waiting} left). Please keep this page open.
                    </p>
                )}
                <div className="mt-8 flex flex-wrap justify-center gap-3">
                    <Link href="/my/exams" className="rounded-lg bg-brand px-6 py-3 font-medium text-brand-foreground">Back to My exams</Link>
                    <Link href="/my/results" className="rounded-lg border border-border px-6 py-3 font-medium">My results</Link>
                </div>
            </div>
        </div>
    );
}
