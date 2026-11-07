import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, formatDateTime, inputClass, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

export type Server = {
    id: number; name: string; base_url: string; checksum_algo: string; enabled: boolean; weight: number; max_participants: number | null; status: string;
    version: string | null; live_meetings: number; live_participants: number; last_error: string | null; checked_at: string | null; webhook_url: string;
};

/** BigBlueButton servers: add, check, register the webhook, switch off. The secret is write-only. */
export function ServerList({ servers, base }: { servers: Server[]; base: string }) {
    const [editing, setEditing] = useState<Server | 'new' | null>(servers.length ? null : 'new');

    return (
        <section className="grid gap-3">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-medium">Meeting servers (BigBlueButton)</h2>
                <Button size="sm" variant="outline" onClick={() => setEditing(editing ? null : 'new')}>{editing ? 'Close' : 'Add server'}</Button>
            </div>
            {editing && <ServerForm key={editing === 'new' ? 'new' : editing.id} server={editing === 'new' ? null : editing} base={base} onDone={() => setEditing(null)} />}
            <Table head={['Server', 'Status', 'Now', 'Last checked', '']}>
                {servers.length === 0 && <tr><Td className="text-muted-foreground">No server yet.</Td></tr>}
                {servers.map((s) => (
                    <tr key={s.id}>
                        <Td><span className="font-medium">{s.name}</span><span className="block font-mono text-xs text-muted-foreground">{s.base_url}</span>{s.last_error && <span className="block text-xs text-status-critical">{s.last_error}</span>}</Td>
                        <Td><Status value={s.enabled ? (s.status === 'unknown' ? 'pending' : s.status === 'online' ? 'active' : 'offline') : 'inactive'} />{s.version && <span className="ml-1 text-xs text-muted-foreground">{s.version}</span>}</Td>
                        <Td className="tabular-nums text-xs">{s.live_meetings} meetings · {s.live_participants} people</Td>
                        <Td className="text-xs">{s.checked_at ? formatDateTime(s.checked_at) : 'never'}</Td>
                        <Td>
                            <div className="flex flex-wrap gap-1">
                                <Button size="sm" variant="outline" onClick={() => router.post(`${base}/servers/${s.id}/check`, {}, { preserveScroll: true })}>Check</Button>
                                <Button size="sm" variant="ghost" onClick={() => router.post(`${base}/servers/${s.id}/check`, { register_webhook: true }, { preserveScroll: true })}>Register webhook</Button>
                                <Button size="sm" variant="ghost" onClick={() => setEditing(s)}>Edit</Button>
                                <Button size="sm" variant="ghost" className="text-status-critical" onClick={() => confirm(`Remove ${s.name}?`) && router.delete(`${base}/servers/${s.id}`, { preserveScroll: true })}>Remove</Button>
                            </div>
                        </Td>
                    </tr>
                ))}
            </Table>
            <p className="text-xs text-muted-foreground">Attendance comes from the server's webhooks (the bbb-webhooks package). "Register webhook" asks the server to send them here; webhook address for each server: {servers[0]?.webhook_url ?? '…/api/conferencing/webhooks/{server}'}.</p>
        </section>
    );
}

function ServerForm({ server, base, onDone }: { server: Server | null; base: string; onDone: () => void }) {
    const form = useForm({ name: server?.name ?? '', base_url: server?.base_url ?? 'https://', secret: '', checksum_algo: server?.checksum_algo ?? 'sha256', enabled: server?.enabled ?? true, weight: server?.weight ?? 100, max_participants: server?.max_participants ?? '' });

    return (
        <form className="grid gap-3 rounded-lg border border-border bg-card p-4 md:grid-cols-3" onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({ ...d, max_participants: d.max_participants === '' ? null : Number(d.max_participants) }));

            if (server) {
                form.put(`${base}/servers/${server.id}`, { preserveScroll: true, onSuccess: onDone });
            } else {
                form.post(`${base}/servers`, { preserveScroll: true, onSuccess: onDone });
            }
        }}>
            <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
            <div className="md:col-span-2"><Field label="Address" hint="e.g. https://bbb.example.edu/bigbluebutton/" error={form.errors.base_url}><input className={`${inputClass} font-mono`} value={form.data.base_url} onChange={(e) => form.setData('base_url', e.target.value)} required /></Field></div>
            <Field label={server ? 'Shared secret (leave blank to keep)' : 'Shared secret'} hint="From `bbb-conf --secret` on the server. Stored encrypted." error={form.errors.secret}>
                <input type="password" autoComplete="off" className={`${inputClass} font-mono`} value={form.data.secret} onChange={(e) => form.setData('secret', e.target.value)} required={!server} />
            </Field>
            <Field label="Checksum" error={form.errors.checksum_algo}><select className={inputClass} value={form.data.checksum_algo} onChange={(e) => form.setData('checksum_algo', e.target.value)}>{['sha256', 'sha1', 'sha384', 'sha512'].map((a) => <option key={a}>{a}</option>)}</select></Field>
            <Field label="Share of meetings (weight)" error={form.errors.weight}><input type="number" min={1} max={1000} className={inputClass} value={form.data.weight} onChange={(e) => form.setData('weight', Number(e.target.value))} /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} /> In use</label>
            <div className="md:col-span-3"><Button disabled={form.processing}>{server ? 'Save' : 'Add server'}</Button></div>
        </form>
    );
}
