import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Check, CheckCheck, Clock, Eye, GraduationCap, Plus, Radio, Scale, Send, ShieldAlert, Timer, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Arrangeable, useArrangement } from '@/components/arrangeable';
import { ExamDayBoard } from '@/components/dashboard/exam-day';
import MidnightDashboard from '@/components/dashboard/midnight';
import type { Decision, ExamWithPapers, Layout, ModuleCard, Panels } from '@/components/dashboard/types';
import { formatDateTime, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { usePlatform } from '@/hooks/use-platform';
import { paperLook } from '@/lib/papers';
import { cn, firstName } from '@/lib/utils';


const moduleLinks: Record<string, string> = { question_bank: '/question-bank', lms: '/learn/courses', browser_proctoring: '/proctor', results: '/marking', analytics: '/admin/reports' };
const decisionIcons: Record<string, LucideIcon> = { eye: Eye, check: CheckCheck, send: Send, timer: Timer, scale: Scale };
const toneStyle = {
    critical: 'bg-status-critical/12 text-status-critical',
    warning: 'bg-status-warning/14 text-status-warning',
    info: 'bg-status-info/12 text-status-info',
};

function Panel({ title, children, href, linkText = 'Open', count, className }: { title: string; children: ReactNode; href?: string; linkText?: string; count?: number; className?: string }) {
    return (
        <section className={cn('surface grid content-start gap-3 p-5 md:p-6', className)}>
            <div className="flex items-center gap-2.5">
                <h2 className="text-lg font-semibold">{title}</h2>
                {count !== undefined && count > 0 && <span className="rounded-full bg-foreground px-2 text-xs leading-5 font-semibold text-background tabular-nums">{count}</span>}
                {href && <Link href={href} className="ml-auto inline-flex items-center gap-1 text-sm font-medium text-brand">{linkText} <ArrowRight className="size-3.5" /></Link>}
            </div>
            {children}
        </section>
    );
}

/** A small trend line under a figure. */
function Spark({ values, color, id }: { values: number[]; color: string; id: string }) {
    if (values.length < 2) {
        return <div className="h-9" />;
    }

    const max = Math.max(...values);
    const min = Math.min(...values);
    const pts = values.map((v, i) => `${(i / (values.length - 1)) * 120},${30 - ((v - min) / Math.max(1, max - min)) * 26}`).join(' ');

    return (
        <svg viewBox="0 0 120 34" preserveAspectRatio="none" className="mt-2 h-9 w-full overflow-visible" aria-hidden>
            <defs><linearGradient id={`spark-${id}`} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor={color} stopOpacity=".22" /><stop offset="1" stopColor={color} stopOpacity="0" /></linearGradient></defs>
            <polygon points={`0,34 ${pts} 120,34`} fill={`url(#spark-${id})`} />
            <polyline points={pts} fill="none" stroke={color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

function GlanceCard({ label, icon: Icon, value, note, noteTone, href, children }: { label: string; icon: LucideIcon; value: ReactNode; note: ReactNode; noteTone?: string; href: string; children?: ReactNode }) {
    return (
        <Link href={href} className="surface group block p-5 transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-24px_var(--brand)]">
            <span className="flex items-center justify-between text-sm text-muted-foreground">{label}<Icon className="size-[18px] transition-transform group-hover:scale-110" /></span>
            <span className="mt-2 block font-display text-[2rem] leading-none font-semibold tabular-nums">{value}</span>
            <span className={cn('mt-2 block text-[13px] font-semibold', noteTone ?? 'text-status-good')}>{note}</span>
            {children}
        </Link>
    );
}

function Decisions({ items }: { items: Decision[] }) {
    const [tab, setTab] = useState<'all' | Decision['kind']>('all');
    const tabs: { key: 'all' | Decision['kind']; label: string }[] = [
        { key: 'all', label: 'All' }, { key: 'exam_day', label: 'Exam day' }, { key: 'approvals', label: 'Approvals' }, { key: 'requests', label: 'Requests' },
    ];
    const list = items.filter((d) => tab === 'all' || d.kind === tab);

    return (
        <Panel title="Needs your decision" count={items.length}>
            <div className="flex flex-wrap gap-1.5" role="tablist">
                {tabs.map((t) => {
                    const n = t.key === 'all' ? items.length : items.filter((d) => d.kind === t.key).length;

                    return (
                        <button key={t.key} role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)}
                            className={cn('rounded-full border px-3 py-1 text-[13px] font-semibold transition-colors', tab === t.key ? 'border-foreground bg-foreground text-background' : 'border-border bg-card/60 text-muted-foreground hover:text-foreground')}>
                            {t.label}{t.key !== 'all' && n > 0 ? ` ${n}` : ''}
                        </button>
                    );
                })}
            </div>
            {list.length === 0 && (
                <p className="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Check className="size-4 text-status-good" /> Nothing is waiting for you here.</p>
            )}
            <ul className="-mx-2">
                {list.map((d, n) => {
                    const Icon = decisionIcons[d.icon] ?? Eye;

                    return (
                        <li key={n}>
                            <Link href={d.href} className="group grid grid-cols-[40px_1fr_auto] items-center gap-3 rounded-2xl p-2 transition-colors hover:bg-card/80">
                                <span className={cn('grid size-10 place-items-center rounded-xl', toneStyle[d.tone])}><Icon className="size-[18px]" /></span>
                                <span className="min-w-0">
                                    <b className="block truncate font-semibold">{d.title}</b>
                                    <small className="block truncate text-[13px] text-muted-foreground">{d.detail}</small>
                                </span>
                                <span className="inline-flex h-8 items-center gap-1.5 rounded-[10px] border border-border bg-card px-3 text-[13px] font-semibold transition-[border-color,color,box-shadow] group-hover:border-brand group-hover:text-brand-ink group-hover:shadow-[0_6px_14px_-10px_var(--brand)]">
                                    <Icon className="size-3.5" />{d.action}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}

function ExamsWithPapers({ exams }: { exams: ExamWithPapers[] }) {
    if (exams.length === 0) {
        return (
            <Panel title="Exams" href="/exams" linkText="All exams">
                <p className="text-sm text-muted-foreground">No exam is being set up or running. Start with New exam category, then add its objective, theory, practical or viva exams.</p>
            </Panel>
        );
    }

    return (
        <div className="grid gap-5">
            {exams.map((g) => (
                <Panel key={g.id} title={g.title} href={`/exam-groups/${g.id}`}>
                    <p className="-mt-2 text-sm text-muted-foreground">{g.papers.length} {g.papers.length === 1 ? 'exam' : 'exams'} · {g.students} students</p>
                    <ul className="divide-y divide-dashed divide-border">
                        {g.papers.map((p) => {
                            const L = paperLook[p.type] ?? paperLook.cbt;
                            const total = Math.max(1, p.candidates);
                            const live = p.writing > 0;

                            return (
                                <li key={p.id}>
                                    <Link href={`/exams/${p.id}`} className="grid grid-cols-[36px_1fr_auto] items-center gap-3 py-2.5">
                                        <span className="grid size-9 place-items-center rounded-[11px] bg-brand-soft text-brand-ink"><L.icon className="size-[17px]" /></span>
                                        <span className="min-w-0">
                                            <b className="block font-semibold">{p.label}</b>
                                            <small className="block text-[12.5px] text-muted-foreground">
                                                {live ? `${p.writing} writing now` : p.starts_at ? formatDateTime(p.starts_at) : 'No date yet'}
                                            </small>
                                            <span className="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-foreground/[.07]">
                                                <i className="bg-status-info" style={{ width: `${(p.submitted / total) * 100}%` }} />
                                                <i className="shimmer bg-[var(--live)]" style={{ width: `${(p.writing / total) * 100}%` }} />
                                            </span>
                                        </span>
                                        <Status value={live ? 'writing' : p.status} />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </Panel>
            ))}
        </div>
    );
}

/** A student's next exam, counting down, with the button to go in when it opens. */
function NextExam({ exam }: { exam: { id: number; title: string; starts_at: string } }) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(t);
    }, []);

    const left = new Date(exam.starts_at).getTime() - now;
    const d = Math.floor(left / 86400000);
    const h = Math.floor((left % 86400000) / 3600000);
    const m = Math.floor((left % 3600000) / 60000);
    const open = left <= 15 * 60000;

    return (
        <section className="deep-board flex flex-wrap items-center gap-6 p-6 md:p-8">
            <span className="grid size-14 place-items-center rounded-2xl bg-white/10"><GraduationCap className="size-7" /></span>
            <div className="min-w-0 flex-1">
                <p className="text-sm text-[var(--side)]">{left <= 0 ? 'Open now' : 'Your next exam'}</p>
                <h2 className="text-2xl font-semibold md:text-3xl">{exam.title}</h2>
                <p className="mt-1 flex items-center gap-2 text-sm text-[var(--side)]"><Clock className="size-4" /> {formatDateTime(exam.starts_at)}</p>
            </div>
            <div className="text-right">
                {left > 0 && (
                    <p className="font-display text-3xl font-semibold tabular-nums">{d > 0 ? `${d}d ` : ''}{h}h {String(m).padStart(2, '0')}m</p>
                )}
                <Link href={`/my/exams/${exam.id}`} className={cn('mt-2 inline-flex h-11 items-center gap-2 rounded-xl px-5 font-semibold transition-transform hover:-translate-y-px', open ? 'bg-[var(--live)] text-white shadow-[0_10px_24px_-10px_var(--live)]' : 'bg-white/12 text-white')}>
                    {open ? 'Go to the exam' : 'See details'} <ArrowRight className="size-4" />
                </Link>
            </div>
        </section>
    );
}

const KPIS: { key: string; label: string; tone?: (v: unknown) => string | undefined; render?: (v: unknown) => ReactNode }[] = [
    { key: 'courses', label: 'Courses this semester' },
    { key: 'staff', label: 'Active staff' },
    { key: 'pending_registrations', label: 'Registrations to approve' },
    { key: 'failed_logins_24h', label: 'Failed sign-ins (24 h)', tone: (v) => (Number(v) > 20 ? 'text-status-critical' : undefined) },
    { key: 'last_backup', label: 'Last backup', tone: (v) => (v ? undefined : 'text-status-critical'), render: (v) => (v ? formatDateTime(String(v)) : 'never') },
];

const PANEL_NAMES: Record<string, string> = {
    day: 'Exam day', glance: 'At a glance', decisions: 'Needs your decision', exams: 'Exam categories', kpis: 'More figures', upcoming: 'Examinations this week',
    oversight: 'Faculty or department', teaching: 'Your teaching', casework: 'Cases waiting', student_due: 'Due soon', student_exams: 'Your examinations', modules: "Institution's modules",
};
const WIDE = ['day', 'glance', 'kpis', 'modules'];

export default function Dashboard(props: { moduleCards: ModuleCard[]; panels: Panels; semester: string | null; layout?: Layout }) {
    const theme = usePage().props.tenant?.theme;

    if (theme === 'midnight') {
        return (
            <>
                <Head title="Dashboard" />
                <MidnightDashboard {...props} layout={props.layout ?? { hidden: [], order: [] }} />
            </>
        );
    }

    return <StandardDashboard {...props} />;
}

function StandardDashboard({ moduleCards, panels, semester, layout: saved = { hidden: [], order: [] } }: { moduleCards: ModuleCard[]; panels: Panels; semester: string | null; layout?: Layout }) {
    const { tenant, auth, platform } = usePage().props;
    const { can, hasModule } = usePlatform();
    const i = panels.institution;
    // which of the "More figures" are shown is kept with the dashboard's own arrangement
    const kpiPrefs = useArrangement('dashboard-figures', KPIS.map((x) => x.key));
    const shownKpi = (k: string) => !kpiPrefs.state.hidden.includes(k) && !saved.hidden.includes(`kpi:${k}`);
    const toggleKpi = (k: string) => kpiPrefs.save({ ...kpiPrefs.state, hidden: shownKpi(k) ? [...kpiPrefs.state.hidden, k] : kpiPrefs.state.hidden.filter((x) => x !== k) });
    const hasPanel = (k: string) => (k === 'modules' ? moduleCards.some((m) => m.enabled) : k === 'kpis' ? !!i : k === 'student_due' ? panels.student?.due != null : k === 'student_exams' ? panels.student?.exams != null : k === 'upcoming' ? !!panels.upcoming && !panels.day : !!panels[k as keyof Panels]);
    const available = ['day', 'glance', 'decisions', 'exams', 'student_exams', 'student_due', 'teaching', 'oversight', 'casework', 'upcoming', 'kpis', 'modules'].filter(hasPanel);
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const today = new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
    const nextExam = panels.student?.exams?.[0];
    const g = panels.glance;

    const renderPanel = (k: string): ReactNode => {
        switch (k) {
            case 'day':
                return panels.day && <ExamDayBoard day={panels.day} />;
            case 'glance':
                return g && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <GlanceCard label="Active students" icon={GraduationCap} value={g.students.toLocaleString()} note={g.students_week > 0 ? `+${g.students_week} this week` : 'No change this week'} noteTone={g.students_week > 0 ? undefined : 'text-muted-foreground'} href="/admin/people">
                            <Spark id="students" values={g.students_trend} color="var(--brand)" />
                        </GlanceCard>
                        {(g.pass_rate !== null || g.pass_trend.length > 0) && <GlanceCard label="Pass rate, last 5 exams" icon={TrendingUp} value={g.pass_rate === null ? '—' : `${g.pass_rate}%`} note={g.pass_trend.length > 1 ? `${g.pass_trend.at(-1)! - g.pass_trend[0] >= 0 ? '+' : ''}${g.pass_trend.at(-1)! - g.pass_trend[0]} points` : 'Published results only'} noteTone={g.pass_trend.length > 1 && g.pass_trend.at(-1)! < g.pass_trend[0] ? 'text-status-critical' : g.pass_trend.length > 1 ? undefined : 'text-muted-foreground'} href="/admin/reports">
                            <Spark id="pass" values={g.pass_trend} color="var(--live)" />
                        </GlanceCard>}
                        {g.results_to_approve !== null && <GlanceCard label="Results to approve" icon={CheckCheck} value={g.results_to_approve} note={g.results_ready ? `${g.results_ready} approved, ready to publish` : 'None ready to publish'} noteTone={g.results_ready ? 'text-status-warning' : 'text-muted-foreground'} href="/marking" />}
                        {g.open_incidents !== null && <GlanceCard label="Open incidents" icon={ShieldAlert} value={g.open_incidents} note={g.urgent_incidents ? `${g.urgent_incidents} serious` : 'None serious'} noteTone={g.urgent_incidents ? 'text-status-critical' : 'text-muted-foreground'} href="/incidents" />}
                    </div>
                );
            case 'decisions':
                return panels.decisions && <Decisions items={panels.decisions} />;
            case 'exams':
                return panels.exams && <ExamsWithPapers exams={panels.exams} />;
            case 'kpis':
                return i && (
                    <Panel title="More figures">
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-5">
                            {KPIS.filter((x) => x.key in i && i[x.key] !== null && shownKpi(x.key)).map((x) => (
                                <div key={x.key}>
                                    <dt className="text-[13px] text-muted-foreground">{x.label}</dt>
                                    <dd className={cn('font-display text-xl font-semibold tabular-nums', x.tone?.(i[x.key]))}>{x.render ? x.render(i[x.key]) : String(i[x.key])}</dd>
                                </div>
                            ))}
                        </dl>
                        {(i.access_review_due || Number(i.pending_exports) > 0) && (
                            <div className="flex flex-wrap gap-2 text-sm">
                                {i.access_review_due && <Link href="/admin/access-review" className="flex items-center gap-2 rounded-xl bg-status-warning/12 px-3 py-1.5 text-status-warning"><AlertTriangle className="size-4" /> Access review is due</Link>}
                                {Number(i.pending_exports) > 0 && <Link href="/admin/reports" className="flex items-center gap-2 rounded-xl bg-foreground/[.05] px-3 py-1.5">{String(i.pending_exports)} export request(s) waiting</Link>}
                            </div>
                        )}
                    </Panel>
                );
            case 'upcoming':
                return panels.upcoming && (
                    <Panel title="Examinations this week" href="/exam-calendar" linkText="Timetable">
                        {panels.upcoming.length === 0 && <p className="text-sm text-muted-foreground">Nothing scheduled.</p>}
                        {panels.upcoming.map((s) => (
                            <Link key={s.id} href={`/sessions/${s.id}`} className="-mx-2 flex items-center justify-between rounded-xl px-2 py-2 text-sm hover:bg-card/80">
                                <span>{s.title}<span className="block text-xs text-muted-foreground">{formatDateTime(s.starts_at)} · {s.venue ?? 'online'}</span></span>
                                <span className="text-muted-foreground tabular-nums">{s.candidates}</span>
                            </Link>
                        ))}
                    </Panel>
                );
            case 'oversight':
                return panels.oversight && (
                    <Panel title={`Your ${panels.oversight.scope}`} href="/admin/reports" linkText="Reports">
                        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            {[['Courses', panels.oversight.courses], ['Pass rate', panels.oversight.pass_rate === null ? '—' : `${panels.oversight.pass_rate}%`], ['Course reviews waiting', panels.oversight.reviews_waiting], ['Results to approve', panels.oversight.results_to_approve], ['Marking backlog', panels.oversight.marking_backlog]].map(([l, v]) => (
                                <div key={String(l)}><dt className="text-[13px] text-muted-foreground">{l}</dt><dd className="font-display text-2xl font-semibold tabular-nums">{v}</dd></div>
                            ))}
                        </dl>
                        {panels.oversight.courses_needing_attention.length > 0 && (
                            <div className="text-sm"><p className="text-[13px] text-muted-foreground">Courses with little or no material</p>
                                {panels.oversight.courses_needing_attention.map((c) => <Link key={c.id} href={`/learn/courses/${c.id}`} className="block py-0.5 hover:text-brand">{c.code} · {c.title}</Link>)}</div>
                        )}
                    </Panel>
                );
            case 'teaching':
                return panels.teaching && (
                    <Panel title="Your teaching" href="/learn/courses" linkText="Courses">
                        <dl className="grid grid-cols-2 gap-4">
                            <div><dt className="text-[13px] text-muted-foreground">Courses</dt><dd className="font-display text-2xl font-semibold">{panels.teaching.courses}</dd></div>
                            <div><dt className="text-[13px] text-muted-foreground">Students</dt><dd className="font-display text-2xl font-semibold">{panels.teaching.students}</dd></div>
                            <div><dt className="text-[13px] text-muted-foreground">Waiting to be marked</dt><dd className={cn('font-display text-2xl font-semibold', panels.teaching.to_mark && 'text-status-warning')}>{panels.teaching.to_mark}</dd></div>
                            <div><dt className="text-[13px] text-muted-foreground">Unanswered questions</dt><dd className="font-display text-2xl font-semibold">{panels.teaching.unanswered_threads}</dd></div>
                        </dl>
                    </Panel>
                );
            case 'casework':
                return panels.casework && (
                    <Panel title="Cases waiting" href="/admin/exceptions">
                        <dl className="grid grid-cols-3 gap-4 text-sm">
                            {panels.casework.requests !== undefined && <Link href="/admin/exceptions" className="hover:text-brand"><dt className="text-[13px] text-muted-foreground">Exception requests</dt><dd className="font-display text-2xl font-semibold">{panels.casework.requests}</dd></Link>}
                            {panels.casework.hardware !== undefined && <Link href="/exams" className="hover:text-brand"><dt className="text-[13px] text-muted-foreground">Hardware exceptions</dt><dd className="font-display text-2xl font-semibold">{panels.casework.hardware}</dd></Link>}
                            {panels.casework.anomalies !== undefined && <Link href="/exams" className="hover:text-brand"><dt className="text-[13px] text-muted-foreground">Unusual attempts</dt><dd className="font-display text-2xl font-semibold">{panels.casework.anomalies}</dd></Link>}
                        </dl>
                    </Panel>
                );
            case 'student_exams':
                return panels.student && (
                    <Panel title="Your examinations" href="/my/exams" linkText="All">
                        {panels.student.exams?.length === 0 && <p className="text-sm text-muted-foreground">None scheduled.</p>}
                        {panels.student.exams?.map((e) => (
                            <Link key={e.id} href={`/my/exams/${e.id}`} className="-mx-2 flex items-center justify-between gap-3 rounded-xl px-2 py-2 text-sm hover:bg-card/80">
                                <span>{e.title}<span className="block text-xs text-muted-foreground">{formatDateTime(e.starts_at)}</span></span>
                                <ArrowRight className="size-4 text-muted-foreground" />
                            </Link>
                        ))}
                        {panels.student.unread > 0 && <Link href="/notifications" className="text-sm font-medium text-brand">{panels.student.unread} unread notification(s)</Link>}
                    </Panel>
                );
            case 'student_due':
                return panels.student && (
                    <Panel title="Due in the next two weeks" href="/learn/courses" linkText="Courses">
                        {panels.student.due?.length === 0 && <p className="text-sm text-muted-foreground">Nothing due.</p>}
                        {panels.student.due?.map((d) => <Link key={d.id} href={`/learn/items/${d.id}`} className="-mx-2 block rounded-xl px-2 py-2 text-sm hover:bg-card/80">{d.title}<span className="block text-xs text-muted-foreground">{d.code} · {d.type} · {formatDateTime(d.due_at)}</span></Link>)}
                    </Panel>
                );
            case 'modules':
                return (
                    <Panel title="Your subscription">
                        <ul className="grid gap-x-6 sm:grid-cols-2">
                            {moduleCards.filter((m) => m.enabled).map((m) => (
                                <li key={m.key}>
                                    <Link href={moduleLinks[m.key] ?? '/dashboard'} className="-mx-2 block rounded-xl px-2 py-2 hover:bg-card/80">
                                        <span className="block text-sm font-semibold">{m.name}</span>
                                        <span className="line-clamp-1 text-xs text-muted-foreground">{m.description}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                );
            default:
                return null;
        }
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 px-4 pt-6 pb-10 md:px-8 md:pt-8">
                <section className="flex flex-wrap items-end justify-between gap-4">
                    <div className="max-w-3xl">
                        <p className="text-[15px] text-muted-foreground">{greeting}, {firstName(auth.user.name)}</p>
                        <h1 className="mt-0.5 text-[2.1rem] leading-tight font-semibold md:text-[2.4rem]">{today}</h1>
                        <p className="mt-1 text-[15px] text-muted-foreground">
                            {[tenant?.name ?? platform?.name, semester].filter(Boolean).join(' · ')}
                            {panels.day && panels.day.sittings.length > 0 && ` · ${panels.day.sittings.length} ${panels.day.sittings.length === 1 ? 'sitting' : 'sittings'} today`}
                        </p>
                        {tenant?.welcome_message && <p className="mt-3 max-w-2xl whitespace-pre-line text-muted-foreground">{tenant.welcome_message}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {panels.day && <Button variant="outline" asChild><Link href="/proctor"><Radio className="size-4" /> Live monitoring</Link></Button>}
                        {can('exams.manage') && hasModule('question_bank') && <Button asChild><Link href="/exams/new"><Plus className="size-4" /> New exam</Link></Button>}
                    </div>
                </section>

                {nextExam && <NextExam exam={nextExam} />}

                <Arrangeable page="dashboard" label="dashboard" className="grid gap-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]"
                    cards={available.map((k) => ({ key: k, title: PANEL_NAMES[k], wide: WIDE.includes(k), node: renderPanel(k) }))}
                    toolbar={i && (
                        <span className="flex flex-wrap items-center gap-1.5">
                            <span className="text-xs text-muted-foreground">More figures:</span>
                            {KPIS.filter((x) => x.key in i && i[x.key] !== null).map((x) => (
                                <button key={x.key} type="button" aria-pressed={shownKpi(x.key)} onClick={() => toggleKpi(x.key)} className={cn('rounded-full border px-2.5 py-1 text-xs', shownKpi(x.key) ? 'border-brand bg-brand-soft text-brand-ink' : 'text-muted-foreground')}>{x.label}</button>
                            ))}
                        </span>
                    )} />
            </div>
        </>
    );
}
