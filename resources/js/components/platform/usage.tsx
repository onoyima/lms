export type UsageRow = { key: string; label: string; unit: string; about: string; limit: number | null; used: number; percent: number | null; ceiling: boolean };

const unitText = (u: string) => (u && u !== 'accounts' && u !== 'people' ? ` ${u}` : '');

/** Current consumption against what the subscription allows — the same numbers the server enforces. */
export function UsageMeters({ rows }: { rows: UsageRow[] }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {rows.map((r) => {
                const tone = r.percent === null ? 'bg-brand' : r.percent >= 100 ? 'bg-status-critical' : r.percent >= 85 ? 'bg-status-warning' : 'bg-status-good';

                return (
                    <div key={r.key} className="rounded-xl border p-3" title={r.about}>
                        <div className="flex items-baseline justify-between gap-2 text-sm">
                            <span className="font-medium">{r.label}</span>
                            <span className="tabular-nums text-muted-foreground">
                                {r.ceiling ? (r.limit === null ? 'No limit' : `${r.limit.toLocaleString()}${unitText(r.unit)} per file`)
                                    : `${r.used.toLocaleString()} / ${r.limit === null ? '∞' : r.limit.toLocaleString()}${unitText(r.unit)}`}
                            </span>
                        </div>
                        {!r.ceiling && (
                            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label={r.label} aria-valuenow={r.percent ?? 0} aria-valuemin={0} aria-valuemax={100}>
                                <div className={`h-full ${tone}`} style={{ width: `${r.limit === null ? 0 : Math.max(2, r.percent ?? 0)}%` }} />
                            </div>
                        )}
                        <p className="mt-1.5 text-xs text-muted-foreground">{r.about}</p>
                    </div>
                );
            })}
        </div>
    );
}

const EVENT_TEXT: Record<string, string> = {
    created: 'Subscription started', plan_changed: 'Package changed', cycle_changed: 'Billing period changed', renewed: 'Renewed', extended: 'Validity extended',
    cancelled: 'Cancellation requested', resumed: 'Cancellation undone', expired: 'Expired', suspended: 'Suspended', reactivated: 'Access restored',
    limits_changed: 'Capacity changed', addon_added: 'Extra capacity added', addon_ended: 'Extra capacity ended', module_granted: 'Temporary module access',
    module_revoked: 'Module access ended', coupon_applied: 'Discount code applied', payment_failed: 'Payment failed', payment_succeeded: 'Payment received',
    past_due: 'Payment overdue', trial_ended: 'Free trial ended',
};

export type HistoryRow = { id: number; type: string; data: Record<string, unknown> | null; by?: string | null; at: string };

export function eventText(e: HistoryRow): string {
    const d = e.data ?? {};
    const bits = Object.entries(d).filter(([, v]) => v !== null && v !== '' && typeof v !== 'object').map(([k, v]) => `${k.replace(/_/g, ' ')}: ${String(v)}`);

    return `${EVENT_TEXT[e.type] ?? e.type.replace(/_/g, ' ')}${bits.length ? ` — ${bits.join(', ')}` : ''}`;
}
