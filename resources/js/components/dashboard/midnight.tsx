import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight, BarChart3, Bell, BookOpen, CalendarDays, CalendarRange, Check, CheckCheck, ClipboardCheck, Clock, Eye, FileUp, GraduationCap, Keyboard,
    LifeBuoy, MessagesSquare, Radio, Scale, ScrollText, Search, Send, ShieldAlert, Timer, Trophy, TrendingUp, UsersRound, Video,
    Volume2, VolumeX, X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Arrangeable } from '@/components/arrangeable';
import type { ArrangeCard } from '@/components/arrangeable';
import { ExamDayBoard } from '@/components/dashboard/exam-day';
import type { Decision, ExamWithPapers, Layout, ModuleCard, Panels } from '@/components/dashboard/types';
import { formatDateTime, Status } from '@/components/platform/ui';
import { usePlatform } from '@/hooks/use-platform';
import { paperLook } from '@/lib/papers';
import { play, setSound, soundOn } from '@/lib/ui-sound';
import { cn, firstName } from '@/lib/utils';

/*
 * Midnight command deck: the dashboard an institution gets with the Midnight theme.
 * Same data, permissions and module switches as the standard dashboard; laid out as a hero with
 * a live clock, a dock of the person's most-used actions (keys 1–8), animated figures, and a
 * single agenda. Everything is a link, and every press answers with a quiet sound.
 */

const reduced = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Counts up to a figure once, on first show. */
function useCountUp(target: number, ms = 750): number {
    const [v, setV] = useState(() => (reduced() ? target : 0));

    useEffect(() => {
        if (reduced()) {
            return;
        }

        let raf = 0;
        const t0 = performance.now();
        const step = (t: number) => {
            const k = Math.min(1, (t - t0) / ms);
            setV(Math.round(target * (1 - Math.pow(1 - k, 3))));

            if (k < 1) {
                raf = requestAnimationFrame(step);
            }
        };
        raf = requestAnimationFrame(step);

        return () => cancelAnimationFrame(raf);
    }, [target, ms]);

    return reduced() ? target : v;
}

function Count({ value, suffix = '' }: { value: number; suffix?: string }) {
    return <>{useCountUp(value).toLocaleString()}{suffix}</>;
}

function useNow(every = 1000): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const t = window.setInterval(() => setNow(new Date()), every);

        return () => window.clearInterval(t);
    }, [every]);

    return now;
}

function Spark({ values, id }: { values: number[]; id: string }) {
    if (values.length < 2) {
        return <div className="h-10" />;
    }

    const max = Math.max(...values);
    const min = Math.min(...values);
    const pts = values.map((v, i) => `${(i / (values.length - 1)) * 120},${32 - ((v - min) / Math.max(1, max - min)) * 28}`).join(' ');

    return (
        <svg viewBox="0 0 120 36" preserveAspectRatio="none" className="mt-3 h-10 w-full overflow-visible" aria-hidden>
            <defs><linearGradient id={`mn-${id}`} x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor="var(--brand)" stopOpacity=".35" /><stop offset="1" stopColor="var(--brand)" stopOpacity="0" /></linearGradient></defs>
            <polygon points={`0,36 ${pts} 120,36`} fill={`url(#mn-${id})`} />
            <polyline points={pts} fill="none" stroke="var(--brand)" strokeWidth="2.25" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke"
                style={{ strokeDasharray: 400, ['--len' as string]: 400, animation: reduced() ? undefined : 'mn-draw 1s ease-out both' }} />
        </svg>
    );
}

/** A ring gauge for a percentage. */
function Ring({ value, size = 88, label }: { value: number | null; size?: number; label: string }) {
    const r = size / 2 - 7;
    const c = 2 * Math.PI * r;
    const [shown, setShown] = useState(reduced() ? value ?? 0 : 0);

    useEffect(() => {
        const t = window.setTimeout(() => setShown(value ?? 0), 60);

        return () => window.clearTimeout(t);
    }, [value]);

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={label} className="shrink-0 -rotate-90">
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="currentColor" strokeOpacity=".1" strokeWidth="7" />
            <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--brand)" strokeWidth="7" strokeLinecap="round"
                strokeDasharray={c} strokeDashoffset={c * (1 - Math.min(100, Math.max(0, shown)) / 100)}
                style={{ transition: 'stroke-dashoffset 1s cubic-bezier(.2,.8,.2,1)', filter: 'drop-shadow(0 0 6px rgb(255 106 19 / .5))' }} />
        </svg>
    );
}

function Section({ title, href, linkText = 'Open', count, children, className }: { title: string; href?: string; linkText?: string; count?: number; children: ReactNode; className?: string }) {
    return (
        <section className={cn('mn-tile grid content-start gap-4 p-5 md:p-6', className)}>
            <header className="flex items-center gap-2.5">
                <h2 className="text-[1.05rem] font-semibold">{title}</h2>
                {count !== undefined && count > 0 && <span className="rounded-full bg-brand px-2 text-xs leading-5 font-bold text-brand-foreground tabular-nums">{count}</span>}
                {href && (
                    <Link href={href} prefetch className="group ml-auto inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-brand-ink hover:bg-brand-soft">
                        {linkText} <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                    </Link>
                )}
            </header>
            {children}
        </section>
    );
}

type Action = { title: string; hint: string; href: string; icon: LucideIcon; badge?: string | null };

function useActions(panels: Panels): Action[] {
    const { can, canAny, hasModule, userType } = usePlatform();
    const exams = hasModule('question_bank');
    const lms = hasModule('lms');
    const meetings = hasModule('conferencing');
    const live = panels.day?.writing ?? 0;
    const list: (Action | false)[] = userType === 'student'
        ? [
            exams && { title: 'My exams', hint: panels.student?.exams?.length ? `${panels.student.exams.length} coming up` : 'Timetable and rooms', href: '/my/exams', icon: GraduationCap },
            lms && { title: 'My courses', hint: panels.student ? `${panels.student.courses} this semester` : 'Materials and lessons', href: '/learn/courses', icon: BookOpen },
            meetings && { title: 'Live classes', hint: 'Join a class', href: '/meetings', icon: Video },
            exams && hasModule('my_results') && { title: 'My results', hint: 'Scores and statements', href: '/my/results', icon: Trophy },
            lms && { title: 'Calendar', hint: 'What is due', href: '/learn/calendar', icon: CalendarRange },
            lms && { title: 'Messages', hint: 'Lecturers and classmates', href: '/messages', icon: MessagesSquare },
            { title: 'Notifications', hint: panels.student?.unread ? `${panels.student.unread} unread` : 'All caught up', href: '/notifications', icon: Bell, badge: panels.student?.unread ? String(panels.student.unread) : null },
            { title: 'Help', hint: 'How things work', href: '/docs', icon: LifeBuoy },
        ]
        : [
            exams && can('exams.manage') && { title: 'New exam', hint: 'Set up a paper', href: '/exams/new', icon: ScrollText },
            exams && can('questions.create') && { title: 'Upload questions', hint: 'Word, PDF or Excel', href: '/question-bank/import', icon: FileUp },
            exams && hasModule('browser_proctoring') && canAny('exams.proctor', 'exams.invigilate', 'exams.manage') && { title: 'Live monitoring', hint: live > 0 ? `${live} writing now` : 'Watch sittings', href: '/proctor', icon: Radio, badge: live > 0 ? 'live' : null },
            exams && can('results.mark') && { title: 'Marking', hint: 'Scripts to mark', href: '/marking', icon: ClipboardCheck },
            lms && canAny('lms.teach', 'academics.manage', 'results.approve') && { title: 'Courses', hint: panels.teaching ? `${panels.teaching.courses} you teach` : 'Teaching', href: '/learn/courses', icon: BookOpen },
            meetings && canAny('meetings.host', 'lms.teach') && { title: 'Live classes', hint: 'Start or schedule', href: '/meetings', icon: Video },
            exams && can('exams.manage') && { title: 'Timetable', hint: 'Exam calendar', href: '/exam-calendar', icon: CalendarDays },
            can('users.manage') && { title: 'People', hint: 'Students and staff', href: '/admin/people', icon: UsersRound },
            canAny('audit.view', 'results.approve', 'exams.manage', 'academics.manage') && { title: 'Reports', hint: 'Figures and exports', href: '/admin/reports', icon: BarChart3 },
            { title: 'Notifications', hint: 'Recent updates', href: '/notifications', icon: Bell },
            { title: 'Help', hint: 'Documentation', href: '/docs', icon: LifeBuoy },
        ];

    return (list.filter(Boolean) as Action[]).slice(0, 8);
}

function Dock({ actions }: { actions: Action[] }) {
    return (
        <nav aria-label="Quick actions" className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {actions.map((a, n) => (
                <Link key={a.href} href={a.href} prefetch className="mn-tile group flex items-center gap-3 p-3.5 sm:p-4">
                    <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-brand-soft text-brand transition-[background,color,transform] duration-200 group-hover:scale-105 group-hover:bg-brand group-hover:text-brand-foreground">
                        <a.icon className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <b className="flex items-center gap-1.5 truncate text-[0.95rem] font-semibold">
                            {a.title}
                            {a.badge && <span className={cn('rounded-full px-1.5 text-[11px] leading-4 font-bold', a.badge === 'live' ? 'mn-pulse bg-brand text-brand-foreground' : 'bg-foreground text-background')}>{a.badge}</span>}
                        </b>
                        <small className="block truncate text-[12.5px] text-muted-foreground">{a.hint}</small>
                    </span>
                    <kbd className="mn-key hidden md:inline-grid" aria-hidden>{n + 1}</kbd>
                </Link>
            ))}
        </nav>
    );
}

const decisionIcons: Record<string, LucideIcon> = { eye: Eye, check: CheckCheck, send: Send, timer: Timer, scale: Scale };
const toneDot = { critical: 'bg-status-critical', warning: 'bg-status-warning', info: 'bg-status-info' };

function Decisions({ items }: { items: Decision[] }) {
    const [tab, setTab] = useState<'all' | Decision['kind']>('all');
    const tabs = ([['all', 'All'], ['exam_day', 'Exam day'], ['approvals', 'Approvals'], ['requests', 'Requests']] as const)
        .filter(([k]) => k === 'all' || items.some((d) => d.kind === k));
    const list = items.filter((d) => tab === 'all' || d.kind === tab);

    return (
        <Section title="Needs your decision" count={items.length}>
            {tabs.length > 2 && (
                <div className="flex flex-wrap gap-1.5" role="tablist">
                    {tabs.map(([k, label]) => (
                        <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
                            className={cn('rounded-full px-3 py-1 text-[13px] font-semibold transition-colors', tab === k ? 'bg-brand text-brand-foreground' : 'bg-foreground/[.05] text-muted-foreground hover:text-foreground')}>
                            {label}
                        </button>
                    ))}
                </div>
            )}
            {list.length === 0 ? (
                <p className="flex items-center gap-2 py-4 text-sm text-muted-foreground"><Check className="size-4 text-status-good" /> Nothing is waiting for you.</p>
            ) : (
                <ul className="-mx-2 grid gap-1">
                    {list.map((d, n) => {
                        const Icon = decisionIcons[d.icon] ?? Eye;

                        return (
                            <li key={n}>
                                <Link href={d.href} className="group grid grid-cols-[auto_1fr_auto] items-center gap-3 rounded-2xl p-2.5 transition-colors hover:bg-foreground/[.04]">
                                    <span className="relative grid size-10 place-items-center rounded-xl bg-foreground/[.05]">
                                        <Icon className="size-[18px]" />
                                        <i className={cn('absolute -top-0.5 -right-0.5 size-2.5 rounded-full ring-2 ring-[var(--card)]', toneDot[d.tone])} />
                                    </span>
                                    <span className="min-w-0">
                                        <b className="block truncate font-semibold">{d.title}</b>
                                        <small className="block truncate text-[13px] text-muted-foreground">{d.detail}</small>
                                    </span>
                                    <span className="inline-flex h-8 items-center gap-1 rounded-lg border border-border px-3 text-[13px] font-semibold transition-colors group-hover:border-brand group-hover:bg-brand group-hover:text-brand-foreground">
                                        {d.action}<ArrowRight className="size-3.5" />
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Section>
    );
}

function Exams({ exams }: { exams: ExamWithPapers[] }) {
    if (exams.length === 0) {
        return (
            <Section title="Exams" href="/exams" linkText="All exams">
                <p className="text-sm text-muted-foreground">Nothing is being set up or running. Start with New exam.</p>
            </Section>
        );
    }

    return (
        <>
            {exams.map((g) => (
                <Section key={g.id} title={g.title} href={`/exam-groups/${g.id}`}>
                    <p className="-mt-2 text-sm text-muted-foreground">{g.papers.length} {g.papers.length === 1 ? 'paper' : 'papers'}, {g.students} students</p>
                    <ul className="grid gap-1">
                        {g.papers.map((p) => {
                            const L = paperLook[p.type] ?? paperLook.cbt;
                            const total = Math.max(1, p.candidates);
                            const live = p.writing > 0;

                            return (
                                <li key={p.id}>
                                    <Link href={`/exams/${p.id}`} className="-mx-2 grid grid-cols-[36px_1fr_auto] items-center gap-3 rounded-xl p-2 hover:bg-foreground/[.04]">
                                        <span className={cn('grid size-9 place-items-center rounded-[11px]', live ? 'mn-pulse bg-brand text-brand-foreground' : 'bg-foreground/[.06] text-brand-ink')}><L.icon className="size-[17px]" /></span>
                                        <span className="min-w-0">
                                            <b className="block truncate font-semibold">{p.label}</b>
                                            <small className="block text-[12.5px] text-muted-foreground">{live ? `${p.writing} writing now` : p.starts_at ? formatDateTime(p.starts_at) : 'No date yet'}</small>
                                            <span className="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-foreground/[.07]">
                                                <i className="bg-status-info transition-[width] duration-700" style={{ width: `${(p.submitted / total) * 100}%` }} />
                                                <i className="bg-brand transition-[width] duration-700" style={{ width: `${(p.writing / total) * 100}%` }} />
                                            </span>
                                        </span>
                                        <Status value={live ? 'writing' : p.status} />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </Section>
            ))}
        </>
    );
}

/** The student's next exam with a ring that fills as it approaches (the last 24 hours). */
function NextExam({ exam }: { exam: { id: number; title: string; starts_at: string } }) {
    const now = useNow();
    const left = new Date(exam.starts_at).getTime() - now.getTime();
    const d = Math.floor(left / 86400000);
    const h = Math.floor((left % 86400000) / 3600000);
    const m = Math.floor((left % 3600000) / 60000);
    const s = Math.floor((left % 60000) / 1000);
    const open = left <= 15 * 60000;
    const pct = left <= 0 ? 100 : Math.max(0, 100 - (left / 86400000) * 100);

    return (
        <section className="mn-hero flex flex-wrap items-center gap-5 p-5 md:p-7">
            <div className="relative grid place-items-center">
                <Ring value={pct} size={96} label="Time until your exam" />
                <GraduationCap className="absolute size-7 text-brand" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm text-muted-foreground">{left <= 0 ? 'Open now' : 'Your next exam'}</p>
                <h2 className="text-2xl font-semibold md:text-3xl">{exam.title}</h2>
                <p className="mt-1 flex items-center gap-2 text-sm text-muted-foreground"><Clock className="size-4" /> {formatDateTime(exam.starts_at)}</p>
            </div>
            <div className="grid justify-items-end gap-2">
                {left > 0 && (
                    <p className="font-display text-3xl font-semibold tabular-nums md:text-4xl" aria-live="off">
                        {d > 0 && <>{d}<span className="text-base text-muted-foreground">d </span></>}{h}<span className="text-base text-muted-foreground">h </span>{String(m).padStart(2, '0')}<span className="text-base text-muted-foreground">m </span>
                        {d === 0 && <>{String(s).padStart(2, '0')}<span className="text-base text-muted-foreground">s</span></>}
                    </p>
                )}
                <Link href={`/my/exams/${exam.id}`} className={cn('inline-flex h-12 items-center gap-2 rounded-2xl px-6 font-semibold transition-transform hover:-translate-y-px active:scale-95', open ? 'mn-pulse bg-brand text-brand-foreground' : 'bg-foreground/[.08] hover:bg-foreground/[.12]')}>
                    {open ? 'Go to the exam' : 'See details'} <ArrowRight className="size-4" />
                </Link>
            </div>
        </section>
    );
}

function Pulse({ label, icon: Icon, href, children, note, noteTone }: { label: string; icon: LucideIcon; href: string; children: ReactNode; note?: ReactNode; noteTone?: string }) {
    return (
        <Link href={href} prefetch className="mn-tile group block p-5">
            <span className="flex items-center justify-between text-sm text-muted-foreground">
                {label}
                <span className="grid size-8 place-items-center rounded-xl bg-foreground/[.05] transition-colors group-hover:bg-brand group-hover:text-brand-foreground"><Icon className="size-4" /></span>
            </span>
            {children}
            {note && <span className={cn('mt-1 block text-[13px] font-semibold', noteTone ?? 'text-muted-foreground')}>{note}</span>}
        </Link>
    );
}

const Big = ({ children }: { children: ReactNode }) => <span className="mt-2 block font-display text-[2.4rem] leading-none font-semibold tracking-tight tabular-nums">{children}</span>;

type Agenda = { key: string; when: string; title: string; detail: string; href: string; kind: string };


export default function MidnightDashboard({ moduleCards, panels, semester }: { moduleCards: ModuleCard[]; panels: Panels; semester: string | null; layout?: Layout }) {
    const { tenant, auth } = usePage().props;
    const { hasModule } = usePlatform();
    const now = useNow();
    const [sound, setSoundState] = useState(soundOn);
    const [keys, setKeys] = useState(false);
    const actions = useActions(panels);
    const actionsRef = useRef(actions);

    useEffect(() => {
        actionsRef.current = actions;
    }, [actions]);
    const g = panels.glance;
    const i = panels.institution;
    const hour = now.getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const nextExam = panels.student?.exams?.[0];

    // keys 1–8 open the dock's actions; / searches; ? lists the keys
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const t = e.target as HTMLElement;

            if (e.ctrlKey || e.metaKey || e.altKey || t.closest('input, textarea, select, [contenteditable=true]')) {
                return;
            }

            if (/^[1-8]$/.test(e.key) && actionsRef.current[Number(e.key) - 1]) {
                play('open');
                router.visit(actionsRef.current[Number(e.key) - 1].href);
            } else if (e.key === '/') {
                e.preventDefault();
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
            } else if (e.key === '?') {
                setKeys((v) => !v);
            } else if (e.key === 'Escape') {
                setKeys(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const flipSound = () => {
        setSound(!sound);
        setSoundState(!sound);

        if (!sound) {
            window.setTimeout(() => play('success'), 10);
        }
    };

    const agenda = useMemo<Agenda[]>(() => {
        const list: Agenda[] = [];
        (panels.upcoming && !panels.day ? panels.upcoming : []).forEach((s) => list.push({ key: `s${s.id}`, when: s.starts_at, title: s.title, detail: `${s.venue ?? 'Online'}, ${s.candidates} candidates`, href: `/sessions/${s.id}`, kind: 'Exam' }));
        (panels.student?.exams ?? []).forEach((e) => list.push({ key: `e${e.id}`, when: e.starts_at, title: e.title, detail: 'Examination', href: `/my/exams/${e.id}`, kind: 'Exam' }));
        (panels.student?.due ?? []).forEach((d) => list.push({ key: `d${d.id}`, when: d.due_at, title: d.title, detail: `${d.code}, ${d.type}`, href: `/learn/items/${d.id}`, kind: 'Due' }));

        return list.sort((a, b) => a.when.localeCompare(b.when)).slice(0, 8);
    }, [panels]);
    const hasAgenda = agenda.length > 0 || (panels.upcoming !== undefined && !panels.day) || panels.student?.exams != null || panels.student?.due != null;

    const pulse: React.ReactElement<{ label: string }>[] = [];

    if (g) {
        pulse.push(
            <Pulse key="students" label="Active students" icon={GraduationCap} href="/admin/people" note={g.students_week > 0 ? `+${g.students_week} this week` : 'No change this week'} noteTone={g.students_week > 0 ? 'text-status-good' : undefined}>
                <Big><Count value={g.students} /></Big>
                <Spark id="students" values={g.students_trend} />
            </Pulse>,
        );

        if (g.pass_rate !== null || g.pass_trend.length > 0) {
            pulse.push(
                <Pulse key="pass" label="Pass rate, last 5 exams" icon={TrendingUp} href="/admin/reports" note="Published results only">
                    <span className="mt-2 flex items-center gap-4">
                        <Ring value={g.pass_rate} size={72} label={`Pass rate ${g.pass_rate ?? 0}%`} />
                        <span className="font-display text-[2.4rem] leading-none font-semibold tabular-nums">{g.pass_rate === null ? '—' : <Count value={g.pass_rate} suffix="%" />}</span>
                    </span>
                </Pulse>,
            );
        }

        if (g.results_to_approve !== null) {
            pulse.push(
                <Pulse key="approve" label="Results to approve" icon={CheckCheck} href="/marking" note={g.results_ready ? `${g.results_ready} ready to publish` : 'None ready to publish'} noteTone={g.results_ready ? 'text-status-warning' : undefined}>
                    <Big><Count value={g.results_to_approve} /></Big>
                </Pulse>,
            );
        }

        if (g.open_incidents !== null) {
            pulse.push(
                <Pulse key="incidents" label="Open incidents" icon={ShieldAlert} href="/incidents" note={g.urgent_incidents ? `${g.urgent_incidents} serious` : 'None serious'} noteTone={g.urgent_incidents ? 'text-status-critical' : undefined}>
                    <Big><Count value={g.open_incidents} /></Big>
                </Pulse>,
            );
        }
    }

    if (panels.teaching) {
        const t = panels.teaching;
        pulse.push(
            <Pulse key="t-students" label="Your students" icon={UsersRound} href="/learn/courses" note={`${t.courses} ${t.courses === 1 ? 'course' : 'courses'}`}><Big><Count value={t.students} /></Big></Pulse>,
            <Pulse key="t-mark" label="Waiting to be marked" icon={ClipboardCheck} href="/learn/courses" note={t.to_mark ? 'Submissions in your courses' : 'All marked'} noteTone={t.to_mark ? 'text-status-warning' : 'text-status-good'}><Big><Count value={t.to_mark} /></Big></Pulse>,
            <Pulse key="t-q" label="Unanswered questions" icon={MessagesSquare} href="/learn/courses" note="From course discussions"><Big><Count value={t.unanswered_threads} /></Big></Pulse>,
        );
    }

    if (panels.student) {
        const st = panels.student;

        if (hasModule('lms')) {
            pulse.push(<Pulse key="s-courses" label="My courses" icon={BookOpen} href="/learn/courses" note="This semester"><Big><Count value={st.courses} /></Big></Pulse>);
        }

        if (st.due != null) {
            pulse.push(<Pulse key="s-due" label="Due in two weeks" icon={CalendarRange} href="/learn/calendar" note={st.due.length ? `Next: ${st.due[0].title}` : 'Nothing due'} noteTone={st.due.length ? 'text-status-warning' : 'text-status-good'}><Big><Count value={st.due.length} /></Big></Pulse>);
        }

        if (st.exams != null) {
            pulse.push(<Pulse key="s-exams" label="Exams coming up" icon={GraduationCap} href="/my/exams" note={st.exams.length ? formatDateTime(st.exams[0].starts_at) : 'None scheduled'}><Big><Count value={st.exams.length} /></Big></Pulse>);
        }

        pulse.push(<Pulse key="s-unread" label="Unread notifications" icon={Bell} href="/notifications" note={st.unread ? 'Tap to read' : 'All caught up'} noteTone={st.unread ? 'text-brand-ink' : 'text-status-good'}><Big><Count value={st.unread} /></Big></Pulse>);
    }

    const main: ArrangeCard[] = [];

    if (panels.decisions) {
        main.push({ key: 'decisions', title: 'Needs your decision', node: <Decisions items={panels.decisions} /> });
    }

    if (panels.exams) {
        main.push({ key: 'exams', title: 'Exams', node: <div className="grid gap-5"><Exams exams={panels.exams} /></div> });
    }

    if (hasAgenda) {
        main.push({ key: 'agenda', title: 'Agenda', node: (
            <Section title="Agenda" href={panels.student ? '/learn/calendar' : '/exam-calendar'} linkText="Calendar">
                {agenda.length === 0 ? <p className="text-sm text-muted-foreground">Nothing scheduled.</p> : (
                    <ol className="relative grid gap-1 before:absolute before:top-2 before:bottom-2 before:left-[19px] before:w-px before:bg-foreground/10">
                        {agenda.map((a) => {
                            const at = new Date(a.when);
                            const soon = at.getTime() - now.getTime() < 86400000;

                            return (
                                <li key={a.key}>
                                    <Link href={a.href} className="relative grid grid-cols-[40px_1fr] items-start gap-3 rounded-xl py-2 pr-2 hover:bg-foreground/[.04]">
                                        <span className="grid justify-items-center pt-0.5">
                                            <i className={cn('z-10 size-2.5 rounded-full ring-4 ring-[var(--card)]', soon ? 'mn-pulse bg-brand' : 'bg-foreground/25')} />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="text-xs font-semibold text-brand-ink">{a.kind}, {at.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })} {at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                                            <b className="block truncate font-semibold">{a.title}</b>
                                            <small className="block truncate text-[12.5px] text-muted-foreground">{a.detail}</small>
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </Section>
        ) });
    }

    if (panels.oversight) {
        const o = panels.oversight;
        main.push({ key: 'oversight', title: `Your ${o.scope}`, node: (
            <Section title={`Your ${o.scope}`} href="/admin/reports" linkText="Reports">
                <dl className="grid grid-cols-2 gap-3">
                    {([['Courses', o.courses], ['Pass rate', o.pass_rate === null ? '—' : `${o.pass_rate}%`], ['Reviews waiting', o.reviews_waiting], ['Results to approve', o.results_to_approve], ['Marking backlog', o.marking_backlog]] as const).map(([l, v]) => (
                        <div key={l} className="rounded-xl bg-foreground/[.03] p-3"><dt className="text-[12.5px] text-muted-foreground">{l}</dt><dd className="font-display text-2xl font-semibold tabular-nums">{v}</dd></div>
                    ))}
                </dl>
                {o.courses_needing_attention.length > 0 && (
                    <div className="text-sm"><p className="text-[12.5px] text-muted-foreground">Courses with little or no material</p>
                        {o.courses_needing_attention.map((c) => <Link key={c.id} href={`/learn/courses/${c.id}`} className="block py-0.5 hover:text-brand-ink">{c.code}, {c.title}</Link>)}</div>
                )}
            </Section>
        ) });
    }

    if (panels.casework) {
        const c = panels.casework;
        main.push({ key: 'casework', title: 'Cases waiting', node: (
            <Section title="Cases waiting" href="/admin/exceptions">
                <div className="grid grid-cols-3 gap-2">
                    {([['Requests', c.requests, '/admin/exceptions'], ['Hardware', c.hardware, '/exams'], ['Unusual attempts', c.anomalies, '/exams']] as const).filter(([, v]) => v !== undefined).map(([l, v, href]) => (
                        <Link key={l} href={href} className="rounded-xl bg-foreground/[.03] p-3 transition-colors hover:bg-foreground/[.06]"><span className="block text-[12.5px] text-muted-foreground">{l}</span><span className="font-display text-2xl font-semibold tabular-nums">{v}</span></Link>
                    ))}
                </div>
            </Section>
        ) });
    }

    if (i) {
        const rows = ([['courses', 'Courses this semester'], ['staff', 'Active staff'], ['pending_registrations', 'Registrations to approve'], ['failed_logins_24h', 'Failed sign-ins (24 h)'], ['last_backup', 'Last backup']] as const)
            .filter(([k]) => k in i && i[k] !== null);
        main.push({ key: 'kpis', title: 'More figures', node: (
            <Section title="More figures">
                <dl className="grid gap-1">
                    {rows.map(([k, l]) => (
                        <div key={k} className="flex items-baseline justify-between gap-3 border-b border-foreground/[.05] py-1.5 last:border-0">
                            <dt className="text-sm text-muted-foreground">{l}</dt>
                            <dd className={cn('font-semibold tabular-nums', k === 'failed_logins_24h' && Number(i[k]) > 20 && 'text-status-critical', k === 'last_backup' && !i[k] && 'text-status-critical')}>
                                {k === 'last_backup' ? (i[k] ? formatDateTime(String(i[k])) : 'never') : String(i[k])}
                            </dd>
                        </div>
                    ))}
                </dl>
            </Section>
        ) });
    }

    if (moduleCards.some((m) => m.enabled)) {
        main.push({ key: 'modules', title: 'Your subscription', node: (
            <Section title="Your subscription">
                <ul className="flex flex-wrap gap-1.5">
                    {moduleCards.filter((m) => m.enabled).map((m) => (
                        <li key={m.key} title={m.description} className="rounded-full border border-border px-2.5 py-1 text-[12.5px] font-medium">{m.name}</li>
                    ))}
                </ul>
            </Section>
        ) });
    }

    return (
        <div className="flex flex-1 flex-col gap-5 px-4 pt-5 pb-12 md:px-8 md:pt-7">
            <div className="mn-enter grid gap-5">
                <section className="mn-hero grid gap-6 p-6 md:grid-cols-[1fr_auto] md:items-end md:p-8">
                    <div className="relative z-10 min-w-0">
                        <p className="text-[15px] text-muted-foreground">{greeting}, <span className="text-foreground">{firstName(auth.user.name)}</span></p>
                        <h1 className="mt-1 text-[2.1rem] leading-[1.05] font-semibold tracking-tight md:text-[3rem]">
                            {now.toLocaleDateString(undefined, { weekday: 'long' })}
                            <span className="block text-brand">{now.toLocaleDateString(undefined, { day: 'numeric', month: 'long' })}</span>
                        </h1>
                        <p className="mt-2 text-[15px] text-muted-foreground">{[tenant?.name, semester].filter(Boolean).join(', ')}</p>
                        {tenant?.welcome_message && <p className="mt-3 max-w-2xl whitespace-pre-line text-muted-foreground">{tenant.welcome_message}</p>}
                    </div>
                    <div className="relative z-10 grid gap-3 md:justify-items-end">
                        <p className="font-display text-5xl leading-none font-semibold tabular-nums md:text-6xl" aria-label="Current time">
                            {now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })}
                            <span className="ml-1 align-top text-lg text-brand">{String(now.getSeconds()).padStart(2, '0')}</span>
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }))}
                                className="inline-flex h-10 items-center gap-2 rounded-xl border border-border bg-foreground/[.04] px-3 text-sm text-muted-foreground transition-colors hover:border-brand hover:text-foreground">
                                <Search className="size-4" /> Search <kbd className="mn-key">/</kbd>
                            </button>
                            <button type="button" onClick={flipSound} aria-pressed={sound} aria-label={sound ? 'Turn click sounds off' : 'Turn click sounds on'} title={sound ? 'Sounds on' : 'Sounds off'}
                                className="grid size-10 place-items-center rounded-xl border border-border bg-foreground/[.04] transition-colors hover:border-brand">
                                {sound ? <Volume2 className="size-4 text-brand" /> : <VolumeX className="size-4 text-muted-foreground" />}
                            </button>
                            <button type="button" onClick={() => setKeys(!keys)} aria-pressed={keys} aria-label="Keyboard shortcuts" className="hidden size-10 place-items-center rounded-xl border border-border bg-foreground/[.04] transition-colors hover:border-brand md:grid">
                                <Keyboard className="size-4" />
                            </button>
                        </div>
                    </div>
                </section>

                {keys && (
                    <section className="mn-tile flex flex-wrap items-center gap-x-6 gap-y-2 p-4 text-sm [animation:mn-rise_.2s_ease-out]" aria-label="Keyboard shortcuts">
                        <span className="flex items-center gap-2"><kbd className="mn-key">1</kbd>–<kbd className="mn-key">8</kbd> open a quick action</span>
                        <span className="flex items-center gap-2"><kbd className="mn-key">/</kbd> search</span>
                        <span className="flex items-center gap-2"><kbd className="mn-key">?</kbd> show or hide this</span>
                        <button type="button" onClick={() => setKeys(false)} aria-label="Close" className="ml-auto rounded-lg p-1 hover:bg-foreground/10"><X className="size-4" /></button>
                    </section>
                )}

                <Arrangeable page="dashboard-midnight" label="dashboard" className="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]"
                    cards={[
                        ...(nextExam ? [{ key: 'next', title: 'Your next exam', wide: true, node: <NextExam exam={nextExam} /> }] : []),
                        ...(actions.length > 0 ? [{ key: 'dock', title: 'Quick actions', wide: true, node: <Dock actions={actions} /> }] : []),
                        ...(pulse.length > 0 ? [{
                            key: 'figures', title: 'Figures', wide: true,
                            node: <Arrangeable page="dashboard-midnight-figures" label="figures" className="grid grid-cols-2 gap-3 md:grid-cols-[repeat(auto-fit,minmax(220px,1fr))]"
                                cards={pulse.map((el) => ({ key: String(el.key), title: el.props.label, node: el }))} />,
                        }] : []),
                        ...(panels.day ? [{ key: 'day', title: 'Exam day', wide: true, node: <ExamDayBoard day={panels.day} /> }] : []),
                        ...main,
                    ]} />
            </div>
        </div>
    );
}
