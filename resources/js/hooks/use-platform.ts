import { usePage } from '@inertiajs/react';

/**
 * Which module each area of the app belongs to (longest prefix wins), so a link to an area the
 * institution does not have is not shown: switching one module off never leaves dead links in
 * another.
 */
const AREAS: [string, string[]][] = [
    ['/exam-board', ['question_bank', 'exam_board']],
    ['/proctor', ['browser_proctoring']],
    ['/incidents', ['browser_proctoring']],
    ['/computer-checks', ['computer_checks']],
    ['/venues', ['cbt_halls']],
    ['/exam-network', ['cbt_halls']],
    ['/certificates', ['certificates']],
    ['/my/certificates', ['certificates']],
    ['/my/results', ['my_results']],
    ['/results', ['results']],
    ['/marking', ['question_bank']],
    ['/question-bank', ['question_bank']],
    ['/exams', ['question_bank']],
    ['/my/exams', ['question_bank']],
    ['/meetings', ['conferencing']],
    ['/learn', ['lms']],
    ['/messages', ['lms']],
    ['/my/identity', ['identity_checks']],
    ['/admin/identity', ['identity_checks']],
    ['/my/exceptions', ['student_requests']],
    ['/admin/exceptions', ['student_requests']],
    ['/admin/ai', ['ai_tools']],
    ['/admin/sms', ['sms']],
    ['/admin/shop', ['test_shop']],
    ['/shop', ['test_shop']],
    ['/my/test-series', ['test_shop']],
];

export function modulesFor(href: string): string[] {
    const path = href.replace(/^https?:\/\/[^/]+/, '').split(/[?#]/)[0];
    let best: [string, string[]] | null = null;

    for (const a of AREAS) {
        if ((path === a[0] || path.startsWith(a[0] + '/')) && (!best || a[0].length > best[0].length)) {
            best = a;
        }
    }

    return best ? best[1] : [];
}

/**
 * Which modules this institution has, and what the signed-in user may do.
 * Platform administrators hold no institution permissions: they run the platform console only.
 */
export function usePlatform() {
    const { modules, auth, tenant } = usePage().props;
    const permissions: string[] = auth?.permissions ?? [];
    const roles: string[] = (usePage().props as { roles?: string[] }).roles ?? [];

    return {
        tenant,
        hasModule: (key: string) => (modules ?? []).includes(key),
        /** The page at this address belongs to modules the institution has (links to others are hidden). */
        opens: (href: string) => modulesFor(href).every((m) => (modules ?? []).includes(m)),
        can: (permission: string) => permissions.includes(permission),
        canAny: (...list: string[]) => list.some((p) => permissions.includes(p)),
        hasRole: (...list: string[]) => list.some((r) => roles.includes(r)),
        isPlatformAdmin: Boolean(auth?.isPlatformAdmin),
        userType: (auth?.user as { user_type?: string } | undefined)?.user_type ?? null,
    };
}
