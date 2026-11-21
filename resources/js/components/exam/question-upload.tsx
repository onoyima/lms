import { router } from '@inertiajs/react';
import { FileDown, ImagePlus, Upload } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

/** Every question template, in the order a setter usually needs them. */
export const QUESTION_TEMPLATES: [string, string][] = [
    ['questions_all_txt', 'Every type (.txt)'],
    ['questions_all_docx', 'Every type (Word)'],
    ['questions_all_pdf', 'Every type (PDF)'],
    ['questions_csv', 'Every type (CSV)'],
    ['questions_simple_txt', 'Simple objective (.txt)'],
    ['questions_mtf_txt', 'Multiple true/false (.txt)'],
    ['questions_csv_simple', 'Simple objective (CSV)'],
    ['questions_csv_pictures', 'With pictures (CSV)'],
    ['questions_pictures_zip', 'Sample pictures (.zip)'],
];

export function TemplateLinks({ keys = QUESTION_TEMPLATES }: { keys?: [string, string][] }) {
    return (
        <div className="flex flex-wrap gap-2">
            {keys.map(([k, label]) => (
                <a key={k} href={`/templates/${k}`} className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold shadow-[0_2px_6px_-3px_rgb(16_26_51/.3)] transition hover:border-brand hover:shadow-[0_8px_16px_-10px_rgb(16_26_51/.45)]">
                    <FileDown className="size-3.5 text-brand" /> {label}
                </a>
            ))}
        </div>
    );
}

const pdfText = async (file: File) => (await import('@/lib/pdf-pages')).pdfToText(file);

/**
 * Upload a question file (text, CSV, Word or PDF) and its pictures into a bank and, optionally, a
 * section of the paper. A PDF's text is read here in the browser; the server shows a preview of
 * every question before anything is saved.
 */
export function QuestionUpload({ bankId, sectionId, url = '/question-bank/import', onCancel }: { bankId?: number; sectionId?: number; url?: string; onCancel?: () => void }) {
    const [file, setFile] = useState<File | null>(null);
    const [pictures, setPictures] = useState<File[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const send = async () => {
        if (!file) {
return;
}

        setError(null);
        let upload = file;
        let name: string | undefined;

        if (/\.pdf$/i.test(file.name)) {
            setBusy('Reading the PDF…');

            try {
                const text = await pdfText(file);

                if (text.replace(/\s/g, '').length < 20) {
                    setBusy(null);
                    setError('This PDF has no typed text (it may be a scan or a photo). Use "Read with AI" under Question bank › Import, or upload the Word file.');

                    return;
                }

                upload = new File([text], file.name.replace(/\.pdf$/i, '') + '.txt', { type: 'text/plain' });
                name = file.name;
            } catch {
                setBusy(null);
                setError('That PDF could not be opened. If it has a password, remove it first.');

                return;
            }
        }

        setBusy('Uploading…');
        router.post(url, { file: upload, name, bank_id: bankId, section_id: sectionId, images: pictures, reader: /\.docx$/i.test(file.name) ? 'science' : 'standard' } as never, {
            forceFormData: true,
            onError: (e) => setError(Object.values(e)[0] ?? 'Upload failed.'),
            onFinish: () => setBusy(null),
        });
    };

    return (
        <form className="grid gap-3 rounded-xl border border-brand/35 bg-brand-soft/30 p-4" onSubmit={(e) => (e.preventDefault(), void send())}>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border border-dashed border-border bg-card p-4 text-center text-sm hover:border-brand/60">
                    <Upload className="size-5 text-brand" />
                    <span className="font-medium">{file ? file.name : 'Choose the question file'}</span>
                    <span className="text-xs text-muted-foreground">.txt, .csv, Word (.docx) or PDF</span>
                    <input type="file" className="sr-only" accept=".txt,.csv,.docx,.pdf,.md" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                </label>
                <label className="flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border border-dashed border-border bg-card p-4 text-center text-sm hover:border-brand/60">
                    <ImagePlus className="size-5 text-brand" />
                    <span className="font-medium">{pictures.length ? `${pictures.length} picture file${pictures.length === 1 ? '' : 's'}` : 'Pictures (optional)'}</span>
                    <span className="text-xs text-muted-foreground">Named on IMAGE: lines or in the CSV. One by one or a .zip</span>
                    <input type="file" multiple className="sr-only" accept=".zip,.png,.jpg,.jpeg,.gif,.webp" onChange={(e) => setPictures(Array.from(e.target.files ?? []))} />
                </label>
            </div>
            {error && <InputError message={error} />}
            <div className="flex flex-wrap items-center gap-2">
                <Button disabled={!file || !!busy}>{busy ?? 'Upload and preview'}</Button>
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                )}
                <span className="text-xs text-muted-foreground">You will see every question before anything is saved.</span>
            </div>
        </form>
    );
}
