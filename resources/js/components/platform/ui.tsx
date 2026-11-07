import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Page header: a plain serif title, then (when given) a details line — course code, due date, the exam a
 * session belongs to — and one line saying what the page is for. `accent` is simply part of the title.
 */
export function PageHeader({ eyebrow, title, accent, lead, actions }: { eyebrow?: ReactNode; title: ReactNode; accent?: ReactNode; lead?: ReactNode; actions?: ReactNode }) {
    return (
        <header className="flex flex-wrap items-end justify-between gap-4 border-b border-border pb-4">
            <div className="max-w-3xl">
                <h1 className="text-[1.75rem] leading-tight font-semibold">
                    {title}{accent ? <> {accent}</> : null}
                </h1>
                {eyebrow && <p className="mt-1 text-sm text-foreground/80">{eyebrow}</p>}
                {lead && <p className="mt-1.5 text-sm text-muted-foreground">{lead}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </header>
    );
}

const tones: Record<string, string> = {
    good: 'bg-status-good/15 text-status-good border-status-good/30',
    warning: 'bg-status-warning/15 text-status-warning border-status-warning/30',
    critical: 'bg-status-critical/15 text-status-critical border-status-critical/30',
    brand: 'bg-brand-soft text-brand border-brand/30',
    muted: 'bg-muted text-muted-foreground border-border',
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

    return <span className={cn('inline-flex items-center rounded border px-1.5 py-px text-[11px] font-medium whitespace-nowrap', tone, className)}>{value.replace(/_/g, ' ')}</span>;
}

export function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: 'good' | 'warning' | 'critical' }) {
    return (
        <div className="border-l-2 border-border py-1 pl-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('mt-0.5 text-xl font-semibold tabular-nums', tone && `text-status-${tone}`)}>{value}</p>
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

export const inputClass = 'h-10 w-full rounded-md border border-input bg-card px-3 text-sm outline-none focus:border-brand';
export const textareaClass = 'min-h-24 w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus:border-brand';

export function Table({ head, children, className }: { head: ReactNode[]; children: ReactNode; className?: string }) {
    return (
        <div className={cn('overflow-x-auto rounded-lg border border-border bg-card', className)}>
            <table className="w-full text-sm">
                <thead className="border-b border-border bg-muted/60 text-left text-xs text-muted-foreground">
                    <tr>
                        {head.map((h, i) => (
                            <th key={i} className="px-3 py-2 font-medium">
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
    return <td className={cn('px-3 py-2 align-top', className)}>{children}</td>;
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
