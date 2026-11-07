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
    Network,
    MonitorPlay,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
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

type Group = { label: string; items: NavItem[] };

/**
 * The menu follows what the signed-in person may do. Platform administrators see only the
 * platform console; institution staff and students see the parts of their institution that
 * their roles and the institution's plan allow. (Every page also checks on the server.)
 */
function useMenu(): { home: string; groups: Group[] } {
    const { hasModule, can, canAny, isPlatformAdmin, userType } = usePlatform();
    const examBoard = Boolean((usePage().props.auth as { examBoard?: boolean } | undefined)?.examBoard);

    const examinerExam = (usePage().props.auth as { examinerExam?: { id: number; title: string } | null } | undefined)?.examinerExam;

    if (userType === 'exam_examiner' && examinerExam) {
        const sheet = `/marking/${examinerExam.id}/panel`;

        return { home: sheet, groups: [{ label: examinerExam.title, items: [{ title: 'Scoring sheet', href: sheet, icon: ClipboardCheck }] }] };
    }

    if (isPlatformAdmin) {
        return {
            home: '/platform',
            groups: [
                {
                    label: 'Platform',
                    items: [
                        { title: 'Overview', href: '/platform', icon: Gauge },
                        { title: 'Institutions', href: '/platform/institutions', icon: Building2 },
                        { title: 'Applications', href: '/platform/applications', icon: Inbox },
                    ],
                },
                {
                    label: 'Commercial',
                    items: [
                        { title: 'Plans & prices', href: '/platform/plans', icon: Tags },
                        { title: 'Modules per institution', href: '/platform/modules', icon: Layers },
                        { title: 'Payments & invoices', href: '/platform/payments', icon: Receipt },
                        { title: 'Discount codes', href: '/platform/coupons', icon: TicketPercent },
                        { title: 'Billing rules', href: '/platform/settings', icon: SlidersHorizontal },
                    ],
                },
                {
                    label: 'Team',
                    items: [
                        { title: 'Platform administrators', href: '/platform/admins', icon: ShieldCheck },
                        { title: 'Platform branding', href: '/platform/branding', icon: Palette },
                        { title: 'Conferencing servers', href: '/platform/conferencing', icon: Video },
                        { title: 'Announcements', href: '/platform/announcements', icon: Megaphone },
                        { title: 'Audit trail', href: '/platform/audit', icon: ScrollText },
                        { title: 'System health', href: '/platform/health', icon: Activity },
                        { title: 'Security check', href: '/platform/security', icon: ShieldAlert },
                    ],
                },
                { label: 'Help', items: [{ title: 'Platform documentation', href: '/platform/docs', icon: BookMarked }] },
            ],
        };
    }

    const student = userType === 'student';
    const lms = hasModule('lms');
    const exams = hasModule('question_bank');
    const meetings = hasModule('conferencing');
    const proctoring = hasModule('browser_proctoring');
    const groups: Group[] = [];
    const push = (label: string, items: (NavItem | false)[]) => groups.push({ label, items: items.filter(Boolean) as NavItem[] });

    push('Home', [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
        { title: 'Notifications', href: '/notifications', icon: Bell },
        lms && { title: 'Messages', href: '/messages', icon: MessagesSquare },
        lms && { title: 'Calendar', href: '/learn/calendar', icon: CalendarRange },
        meetings && { title: 'Live classes & meetings', href: '/meetings', icon: Video },
    ]);

    if (student) {
        push('My studies', [
            lms && { title: 'My courses', href: '/learn/courses', icon: BookOpen },
            lms && { title: 'Course registration', href: '/learn/registration', icon: ClipboardList },
            lms && { title: 'Grades', href: '/learn/grades', icon: NotebookPen },
            exams && { title: 'My exams', href: '/my/exams', icon: GraduationCap },
            exams && { title: 'My results', href: '/my/results', icon: Trophy },
            lms && { title: 'Announcements', href: '/learn/announcements', icon: Megaphone },
            lms && { title: 'Search', href: '/learn/search', icon: Search },
            { title: 'Requests & exceptions', href: '/my/exceptions', icon: FileQuestion },
        ]);
    } else if (lms && canAny('lms.teach', 'results.approve', 'academics.manage')) {
        push('Teaching', [
            { title: 'Courses', href: '/learn/courses', icon: BookOpen },
            canAny('academics.manage', 'results.approve') && { title: 'Registration', href: '/learn/registration', icon: ClipboardList },
            { title: 'Announcements', href: '/learn/announcements', icon: Megaphone },
            { title: 'Search', href: '/learn/search', icon: Search },
        ]);
    }

    if (exams && !student) {
        push('Assessment', [
            can('questions.view') && { title: 'Question bank', href: '/question-bank', icon: Library },
            can('questions.create') && { title: 'Import questions', href: '/question-bank/import', icon: FileUp },
            examBoard && { title: 'Exam board', href: '/exam-board', icon: MonitorPlay },
            can('exams.manage') && { title: 'Exams', href: '/exams', icon: ScrollText },
            can('exams.manage') && { title: 'Exam calendar', href: '/exam-calendar', icon: CalendarDays },
            hasModule('cbt_halls') && can('exams.manage') && { title: 'Halls & devices', href: '/venues', icon: Building2 },
            hasModule('cbt_halls') && can('exams.manage') && { title: 'Exam network', href: '/exam-network', icon: Network },
            can('results.mark') && { title: 'Marking', href: '/marking', icon: ClipboardCheck },
        ]);
    }

    if (proctoring && !student && canAny('exams.proctor', 'exams.invigilate', 'exams.manage')) {
        push('Exam day', [
            { title: 'Proctoring', href: '/proctor', icon: Video },
            { title: 'Incidents', href: '/incidents', icon: ShieldAlert },
            { title: 'Proctor shifts', href: '/proctor/shifts', icon: CalendarClock },
        ]);
    } else if (proctoring && !student && examBoard) {
        // examiners and course lecturers watch their own exams' sittings
        push('Exam day', [{ title: 'Monitoring', href: '/proctor', icon: Video }]);
    }

    push('Administration', [
        can('users.manage') && { title: 'People', href: '/admin/people', icon: UsersRound },
        can('roles.manage') && { title: 'Roles & permissions', href: '/admin/roles', icon: ShieldCheck },
        can('users.manage') && { title: 'Identity checks', href: '/admin/identity', icon: ScanFace },
        can('users.manage') && { title: 'Access review', href: '/admin/access-review', icon: UserCheck },
        can('academics.manage') && { title: 'Academic structure', href: '/admin/academics', icon: Landmark },
        canAny('exams.manage', 'academics.manage', 'results.approve', 'users.manage') && { title: 'Exceptions', href: '/admin/exceptions', icon: FileQuestion },
        canAny('audit.view', 'results.approve', 'exams.manage', 'academics.manage') && { title: 'Reports', href: '/admin/reports', icon: BarChart3 },
        can('audit.view') && { title: 'Audit trail', href: '/admin/audit', icon: ScrollText },
    ]);

    push('Institution settings', [
        can('institution.manage') && { title: 'Policy', href: '/admin/policy', icon: Scale },
        can('institution.manage') && { title: 'Branding', href: '/admin/branding', icon: Palette },
        hasModule('enterprise_hosting') && can('institution.manage') && { title: 'Single sign-on', href: '/admin/sso', icon: KeyRound },
        can('institution.manage') && { title: 'Maintenance', href: '/admin/maintenance', icon: Wrench },
        meetings && can('conferencing.manage') && { title: 'Conferencing', href: '/admin/conferencing', icon: Video },
        can('institution.manage') && { title: 'System health', href: '/admin/system-health', icon: Activity },
        can('institution.manage') && { title: 'E-mail delivery', href: '/admin/mail-log', icon: MailCheck },
        can('institution.manage') && can('integrations.manage') && { title: 'Integrations', href: '/admin/integrations', icon: Plug },
        can('billing.manage') && { title: 'Subscription & billing', href: '/institution/billing', icon: CreditCard },
    ]);

    push('Help', [
        { title: 'Documentation', href: '/docs', icon: BookMarked },
        !student && { title: 'Templates', href: '/templates', icon: FileDown },
        { title: 'Help centre', href: '/help', icon: LifeBuoy },
    ]);

    push('Account', [{ title: 'Face identity', href: '/my/identity', icon: ScanFace }]);

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

            <SidebarContent>
                {groups.map((g) => (
                    <NavMain key={g.label} label={g.label} items={g.items} />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
