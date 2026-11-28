import { CheckCircle2, Gauge, TriangleAlert } from 'lucide-react';

export type SpeedCheck = { key: string; label: string; ok: boolean; fix: string };

/** Server settings that make every page slow when wrong, each with what to change. */
export function SpeedChecks({ checks }: { checks?: SpeedCheck[] }) {
    if (!checks?.length) {
        return null;
    }

    const bad = checks.filter((c) => !c.ok);

    return (
        <section className="surface grid gap-3 p-5 text-sm" aria-labelledby="speed-title">
            <h2 id="speed-title" className="flex items-center gap-2 font-medium">
                <Gauge className="size-4" /> Speed
                <span className={`ml-1 rounded-full px-2 py-0.5 text-xs ${bad.length ? 'bg-status-warning/15 text-status-warning' : 'bg-status-success/12 text-status-success'}`}>
                    {bad.length ? `${bad.length} setting${bad.length === 1 ? '' : 's'} slowing pages down` : 'All good'}
                </span>
            </h2>
            <ul className="grid gap-2">
                {[...bad, ...checks.filter((c) => c.ok)].map((c) => (
                    <li key={c.key} className="flex gap-2">
                        {c.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-status-success" /> : <TriangleAlert className="mt-0.5 size-4 shrink-0 text-status-warning" />}
                        <span>
                            <span className={c.ok ? 'text-muted-foreground' : 'font-medium'}>{c.label}</span>
                            {!c.ok && <span className="block text-muted-foreground">{c.fix}</span>}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
