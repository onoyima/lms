import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Req = { id: number; type: string; subject: string; reason: string; status: string; decision_note: string | null; decided_at: string | null; created_at: string; evidence_name: string | null; student: { name: string; identifier: string | null } | null; course: string | null; exam: string | null; decider: string | null };
type Hw = { id: number; kind: string; detail: string; relax: string[]; status: string; valid_until: string | null; requested_by: number | null; decided_at: string | null; student: { name: string; identifier: string | null } | null; exam: string | null };
type Props = { requests: Req[]; hardware: Hw[]; types: Record<string, string>; kinds: string[]; relaxable: string[]; exams: { id: number; title: string }[]; canDecide: boolean; me: number; filter: string };

/** Exception requests from students, and the register of hardware exceptions (LMS 178, Target 141). */
export default function ExceptionsAdmin(p: Props) {
    const [tab, setTab] = useState<'requests' | 'hardware'>('requests');

    return (
        <>
            <Head title="Exceptions" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Exceptions" lead="Every decision is recorded with its reason. Evidence is opened only here and each opening is logged." />
                <Flash />
                <div className="flex gap-2">
                    <Button variant={tab === 'requests' ? 'default' : 'outline'} onClick={() => setTab('requests')}>Requests from students</Button>
                    <Button variant={tab === 'hardware' ? 'default' : 'outline'} onClick={() => setTab('hardware')}>Hardware exceptions</Button>
                </div>
                {tab === 'requests' ? <Requests {...p} /> : <Hardware {...p} />}
            </div>
        </>
    );
}

function Requests({ requests, types, canDecide, filter }: Props) {
    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-2">
                {['pending', 'approved', 'rejected', 'all'].map((s) => <Button key={s} size="sm" variant={filter === s ? 'default' : 'outline'} onClick={() => router.get('/admin/exceptions', { status: s }, { preserveState: true })}>{s}</Button>)}
            </div>
            {requests.length === 0 && <p className="text-sm text-muted-foreground">Nothing here.</p>}
            {requests.map((r) => <RequestCard key={r.id} r={r} types={types} canDecide={canDecide} />)}
        </div>
    );
}

function RequestCard({ r, types, canDecide }: { r: Req; types: Record<string, string>; canDecide: boolean }) {
    const [note, setNote] = useState('');
    const decide = (approve: boolean) => router.post(`/admin/exceptions/${r.id}/decide`, { approve, note }, { preserveScroll: true });

    return (
        <article className="surface grid gap-2 p-5 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">{r.subject}</h3>
                <Status value={r.status === 'rejected' ? 'withheld' : r.status} />
            </div>
            <p className="text-xs text-muted-foreground">{r.student?.name} {r.student?.identifier} · {types[r.type]} · {formatDateTime(r.created_at)}{r.course ? ` · ${r.course}` : ''}{r.exam ? ` · ${r.exam}` : ''}</p>
            <p className="whitespace-pre-line">{r.reason}</p>
            {r.evidence_name && <a className="w-fit text-xs text-brand underline" href={`/exceptions/${r.id}/evidence`}>Evidence: {r.evidence_name}</a>}
            {r.decision_note && <p className="rounded-xl bg-muted/50 p-3">{r.decider}: {r.decision_note}</p>}
            {r.status === 'pending' && canDecide && (
                <div className="flex flex-wrap items-end gap-2">
                    <input className={`${inputClass} flex-1`} placeholder="Reason for the decision (the student sees this)" value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button disabled={note.trim().length < 5} onClick={() => decide(true)}>Approve</Button>
                    <Button variant="outline" disabled={note.trim().length < 5} onClick={() => decide(false)}>Decline</Button>
                </div>
            )}
        </article>
    );
}

function Hardware({ hardware, kinds, relaxable, exams, canDecide, me }: Props) {
    const form = useForm<{ identifier: string; exam_id: string; kind: string; detail: string; relax: string[]; valid_until: string }>({ identifier: '', exam_id: '', kind: 'no_webcam', detail: '', relax: ['webcam'], valid_until: '' });
    const toggle = (k: string) => form.setData('relax', form.data.relax.includes(k) ? form.data.relax.filter((x) => x !== k) : [...form.data.relax, k]);

    return (
        <div className="grid gap-6">
            <form className="surface grid gap-3 p-5 md:grid-cols-4" onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/hardware-exceptions', { preserveScroll: true, onSuccess: () => form.reset() }); 
}}>
                <Field label="Student (matric no. or email)" error={form.errors.identifier}><input className={inputClass} value={form.data.identifier} onChange={(e) => form.setData('identifier', e.target.value)} required /></Field>
                <Field label="Exam" hint="blank = every exam until it expires"><select className={inputClass} value={form.data.exam_id} onChange={(e) => form.setData('exam_id', e.target.value)}><option value="">Every exam</option>{exams.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}</select></Field>
                <Field label="Kind"><select className={inputClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>{kinds.map((k) => <option key={k} value={k}>{k.replace(/_/g, ' ')}</option>)}</select></Field>
                <Field label="Valid until" error={form.errors.valid_until}><input type="date" className={inputClass} value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} /></Field>
                <div className="md:col-span-2"><Field label="Reason and evidence seen" error={form.errors.detail}><textarea className={textareaClass} value={form.data.detail} onChange={(e) => form.setData('detail', e.target.value)} required /></Field></div>
                <div className="md:col-span-2">
                    <p className="mb-1.5 text-sm font-medium">Checks switched off for this candidate</p>
                    <div className="flex flex-wrap gap-1.5">
                        {relaxable.map((k) => (
                            <label key={k} className={`cursor-pointer rounded-full border px-2.5 py-1 text-xs ${form.data.relax.includes(k) ? 'border-brand bg-brand-soft text-brand' : 'text-muted-foreground'}`}>
                                <input type="checkbox" className="sr-only" checked={form.data.relax.includes(k)} onChange={() => toggle(k)} />{k.replace(/_/g, ' ')}
                            </label>
                        ))}
                    </div>
                    {form.errors.relax && <p className="mt-1 text-xs text-destructive">{form.errors.relax}</p>}
                </div>
                <Button className="w-fit" disabled={form.processing}>Record exception</Button>
            </form>
            <Table head={['Candidate', 'Exam', 'Kind', 'Switched off', 'Until', 'Status', '']}>
                {hardware.length === 0 && <tr><Td className="text-muted-foreground">No hardware exceptions.</Td></tr>}
                {hardware.map((h) => (
                    <tr key={h.id}>
                        <Td>{h.student?.name}<span className="block font-mono text-xs text-muted-foreground">{h.student?.identifier}</span></Td>
                        <Td>{h.exam ?? 'Every exam'}</Td>
                        <Td>{h.kind.replace(/_/g, ' ')}<span className="block text-xs text-muted-foreground">{h.detail}</span></Td>
                        <Td className="text-xs">{h.relax.map((x) => x.replace(/_/g, ' ')).join(', ')}</Td>
                        <Td>{h.valid_until ?? '—'}</Td>
                        <Td><Status value={h.status === 'rejected' || h.status === 'revoked' ? 'withheld' : h.status} /></Td>
                        <Td>
                            {canDecide && h.status === 'pending' && h.requested_by !== me && (
                                <div className="flex gap-1">
                                    <Button size="sm" onClick={() => router.post(`/admin/hardware-exceptions/${h.id}`, { action: 'approve' }, { preserveScroll: true })}>Approve</Button>
                                    <Button size="sm" variant="ghost" onClick={() => router.post(`/admin/hardware-exceptions/${h.id}`, { action: 'reject' }, { preserveScroll: true })}>Decline</Button>
                                </div>
                            )}
                            {canDecide && h.status === 'pending' && h.requested_by === me && <span className="text-xs text-muted-foreground">needs a second person</span>}
                            {canDecide && h.status === 'approved' && <Button size="sm" variant="ghost" onClick={() => confirm('Revoke this exception?') && router.post(`/admin/hardware-exceptions/${h.id}`, { action: 'revoke' }, { preserveScroll: true })}>Revoke</Button>}
                        </Td>
                    </tr>
                ))}
            </Table>
        </div>
    );
}

ExceptionsAdmin.layout = { breadcrumbs: [{ title: 'Exceptions', href: '/admin/exceptions' }] };
