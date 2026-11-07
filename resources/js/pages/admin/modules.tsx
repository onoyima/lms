import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Plan = { id: number; key: string; name: string; description: string; modules: string[] };
type ModuleDef = { key: string; name: string; description: string; always_on: boolean; requires: string[]; items: string | null };
type TenantRow = {
    id: number;
    name: string;
    slug: string;
    status: string;
    plan_id: number | null;
    enabled: string[];
    overrides: Record<string, boolean>;
};

/** Platform staff: choose each institution's plan and switch modules on or off on top of it. */
export default function Modules({ plans, modules, tenants }: { plans: Plan[]; modules: ModuleDef[]; tenants: TenantRow[] }) {
    return (
        <>
            <Head title="Plans & modules" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <header className="max-w-3xl">
                    <h1 className="text-[1.75rem] leading-tight font-semibold">
                        Plans & modules
                    </h1>
                    <p className="mt-3 text-muted-foreground">
                        An institution gets the modules of its plan. Switch a module on as an add-on, or off, for one institution
                        without changing the plan. Core is always on; a module whose required module is off stays off.
                    </p>
                </header>
                {tenants.map((t) => (
                    <TenantCard key={t.id} tenant={t} plans={plans} modules={modules} />
                ))}
            </div>
        </>
    );
}

function TenantCard({ tenant, plans, modules }: { tenant: TenantRow; plans: Plan[]; modules: ModuleDef[] }) {
    const [planId, setPlanId] = useState<number | null>(tenant.plan_id);
    const [overrides, setOverrides] = useState<Record<string, boolean | null>>(tenant.overrides);
    const plan = plans.find((p) => p.id === planId);

    const state = (key: string): 'plan' | 'on' | 'off' =>
        overrides[key] === true ? 'on' : overrides[key] === false ? 'off' : 'plan';

    const save = () =>
        router.put(`/platform/modules/${tenant.id}`, { plan_id: planId, overrides }, { preserveScroll: true });

    return (
        <section className="surface p-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 className="text-lg font-medium">{tenant.name}</h2>
                    <p className="font-mono text-xs text-muted-foreground">{tenant.slug}</p>
                </div>
                <div className="flex items-center gap-3">
                    <select
                        className="h-9 rounded-xl border border-input bg-background px-3 text-sm"
                        value={planId ?? ''}
                        onChange={(e) => setPlanId(e.target.value ? Number(e.target.value) : null)}
                        aria-label="Plan"
                    >
                        <option value="">No plan</option>
                        {plans.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                    <Button onClick={save}>Save</Button>
                </div>
            </div>

            <div className="mt-6 grid gap-3 md:grid-cols-2">
                {modules.map((m) => {
                    const inPlan = m.always_on || Boolean(plan?.modules.includes(m.key));
                    const active = tenant.enabled.includes(m.key);

                    return (
                        <div key={m.key} className="flex items-start justify-between gap-4 rounded-xl border border-border p-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">{m.name}</span>
                                    {active ? <Badge className="bg-brand text-brand-foreground">On</Badge> : <Badge variant="outline">Off</Badge>}
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">{m.description}</p>
                                {m.requires.length > 0 && (
                                    <p className="mt-1 font-mono text-[11px] text-muted-foreground">needs: {m.requires.join(', ')}</p>
                                )}
                            </div>
                            {m.always_on ? (
                                <span className="text-xs text-muted-foreground">Always on</span>
                            ) : (
                                <select
                                    className="h-8 shrink-0 rounded-lg border border-input bg-background px-2 text-xs"
                                    value={state(m.key)}
                                    aria-label={`${m.name} setting`}
                                    onChange={(e) =>
                                        setOverrides({
                                            ...overrides,
                                            [m.key]: e.target.value === 'plan' ? null : e.target.value === 'on',
                                        })
                                    }
                                >
                                    <option value="plan">As plan ({inPlan ? 'on' : 'off'})</option>
                                    <option value="on">Add-on: on</option>
                                    <option value="off">Switched off</option>
                                </select>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

Modules.layout = { breadcrumbs: [{ title: 'Plans & modules', href: '/platform/modules' }] };
