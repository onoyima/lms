import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Loader2, Sparkles, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { sendJson } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Props = {
    scope: 'institution' | 'platform';
    current: { provider: string; base_url: string; model: string; has_key: boolean; use_platform?: boolean; shared?: boolean; features?: Record<string, boolean> };
    platformOffers?: boolean;
    active?: { source: string; provider: string; model: string } | null;
    providers: Record<string, string>;
    defaults: { model: Record<string, string>; url: Record<string, string> };
    features: Record<string, string>;
};

/** AI provider and key (platform-wide or for one institution), and which AI features are on. */
export default function AiSettings({ scope, current, platformOffers, active, providers, defaults, features }: Props) {
    const form = useForm({
        provider: current.provider, base_url: current.base_url, model: current.model, key: '', remove_key: false,
        use_platform: current.use_platform ?? true, shared: current.shared ?? true, features: current.features ?? {},
    });
    const [test, setTest] = useState<{ ok: boolean; message: string } | null>(null);
    const [testing, setTesting] = useState(false);
    const url = scope === 'platform' ? '/platform/ai' : '/admin/ai';

    const runTest = async () => {
        setTesting(true);
        setTest(null);
        const res = await sendJson<{ ok: boolean; message: string }>(`${url}/test`, 'POST', { provider: form.data.provider, base_url: form.data.base_url || null, model: form.data.model || null, key: form.data.key || null }).catch(() => null);
        setTest(res?.data ?? { ok: false, message: 'No answer from the server.' });
        setTesting(false);
    };

    return (
        <>
            <Head title="AI" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader
                    title="AI"
                    lead={scope === 'platform'
                        ? 'The AI the platform provides. Institutions without their own key use it when you share it. Off until a key is set.'
                        : 'AI reads uploaded papers into questions, suggests new questions and suggests marks for written answers. A person always checks and decides; nothing is used without review.'}
                />
                <Flash />
                {scope === 'institution' && (
                    <section className={`surface flex items-center gap-4 p-5 ${active ? '' : 'opacity-90'}`}>
                        <span className={`grid size-11 shrink-0 place-items-center rounded-2xl ${active ? 'bg-status-good/12 text-status-good' : 'bg-foreground/[.06] text-muted-foreground'}`}><Sparkles className="size-5" /></span>
                        <p className="text-sm">
                            {active
                                ? <>AI is on, using <b>{active.source === 'platform' ? "the platform's" : 'your own'}</b> {active.provider} ({active.model}).</>
                                : 'AI is off: add your own key below' + (platformOffers ? ", or use the platform's AI." : '.')}
                        </p>
                    </section>
                )}
                <form className="surface grid gap-5 p-5 sm:p-6" onSubmit={(e) => (e.preventDefault(), form.put(url, { preserveScroll: true, onSuccess: () => form.reset('key', 'remove_key') }))}>
                    {scope === 'institution' && platformOffers && (
                        <label className="flex items-start gap-3 rounded-xl border border-border p-3.5 text-sm">
                            <input type="checkbox" className="mt-1" checked={form.data.use_platform} onChange={(e) => form.setData('use_platform', e.target.checked)} />
                            <span><b className="block font-semibold">Use the platform's AI when we have no key of our own</b><span className="text-muted-foreground">Your own key below always comes first.</span></span>
                        </label>
                    )}
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Provider">
                            <select className={inputClass} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                {Object.entries(providers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select>
                        </Field>
                        <Field label="Model" hint={`Empty: ${defaults.model[form.data.provider]}`} error={form.errors.model}>
                            <input className={inputClass} value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} placeholder={defaults.model[form.data.provider]} />
                        </Field>
                        <div className="md:col-span-2">
                            <Field label="Address (only if not the provider's own)" hint={`Empty: ${defaults.url[form.data.provider]}. For a model on your own network, e.g. http://10.0.0.5:11434/v1`} error={form.errors.base_url}>
                                <input className={inputClass} value={form.data.base_url} onChange={(e) => form.setData('base_url', e.target.value)} placeholder={defaults.url[form.data.provider]} />
                            </Field>
                        </div>
                        <div className="md:col-span-2">
                            <Field label={current.has_key ? 'API key (saved; type a new one to change it)' : 'API key'} error={form.errors.key}>
                                <input type="password" autoComplete="off" className={inputClass} value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} placeholder={current.has_key ? '••••••••••••' : ''} />
                            </Field>
                        </div>
                    </div>
                    {current.has_key && (
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remove_key} onChange={(e) => form.setData('remove_key', e.target.checked)} /><KeyRound className="size-4" /> Remove the saved key</label>
                    )}
                    {scope === 'platform' && (
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.shared} onChange={(e) => form.setData('shared', e.target.checked)} /> Institutions without their own key may use this AI</label>
                    )}
                    {scope === 'institution' && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">What AI may do here</legend>
                            {Object.entries(features).map(([k, label]) => (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={form.data.features[k] ?? true} onChange={(e) => form.setData('features', { ...form.data.features, [k]: e.target.checked })} /> {label}
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button disabled={form.processing}>Save</Button>
                        <Button type="button" variant="outline" onClick={() => void runTest()} disabled={testing}>{testing ? <Loader2 className="size-4 animate-spin" /> : null} Test the connection</Button>
                        {test && (
                            <span role="status" className={`flex items-center gap-1.5 text-sm ${test.ok ? 'text-status-good' : 'text-status-critical'}`}>
                                {test.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />} {test.message}
                            </span>
                        )}
                    </div>
                </form>
                <p className="text-xs text-muted-foreground">Keys are stored encrypted and are never shown again. Papers and answers sent to an outside AI service leave your server; for a closed campus network, run a model on your own server and give its address above.</p>
            </div>
        </>
    );
}
