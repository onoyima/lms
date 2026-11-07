import { Head, Link, usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, Eye, EyeOff, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { formatDateTime, Stat } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type ModuleCard = { key: string; name: string; description: string; enabled: boolean };
type Panels = {
    institution?: Record<string, number | string | boolean | null>;
    upcoming?: { id: number; title: string; starts_at: string; ends_at: string; status: string; venue: string | null; candidates: number }[];
    oversight?: { scope: string; courses: number; reviews_waiting: number; results_to_approve: number; pass_rate: number | null; marking_backlog: number; courses_needing_attention: { id: number; code: string; title: string }[] };
    teaching?: { courses: number; to_mark: number; unanswered_threads: number; students: number };
    student?: { courses: number; unread: number; due: { id: number; title: string; type: string; due_at: string; code: string }[]; exams: { id: number; title: string; starts_at: string }[] };
    casework?: { requests: number; hardware: number; anomalies: number };
};
type Layout = { hidden: string[]; order: string[] };

const moduleLinks: Record<string, string> = { question_bank: '/question-bank', lms: '/learn/courses', browser_proctoring: '/proctor', results: '/marking', analytics: '/admin/reports' };

function Card({ title, children, href }: { title: string; children: ReactNode; href?: string }) {
    return (
        <section className="surface grid content-start gap-3 p-6">
            <div className="flex items-center justify-between"><p className="eyebrow">{title}</p>{href && <Link href={href} className="text-xs text-brand">Open</Link>}</div>
            {children}
        </section>
    );
}

const KPIS: { key: string; label: string; tone?: (v: unknown) => 'good' | 'warning' | 'critical' | undefined; render?: (v: unknown) => ReactNode }[] = [
    { key: 'students', label: 'Active students' },
    { key: 'courses', label: 'Courses this semester' },
    { key: 'live_attempts', label: 'Candidates writing now', tone: (v) => (Number(v) > 0 ? 'good' : undefined) },
    { key: 'open_incidents', label: 'Open incidents', tone: (v) => (Number(v) > 0 ? 'warning' : undefined) },
    { key: 'results_waiting', label: 'Results awaiting approval' },
    { key: 'pending_registrations', label: 'Registrations to approve' },
    { key: 'failed_logins_24h', label: 'Failed sign-ins (24 h)', tone: (v) => (Number(v) > 20 ? 'critical' : undefined) },
    { key: 'last_backup', label: 'Last backup', tone: (v) => (v ? undefined : 'critical'), render: (v) => <span className="text-base">{v ? formatDateTime(String(v)) : 'never'}</span> },
    { key: 'staff', label: 'Active staff' },
];

const PANEL_NAMES: Record<string, string> = { kpis: 'Key figures', upcoming: 'Examinations this week', oversight: 'Faculty or department', teaching: 'Your teaching', casework: 'Cases waiting', student_due: 'Due soon', student_exams: 'Your examinations', modules: "Institution's modules" };

export default function Dashboard({ moduleCards, panels, semester, layout: saved = { hidden: [], order: [] } }: { moduleCards: ModuleCard[]; panels: Panels; semester: string | null; layout?: Layout }) {
    const { tenant, auth, platform } = usePage().props;
    const i = panels.institution;
    const [layout, setLayout] = useState<Layout>(saved);
    const [editing, setEditing] = useState(false);
    const available = ['kpis', 'upcoming', 'oversight', 'teaching', 'casework', 'student_due', 'student_exams', 'modules'].filter((k) =>
        k === 'modules' || (k === 'kpis' ? !!i : k === 'student_due' || k === 'student_exams' ? !!panels.student : !!panels[k as keyof Panels]));
    const order = [...layout.order.filter((k) => available.includes(k)), ...available.filter((k) => !layout.order.includes(k))];
    const shown = (k: string) => !layout.hidden.includes(k);
    const persist = (next: Layout) => {
        setLayout(next);
        router.put('/dashboard/layout', next, { preserveScroll: true, preserveState: true, only: [] });
    };
    const toggle = (k: string) => persist({ ...layout, hidden: shown(k) ? [...layout.hidden, k] : layout.hidden.filter((x) => x !== k) });

    const renderPanel = (k: string): ReactNode => {
        switch (k) {
            case 'kpis':
                return (
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {KPIS.filter((x) => i && x.key in i && shown(`kpi:${x.key}`)).map((x) => (
                        <Stat key={x.key} label={x.label} value={x.render ? x.render(i![x.key]) : String(i![x.key])} tone={x.tone?.(i![x.key])} />
                    ))}
                </div>
                {i && (i.access_review_due || Number(i.pending_exports) > 0) && (
                    <div className="flex flex-wrap gap-3 text-sm">
                        {i.access_review_due && <Link href="/admin/access-review" className="flex items-center gap-2 rounded-xl border border-status-warning/50 bg-status-warning/10 px-4 py-2"><AlertTriangle className="size-4" /> Access review is due</Link>}
                        {Number(i.pending_exports) > 0 && <Link href="/admin/reports" className="flex items-center gap-2 rounded-xl border border-border px-4 py-2">{String(i.pending_exports)} export request(s) waiting</Link>}
                    </div>
                )}
            </div>);
            case 'upcoming':
                return panels.upcoming && (
            <Card title="Examinations in the next 7 days" href="/exams">
                            {panels.upcoming.length === 0 && <p className="text-sm text-muted-foreground">Nothing scheduled.</p>}
                            {panels.upcoming.map((s) => (
                                <Link key={s.id} href={`/sessions/${s.id}`} className="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm hover:bg-muted">
                                    <span>{s.title}<span className="block text-xs text-muted-foreground">{formatDateTime(s.starts_at)} · {s.venue ?? 'online'}</span></span>
                                    <span className="tabular-nums text-muted-foreground">{s.candidates}</span>
                                </Link>
                            ))}
                        </Card>);
            case 'oversight':
                return panels.oversight && (
            <Card title={`Your ${panels.oversight.scope}`} href="/admin/reports">
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <p>Courses <strong className="block text-2xl">{panels.oversight.courses}</strong></p>
                                <p>Pass rate <strong className="block text-2xl">{panels.oversight.pass_rate ?? '—'}{panels.oversight.pass_rate !== null && '%'}</strong></p>
                                <p>Course reviews waiting <strong className="block text-2xl">{panels.oversight.reviews_waiting}</strong></p>
                                <p>Results to approve <strong className="block text-2xl">{panels.oversight.results_to_approve}</strong></p>
                                <p>Marking backlog <strong className="block text-2xl">{panels.oversight.marking_backlog}</strong></p>
                            </div>
                            {panels.oversight.courses_needing_attention.length > 0 && (
                                <div className="text-sm"><p className="mt-2 text-xs text-muted-foreground">Courses with little or no material</p>
                                    {panels.oversight.courses_needing_attention.map((c) => <Link key={c.id} href={`/learn/courses/${c.id}`} className="block hover:text-brand">{c.code} · {c.title}</Link>)}</div>
                            )}
                        </Card>);
            case 'teaching':
                return panels.teaching && (
            <Card title="Your teaching" href="/learn/courses">
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <p>Courses <strong className="block text-2xl">{panels.teaching.courses}</strong></p>
                                <p>Students <strong className="block text-2xl">{panels.teaching.students}</strong></p>
                                <p>Waiting to be marked <strong className={`block text-2xl ${panels.teaching.to_mark ? 'text-status-warning' : ''}`}>{panels.teaching.to_mark}</strong></p>
                                <p>Unanswered questions <strong className="block text-2xl">{panels.teaching.unanswered_threads}</strong></p>
                            </div>
                        </Card>);
            case 'casework':
                return panels.casework && (
            <Card title="Cases waiting" href="/admin/exceptions">
                <div className="grid grid-cols-3 gap-3 text-sm">
                    <Link href="/admin/exceptions" className="hover:text-brand">Exception requests <strong className="block text-2xl">{panels.casework!.requests}</strong></Link>
                    <Link href="/admin/exceptions" className="hover:text-brand">Hardware exceptions <strong className="block text-2xl">{panels.casework!.hardware}</strong></Link>
                    <Link href="/exams" className="hover:text-brand">Unusual attempts <strong className="block text-2xl">{panels.casework!.anomalies}</strong></Link>
                </div>
            </Card>);
            case 'student_due':
                return panels.student && (
            <Card title="Due in the next two weeks" href="/learn/courses">
                                {panels.student.due.length === 0 && <p className="text-sm text-muted-foreground">Nothing due.</p>}
                                {panels.student.due.map((d) => <Link key={d.id} href={`/learn/items/${d.id}`} className="block rounded-lg px-2 py-1.5 text-sm hover:bg-muted">{d.title}<span className="block text-xs text-muted-foreground">{d.code} · {d.type} · {formatDateTime(d.due_at)}</span></Link>)}
                            </Card>);
            case 'student_exams':
                return panels.student && (
            <Card title="Your examinations" href="/my/exams">
                                {panels.student.exams.length === 0 && <p className="text-sm text-muted-foreground">None scheduled.</p>}
                                {panels.student.exams.map((e) => <Link key={e.id} href={`/my/exams/${e.id}`} className="block rounded-lg px-2 py-1.5 text-sm hover:bg-muted">{e.title}<span className="block text-xs text-muted-foreground">{formatDateTime(e.starts_at)}</span></Link>)}
                                {panels.student.unread > 0 && <Link href="/notifications" className="text-sm text-brand">{panels.student.unread} unread notification(s)</Link>}
                            </Card>);
            case 'modules':
                return (
            <section className="grid gap-2">
                    <h2 className="text-sm font-medium">Modules in your subscription</h2>
                    <ul className="divide-y divide-border rounded-lg border border-border bg-card">
                        {moduleCards.filter((m) => m.enabled).map((m) => (
                            <li key={m.key}>
                                <Link href={moduleLinks[m.key] ?? dashboard().url} className="flex flex-wrap items-baseline gap-x-4 gap-y-0.5 px-4 py-2.5 hover:bg-muted/50">
                                    <span className="w-56 shrink-0 text-sm font-medium">{m.name}</span>
                                    <span className="min-w-0 flex-1 text-xs text-muted-foreground">{m.description}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>);
            default:
                return null;
        }
    };

    const move = (k: string, d: -1 | 1) => {
        const o = [...order];
        const n = o.indexOf(k);
        [o[n], o[n + d]] = [o[n + d], o[n]];
        persist({ ...layout, order: o });
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-8 p-6 md:p-10">
                <section className="flex flex-wrap items-end justify-between gap-4">
                    <div className="max-w-3xl">
                        <h1 className="text-[1.75rem] leading-tight font-semibold">
                            Welcome back, {auth.user.name.split(' ')[0]}.
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">{tenant?.name ?? platform?.name}{semester && ` · ${semester}`}</p>
                        {tenant?.welcome_message && <p className="mt-3 max-w-2xl whitespace-pre-line text-muted-foreground">{tenant.welcome_message}</p>}
                    </div>
                    <Button variant={editing ? 'default' : 'outline'} size="sm" onClick={() => setEditing(!editing)}><SlidersHorizontal className="size-4" /> {editing ? 'Done' : 'Customise'}</Button>
                </section>

                {editing && (
                    <section className="surface grid gap-3 p-5 text-sm" aria-label="Customise dashboard">
                        <p className="text-muted-foreground">Choose what you see and the order. Saved to your account.</p>
                        <ul className="grid gap-1">
                            {order.map((k, n) => (
                                <li key={k} className="flex items-center justify-between gap-2 rounded-lg border px-3 py-1.5">
                                    <span className={shown(k) ? '' : 'text-muted-foreground line-through'}>{PANEL_NAMES[k]}</span>
                                    <span className="flex gap-1">
                                        <Button size="icon" variant="ghost" aria-label={`Move ${PANEL_NAMES[k]} up`} disabled={n === 0} onClick={() => move(k, -1)}><ArrowUp className="size-4" /></Button>
                                        <Button size="icon" variant="ghost" aria-label={`Move ${PANEL_NAMES[k]} down`} disabled={n === order.length - 1} onClick={() => move(k, 1)}><ArrowDown className="size-4" /></Button>
                                        <Button size="icon" variant="ghost" aria-label={shown(k) ? `Hide ${PANEL_NAMES[k]}` : `Show ${PANEL_NAMES[k]}`} onClick={() => toggle(k)}>{shown(k) ? <Eye className="size-4" /> : <EyeOff className="size-4" />}</Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {i && (
                            <div className="flex flex-wrap gap-1.5">
                                <span className="w-full text-xs text-muted-foreground">Key figures</span>
                                {KPIS.filter((x) => x.key in i).map((x) => (
                                    <button key={x.key} type="button" aria-pressed={shown(`kpi:${x.key}`)} onClick={() => toggle(`kpi:${x.key}`)} className={`rounded-full border px-2.5 py-1 text-xs ${shown(`kpi:${x.key}`) ? 'border-brand bg-brand-soft text-brand' : 'text-muted-foreground'}`}>{x.label}</button>
                                ))}
                            </div>
                        )}
                    </section>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {order.filter(shown).map((k) => {
                        const wide = k === 'kpis' || k === 'modules';

                        return <div key={k} className={wide ? 'lg:col-span-2' : ''}>{renderPanel(k)}</div>;
                    })}
                </div>
            </div>
        </>
    );
}
