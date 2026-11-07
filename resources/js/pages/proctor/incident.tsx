import { Head, router, useForm } from '@inertiajs/react';
import { IncidentContacts } from '@/components/incident-contacts';
import type { IncidentContact } from '@/components/incident-contacts';
import { inputClass, PageHeader, Status, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Incident = {
    id: number; type: string; severity: string; status: string; source: string; needs_human_review: boolean; title: string; description: string | null; resolution: string | null; attempt_id: number | null;
    exam: string | null; candidate: { name: string; identifier: string | null } | null; assignee: { id: number; name: string } | null; created_at: string;
    notes: { body: string; user: string | null; at: string }[]; evidence: { id: number; kind: string; mime: string; sha256: string }[];
};

export default function IncidentPage({ incident, staff, contacts = [] }: { incident: Incident; staff: { id: number; name: string }[]; contacts?: IncidentContact[] }) {
    const form = useForm({ action: 'investigate', note: '', user_id: incident.assignee?.id ?? '', severity: incident.severity });
    const run = (action: string) => router.put(`/incidents/${incident.id}`, { ...form.data, action }, { preserveScroll: true, onSuccess: () => form.setData('note', '') });

    return (
        <>
            <Head title={incident.title} />
            <div className="grid gap-8 p-6 md:p-10 lg:grid-cols-[1fr_360px]">
                <div className="grid content-start gap-6">
                    <PageHeader eyebrow={`Incident #${incident.id} · ${incident.type} · ${incident.source}`} title={incident.title} lead={<span className="flex gap-2"><Status value={incident.severity} /><Status value={incident.status} />{incident.needs_human_review && <Status value="pending" />}</span>} />
                    <dl className="surface grid grid-cols-2 gap-4 p-6 text-sm">
                        <div><dt className="eyebrow">Candidate</dt><dd className="mt-1">{incident.candidate?.name ?? '—'} <span className="font-mono text-xs">{incident.candidate?.identifier}</span></dd></div>
                        <div><dt className="eyebrow">Exam</dt><dd className="mt-1">{incident.exam ?? '—'}</dd></div>
                        <div><dt className="eyebrow">Opened</dt><dd className="mt-1">{incident.created_at}</dd></div>
                        <div><dt className="eyebrow">Assigned</dt><dd className="mt-1">{incident.assignee?.name ?? '—'}</dd></div>
                        <div className="col-span-2"><dt className="eyebrow">Description</dt><dd className="mt-1 whitespace-pre-wrap">{incident.description}</dd></div>
                        {incident.resolution && <div className="col-span-2"><dt className="eyebrow">Decision</dt><dd className="mt-1 whitespace-pre-wrap">{incident.resolution}</dd></div>}
                    </dl>
                    {incident.evidence.length > 0 && (
                        <section>
                            <p className="eyebrow mb-2">Evidence (integrity checked on every view)</p>
                            <div className="grid grid-cols-3 gap-2">
                                {incident.evidence.map((e) => e.mime.startsWith('image') ? <img key={e.id} src={`/proctor/evidence/${e.id}`} alt="" className="rounded-lg" /> : <a key={e.id} className="text-brand" href={`/proctor/evidence/${e.id}?download=1`}>{e.kind}</a>)}
                            </div>
                        </section>
                    )}
                    <section className="grid gap-2">
                        <p className="eyebrow">Notes</p>
                        {incident.notes.map((n, i) => (
                            <div key={i} className="surface p-4 text-sm"><p className="whitespace-pre-wrap">{n.body}</p><p className="mt-2 text-xs text-muted-foreground">{n.user} · {n.at}</p></div>
                        ))}
                    </section>
                </div>
                <div className="grid content-start gap-4">
                <IncidentContacts contacts={contacts} />
                <aside className="surface grid content-start gap-3 p-5">
                    <p className="eyebrow">Act on this incident</p>
                    <textarea className={textareaClass} placeholder="Note or decision (required to resolve or dismiss)" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    <select className={inputClass} value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value ? Number(e.target.value) : '')}>
                        <option value="">Assign to…</option>
                        {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                    <div className="grid grid-cols-2 gap-2">
                        <Button variant="outline" onClick={() => run('assign')}>Assign</Button>
                        <Button variant="outline" onClick={() => run('escalate')}>Escalate</Button>
                        {incident.needs_human_review && <Button onClick={() => run('confirm_alert')}>Confirm alert</Button>}
                        <Button variant="outline" onClick={() => run('investigate')}>Add note</Button>
                        <Button onClick={() => run('resolve')}>Resolve</Button>
                        <Button variant="ghost" onClick={() => run('dismiss')}>Dismiss</Button>
                    </div>
                    <select className={inputClass} value={form.data.severity} onChange={(e) => {
 form.setData('severity', e.target.value); router.put(`/incidents/${incident.id}`, { action: 'severity', severity: e.target.value }, { preserveScroll: true }); 
}}>
                        {['low', 'medium', 'high', 'critical'].map((s) => <option key={s}>{s}</option>)}
                    </select>
                </aside>
                </div>
            </div>
        </>
    );
}

IncidentPage.layout = { breadcrumbs: [{ title: 'Incidents', href: '/incidents' }, { title: 'Incident', href: '#' }] };
