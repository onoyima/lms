import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Page header: the title (display face), an optional details line under it (course code, due date,
 * the exam a sitting belongs to) and one line saying what the page is for; actions sit on the right.
 */
export function PageHeader({ eyebrow, title, accent, lead, actions }: { eyebrow?: ReactNode; title: ReactNode; accent?: ReactNode; lead?: ReactNode; actions?: ReactNode }) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <div className="min-w-0 max-w-3xl">
                <h1 className="text-[1.9rem] leading-[1.1] font-semibold tracking-tight text-balance md:text-[2.15rem]">
                    {title}{accent ? <> {accent}</> : null}
                </h1>
                {eyebrow && <p className="mt-2 text-sm font-medium text-foreground/80">{eyebrow}</p>}
                {lead && <p className="mt-1.5 max-w-2xl text-[15px] text-muted-foreground">{lead}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}

const tones: Record<string, string> = {
    good: 'bg-status-good/12 text-status-good',
    warning: 'bg-status-warning/14 text-status-warning',
    critical: 'bg-status-critical/12 text-status-critical',
    brand: 'bg-brand-soft text-brand-ink',
    muted: 'bg-foreground/[.06] text-muted-foreground',
};

const statusTone: Record<string, keyof typeof tones> = {
    draft: 'muted', returned: 'warning', submitted: 'warning', scheduled: 'brand', live: 'good', closed: 'muted', results_published: 'good', archived: 'muted',
    in_progress: 'good', paused: 'warning', locked: 'critical', auto_submitted: 'muted', terminated: 'critical',
    open: 'warning', investigating: 'warning', escalated: 'critical', resolved: 'good', dismissed: 'muted',
    low: 'muted', medium: 'warning', high: 'critical', critical: 'critical',
    pending_marking: 'warning', moderated: 'brand', approved: 'good', published: 'good', withheld: 'critical',
    eligible: 'good', ineligible: 'critical', override: 'warning', present: 'good', absent: 'critical', expected: 'muted', excused: 'muted',
    active: 'good', blur: 'warning', offline: 'critical', camera_off: 'critical', pending: 'warning', blocked: 'critical',
    warning: 'warning', trialing: 'brand', past_due: 'warning', suspended: 'critical', paid: 'good', void: 'muted', succeeded: 'good', failed: 'critical', rejected: 'critical', ready: 'good', waiting: 'warning', refused: 'critical', not_started: 'muted', writing: 'good', cancelled: 'muted', expired: 'muted', inactive: 'muted',
};

export function Status({ value, className }: { value: string | null | undefined; className?: string }) {
    if (!value) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const tone = tones[statusTone[value] ?? 'muted'];

    const live = ['in_progress', 'live', 'writing', 'active'].includes(value);

    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11.5px] font-semibold whitespace-nowrap capitalize', tone, className)}>
            <span className={cn('size-1.5 rounded-full bg-current', live && 'animate-pulse')} aria-hidden />
            {value.replace(/_/g, ' ')}
        </span>
    );
}

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: 'good' | 'warning' | 'critical' }) {
    return (
        <div className="surface relative overflow-hidden px-4 py-3.5 transition-transform duration-200 hover:-translate-y-0.5">
            <span className={cn('absolute inset-y-3 left-0 w-[3px] rounded-r-full', tone ? `bg-status-${tone}` : 'bg-brand')} aria-hidden />
            <p className="text-[13px] text-muted-foreground">{label}</p>
            <p className={cn('mt-1 font-display text-[1.6rem] leading-none font-semibold tabular-nums', tone && `text-status-${tone}`)}>{value}</p>
        </div>
    );
}

export function Field({ label, children, hint, error }: { label: string; children: ReactNode; hint?: string; error?: string }) {
    return (
        <label className="grid gap-1.5 text-sm">
            <span className="font-medium">{label}</span>
            {children}
            {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
            {error && <span className="text-xs text-status-critical">{error}</span>}
        </label>
    );
}

export const inputClass = 'h-10 w-full rounded-[10px] border border-input bg-card/90 px-3 text-sm outline-none transition-[border-color,box-shadow] hover:border-foreground/25 focus:border-brand focus:shadow-[0_0_0_4px_var(--brand-soft)]';
export const textareaClass = 'min-h-24 w-full rounded-[10px] border border-input bg-card/90 px-3 py-2 text-sm outline-none transition-[border-color,box-shadow] hover:border-foreground/25 focus:border-brand focus:shadow-[0_0_0_4px_var(--brand-soft)]';

export function Table({ head, children, className }: { head: ReactNode[]; children: ReactNode; className?: string }) {
    return (
        <div className={cn('surface overflow-x-auto', className)}>
            <table className="w-full text-sm [&_tbody_tr]:transition-colors [&_tbody_tr:hover]:bg-brand-soft/60">
                <thead className="border-b border-border text-left text-xs text-muted-foreground">
                    <tr>
                        {head.map((h, i) => (
                            <th key={i} className="px-4 py-3 font-semibold">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">{children}</tbody>
            </table>
        </div>
    );
}

export function Td({ children, className }: { children?: ReactNode; className?: string }) {
    return <td className={cn('px-4 py-2.5 align-top', className)}>{children}</td>;
}

export function Flash() {
    return null;
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function formatClock(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const ss = s % 60;

    return (h ? `${h}:` : '') + `${String(m).padStart(h ? 2 : 1, '0')}:${String(ss).padStart(2, '0')}`;
}

/** fetch() with the Laravel CSRF cookie, JSON in and out. */
export async function sendJson<T = unknown>(url: string, method: string, body?: unknown, headers: Record<string, string> = {}): Promise<{ ok: boolean; status: number; data: T }> {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', ...headers },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = (await res.json().catch(() => ({}))) as T;

    return { ok: res.ok, status: res.status, data };
}

/** POST (with CSRF) and save the response as a file — for downloads that change state, like new PINs. */
export async function downloadPost(url: string, filename: string): Promise<void> {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' } });

    if (!res.ok) {
        alert('Download failed.');

        return;
    }

    const href = URL.createObjectURL(await res.blob());
    const a = Object.assign(document.createElement('a'), { href, download: filename });
    a.click();
    URL.revokeObjectURL(href);
}
