import { FileUp, Search, UserPlus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { inputClass, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Preview = { token: string; total: number; existing: number; new: number; invalid: { line: number; reason: string }[]; sample: { line: number; name: string; identifier: string; email: string; has_account: boolean }[] };
type Found = { id: number; name: string; identifier: string | null; email: string };
type Mode = 'upload' | 'one' | 'paste' | 'match';

const csrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/**
 * The four ways to put students on an exam or a category, the way the old system did and more:
 * upload a list (CSV, Excel or text; unknown students get accounts after a preview), find one
 * student, paste matric numbers, or everyone whose matric number / e-mail matches.
 * `send` posts the chosen source with the page's own fields (sitting, or category and papers).
 */
export function AddStudents({ send, busy, extra, audience = 'student' }: { send: (body: Record<string, unknown>) => void; busy?: boolean; extra?: React.ReactNode; audience?: 'student' | 'staff' }) {
    const who = audience === 'staff' ? 'staff' : 'students';
    const [mode, setMode] = useState<Mode>('upload');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [reading, setReading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [create, setCreate] = useState(true);
    const [rule, setRule] = useState('file_or_matric');
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [paste, setPaste] = useState('');
    const [prefix, setPrefix] = useState('');
    const [domain, setDomain] = useState('');

    const readList = async (file: File) => {
        setError(null);
        setReading(true);
        const body = new FormData();
        body.append('file', file);
        const res = await fetch('/student-lists/preview', { method: 'POST', body, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() } });
        setReading(false);
        const data = await res.json().catch(() => ({}));

        if (!res.ok) {
            setError(data.message ?? 'The list could not be read.');

            return;
        }

        setPreview(data as Preview);
    };
    const search = async () => {
        if (q.trim().length < 2) {
return;
}

        const res = await fetch(`/student-search?q=${encodeURIComponent(q.trim())}&audience=${audience}`, { headers: { Accept: 'application/json' } });
        setFound(res.ok ? await res.json() : []);
    };
    const tab = (m: Mode, label: string) => (
        <button type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m)} className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${mode === m ? 'bg-brand text-brand-foreground shadow-[0_4px_12px_-6px_var(--brand)]' : 'bg-muted/60 text-foreground hover:bg-muted'}`}>
            {label}
        </button>
    );

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap gap-1.5" role="tablist">
                {tab('upload', 'Upload a list')}
                {tab('one', audience === 'staff' ? 'Find a staff member' : 'Find a student')}
                {tab('paste', audience === 'staff' ? 'Paste staff numbers' : 'Paste matric numbers')}
                {tab('match', 'By matric / e-mail')}
            </div>
            {extra}

            {mode === 'upload' && (
                <div className="grid gap-2">
                    <label className="flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed border-border bg-card p-4 text-center text-sm hover:border-brand/60">
                        <FileUp className="size-5 text-brand" />
                        <span className="font-medium">{reading ? 'Reading…' : 'Choose a CSV, Excel (.xlsx) or text file'}</span>
                        <span className="text-xs text-muted-foreground">Columns such as matric_no, firstname, lastname, email, password. <a className="text-brand underline" href="/templates/exam_students" onClick={(e) => e.stopPropagation()}>Template</a></span>
                        <input type="file" className="sr-only" accept=".csv,.xlsx,.txt" onChange={(e) => (e.target.files?.[0] && void readList(e.target.files[0]), (e.target.value = ''))} />
                    </label>
                    {error && <InputError message={error} />}
                    {preview && (
                        <div className="grid gap-2 rounded-lg border border-border bg-card p-3 text-sm">
                            <p>
                                <strong>{preview.total}</strong> {who} on the list: {preview.existing} already have an account, <strong>{preview.new}</strong> {preview.new === 1 ? 'does' : 'do'} not.
                                {preview.invalid.length > 0 && <span className="text-status-warning"> {preview.invalid.length} line(s) skipped.</span>}
                            </p>
                            <ul className="max-h-40 overflow-y-auto text-xs">
                                {preview.sample.map((r) => (
                                    <li key={r.line} className="flex gap-2 border-b border-border py-1 last:border-0">
                                        <span className="w-10 text-muted-foreground">#{r.line}</span>
                                        <span className="flex-1 truncate">{r.name || '—'}</span>
                                        <span className="font-mono">{r.identifier || r.email}</span>
                                        <span className={r.has_account ? 'text-status-good' : 'text-brand'}>{r.has_account ? 'has account' : 'new'}</span>
                                    </li>
                                ))}
                            </ul>
                            {preview.invalid.length > 0 && (
                                <details className="text-xs">
                                    <summary className="cursor-pointer">Skipped lines</summary>
                                    {preview.invalid.slice(0, 50).map((i) => (
                                        <p key={i.line}>Line {i.line}: {i.reason}</p>
                                    ))}
                                </details>
                            )}
                            {preview.new > 0 && (
                                <div className="grid gap-1.5 rounded-md bg-muted/50 p-2">
                                    <label className="flex items-center gap-2"><input type="checkbox" checked={create} onChange={(e) => setCreate(e.target.checked)} /> Make accounts for the {preview.new} new {who}</label>
                                    {create && (
                                        <label className="flex flex-wrap items-center gap-2 text-xs">
                                            Their password:
                                            <select className={`${inputClass} h-8 !w-auto`} value={rule} onChange={(e) => setRule(e.target.value)}>
                                                <option value="file_or_matric">From the password column, or their matric number</option>
                                                <option value="matric">Their matric number</option>
                                                <option value="surname">Their surname, in small letters</option>
                                            </select>
                                        </label>
                                    )}
                                </div>
                            )}
                            <Button className="w-fit" disabled={busy || preview.total === 0} onClick={() => send({ source: 'upload', list_token: preview.token, create_missing: create, password_rule: rule })}>
                                <UserPlus className="size-4" /> Add {create ? preview.total : preview.existing} {who}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {mode === 'one' && (
                <div className="grid gap-2">
                    <div className="flex gap-2">
                        <input className={inputClass} placeholder="Name, matric number or e-mail" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), void search())} />
                        <Button type="button" variant="outline" onClick={() => void search()}><Search className="size-4" /> Find</Button>
                    </div>
                    <ul className="grid gap-1.5">
                        {found.map((u) => (
                            <li key={u.id} className="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
                                <span className="min-w-0 flex-1"><b className="block truncate font-medium">{u.name}</b><span className="font-mono text-xs text-muted-foreground">{u.identifier ?? u.email}</span></span>
                                <Button size="sm" disabled={busy} onClick={() => send({ source: 'ids', user_ids: [u.id] })}>Add</Button>
                            </li>
                        ))}
                    </ul>
                    {q && found.length === 0 && <p className="text-xs text-muted-foreground">Press Find. A student with no account yet can be added with “Upload a list”.</p>}
                </div>
            )}

            {mode === 'paste' && (
                <div className="grid gap-2">
                    <textarea className={`${textareaClass} font-mono`} placeholder={'VUG/NSC/22/0001\nVUG/NSC/22/0002'} value={paste} onChange={(e) => setPaste(e.target.value)} />
                    <p className="text-xs text-muted-foreground">Matric numbers or e-mails, one per line (a column copied from Excel works).</p>
                    <Button className="w-fit" disabled={busy || !paste.trim()} onClick={() => send({ source: 'list', identifiers: paste })}>Add</Button>
                </div>
            )}

            {mode === 'match' && (
                <div className="grid gap-2">
                    <label className="grid gap-1 text-sm">Matric numbers starting with<input className={`${inputClass} font-mono`} placeholder="VUG/NSC/22/" value={prefix} onChange={(e) => setPrefix(e.target.value)} /></label>
                    <label className="grid gap-1 text-sm">or e-mail addresses at<input className={inputClass} placeholder="students.example.edu.ng" value={domain} onChange={(e) => setDomain(e.target.value)} /></label>
                    <p className="text-xs text-muted-foreground">Every active {audience === 'staff' ? 'staff member' : 'student'} whose account matches is added.</p>
                    <Button className="w-fit" disabled={busy || (prefix.trim().length < 2 && domain.trim().length < 3)} onClick={() => send({ source: 'match', identifier_prefix: prefix.trim() || undefined, email_domain: domain.trim() || undefined })}>Add matching {who}</Button>
                </div>
            )}
        </div>
    );
}
