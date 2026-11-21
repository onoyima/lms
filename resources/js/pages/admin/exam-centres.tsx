import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Copy, KeyRound, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { Field, formatDateTime, inputClass, PageHeader, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Centre = { id: number; name: string; code: string; role: 'centre' | 'main'; notes: string | null; last_package_at: string | null; last_results_at: string | null };

/**
 * Exam centres that write without internet. On the main server: the centres and their keys. On a
 * centre's own server: the main server's key, and reading a sitting package.
 */
export default function ExamCentres({ centres, newKey, sittings }: { centres: Centre[]; newKey: { code: string; key: string } | null; sittings: { id: number; exam: string | null; name: string | null; starts_at: string }[] }) {
    const { flash } = usePage<{ flash?: { success?: string } }>().props;
    const form = useForm({ name: '', code: '', role: 'centre', secret: '', notes: '' });
    const mains = centres.filter((c) => c.role === 'main');
    const [pkg, setPkg] = useState<File | null>(null);
    const [main, setMain] = useState(String(mains[0]?.id ?? ''));

    return (
        <>
            <Head title="Exam centres" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Exam centres without internet"
                    lead="A centre runs its own copy of this platform. You send it a signed package for a sitting (the paper, candidates and times); after the exam it sends the scripts back, and they are marked here with your answer keys. Each centre has its own key, so a package cannot be changed or read into the wrong place."
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                {newKey && (
                    <div className="surface grid gap-2 border-brand/50 p-5">
                        <p className="flex items-center gap-2 font-medium"><KeyRound className="size-4 text-brand" /> Key for {newKey.code} — shown once</p>
                        <code className="overflow-x-auto rounded-lg bg-muted px-3 py-2 font-mono text-sm">{newKey.key}</code>
                        <Button size="sm" variant="outline" className="w-fit" onClick={() => void navigator.clipboard?.writeText(newKey.key)}><Copy className="size-4" /> Copy</Button>
                        <p className="text-xs text-muted-foreground">On the centre&apos;s server: Exam centres › add the main server, with code {newKey.code} and this key.</p>
                    </div>
                )}

                <Table head={['Name', 'Code', 'What it is', 'Last package', 'Last scripts', '']}>
                    {centres.map((c) => (
                        <tr key={c.id}>
                            <Td className="font-medium">{c.name}</Td>
                            <Td className="font-mono text-xs">{c.code}</Td>
                            <Td>{c.role === 'centre' ? 'An exam centre' : 'The main server'}</Td>
                            <Td className="text-xs">{c.last_package_at ? formatDateTime(c.last_package_at) : '—'}</Td>
                            <Td className="text-xs">{c.last_results_at ? formatDateTime(c.last_results_at) : '—'}</Td>
                            <Td>
                                <span className="flex gap-1.5">
                                    {c.role === 'centre' && <Button size="sm" variant="outline" onClick={() => confirm(`Make a new key for ${c.name}? The old key stops working.`) && router.post(`/exam-centres/${c.id}/rotate`, {}, { preserveScroll: true })}>New key</Button>}
                                    <Button size="sm" variant="ghost" aria-label={`Remove ${c.name}`} onClick={() => confirm(`Remove ${c.name}?`) && router.delete(`/exam-centres/${c.id}`, { preserveScroll: true })}><Trash2 className="size-4" /></Button>
                                </span>
                            </Td>
                        </tr>
                    ))}
                </Table>

                <div className="grid gap-6 lg:grid-cols-2">
                    <form className="surface grid gap-3 p-5" onSubmit={(e) => (e.preventDefault(), form.post('/exam-centres', { preserveScroll: true, onSuccess: () => form.reset() }))}>
                        <p className="font-medium">Add</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(['centre', 'main'] as const).map((r) => (
                                <label key={r} className={`flex cursor-pointer gap-2 rounded-lg border p-3 text-sm ${form.data.role === r ? 'border-brand bg-brand-soft' : 'border-border'}`}>
                                    <input type="radio" checked={form.data.role === r} onChange={() => form.setData('role', r)} />
                                    <span>{r === 'centre' ? <><b>An exam centre</b> (on the main server)</> : <><b>The main server</b> (on a centre&apos;s server)</>}</span>
                                </label>
                            ))}
                        </div>
                        <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={form.data.role === 'centre' ? 'Enugu study centre' : 'Main server'} required /></Field>
                        <Field label="Code" error={form.errors.code} hint="Letters and numbers, the same on both servers"><input className={`${inputClass} font-mono uppercase`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="ENU" required /></Field>
                        {form.data.role === 'main' && <Field label="Key from the main server" error={form.errors.secret}><input className={`${inputClass} font-mono`} value={form.data.secret} onChange={(e) => form.setData('secret', e.target.value)} required /></Field>}
                        <Button className="w-fit" disabled={form.processing}>{form.data.role === 'centre' ? 'Add centre and make its key' : 'Add main server'}</Button>
                    </form>

                    <section className="surface grid content-start gap-3 p-5">
                        <p className="font-medium">On a centre: read a sitting package</p>
                        {mains.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Add the main server first (code and key from the main server).</p>
                        ) : (
                            <>
                                <select className={inputClass} value={main} onChange={(e) => setMain(e.target.value)} aria-label="From">
                                    {mains.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                                </select>
                                <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-border px-3 py-3 text-sm hover:border-brand/60">
                                    <Upload className="size-4 text-brand" /> {pkg ? pkg.name : 'Choose the .cbtpkg file'}
                                    <input type="file" className="sr-only" accept=".cbtpkg" onChange={(e) => setPkg(e.target.files?.[0] ?? null)} />
                                </label>
                                <Button className="w-fit" disabled={!pkg} onClick={() => pkg && router.post('/exam-centres/read', { centre_id: main, file: pkg }, { forceFormData: true })}>Set up the sitting here</Button>
                            </>
                        )}
                        {sittings.length > 0 && (
                            <ul className="grid gap-1 border-t border-border pt-3 text-sm">
                                <li className="text-xs text-muted-foreground">Sittings from the main server</li>
                                {sittings.map((s) => <li key={s.id}><Link className="text-brand" href={`/sessions/${s.id}`}>{s.exam} · {s.name ?? formatDateTime(s.starts_at)}</Link></li>)}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

ExamCentres.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Exam centres', href: '/exam-centres' }] };
