import { Head, Link } from '@inertiajs/react';
import { Apple, Download, ExternalLink, KeyRound, Laptop, MonitorSmartphone, Settings } from 'lucide-react';
import { PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type System = { key: string; label: string; needs: string; file: { name: string; size: number; version: string | null } | null };

const ICONS: Record<string, typeof Laptop> = { windows: Laptop, macos: Apple, ios: MonitorSmartphone };
const mb = (b: number) => `${(b / 1048576).toFixed(0)} MB`;

/** Get Safe Exam Browser: from this institution's server where the exams office uploaded it, else from the SEB project. */
export default function SebDownloads({ systems, official, protected: isProtected, canManage }: { systems: System[]; official: string; protected: boolean; canManage: boolean }) {
    return (
        <>
            <Head title="Safe Exam Browser" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="Safe Exam Browser"
                    lead="A locked exam browser. While it is open nothing else runs on top of the exam: other apps, browser and system notifications, and phone notifications on the computer are kept out of sight."
                    actions={canManage ? <Button asChild variant="outline"><Link href="/admin/safe-exam-browser"><Settings className="size-4" /> Set up</Link></Button> : undefined}
                />
                <div className="grid gap-4 md:grid-cols-3">
                    {systems.map((s) => {
                        const Icon = ICONS[s.key] ?? Laptop;

                        return (
                            <section key={s.key} className="surface flex flex-col gap-3 p-5">
                                <span className="grid size-11 place-items-center rounded-2xl bg-brand/10 text-brand"><Icon className="size-5" /></span>
                                <div>
                                    <h2 className="text-lg font-semibold">{s.label}</h2>
                                    <p className="text-sm text-muted-foreground">{s.needs}</p>
                                </div>
                                <div className="mt-auto">
                                    {s.file ? (
                                        <Button asChild className="w-full">
                                            <a href={`/safe-exam-browser/${s.key}/download`}><Download className="size-4" /> Download{s.file.version ? ` ${s.file.version}` : ''} ({mb(s.file.size)})</a>
                                        </Button>
                                    ) : (
                                        <Button asChild variant="outline" className="w-full">
                                            <a href={official} target="_blank" rel="noreferrer"><ExternalLink className="size-4" /> {s.key === 'ios' ? 'App Store' : 'Official download'}</a>
                                        </Button>
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
                <section className="surface grid gap-4 p-6 md:grid-cols-2">
                    <div>
                        <h2 className="font-semibold">Taking an exam in Safe Exam Browser</h2>
                        <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm text-muted-foreground">
                            <li>Install Safe Exam Browser on your computer (once).</li>
                            <li>Open <b>My exams</b>, choose your exam and press <b>Open in Safe Exam Browser instead</b>. A small settings file downloads; open it.</li>
                            {isProtected && <li>Safe Exam Browser asks for the <b>school's SEB password</b>. Your invigilator or exams office gives it to you.</li>}
                            <li>Sign in with your own account and password, then start the exam as usual.</li>
                            <li>When you submit, Safe Exam Browser closes and your computer is back to normal.</li>
                        </ol>
                    </div>
                    <div className="rounded-2xl bg-foreground/[.04] p-5 text-sm">
                        <p className="flex items-center gap-2 font-semibold"><KeyRound className="size-4" /> What it keeps out</p>
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
                            <li>On Windows the exam runs on its own locked desktop: other programs and their notifications (including Phone Link) do not appear.</li>
                            <li>On Mac and iPad it uses Apple's assessment mode, which silences all notifications and blocks other apps.</li>
                            <li>Other websites, screenshots, screen sharing and extra screens are blocked.</li>
                        </ul>
                    </div>
                </section>
            </div>
        </>
    );
}
