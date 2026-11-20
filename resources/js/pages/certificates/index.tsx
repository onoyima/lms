import { Head, router, useForm } from '@inertiajs/react';
import { Award } from 'lucide-react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { BADGE_ICONS } from '@/lib/badges';

type Template = { id: number; name: string; kind: string; heading: string; body: string; signatory_name: string | null; signatory_title: string | null; colour: string; badge_icon: string | null; min_percent: number; auto_award: boolean; awards: number };
type AwardRow = { id: number; code: string; subject_title: string; percent: number | null; grade: string | null; revoked_at: string | null; created_at: string; name: string; identifier: string | null; template: string; kind: string };

const blank = { name: '', kind: 'certificate', heading: 'Certificate of achievement', body: 'has successfully completed {exam} with a score of {score} (grade {grade}) on {date}.', signatory_name: '', signatory_title: '', colour: '#1d3557', badge_icon: 'award', min_percent: 50, auto_award: true };

/** Certificate and badge designs; choose one on an exam (Rules) and it is awarded when results are released. */
export default function Certificates({ templates, recent, icons }: { templates: Template[]; recent: AwardRow[]; icons: string[] }) {
    const form = useForm(blank);

    return (
        <>
            <Head title="Certificates and badges" />
            <div className="flex flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title="Certificates and badges" lead="Design them once. Choose one on an exam's Rules and everyone whose released result reaches the pass percentage receives it, with a code anyone can check." />
                <Flash />
                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    <form className="surface grid content-start gap-4 p-5 sm:p-6" onSubmit={(e) => (e.preventDefault(), form.post('/certificates', { preserveScroll: true, onSuccess: () => form.reset() }))}>
                        <h2 className="text-lg font-semibold">New design</h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Nursing finals — pass" required /></Field>
                            <Field label="Kind">
                                <select className={inputClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                                    <option value="certificate">Certificate (PDF to print)</option>
                                    <option value="badge">Badge (shown on the profile)</option>
                                </select>
                            </Field>
                            <Field label="Heading"><input className={inputClass} value={form.data.heading} onChange={(e) => form.setData('heading', e.target.value)} /></Field>
                            <Field label="Pass percentage that earns it" error={form.errors.min_percent}><input type="number" min={0} max={100} className={inputClass} value={form.data.min_percent} onChange={(e) => form.setData('min_percent', Number(e.target.value))} /></Field>
                            <div className="sm:col-span-2">
                                <Field label="Text" hint="Use {name}, {exam}, {score}, {grade}, {date} and {institution}." error={form.errors.body}>
                                    <textarea className={textareaClass} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                                </Field>
                            </div>
                            <Field label="Signed by (optional)"><input className={inputClass} value={form.data.signatory_name} onChange={(e) => form.setData('signatory_name', e.target.value)} placeholder="Prof. A. Bello" /></Field>
                            <Field label="Their title"><input className={inputClass} value={form.data.signatory_title} onChange={(e) => form.setData('signatory_title', e.target.value)} placeholder="Registrar" /></Field>
                            <Field label="Colour"><input type="color" className="h-10 w-20 rounded-lg border border-input" value={form.data.colour} onChange={(e) => form.setData('colour', e.target.value)} /></Field>
                            <Field label="Badge symbol">
                                <div className="flex flex-wrap gap-2">
                                    {icons.map((i) => {
                                        const I = BADGE_ICONS[i] ?? Award;

                                        return <button key={i} type="button" aria-label={i} aria-pressed={form.data.badge_icon === i} onClick={() => form.setData('badge_icon', i)}
                                            className={`grid size-10 place-items-center rounded-xl border ${form.data.badge_icon === i ? 'border-brand bg-brand-soft' : 'border-border'}`}><I className="size-5" /></button>;
                                    })}
                                </div>
                            </Field>
                        </div>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.auto_award} onChange={(e) => form.setData('auto_award', e.target.checked)} /> Award automatically when results are released</label>
                        <Button className="w-fit" disabled={form.processing}>Save design</Button>
                    </form>
                    <section className="grid content-start gap-3">
                        {templates.length === 0 && <p className="surface p-5 text-sm text-muted-foreground">No designs yet.</p>}
                        {templates.map((t) => {
                            const I = BADGE_ICONS[t.badge_icon ?? 'award'] ?? Award;

                            return (
                                <article key={t.id} className="surface flex items-center gap-3 p-4">
                                    <span className="grid size-12 shrink-0 place-items-center rounded-full text-white" style={{ background: t.colour }}><I className="size-6" /></span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-semibold">{t.name}</p>
                                        <p className="text-xs text-muted-foreground">{t.kind === 'badge' ? 'Badge' : 'Certificate'} · from {t.min_percent}% · {t.awards} awarded{t.auto_award ? '' : ' · by hand'}</p>
                                    </div>
                                </article>
                            );
                        })}
                    </section>
                </div>
                {recent.length > 0 && (
                    <Table head={['Awarded', 'To', 'For', 'Score', 'Code', '']}>
                        {recent.map((a) => (
                            <tr key={a.id} className={a.revoked_at ? 'opacity-50' : ''}>
                                <Td>{formatDateTime(a.created_at)}</Td>
                                <Td>{a.name}<span className="block text-xs text-muted-foreground">{a.identifier}</span></Td>
                                <Td>{a.template}<span className="block text-xs text-muted-foreground">{a.subject_title}</span></Td>
                                <Td>{a.percent !== null ? `${a.percent}%` : ''}{a.grade ? ` · ${a.grade}` : ''}</Td>
                                <Td className="font-mono text-xs"><a className="underline" href={`/verify/${a.code}`}>{a.code}</a></Td>
                                <Td>{a.revoked_at ? 'Revoked' : (
                                    <span className="flex gap-2">
                                        {a.kind !== 'badge' && <a className="text-sm underline" href={`/certificates/${a.code}.pdf`}>PDF</a>}
                                        <button type="button" className="text-sm text-status-critical" onClick={() => {
                                            const reason = prompt('Why is it revoked?');

                                            if (reason) {
                                                router.post(`/certificates/awards/${a.id}/revoke`, { reason }, { preserveScroll: true });
                                            }
                                        }}>Revoke</button>
                                    </span>
                                )}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}
