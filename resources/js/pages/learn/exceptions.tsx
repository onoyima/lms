import { Head, router, useForm } from '@inertiajs/react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Req = { id: number; type: string; subject: string; reason: string; status: string; decision_note: string | null; decided_at: string | null; created_at: string; evidence_name: string | null; course: string | null; exam: string | null };
type Props = { requests: Req[]; types: Record<string, string>; courses: { id: number; label: string }[]; exams: { id: number; title: string }[] };

/** A student asks for an exception and follows the decision (LMS 178). */
export default function Exceptions({ requests, types, courses, exams }: Props) {
    const form = useForm<{ type: string; subject: string; reason: string; course_offering_id: string; exam_id: string; evidence: File | null }>({ type: 'missed_exam', subject: '', reason: '', course_offering_id: '', exam_id: '', evidence: null });

    return (
        <>
            <Head title="Requests and exceptions" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Requests and exceptions" lead="Ask for a missed-exam arrangement, a deadline change, an accessibility arrangement or an exam hardware exception. Attach evidence if you have it; only you and the person deciding can see it." />
                <Flash />
                <form className="surface grid gap-4 p-6 md:grid-cols-2" onSubmit={(e) => {
 e.preventDefault(); form.post('/my/exceptions', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() }); 
}}>
                    <Field label="What is it about?" error={form.errors.type}>
                        <select className={inputClass} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
                    </Field>
                    <Field label="Short title" error={form.errors.subject}><input className={inputClass} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} required maxLength={190} placeholder="e.g. Missed CHM 101 exam on 12 March (hospital)" /></Field>
                    <Field label="Course (if any)" error={form.errors.course_offering_id}>
                        <select className={inputClass} value={form.data.course_offering_id} onChange={(e) => form.setData('course_offering_id', e.target.value)}><option value="">—</option>{courses.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}</select>
                    </Field>
                    <Field label="Exam (if any)" error={form.errors.exam_id}>
                        <select className={inputClass} value={form.data.exam_id} onChange={(e) => form.setData('exam_id', e.target.value)}><option value="">—</option>{exams.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}</select>
                    </Field>
                    <div className="md:col-span-2"><Field label="What happened, and what are you asking for?" error={form.errors.reason}><textarea className={textareaClass} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required minLength={20} /></Field></div>
                    <Field label="Evidence (optional)" hint="PDF, image or Word, up to 10 MB" error={form.errors.evidence}><input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" className="text-sm" onChange={(e) => form.setData('evidence', e.target.files?.[0] ?? null)} /></Field>
                    <div className="flex items-end"><Button disabled={form.processing}>Send request</Button></div>
                </form>
                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">Your requests</h2>
                    {requests.length === 0 && <p className="text-sm text-muted-foreground">You have not sent any requests.</p>}
                    {requests.map((r) => (
                        <article key={r.id} className="surface grid gap-2 p-5 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-medium">{r.subject}</h3>
                                <Status value={r.status === 'rejected' ? 'withheld' : r.status} />
                            </div>
                            <p className="text-xs text-muted-foreground">{types[r.type]} · sent {formatDateTime(r.created_at)}{r.course ? ` · ${r.course}` : ''}{r.exam ? ` · ${r.exam}` : ''}</p>
                            <p className="whitespace-pre-line">{r.reason}</p>
                            {r.evidence_name && <a className="w-fit text-xs text-brand underline" href={`/exceptions/${r.id}/evidence`}>{r.evidence_name}</a>}
                            {r.decision_note && <p className="rounded-xl bg-muted/50 p-3"><b>Decision ({formatDateTime(r.decided_at)}):</b> {r.decision_note}</p>}
                            {r.status === 'pending' && <Button size="sm" variant="ghost" className="w-fit" onClick={() => confirm('Withdraw this request?') && router.post(`/my/exceptions/${r.id}/withdraw`, {}, { preserveScroll: true })}>Withdraw</Button>}
                        </article>
                    ))}
                </section>
            </div>
        </>
    );
}

Exceptions.layout = { breadcrumbs: [{ title: 'Requests and exceptions', href: '/my/exceptions' }] };
