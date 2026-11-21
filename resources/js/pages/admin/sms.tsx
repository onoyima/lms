import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Loader2, MessageSquareText, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, sendJson, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Props = {
    scope: 'institution' | 'platform';
    current: { provider: string; username: string; sender: string; url: string; has_key: boolean; use_platform?: boolean; shared?: boolean };
    platformOffers?: boolean;
    active?: { source: string; provider: string } | null;
    providers: Record<string, string>;
    log?: { to: string; purpose: string; status: string; error: string | null; created_at: string }[];
    month?: { parts: number; failed: number } | null;
};

const HINTS: Record<string, { username?: string; key: string; sender: string }> = {
    africastalking: { username: "Africa's Talking username", key: 'API key', sender: 'Sender ID or short code (optional)' },
    termii: { key: 'API key', sender: 'Sender ID (approved by Termii; empty uses the DND route)' },
    twilio: { username: 'Account SID', key: 'Auth token', sender: 'From number (+1…)' },
    http: { key: 'Key (used as {key} in the address)', sender: 'Sender name ({sender})' },
};

/** Text messages: provider, sender name and key (platform-wide or per institution), a test, and recent messages. */
export default function SmsSettings({ scope, current, platformOffers, active, providers, log = [], month }: Props) {
    const form = useForm({ ...current, key: '', remove_key: false, use_platform: current.use_platform ?? true, shared: current.shared ?? false });
    const [to, setTo] = useState('');
    const [test, setTest] = useState<{ ok: boolean; message: string } | null>(null);
    const [testing, setTesting] = useState(false);
    const url = scope === 'platform' ? '/platform/sms' : '/admin/sms';
    const hint = HINTS[form.data.provider] ?? HINTS.http;

    const runTest = async () => {
        setTesting(true);
        setTest(null);
        const res = await sendJson<{ ok: boolean; message: string }>(`${url}/test`, 'POST', { ...form.data, key: form.data.key || null, to }).catch(() => null);
        setTest(res?.data ?? { ok: false, message: 'No answer from the server.' });
        setTesting(false);
    };

    return (
        <>
            <Head title="Text messages" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title="Text messages" lead="Sign-in codes, exam times and changes, and results by SMS, for people who have given a mobile number and want texts. Each text is logged without its content." />
                <Flash />
                {scope === 'institution' && (
                    <section className="surface flex flex-wrap items-center gap-4 p-5">
                        <span className={`grid size-11 shrink-0 place-items-center rounded-2xl ${active ? 'bg-status-good/12 text-status-good' : 'bg-foreground/[.06] text-muted-foreground'}`}><MessageSquareText className="size-5" /></span>
                        <p className="min-w-0 flex-1 text-sm">{active ? <>On, through <b>{active.source === 'platform' ? "the platform's" : 'your own'}</b> {active.provider} account.</> : 'Off: add a provider below' + (platformOffers ? ", or use the platform's." : '.')}</p>
                        {month && <p className="text-sm text-muted-foreground">This month: {month.parts} texts{month.failed ? `, ${month.failed} failed` : ''}</p>}
                    </section>
                )}
                <form className="surface grid gap-5 p-5 sm:p-6" onSubmit={(e) => (e.preventDefault(), form.put(url, { preserveScroll: true, onSuccess: () => form.reset('key', 'remove_key') }))}>
                    {scope === 'institution' && platformOffers && (
                        <label className="flex items-start gap-3 rounded-xl border border-border p-3.5 text-sm">
                            <input type="checkbox" className="mt-1" checked={form.data.use_platform} onChange={(e) => form.setData('use_platform', e.target.checked)} />
                            <span><b className="block font-semibold">Use the platform's text messages when we have no account of our own</b></span>
                        </label>
                    )}
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Provider">
                            <select className={inputClass} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                {Object.entries(providers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </Field>
                        {hint.username && <Field label={hint.username} error={form.errors.username}><input className={inputClass} value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} /></Field>}
                        <Field label={hint.sender} error={form.errors.sender}><input className={inputClass} value={form.data.sender} onChange={(e) => form.setData('sender', e.target.value)} /></Field>
                        {form.data.provider === 'http' && (
                            <div className="md:col-span-2">
                                <Field label="Gateway address" hint="Use {to}, {message}, {key} and {sender}, e.g. https://sms.example.com/send?to={to}&text={message}&key={key}" error={form.errors.url}>
                                    <input className={inputClass} value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} />
                                </Field>
                            </div>
                        )}
                        <div className="md:col-span-2">
                            <Field label={current.has_key ? `${hint.key} (saved; type a new one to change it)` : hint.key} error={form.errors.key}>
                                <input type="password" autoComplete="off" className={inputClass} value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} placeholder={current.has_key ? '••••••••••••' : ''} />
                            </Field>
                        </div>
                    </div>
                    {current.has_key && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remove_key} onChange={(e) => form.setData('remove_key', e.target.checked)} /><KeyRound className="size-4" /> Remove the saved key</label>}
                    {scope === 'platform' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.shared} onChange={(e) => form.setData('shared', e.target.checked)} /> Institutions without their own account may use this one</label>}
                    <div className="flex flex-wrap items-end gap-3">
                        <Button disabled={form.processing}>Save</Button>
                        <input className={`${inputClass} !w-48`} placeholder="Your mobile number" value={to} onChange={(e) => setTo(e.target.value)} aria-label="Send a test to" />
                        <Button type="button" variant="outline" onClick={() => void runTest()} disabled={testing || !to}>{testing && <Loader2 className="size-4 animate-spin" />} Send a test</Button>
                        {test && <span role="status" className={`flex items-center gap-1.5 text-sm ${test.ok ? 'text-status-good' : 'text-status-critical'}`}>{test.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />} {test.message}</span>}
                    </div>
                </form>
                {log.length > 0 && (
                    <Table head={['When', 'To', 'Why', 'Result']}>
                        {log.map((l, i) => (
                            <tr key={i}>
                                <Td>{formatDateTime(l.created_at)}</Td><Td className="font-mono text-xs">{l.to}</Td><Td>{l.purpose}</Td>
                                <Td>{l.status === 'sent' ? 'Sent' : <span className="text-status-critical">Failed{l.error ? `: ${l.error}` : ''}</span>}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}
