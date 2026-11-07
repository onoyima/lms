import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type App = {
    id: number; institution: string; slug: string; contact_name: string; contact_email: string; contact_phone: string | null; country: string;
    students: number | null; plan: { id: number; name: string } | null; cycle: string; message: string | null; status: string;
    review_note: string | null; created_at: string; reviewed_at: string | null; tenant_id: number | null;
};

/** Institutions that asked to join from the website. Approving creates the institution and emails its first administrator. */
export default function Applications({ applications }: { applications: App[] }) {
    return (
        <>
            <Head title="Applications" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Applications" lead="Check each institution is genuine (call the number, confirm the email domain) before approving. Approval starts the free trial and sends the administrator a link to set a password." />
                <Flash />
                {applications.length === 0 && <p className="text-muted-foreground">No applications yet.</p>}
                {applications.map((a) => <Card key={a.id} app={a} />)}
            </div>
        </>
    );
}

function Card({ app: a }: { app: App }) {
    const [slug, setSlug] = useState(a.slug);
    const [trial, setTrial] = useState(30);
    const [note, setNote] = useState('');
    const decide = (approve: boolean) => {
        if (confirm(approve ? `Approve ${a.institution} and create it?` : `Decline ${a.institution}?`)) {
            router.post(`/platform/applications/${a.id}`, { approve, slug, trial_days: trial, note: note || null }, { preserveScroll: true });
        }
    };

    return (
        <section className="surface flex flex-col gap-3 p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-lg font-medium">{a.institution}</h2>
                    <p className="text-sm text-muted-foreground">{a.contact_name} · <a className="underline" href={`mailto:${a.contact_email}`}>{a.contact_email}</a>{a.contact_phone ? ` · ${a.contact_phone}` : ''} · {a.country}</p>
                </div>
                <div className="text-right text-xs text-muted-foreground"><Status value={a.status} /><span className="block mt-1">{formatDateTime(a.created_at)}</span></div>
            </div>
            <p className="text-sm">Wants <b>{a.plan?.name ?? 'advice on a plan'}</b>, billed {a.cycle}{a.students ? `, about ${a.students.toLocaleString()} students` : ''}.</p>
            {a.message && <p className="rounded-xl bg-muted/50 p-3 text-sm whitespace-pre-line">{a.message}</p>}
            {a.status === 'pending' ? (
                <div className="flex flex-wrap items-end gap-2">
                    <label className="grid gap-1 text-xs">Short name<input className={`${inputClass} w-40`} value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase())} /></label>
                    <label className="grid gap-1 text-xs">Trial days<input type="number" min={0} max={90} className={`${inputClass} w-24`} value={trial} onChange={(e) => setTrial(Number(e.target.value))} /></label>
                    <label className="grid flex-1 gap-1 text-xs">Note (kept on record)<input className={inputClass} value={note} onChange={(e) => setNote(e.target.value)} /></label>
                    <Button onClick={() => decide(true)}>Approve</Button>
                    <Button variant="outline" onClick={() => decide(false)}>Decline</Button>
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">Decided {formatDateTime(a.reviewed_at)}{a.review_note ? ` — ${a.review_note}` : ''}{a.tenant_id ? <> · <a className="underline" href={`/platform/institutions/${a.tenant_id}`}>open institution</a></> : null}</p>
            )}
        </section>
    );
}

Applications.layout = { breadcrumbs: [{ title: 'Applications', href: '/platform/applications' }] };
