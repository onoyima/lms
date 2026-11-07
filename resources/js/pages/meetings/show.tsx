import { Head, Link, router, useForm } from '@inertiajs/react';
import { Camera, CameraOff, Captions, Download, FileUp, Mic, PhoneOff, Play, RefreshCw, Trash2, Video } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import type { MeetingCard } from './index';

type Meeting = MeetingCard & { description: string | null; access: string; settings: Record<string, unknown> | null; host: string | null; started_at: string | null; ended_at: string | null; course_id: number | null };
type Person = { user_id: number; name: string | null; identifier: string | null; role: string };
type Row = { user_id: number | null; name: string | null; identifier: string | null; role: string | null; first_join: string; last_leave: string | null; seconds: number; sessions: number; reconnects: number; percent: number; status: string };
type Recording = { id: number; state: string; format: string | null; duration_seconds: number | null; started_at: string | null; visibility: string };
type Slide = { index: number; name: string; size: number | null };
type Transcript = { id: number; meeting_recording_id: number; language: string | null; kind: 'transcript' | 'translation'; status: string; error: string | null };
type LinkedExam = { id: number; title: string; type: string; examiner: boolean; candidates: { user_id: number; name: string | null; identifier: string | null; scored: boolean }[] };
type Props = {
    meeting: Meeting; can: { moderate: boolean; edit: boolean; join: boolean }; notice: string | null; participants: Person[]; attendance: Row[]; recordings: Recording[]; roles: string[];
    slides: Slide[]; exam: LinkedExam | null; transcripts: Record<string, Transcript[]>; transcription: { enabled: boolean; translation: boolean; languages: Record<string, string> };
    room: { engine: 'builtin' | 'bbb'; max: number }; guestLink: { enabled: boolean; url: string; passcode: boolean } | null;
};

/** The link the host shares with people who have no account; they wait until a host lets them in. */
function GuestLinkPanel({ uuid, link }: { uuid: string; link: Props['guestLink'] }) {
    const [passcode, setPasscode] = useState('');
    const [copied, setCopied] = useState(false);
    const save = (data: Record<string, unknown>) => router.post(`/meetings/${uuid}/guest-link`, data as never, { preserveScroll: true });

    return (
        <section className="grid gap-3 rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Guest link (people without an account)</h2>
                {link?.enabled && <Status value="on" />}
            </div>
            {link?.enabled ? (
                <>
                    <div className="flex flex-wrap gap-2">
                        <input readOnly aria-label="Guest link" className={`${inputClass} min-w-0 flex-1 font-mono text-xs`} value={link.url} onFocus={(e) => e.target.select()} />
                        <Button size="sm" variant="outline" onClick={() => void navigator.clipboard?.writeText(link.url).then(() => setCopied(true))}>{copied ? 'Copied' : 'Copy link'}</Button>
                    </div>
                    <p className="text-xs text-muted-foreground">Guests type their name{link.passcode ? ' and the passcode' : ''}, then wait until a host lets them in. They see only this meeting.</p>
                    <div className="flex flex-wrap items-end gap-2">
                        <Field label={link.passcode ? 'Change passcode' : 'Add a passcode (optional)'}><input className={`${inputClass} w-44`} value={passcode} onChange={(e) => setPasscode(e.target.value)} minLength={4} /></Field>
                        <Button size="sm" variant="outline" disabled={passcode.length > 0 && passcode.length < 4} onClick={() => (save({ enabled: true, passcode }), setPasscode(''))}>{passcode ? 'Save passcode' : link.passcode ? 'Remove passcode' : 'Save'}</Button>
                        <Button size="sm" variant="ghost" onClick={() => confirm('Make a new link? The old one stops working.') && save({ enabled: true, new_link: true })}>New link</Button>
                        <Button size="sm" variant="ghost" className="text-status-critical" onClick={() => save({ enabled: false })}>Switch off</Button>
                    </div>
                </>
            ) : (
                <div className="flex flex-wrap items-end gap-2">
                    <p className="w-full text-sm text-muted-foreground">Invite an external examiner, a guest speaker or a parent without giving them an account.</p>
                    <Field label="Passcode (optional)"><input className={`${inputClass} w-44`} value={passcode} onChange={(e) => setPasscode(e.target.value)} /></Field>
                    <Button size="sm" variant="outline" disabled={passcode.length > 0 && passcode.length < 4} onClick={() => save({ enabled: true, passcode: passcode || null })}>Make a guest link</Button>
                </div>
            )}
        </section>
    );
}

const ROLE_LABEL: Record<string, string> = { host: 'Host', cohost: 'Co-host', presenter: 'Presenter', participant: 'Participant', examiner: 'Examiner', proctor: 'Proctor' };
const mins = (s: number) => `${Math.round(s / 60)} min`;

/** Camera and microphone check before joining; nothing is recorded or sent. */
function DeviceCheck() {
    const video = useRef<HTMLVideoElement>(null);
    const [stream, setStream] = useState<MediaStream | null>(null);
    const [level, setLevel] = useState(0);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!stream) {
            return;
        }

        const ctx = new AudioContext();
        const src = ctx.createMediaStreamSource(stream);
        const an = ctx.createAnalyser();
        an.fftSize = 256;
        src.connect(an);
        const data = new Uint8Array(an.frequencyBinCount);
        let raf = 0;
        const tick = () => {
            an.getByteFrequencyData(data);
            setLevel(Math.min(100, Math.round((data.reduce((a, b) => a + b, 0) / data.length) * 1.6)));
            raf = requestAnimationFrame(tick);
        };
        tick();

        return () => {
            cancelAnimationFrame(raf);
            void ctx.close();
        };
    }, [stream]);

    useEffect(() => () => stream?.getTracks().forEach((t) => t.stop()), [stream]);

    const start = async () => {
        setError(null);

        try {
            const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
            setStream(s);

            if (video.current) {
                video.current.srcObject = s;
            }
        } catch {
            setError('The browser did not allow the camera or microphone. Allow them in the address bar, or check that no other app is using them.');
        }
    };
    const stop = () => {
        stream?.getTracks().forEach((t) => t.stop());
        setStream(null);
    };

    return (
        <div className="grid gap-3 rounded-lg border border-border bg-card p-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Check your camera and microphone</h2>
                {stream ? <Button size="sm" variant="ghost" onClick={stop}><CameraOff className="size-4" /> Stop</Button> : <Button size="sm" variant="outline" onClick={start}><Camera className="size-4" /> Test</Button>}
            </div>
            <div className="grid gap-3 sm:grid-cols-[240px_1fr]">
                <video ref={video} autoPlay muted playsInline className="aspect-video w-full rounded-md bg-muted object-cover" aria-label="Camera preview" />
                <div className="flex flex-col justify-center gap-2 text-sm">
                    <span className="flex items-center gap-2"><Mic className="size-4" /> Microphone</span>
                    <div className="h-2 overflow-hidden rounded bg-muted" role="meter" aria-label="Microphone level" aria-valuenow={level} aria-valuemin={0} aria-valuemax={100}>
                        <div className="h-full bg-brand transition-[width]" style={{ width: `${level}%` }} />
                    </div>
                    <p className="text-xs text-muted-foreground">{error ?? (stream ? 'Speak: the bar should move. Nothing is recorded or sent.' : 'Optional. The meeting will ask for the camera and microphone again when you join.')}</p>
                </div>
            </div>
        </div>
    );
}

/** Files loaded into the room before it opens (BigBlueButton fetches them through a short-lived link). */
function SlidesPanel({ uuid, slides }: { uuid: string; slides: Slide[] }) {
    const up = useForm<{ file: File | null }>({ file: null });

    return (
        <section className="grid gap-3">
            <h2 className="text-sm font-medium">Slides and documents for the room</h2>
            {slides.length > 0 && (
                <ul className="divide-y divide-border rounded-lg border border-border bg-card">
                    {slides.map((s) => (
                        <li key={s.index} className="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                            <span className="truncate">{s.name}{s.size ? <span className="ml-2 text-xs text-muted-foreground">{(s.size / 1048576).toFixed(1)} MB</span> : null}</span>
                            <Button size="sm" variant="ghost" aria-label={`Remove ${s.name}`} onClick={() => router.delete(`/meetings/${uuid}/slides/${s.index}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button>
                        </li>
                    ))}
                </ul>
            )}
            {slides.length < 5 && (
                <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => {
                    e.preventDefault();
                    up.post(`/meetings/${uuid}/slides`, { forceFormData: true, preserveScroll: true, onSuccess: () => up.reset() });
                }}>
                    <Field label="Add a file (PDF, PowerPoint, Word, image; up to 50 MB)" error={up.errors.file}>
                        <input type="file" accept=".pdf,.pptx,.ppt,.docx,.doc,.odp,.odt,.png,.jpg,.jpeg" className={inputClass} onChange={(e) => up.setData('file', e.target.files?.[0] ?? null)} />
                    </Field>
                    <Button variant="outline" disabled={!up.data.file || up.processing}><FileUp className="size-4" /> Upload</Button>
                </form>
            )}
            <p className="text-xs text-muted-foreground">The first file opens on screen when the room starts; the others are ready in the presentation menu.</p>
        </section>
    );
}

/** Transcript and translations of one recording. */
function TranscriptCell({ uuid, rec, items, transcription, moderate }: { uuid: string; rec: Recording; items: Transcript[]; transcription: Props['transcription']; moderate: boolean }) {
    const [lang, setLang] = useState(Object.keys(transcription.languages).find((l) => l !== 'en') ?? 'fr');
    const main = items.find((t) => t.kind === 'transcript');
    const done = items.filter((t) => t.status === 'complete');

    return (
        <div className="flex flex-col gap-1 text-xs">
            {done.map((t) => (
                <span key={t.id} className="flex flex-wrap items-center gap-2">
                    <Captions className="size-3.5" /> {t.kind === 'translation' ? (transcription.languages[t.language ?? ''] ?? t.language) : 'Transcript'}{t.language && t.kind === 'transcript' ? ` (${t.language})` : ''}:
                    {(['vtt', 'srt', 'txt'] as const).map((f) => <a key={f} className="text-brand hover:underline" href={`/meetings/${uuid}/transcripts/${t.id}.${f}`}>{f.toUpperCase()}</a>)}
                </span>
            ))}
            {items.filter((t) => t.status === 'queued' || t.status === 'working').map((t) => <span key={t.id} className="text-muted-foreground">{t.kind === 'translation' ? 'Translating…' : 'Transcribing…'}</span>)}
            {items.filter((t) => t.status === 'failed').map((t) => <span key={t.id} className="text-status-critical">{t.error ?? 'Failed'}</span>)}
            {moderate && transcription.enabled && rec.state === 'published' && (!main || main.status === 'failed') && (
                <Button size="sm" variant="ghost" className="justify-start px-0" onClick={() => router.post(`/meetings/${uuid}/recordings/${rec.id}/transcribe`, {}, { preserveScroll: true })}>Make a transcript</Button>
            )}
            {moderate && transcription.translation && main?.status === 'complete' && (
                <span className="flex items-center gap-1">
                    <select aria-label="Translate into" className="h-7 rounded-md border border-input bg-card px-1 text-xs" value={lang} onChange={(e) => setLang(e.target.value)}>
                        {Object.entries(transcription.languages).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                    <Button size="sm" variant="ghost" className="h-7 px-2" onClick={() => router.post(`/meetings/${uuid}/transcripts/${main.id}/translate`, { language: lang }, { preserveScroll: true })}>Translate</Button>
                </span>
            )}
        </div>
    );
}

export default function MeetingPage({ meeting: m, can, notice, participants, attendance, recordings, roles, slides, exam, transcripts, transcription, room, guestLink }: Props) {
    const people = useForm({ people: '', role: 'participant', from_course: false });

    useEffect(() => {
        if (can.join || m.status !== 'scheduled') {
            return;
        }

        // waiting for the host: look every 15 seconds
        const t = setInterval(async () => {
            const r = await fetch(`/meetings/${m.uuid}/status`, { headers: { Accept: 'application/json' } });

            if (r.ok && (await r.json()).status === 'live') {
                router.reload();
            }
        }, 15000);

        return () => clearInterval(t);
    }, [can.join, m.status, m.uuid]);

    return (
        <>
            <Head title={m.title} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title={m.title} lead={`${m.type_label}${m.course ? ` · ${m.course}` : ''} · ${formatDateTime(m.starts_at)}–${new Date(m.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}${m.host ? ` · host ${m.host}` : ''}`}
                    actions={<>
                        <Status value={m.status} />
                        {can.edit && m.status === 'scheduled' && <Button variant="outline" size="sm" asChild><Link href={`/meetings/${m.uuid}/edit`}>Change</Link></Button>}
                    </>} />
                <Flash />
                {m.description && <p className="max-w-3xl whitespace-pre-line text-sm">{m.description}</p>}

                {['scheduled', 'live'].includes(m.status) && (
                    <section className="grid gap-4 lg:grid-cols-[1fr_320px]">
                        <DeviceCheck />
                        <div className="flex flex-col justify-center gap-3 rounded-lg border border-border bg-card p-4">
                            {notice && <p className="text-sm text-muted-foreground">{notice}</p>}
                            <p className="text-xs text-muted-foreground">{room.engine === 'builtin' ? `Built-in room: up to ${room.max} people, no recording.` : 'Meeting server (BigBlueButton).'}</p>
                            <Button size="lg" disabled={!can.join} onClick={() => router.post(`/meetings/${m.uuid}/join`)}>
                                <Video className="size-4" /> {can.moderate && m.status !== 'live' ? 'Start the meeting' : 'Join'}
                            </Button>
                            {can.moderate && m.status === 'live' && (
                                <Button variant="outline" className="text-status-critical" onClick={() => confirm('End the meeting for everyone?') && router.post(`/meetings/${m.uuid}/end`)}><PhoneOff className="size-4" /> End for everyone</Button>
                            )}
                            {can.edit && m.status === 'scheduled' && (
                                <Button variant="ghost" size="sm" onClick={() => confirm(m.recurring ? 'Cancel this meeting? (Choose OK, then you can also cancel the rest of the series below.)' : 'Cancel this meeting?') && router.post(`/meetings/${m.uuid}/cancel`)}>Cancel meeting</Button>
                            )}
                            {can.edit && m.recurring && m.status === 'scheduled' && (
                                <Button variant="ghost" size="sm" onClick={() => confirm('Cancel every coming meeting in this series?') && router.post(`/meetings/${m.uuid}/cancel`, { series: true })}>Cancel the whole series</Button>
                            )}
                        </div>
                    </section>
                )}

                {exam && exam.examiner && (
                    <section className="grid gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-medium">Candidates for {exam.title}</h2>
                            <Link className="text-sm text-brand hover:underline" href={`/marking/${exam.id}/panel`}>Open the scoring sheet</Link>
                        </div>
                        {exam.candidates.length === 0 ? <p className="text-sm text-muted-foreground">No candidates are on this {exam.type} yet.</p> : (
                            <Table head={['Name', 'ID', 'Your score', '']}>
                                {exam.candidates.map((c) => (
                                    <tr key={c.user_id}>
                                        <Td>{c.name}</Td><Td className="font-mono text-xs">{c.identifier}</Td>
                                        <Td><Status value={c.scored ? 'scored' : 'pending'} /></Td>
                                        <Td><Button size="sm" variant="outline" asChild><Link href={`/marking/${exam.id}/panel?candidate=${c.user_id}`}>Score</Link></Button></Td>
                                    </tr>
                                ))}
                            </Table>
                        )}
                    </section>
                )}

                {can.moderate && ['scheduled', 'live'].includes(m.status) && <GuestLinkPanel uuid={m.uuid} link={guestLink} />}

                {can.moderate && ['scheduled', 'live'].includes(m.status) && room.engine === 'bbb' && <SlidesPanel uuid={m.uuid} slides={slides} />}

                {can.moderate && (
                    <section className="grid gap-3">
                        <h2 className="text-sm font-medium">People and roles</h2>
                        <Table head={['Name', 'ID', 'Role', '']}>
                            {participants.map((p) => (
                                <tr key={p.user_id}>
                                    <Td>{p.name}</Td>
                                    <Td className="font-mono text-xs">{p.identifier}</Td>
                                    <Td>
                                        {can.edit && p.role !== 'host' ? (
                                            <select aria-label={`Role of ${p.name}`} className="h-8 rounded-md border border-input bg-card px-2 text-sm" value={p.role}
                                                onChange={(e) => router.put(`/meetings/${m.uuid}/participants/${p.user_id}`, { role: e.target.value }, { preserveScroll: true })}>
                                                {roles.map((r) => <option key={r} value={r}>{ROLE_LABEL[r] ?? r}</option>)}
                                            </select>
                                        ) : ROLE_LABEL[p.role] ?? p.role}
                                    </Td>
                                    <Td>{can.edit && p.role !== 'host' && <Button size="sm" variant="ghost" onClick={() => router.put(`/meetings/${m.uuid}/participants/${p.user_id}`, { role: null }, { preserveScroll: true })}>Remove</Button>}</Td>
                                </tr>
                            ))}
                        </Table>
                        {m.access === 'course' && <p className="text-xs text-muted-foreground">Everyone registered for the course can join; add people here only to give them a role or to invite others.</p>}
                        {can.edit && (
                            <form className="grid gap-3 sm:grid-cols-[1fr_160px_auto]" onSubmit={(e) => {
                                e.preventDefault();
                                people.post(`/meetings/${m.uuid}/participants`, { preserveScroll: true, onSuccess: () => people.reset('people') });
                            }}>
                                <Field label="Add people (ID numbers or emails)" error={people.errors.people}><input className={inputClass} value={people.data.people} onChange={(e) => people.setData('people', e.target.value)} placeholder="VUG/CHM/21/001, lecturer@…" /></Field>
                                <Field label="As"><select className={inputClass} value={people.data.role} onChange={(e) => people.setData('role', e.target.value)}>{roles.filter((r) => r !== 'host').map((r) => <option key={r} value={r}>{ROLE_LABEL[r] ?? r}</option>)}</select></Field>
                                <div className="flex items-end gap-2">
                                    <Button variant="outline" disabled={people.processing}>Add</Button>
                                    {m.course_id && <Button type="button" variant="ghost" onClick={() => router.post(`/meetings/${m.uuid}/participants`, { from_course: true, role: 'participant' }, { preserveScroll: true })}>Invite the whole course</Button>}
                                </div>
                            </form>
                        )}
                    </section>
                )}

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-medium">{can.moderate ? 'Attendance' : 'Your attendance'}</h2>
                        {can.moderate && attendance.length > 0 && (
                            <span className="flex items-center gap-3 text-sm">
                                <a className="inline-flex items-center gap-1 text-brand hover:underline" href={`/meetings/${m.uuid}/attendance.csv?format=xlsx`}><Download className="size-4" /> Excel</a>
                                <a className="text-brand hover:underline" href={`/meetings/${m.uuid}/attendance.csv?format=pdf`}>PDF</a>
                                <a className="text-brand hover:underline" href={`/meetings/${m.uuid}/attendance.csv`}>CSV</a>
                            </span>
                        )}
                    </div>
                    {attendance.length === 0 ? <p className="text-sm text-muted-foreground">Attendance is recorded automatically from when people join and leave the room.</p> : (
                        <Table head={['Name', 'ID', 'First joined', 'Last left', 'Present', 'Joins', 'Status']}>
                            {attendance.map((a, i) => (
                                <tr key={i}>
                                    <Td>{a.name}</Td><Td className="font-mono text-xs">{a.identifier}</Td>
                                    <Td className="text-xs">{formatDateTime(a.first_join)}</Td><Td className="text-xs">{a.last_leave ? formatDateTime(a.last_leave) : 'still in'}</Td>
                                    <Td className="tabular-nums">{mins(a.seconds)} <span className="text-xs text-muted-foreground">({a.percent}%)</span></Td>
                                    <Td className="tabular-nums">{a.sessions}</Td><Td><Status value={a.status} /></Td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </section>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-medium">Recordings</h2>
                        {can.moderate && <Button size="sm" variant="ghost" onClick={() => router.post(`/meetings/${m.uuid}/recordings/sync`, {}, { preserveScroll: true })}><RefreshCw className="size-4" /> Look for new recordings</Button>}
                    </div>
                    {recordings.length === 0 ? <p className="text-sm text-muted-foreground">No recordings{m.settings && !m.settings.record ? ' (recording is off for this meeting)' : ''}.</p> : (
                        <Table head={['Recorded', 'Length', 'State', can.moderate ? 'Who may watch' : '', 'Transcript', '']}>
                            {recordings.map((r) => (
                                <tr key={r.id}>
                                    <Td>{formatDateTime(r.started_at)}</Td>
                                    <Td className="tabular-nums">{r.duration_seconds ? mins(r.duration_seconds) : '—'}</Td>
                                    <Td><Status value={r.state} /></Td>
                                    <Td>{can.moderate && (
                                        <select aria-label="Who may watch" className="h-8 rounded-md border border-input bg-card px-2 text-sm" value={r.visibility}
                                            onChange={(e) => router.post(`/meetings/${m.uuid}/recordings/${r.id}`, { action: 'visibility', visibility: e.target.value }, { preserveScroll: true })}>
                                            <option value="hosts">Hosts only</option><option value="attendees">Invited people</option><option value="institution">Institution</option>
                                        </select>
                                    )}</Td>
                                    <Td><TranscriptCell uuid={m.uuid} rec={r} items={transcripts[String(r.id)] ?? []} transcription={transcription} moderate={can.moderate} /></Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {r.state === 'published' && <Button size="sm" variant="outline" asChild><a href={`/meetings/${m.uuid}/recordings/${r.id}`} target="_blank" rel="noreferrer"><Play className="size-4" /> Watch</a></Button>}
                                            {can.moderate && r.state === 'published' && <Button size="sm" variant="ghost" onClick={() => router.post(`/meetings/${m.uuid}/recordings/${r.id}`, { action: 'unpublish' }, { preserveScroll: true })}>Hide</Button>}
                                            {can.moderate && r.state === 'unpublished' && <Button size="sm" variant="ghost" onClick={() => router.post(`/meetings/${m.uuid}/recordings/${r.id}`, { action: 'publish' }, { preserveScroll: true })}>Publish</Button>}
                                            {can.moderate && <Button size="sm" variant="ghost" className="text-status-critical" onClick={() => confirm('Delete this recording from the meeting server? This cannot be undone.') && router.post(`/meetings/${m.uuid}/recordings/${r.id}`, { action: 'delete' }, { preserveScroll: true })}>Delete</Button>}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

MeetingPage.layout = { breadcrumbs: [{ title: 'Live classes & meetings', href: '/meetings' }, { title: 'Meeting', href: '#' }] };
