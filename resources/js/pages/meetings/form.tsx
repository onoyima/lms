import { Head, useForm } from '@inertiajs/react';
import { Field, Flash, inputClass, PageHeader, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Settings = Record<string, boolean | number | string | null | string[]>;
type Existing = { uuid: string; title: string; description: string | null; type: string; course_offering_id: number | null; starts_at: string; duration_minutes: number; timezone: string; access: string; settings: Settings | null };
type Props = { meeting: Existing | null; courses: { id: number; label: string }[]; types: Record<string, string>; defaults: Settings; courseId: number | null; canHostAny: boolean; layouts: string[]; features: Record<string, string>; exams: { id: number; label: string }[]; builtinMax: number; hasServer: boolean };

const LAYOUT_LABELS: Record<string, string> = { SMART_LAYOUT: 'Smart (adjusts to what is shared)', PRESENTATION_FOCUS: 'Slides first', VIDEO_FOCUS: 'Cameras first', CUSTOM_LAYOUT: 'Custom (people arrange it)' };

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;
const SWITCHES: [string, string, string][] = [
    ['waiting_room', 'Waiting room', 'Students wait until a host lets them in'],
    ['mute_on_start', 'Microphones off when joining', ''],
    ['record', 'Allow recording', 'Hosts can start and stop recording in the room'],
    ['auto_record', 'Start recording automatically', ''],
    ['join_before_host', 'Students may join before the host', ''],
    ['webcams_only_for_moderator', 'Only hosts see students’ cameras', ''],
    ['lock_cameras', 'Students cannot share cameras', ''],
    ['lock_microphones', 'Students cannot unmute', ''],
    ['lock_public_chat', 'No public chat for students', ''],
    ['lock_private_chat', 'No private chat between students', ''],
    ['hide_user_list', 'Hide the list of people from students', ''],
];

/** The next whole hour, as a datetime-local value. */
function nextHour(): string {
    const d = new Date();
    d.setHours(d.getHours() + 1, 0, 0, 0);

    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

/** Schedule or change a meeting. */
export default function MeetingForm({ meeting, courses, types, defaults, courseId, canHostAny, layouts, features, exams, builtinMax, hasServer }: Props) {
    const form = useForm({
        title: meeting?.title ?? '', description: meeting?.description ?? '', type: meeting?.type ?? 'class',
        course_offering_id: meeting?.course_offering_id ?? courseId ?? (canHostAny ? null : courses[0]?.id ?? null),
        starts_at: meeting?.starts_at ?? nextHour(), duration_minutes: meeting?.duration_minutes ?? 60,
        timezone: meeting?.timezone ?? (Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Lagos'),
        access: meeting?.access ?? (meeting?.course_offering_id ?? courseId ?? (!canHostAny && courses[0]) ? 'course' : 'invited'),
        settings: { ...defaults, ...(meeting?.settings ?? {}) } as Settings,
        repeat: false, recurrence: { days: [] as number[], until: '' },
    });
    const setSetting = (k: string, v: boolean | number | string | null | string[]) => form.setData('settings', { ...form.data.settings, [k]: v });

    return (
        <>
            <Head title={meeting ? 'Change meeting' : 'Schedule a meeting'} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title={meeting ? 'Change meeting' : 'Schedule a meeting'} />
                <Flash />
                <form className="grid max-w-4xl gap-6" onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ ...d, recurrence: d.repeat && d.recurrence.days.length && d.recurrence.until ? d.recurrence : null }));

                    if (meeting) {
                        form.put(`/meetings/${meeting.uuid}`);
                    } else {
                        form.post('/meetings');
                    }
                }}>
                    <section className="grid gap-4 md:grid-cols-2">
                        <div className="md:col-span-2"><Field label="Title" error={form.errors.title}><input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field></div>
                        <Field label="Kind of meeting" error={form.errors.type}>
                            <select className={inputClass} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
                        </Field>
                        <Field label="Course" error={form.errors.course_offering_id}>
                            <select className={inputClass} value={form.data.course_offering_id ?? ''} onChange={(e) => {
                                const v = e.target.value ? Number(e.target.value) : null;
                                form.setData((d) => ({ ...d, course_offering_id: v, access: v ? 'course' : d.access === 'course' ? 'invited' : d.access }));
                            }}>
                                {canHostAny && <option value="">No course</option>}
                                {courses.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                            </select>
                        </Field>
                        <Field label="Starts" error={form.errors.starts_at}><input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required /></Field>
                        <Field label="Length (minutes)" error={form.errors.duration_minutes}><input type="number" min={10} max={600} step={5} className={inputClass} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', Number(e.target.value))} /></Field>
                        <Field label="Time zone" error={form.errors.timezone}><input className={inputClass} value={form.data.timezone} onChange={(e) => form.setData('timezone', e.target.value)} /></Field>
                        <Field label="Who can join" error={form.errors.access}>
                            <select className={inputClass} value={form.data.access} onChange={(e) => form.setData('access', e.target.value)}>
                                {form.data.course_offering_id && <option value="course">Everyone registered for the course</option>}
                                <option value="invited">Only people I invite</option>
                                <option value="institution">Anyone in the institution</option>
                            </select>
                        </Field>
                        <div className="md:col-span-2"><Field label="Description" error={form.errors.description}><textarea className={textareaClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field></div>
                    </section>

                    {!meeting && (
                        <section className="grid gap-3 rounded-lg border border-border bg-card p-4">
                            <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={form.data.repeat} onChange={(e) => form.setData('repeat', e.target.checked)} /> Repeats every week</label>
                            {form.data.repeat && (
                                <div className="flex flex-wrap items-end gap-4">
                                    <div className="flex gap-1" role="group" aria-label="Days">
                                        {DAYS.map(([n, label]) => (
                                            <label key={n} className={`cursor-pointer rounded-md border px-2.5 py-1 text-sm ${form.data.recurrence.days.includes(n) ? 'border-brand bg-brand text-brand-foreground' : 'border-border'}`}>
                                                <input type="checkbox" className="sr-only" checked={form.data.recurrence.days.includes(n)} onChange={() => form.setData('recurrence', { ...form.data.recurrence, days: form.data.recurrence.days.includes(n) ? form.data.recurrence.days.filter((x) => x !== n) : [...form.data.recurrence.days, n] })} />{label}
                                            </label>
                                        ))}
                                    </div>
                                    <Field label="Until" error={form.errors['recurrence.until']}><input type="date" className={inputClass} value={form.data.recurrence.until} onChange={(e) => form.setData('recurrence', { ...form.data.recurrence, until: e.target.value })} /></Field>
                                </div>
                            )}
                        </section>
                    )}

                    <section className="grid gap-2">
                        <h2 className="text-sm font-medium">Room rules</h2>
                        <div className="grid gap-x-6 gap-y-2 rounded-lg border border-border bg-card p-4 sm:grid-cols-2">
                            {SWITCHES.map(([k, label, hint]) => (
                                <label key={k} className="flex items-start gap-2 text-sm">
                                    <input type="checkbox" className="mt-1" checked={!!form.data.settings[k]} onChange={(e) => setSetting(k, e.target.checked)} />
                                    <span>{label}{hint && <span className="block text-xs text-muted-foreground">{hint}</span>}</span>
                                </label>
                            ))}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="Most people at once" hint="blank = no limit" error={form.errors['settings.max_participants']}>
                                <input type="number" min={2} className={inputClass} value={(form.data.settings.max_participants as number | null) ?? ''} onChange={(e) => setSetting('max_participants', e.target.value ? Number(e.target.value) : null)} />
                            </Field>
                            <Field label="Students may enter (minutes early)" error={form.errors['settings.early_join_minutes']}>
                                <input type="number" min={0} max={120} className={inputClass} value={(form.data.settings.early_join_minutes as number) ?? 15} onChange={(e) => setSetting('early_join_minutes', Number(e.target.value))} />
                            </Field>
                            <Field label="Who may watch recordings" error={form.errors['settings.recording_visibility']}>
                                <select className={inputClass} value={(form.data.settings.recording_visibility as string) ?? 'attendees'} onChange={(e) => setSetting('recording_visibility', e.target.value)}>
                                    <option value="hosts">Hosts only</option><option value="attendees">People invited to the meeting</option><option value="institution">Anyone in the institution</option>
                                </select>
                            </Field>
                        </div>
                        <Field label="Welcome message in the room" error={form.errors['settings.welcome']}>
                            <input className={inputClass} value={(form.data.settings.welcome as string) ?? ''} onChange={(e) => setSetting('welcome', e.target.value)} maxLength={500} />
                        </Field>
                    </section>
                    <section className="grid gap-3">
                        <h2 className="text-sm font-medium">In the room</h2>
                        <Field label="Where the meeting runs" error={form.errors['settings.engine']}
                            hint={hasServer ? undefined : `No meeting server is connected yet, so meetings use the built-in room (up to ${builtinMax} people).`}>
                            <select className={inputClass} value={(form.data.settings.engine as string) ?? 'auto'} onChange={(e) => setSetting('engine', e.target.value)}>
                                <option value="auto">Automatic: the meeting server when connected, otherwise the built-in room</option>
                                <option value="builtin">Built-in room: no server needed, up to {builtinMax} people, no recording</option>
                                <option value="bbb">Meeting server (BigBlueButton): large classes and recording</option>
                            </select>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Screen layout" error={form.errors['settings.layout']}>
                                <select className={inputClass} value={(form.data.settings.layout as string) ?? 'SMART_LAYOUT'} onChange={(e) => setSetting('layout', e.target.value)}>
                                    {layouts.map((l) => <option key={l} value={l}>{LAYOUT_LABELS[l] ?? l}</option>)}
                                </select>
                            </Field>
                            {exams.length > 0 && (
                                <Field label="Viva, practical or defence held here" hint="Its examiners host; its candidates may join" error={form.errors['settings.exam_id']}>
                                    <select className={inputClass} value={(form.data.settings.exam_id as number | null) ?? ''} onChange={(e) => setSetting('exam_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">None</option>
                                        {exams.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}
                                    </select>
                                </Field>
                            )}
                        </div>
                        <div className="grid gap-x-6 gap-y-2 rounded-lg border border-border bg-card p-4 sm:grid-cols-2">
                            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={!!form.data.settings.listen_only} onChange={(e) => setSetting('listen_only', e.target.checked)} /><span>Students join listening only<span className="block text-xs text-muted-foreground">Good for large lectures; they can still turn the microphone on</span></span></label>
                            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={!!form.data.settings.skip_audio_check} onChange={(e) => setSetting('skip_audio_check', e.target.checked)} /><span>Skip the echo test when joining</span></label>
                        </div>
                        <div className="rounded-lg border border-border bg-card p-4">
                            <p className="mb-2 text-sm font-medium">Switch off in this room</p>
                            <div className="grid gap-x-6 gap-y-2 sm:grid-cols-3">
                                {Object.entries(features).map(([k, label]) => {
                                    const off = ((form.data.settings.disabled_features as string[] | undefined) ?? []).includes(k);

                                    return (
                                        <label key={k} className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={off} onChange={(e) => {
                                                const cur = ((form.data.settings.disabled_features as string[] | undefined) ?? []).filter((x) => x !== k);
                                                setSetting('disabled_features', e.target.checked ? [...cur, k] : cur);
                                            }} /> {label}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    </section>
                    <div><Button disabled={form.processing}>{meeting ? 'Save' : 'Schedule'}</Button></div>
                </form>
            </div>
        </>
    );
}

MeetingForm.layout = { breadcrumbs: [{ title: 'Live classes & meetings', href: '/meetings' }, { title: 'Meeting', href: '#' }] };
