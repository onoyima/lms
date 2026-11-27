import { Link, usePage } from '@inertiajs/react';
import {
    ScrollText,
    Megaphone,
    SlidersHorizontal,
    TicketPercent,
    Activity,
    BookMarked,
    FileDown,
    BarChart3,
    Bell,
    BookOpen,
    Building2,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    CreditCard,
    FileQuestion,
    FileUp,
    Gauge,
    GraduationCap,
    Inbox,
    KeyRound,
    Landmark,
    Layers,
    LayoutGrid,
    Library,
    LifeBuoy,
    MailCheck,
    MessagesSquare,
    NotebookPen,
    Palette,
    Plug,
    Receipt,
    Scale,
    ScanFace,
    Search,
    ShieldAlert,
    ShieldCheck,
    Tags,
    Trophy,
    UserCheck,
    UsersRound,
    Video,
    Wrench,
    MonitorCheck,
    Network,
    MonitorPlay,
    Radio,
    Settings2,
    Sparkles,
    MessageSquareText,
    Award,
    ShoppingBag,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavGroup, NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePlatform } from '@/hooks/use-platform';
import type { NavItem } from '@/types';

type Group = { label: string; icon?: LucideIcon; badge?: string | null; flat?: boolean; items: NavItem[] };

/**
 * The menu follows what the signed-in person may do. Platform administrators see only the
 * platform console; institution staff and students see the parts of their institution that
 * their roles and the institution's plan allow. (Every page also checks on the server.)
 */
function useMenu(): { home: string; groups: Group[] } {
    const { hasModule, can, canAny, isPlatformAdmin, userType } = usePlatform();
    const page = usePage().props as { auth?: { examBoard?: boolean; sitsExams?: boolean; examinerExam?: { id: number; title: string } | null }; liveWriting?: number };
    const examBoard = Boolean(page.auth?.examBoard) && hasModule('exam_board');
    const sitsExams = Boolean(page.auth?.sitsExams);
    const examinerExam = page.auth?.examinerExam;
    const live = Number(page.liveWriting ?? 0);

    if (userType === 'exam_examiner' && examinerExam) {
        const sheet = `/marking/${examinerExam.id}/panel`;

        return { home: sheet, groups: [{ label: examinerExam.title, flat: true, items: [{ title: 'Scoring sheet', href: sheet, icon: ClipboardCheck }] }] };
    }

    if (isPlatformAdmin) {
        return {
            home: '/platform',
            groups: [
                { label: 'Platform', flat: true, items: [
                    { title: 'Overview', href: '/platform', icon: Gauge },
                    { title: 'Institutions', href: '/platform/institutions', icon: Building2 },
                    { title: 'Applications', href: '/platform/applications', icon: Inbox },
                ] },
                { label: 'Commercial', icon: CreditCard, items: [
                    { title: 'Plans & prices', href: '/platform/plans', icon: Tags },
                    { title: 'Modules per institution', href: '/platform/modules', icon: Layers },
                    { title: 'Payments & invoices', href: '/platform/payments', icon: Receipt },
                    { title: 'Discount codes', href: '/platform/coupons', icon: TicketPercent },
                    { title: 'Billing rules', href: '/platform/settings', icon: SlidersHorizontal },
                    { title: 'AI', href: '/platform/ai', icon: Sparkles },
                    { title: 'Text messages', href: '/platform/sms', icon: MessageSquareText },
                ] },
                { label: 'Team & system', icon: ShieldCheck, items: [
                    { title: 'Platform administrators', href: '/platform/admins', icon: ShieldCheck },
                    { title: 'Platform branding', href: '/platform/branding', icon: Palette },
                    { title: 'Conferencing servers', href: '/platform/conferencing', icon: Video },
                    { title: 'Announcements', href: '/platform/announcements', icon: Megaphone },
                    { title: 'Audit trail', href: '/platform/audit', icon: ScrollText },
                    { title: 'System health', href: '/platform/health', icon: Activity },
                    { title: 'Security check', href: '/platform/security', icon: ShieldAlert },
                ] },
                { label: 'Help', icon: LifeBuoy, items: [{ title: 'Platform documentation', href: '/platform/docs', icon: BookMarked }] },
            ],
        };
    }

    const student = userType === 'student';
    const lms = hasModule('lms');
    const exams = hasModule('question_bank');
    const meetings = hasModule('conferencing');
    const proctoring = hasModule('browser_proctoring');
    const groups: Group[] = [];
    const push = (label: string, icon: LucideIcon | undefined, items: (NavItem | false)[], extra: Partial<Group> = {}) =>
        groups.push({ label, icon, ...extra, items: items.filter(Boolean) as NavItem[] });

    push('Home', undefined, [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
        { title: 'Notifications', href: '/notifications', icon: Bell },
        lms && { title: 'Messages', href: '/messages', icon: MessagesSquare },
        lms && { title: 'Calendar', href: '/learn/calendar', icon: CalendarRange },
    ], { flat: true });

    if (student) {
        push('My studies', BookOpen, [
            lms && { title: 'My courses', href: '/learn/courses', icon: BookOpen },
            lms && { title: 'Course registration', href: '/learn/registration', icon: ClipboardList },
            lms && { title: 'Grades', href: '/learn/grades', icon: NotebookPen },
            lms && { title: 'Announcements', href: '/learn/announcements', icon: Megaphone },
            meetings && { title: 'Live classes', href: '/meetings', icon: Video },
            lms && { title: 'Search', href: '/learn/search', icon: Search },
        ]);
        push('My exams', GraduationCap, [
            exams && { title: 'Exams', href: '/my/exams', icon: GraduationCap },
            exams && hasModule('my_results') && { title: 'Results', href: '/my/results', icon: Trophy },
            hasModule('student_requests') && { title: 'Requests & exceptions', href: '/my/exceptions', icon: FileQuestion },
            hasModule('identity_checks') && { title: 'Face identity', href: '/my/identity', icon: ScanFace },
            hasModule('certificates') && { title: 'Certificates', href: '/my/certificates', icon: Award },
            hasModule('test_shop') && { title: 'Test series', href: '/my/test-series', icon: ShoppingBag },
            exams && hasModule('secure_client') && { title: 'Safe Exam Browser', href: '/safe-exam-browser', icon: ShieldCheck },
        ]);
    }

    if (!student && sitsExams && exams) {
        push('My exams', GraduationCap, [
            { title: 'Exams I sit', href: '/my/exams', icon: GraduationCap },
            hasModule('my_results') && { title: 'My results', href: '/my/results', icon: Trophy },
        ]);
    }

    if (exams && !student) {
        push('Exams', ScrollText, [
            can('exams.manage') && { title: 'All exams', href: '/exams', icon: ScrollText },
            can('questions.view') && { title: 'Question bank', href: '/question-bank', icon: Library },
            can('questions.create') && { title: 'Upload questions', href: '/question-bank/import', icon: FileUp },
            can('exams.manage') && { title: 'Timetable', href: '/exam-calendar', icon: CalendarDays },
            can('results.mark') && { title: 'Marking', href: '/marking', icon: ClipboardCheck },
            can('exams.manage') && hasModule('certificates') && { title: 'Certificates', href: '/certificates', icon: Award },
            can('exams.manage') && hasModule('test_shop') && { title: 'Test-series shop', href: '/admin/shop', icon: ShoppingBag },
            examBoard && { title: 'Exam board', href: '/exam-board', icon: MonitorPlay },
        ]);
    }

    // Exam day: each tool shows with its own module (halls, checks and centres work without proctoring)
    if (exams && !student && canAny('exams.proctor', 'exams.invigilate', 'exams.manage')) {
        push('Exam day', Radio, [
            proctoring && { title: 'Live monitoring', href: '/proctor', icon: Video },
            proctoring && { title: 'Incidents', href: '/incidents', icon: ShieldAlert },
            proctoring && { title: 'Proctor shifts', href: '/proctor/shifts', icon: CalendarClock },
            hasModule('cbt_halls') && can('exams.manage') && { title: 'Halls & computers', href: '/venues', icon: Building2 },
            hasModule('cbt_halls') && can('exams.manage') && { title: 'Exam network', href: '/exam-network', icon: Network },
            hasModule('computer_checks') && can('exams.manage') && { title: 'Computer checks', href: '/computer-checks', icon: MonitorCheck },
            can('exams.manage') && { title: 'Exam centres', href: '/exam-centres', icon: Building2 },
            hasModule('secure_client') && can('exams.manage') && { title: 'Safe Exam Browser', href: '/admin/safe-exam-browser', icon: ShieldCheck },
        ], { badge: proctoring && live > 0 ? `${live} live` : null });
    } else if (exams && proctoring && !student && examBoard) {
        // examiners and course lecturers watch their own exams' sittings
        push('Exam day', Radio, [{ title: 'Live monitoring', href: '/proctor', icon: Video }], { badge: live > 0 ? `${live} live` : null });
    }

    if (!student && (lms || meetings) && canAny('lms.teach', 'results.approve', 'academics.manage', 'meetings.host')) {
        push('Teaching', BookOpen, [
            lms && { title: 'Courses', href: '/learn/courses', icon: BookOpen },
            lms && canAny('academics.manage', 'results.approve') && { title: 'Registration', href: '/learn/registration', icon: ClipboardList },
            lms && { title: 'Announcements', href: '/learn/announcements', icon: Megaphone },
            meetings && { title: 'Live classes & meetings', href: '/meetings', icon: Video },
            lms && { title: 'Search', href: '/learn/search', icon: Search },
        ]);
    } else if (!student && meetings) {
        push('Teaching', BookOpen, [{ title: 'Live classes & meetings', href: '/meetings', icon: Video }]);
    }

    push('People & records', UsersRound, [
        can('users.manage') && { title: 'People', href: '/admin/people', icon: UsersRound },
        can('academics.manage') && { title: 'Academic structure', href: '/admin/academics', icon: Landmark },
        hasModule('student_requests') && canAny('exams.manage', 'academics.manage', 'results.approve', 'users.manage') && { title: 'Exceptions', href: '/admin/exceptions', icon: FileQuestion },
        canAny('audit.view', 'results.approve', 'exams.manage', 'academics.manage') && { title: 'Reports', href: '/admin/reports', icon: BarChart3 },
        hasModule('identity_checks') && can('users.manage') && { title: 'Identity checks', href: '/admin/identity', icon: ScanFace },
        can('roles.manage') && { title: 'Roles & permissions', href: '/admin/roles', icon: ShieldCheck },
        can('users.manage') && { title: 'Access review', href: '/admin/access-review', icon: UserCheck },
        can('audit.view') && { title: 'Audit trail', href: '/admin/audit', icon: ScrollText },
    ]);

    push('Settings', Settings2, [
        can('institution.manage') && { title: 'Branding & theme', href: '/admin/branding', icon: Palette },
        hasModule('ai_tools') && can('institution.manage') && { title: 'AI', href: '/admin/ai', icon: Sparkles },
        hasModule('sms') && can('institution.manage') && { title: 'Text messages', href: '/admin/sms', icon: MessageSquareText },
        can('institution.manage') && { title: 'Policy', href: '/admin/policy', icon: Scale },
        can('billing.manage') && { title: 'Subscription & billing', href: '/institution/billing', icon: CreditCard },
        hasModule('enterprise_hosting') && can('institution.manage') && { title: 'Single sign-on', href: '/admin/sso', icon: KeyRound },
        meetings && can('conferencing.manage') && { title: 'Conferencing', href: '/admin/conferencing', icon: Video },
        can('institution.manage') && can('integrations.manage') && { title: 'Integrations', href: '/admin/integrations', icon: Plug },
        can('institution.manage') && { title: 'E-mail delivery', href: '/admin/mail-log', icon: MailCheck },
        can('institution.manage') && { title: 'System health', href: '/admin/system-health', icon: Activity },
        can('institution.manage') && { title: 'Maintenance', href: '/admin/maintenance', icon: Wrench },
        !student && hasModule('identity_checks') && { title: 'Face identity', href: '/my/identity', icon: ScanFace },
    ]);

    push('Help', LifeBuoy, [
        { title: 'Documentation', href: '/docs', icon: BookMarked },
        !student && { title: 'Templates', href: '/templates', icon: FileDown },
        { title: 'Help centre', href: '/help', icon: LifeBuoy },
    ]);

    return { home: '/dashboard', groups: groups.filter((g) => g.items.length > 0) };
}

export function AppSidebar() {
    const { home, groups } = useMenu();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-1 pt-1">
                {groups.map((g) => (g.flat
                    ? <NavMain key={g.label} label={g.label} items={g.items} />
                    : <NavGroup key={g.label} label={g.label} icon={g.icon} badge={g.badge} items={g.items} />))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
