import { Head, router } from '@inertiajs/react';
import { Copy, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, inputClass, PageHeader, Status, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { useRoleLabel } from '@/lib/role-label';

type Rule = { attribute: string; value: string; role: string };

type Provider = {
    id?: number;
    type: 'saml' | 'oidc';
    name: string;
    slug: string;
    enabled: boolean;
    auto_create: boolean;
    default_user_type: 'student' | 'staff';
    default_role: string | null;
    link_by_email: boolean;
    trust_idp_mfa: boolean;
    role_rules?: Rule[] | null;
    type_attribute?: string | null;
    config: Record<string, unknown> & { has_secret?: boolean; claims?: Record<string, string>; attributes?: Record<string, string> };
    urls?: Record<string, string>;
};

const blank: Provider = { type: 'oidc', name: '', slug: '', enabled: false, auto_create: false, default_user_type: 'student', default_role: 'student', link_by_email: true, trust_idp_mfa: false, config: { scopes: 'openid email profile' } };

function Editor({ initial, roles, onDone }: { initial: Provider; roles: string[]; onDone: () => void }) {
    const [p, setP] = useState<Provider>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const label = useRoleLabel();
    const rules = p.role_rules ?? [];
    const setRule = (i: number, k: keyof Rule, v: string) => setP({ ...p, role_rules: rules.map((r, j) => (j === i ? { ...r, [k]: v } : r)) });
    const c = p.config as Record<string, string>;
    const setC = (k: string, v: unknown) => setP({ ...p, config: { ...p.config, [k]: v } });
    const map = p.type === 'oidc' ? 'claims' : 'attributes';
    const setMap = (k: string, v: string) => setC(map, { ...((p.config[map] as Record<string, string>) ?? {}), [k]: v });
    const save = () => {
        const payload = { ...p, config: { ...p.config, has_secret: undefined } };
        const opts = { preserveScroll: true, onError: (e: Record<string, string>) => setErrors(e), onSuccess: onDone };

        if (p.id) {
            router.put(`/admin/sso/${p.id}`, payload as never, opts);
        } else {
            router.post('/admin/sso', payload as never, opts);
        }
    };

    return (
        <div className="surface grid gap-4 p-6">
            <div className="grid gap-3 md:grid-cols-4">
                <Field label="Type">
                    <select className={inputClass} value={p.type} disabled={!!p.id} onChange={(e) => setP({ ...p, type: e.target.value as Provider['type'] })}>
                        <option value="oidc">OpenID Connect (Microsoft Entra, Google, Keycloak)</option>
                        <option value="saml">SAML 2.0 (ADFS, Shibboleth, Entra)</option>
                    </select>
                </Field>
                <Field label="Button name" error={errors.name}><input className={inputClass} value={p.name} onChange={(e) => setP({ ...p, name: e.target.value })} placeholder="University account" /></Field>
                <Field label="Short name in links" error={errors.slug}><input className={inputClass} value={p.slug} onChange={(e) => setP({ ...p, slug: e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, '') })} placeholder="university" /></Field>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={p.enabled} onChange={(e) => setP({ ...p, enabled: e.target.checked })} /> Show on the sign-in page</label>
            </div>
            {p.type === 'oidc' ? (
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="Issuer (https)" error={errors['config.issuer']}><input className={inputClass} value={c.issuer ?? ''} onChange={(e) => setC('issuer', e.target.value)} placeholder="https://login.microsoftonline.com/<tenant-id>/v2.0" /></Field>
                    <Field label="Client ID" error={errors['config.client_id']}><input className={inputClass} value={c.client_id ?? ''} onChange={(e) => setC('client_id', e.target.value)} /></Field>
                    <Field label={p.config.has_secret ? 'Client secret (saved — leave empty to keep)' : 'Client secret'}><input type="password" autoComplete="new-password" className={inputClass} value={c.client_secret ?? ''} onChange={(e) => setC('client_secret', e.target.value)} /></Field>
                    <Field label="Scopes"><input className={inputClass} value={c.scopes ?? ''} onChange={(e) => setC('scopes', e.target.value)} /></Field>
                </div>
            ) : (
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="IdP entity ID" error={errors['config.idp_entity_id']}><input className={inputClass} value={c.idp_entity_id ?? ''} onChange={(e) => setC('idp_entity_id', e.target.value)} /></Field>
                    <Field label="IdP sign-in URL (https)" error={errors['config.idp_sso_url']}><input className={inputClass} value={c.idp_sso_url ?? ''} onChange={(e) => setC('idp_sso_url', e.target.value)} /></Field>
                    <div className="md:col-span-2">
                        <Field label="IdP signing certificate" error={errors['config.idp_x509']}><textarea className={`${textareaClass} font-mono text-xs`} value={c.idp_x509 ?? ''} onChange={(e) => setC('idp_x509', e.target.value)} placeholder="-----BEGIN CERTIFICATE-----" /></Field>
                    </div>
                </div>
            )}
            <div className="grid gap-3 md:grid-cols-3">
                {['email', 'name', 'identifier'].map((k) => (
                    <Field key={k} label={`${p.type === 'oidc' ? 'Claim' : 'Attribute'} for ${k === 'identifier' ? 'matric / staff number' : k}`}>
                        <input className={inputClass} value={((p.config[map] as Record<string, string>) ?? {})[k] ?? ''} onChange={(e) => setMap(k, e.target.value)} placeholder={k === 'email' ? 'email' : k === 'name' ? (p.type === 'oidc' ? 'name' : 'displayName') : 'optional'} />
                    </Field>
                ))}
            </div>
            <div className="grid gap-2 text-sm md:grid-cols-2">
                <label className="flex items-center gap-2"><input type="checkbox" checked={p.link_by_email} onChange={(e) => setP({ ...p, link_by_email: e.target.checked })} /> On first sign-in, match an existing account by verified email</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={p.trust_idp_mfa} onChange={(e) => setP({ ...p, trust_idp_mfa: e.target.checked })} /> The provider already enforces two-factor sign-in</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={p.auto_create} onChange={(e) => setP({ ...p, auto_create: e.target.checked })} /> Create accounts for unknown people (allowed email domains only)</label>
                {p.auto_create && (
                    <span className="flex gap-2">
                        <select className={inputClass} value={p.default_user_type} onChange={(e) => setP({ ...p, default_user_type: e.target.value as Provider['default_user_type'] })}><option value="student">as students</option><option value="staff">as staff</option></select>
                        <select className={inputClass} value={p.default_role ?? ''} onChange={(e) => setP({ ...p, default_role: e.target.value || null })}><option value="">no role</option>{roles.map((r) => <option key={r} value={r}>{label(r)}</option>)}</select>
                    </span>
                )}
            </div>
            <div className="grid gap-3 rounded-xl border border-border p-4">
                <div>
                    <p className="font-medium">Roles from your directory</p>
                    <p className="text-sm text-muted-foreground">
                        Give roles automatically from the groups or attributes your provider sends — for example, everyone in the group "Exams Office" becomes an exams officer. Checked at every sign-in (and at every directory sync): roles named in these rules follow the directory, given when a rule matches and taken away when it no longer does. Roles that control settings, people, roles, integrations or billing can only be given by hand under People.
                    </p>
                </div>
                {rules.map((r, i) => (
                    <div key={i} className="grid items-end gap-2 md:grid-cols-[1fr_1fr_1fr_auto]">
                        <Field label={p.type === 'oidc' ? 'Claim (e.g. groups, roles)' : 'Attribute (e.g. memberOf)'} error={errors[`role_rules.${i}.attribute`]}><input className={inputClass} value={r.attribute} onChange={(e) => setRule(i, 'attribute', e.target.value)} placeholder="groups" /></Field>
                        <Field label="Contains the value" error={errors[`role_rules.${i}.value`]}><input className={inputClass} value={r.value} onChange={(e) => setRule(i, 'value', e.target.value)} placeholder="Exams Office" /></Field>
                        <Field label="Gives the role" error={errors[`role_rules.${i}.role`]}>
                            <select className={inputClass} value={r.role} onChange={(e) => setRule(i, 'role', e.target.value)}>{roles.map((x) => <option key={x} value={x}>{label(x)}</option>)}</select>
                        </Field>
                        <Button variant="ghost" size="sm" aria-label="Remove rule" onClick={() => setP({ ...p, role_rules: rules.filter((_, j) => j !== i) })}><Trash2 className="size-4" /></Button>
                    </div>
                ))}
                <div className="flex flex-wrap items-end gap-3">
                    <Button size="sm" variant="outline" disabled={roles.length === 0} onClick={() => setP({ ...p, role_rules: [...rules, { attribute: p.type === 'oidc' ? 'groups' : 'memberOf', value: '', role: roles[0] }] })}><Plus className="size-4" /> Add rule</Button>
                    <Field label="Attribute that says staff or student (optional)" error={errors.type_attribute}>
                        <input className={inputClass} value={p.type_attribute ?? ''} onChange={(e) => setP({ ...p, type_attribute: e.target.value || null })} placeholder="eduPersonAffiliation" />
                    </Field>
                </div>
            </div>
            {Object.keys(errors).length > 0 && <p className="text-sm text-status-critical">{Object.values(errors).join(' ')}</p>}
            <div className="flex gap-2">
                <Button onClick={save}>Save</Button>
                <Button variant="outline" onClick={onDone}>Cancel</Button>
            </div>
        </div>
    );
}

export default function Sso({ providers, roles }: { providers: Provider[]; roles: string[] }) {
    const [editing, setEditing] = useState<Provider | null>(null);

    return (
        <>
            <Head title="Single sign-on" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Single sign-on"
                    lead="Let people sign in with the institution's own accounts. Passwords never pass through this platform; the provider's signed answer is checked here."
                    actions={!editing && <Button onClick={() => setEditing(blank)}><Plus className="size-4" /> Add provider</Button>}
                />
                <Flash />
                {editing && <Editor key={editing.id ?? 'new'} initial={editing} roles={roles} onDone={() => setEditing(null)} />}
                {providers.length === 0 && !editing && <div className="surface p-10 text-center text-sm text-muted-foreground">No providers yet. People sign in with their password (and two-factor where required).</div>}
                <div className="grid gap-4 lg:grid-cols-2">
                    {providers.map((p) => (
                        <article key={p.id} className="surface grid gap-3 p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="font-medium">{p.name}</p>
                                    <p className="text-xs text-muted-foreground">{p.type === 'oidc' ? 'OpenID Connect' : 'SAML 2.0'} · /sso/{p.slug}</p>
                                </div>
                                <Status value={p.enabled ? 'enabled' : 'disabled'} />
                            </div>
                            <div className="grid gap-1 text-xs">
                                <p className="eyebrow">Give these to the provider's administrator</p>
                                {Object.entries(p.urls ?? {}).map(([k, v]) => (
                                    <p key={k} className="flex items-center justify-between gap-2">
                                        <span className="text-muted-foreground">{k}</span>
                                        <button className="flex items-center gap-1 font-mono" onClick={() => navigator.clipboard?.writeText(v)} title="Copy">{v} <Copy className="size-3" /></button>
                                    </p>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <Button size="sm" variant="outline" onClick={() => setEditing(p)}>Edit</Button>
                                <Button size="sm" variant="ghost" aria-label="Remove" onClick={() => confirm(`Remove ${p.name}? People who only sign in through it will need a password.`) && router.delete(`/admin/sso/${p.id}`)}><Trash2 className="size-4" /></Button>
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}

Sso.layout = { breadcrumbs: [{ title: 'Single sign-on', href: '/admin/sso' }] };
