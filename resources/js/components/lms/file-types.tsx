import { cn } from '@/lib/utils';

/** Kinds of file a lecturer can accept for an assignment, in words a student understands. */
export const FILE_GROUPS: { key: string; label: string; types: string[] }[] = [
    { key: 'pdf', label: 'PDF', types: ['pdf'] },
    { key: 'word', label: 'Word', types: ['doc', 'docx', 'odt', 'rtf'] },
    { key: 'images', label: 'Pictures / scans', types: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'] },
    { key: 'slides', label: 'Slides', types: ['ppt', 'pptx', 'odp'] },
    { key: 'sheets', label: 'Spreadsheets', types: ['xls', 'xlsx', 'csv', 'ods'] },
    { key: 'text', label: 'Plain text', types: ['txt', 'md'] },
    { key: 'code', label: 'Program code', types: ['py', 'java', 'c', 'cpp', 'h', 'js', 'ts', 'php', 'ipynb', 'm', 'r', 'sql', 'html', 'css', 'go', 'rs', 'kt', 'swift', 'cs', 'ino'] },
    { key: 'design', label: 'Circuit / CAD', types: ['sch', 'brd', 'dxf', 'dwg', 'stl', 'step'] },
    { key: 'audio', label: 'Audio', types: ['mp3', 'wav', 'm4a', 'ogg'] },
    { key: 'video', label: 'Video', types: ['mp4', 'mov', 'webm'] },
    { key: 'archive', label: 'ZIP folder', types: ['zip', 'rar', '7z'] },
];

/** "PDF, Word or Pictures / scans" for a list of extensions. */
export function describeTypes(types: string[] | undefined): string {
    const list = (types ?? []).map((t) => t.toLowerCase());

    if (list.length === 0) {
        return 'any file';
    }

    const named = FILE_GROUPS.filter((g) => g.types.some((t) => list.includes(t)));
    const leftover = list.filter((t) => !FILE_GROUPS.some((g) => g.types.includes(t)));
    const words = [...named.map((g) => g.label), ...leftover.map((t) => `.${t}`)];

    return words.length <= 1 ? words.join('') : `${words.slice(0, -1).join(', ')} or ${words.at(-1)}`;
}

/** The browser's file picker shows only these kinds. */
export const acceptFor = (types: string[] | undefined) => (types ?? []).map((t) => `.${t}`).join(',');

/** Chips to choose which kinds of file an assignment accepts. */
export function FileTypePicker({ value, onChange }: { value: string[]; onChange: (types: string[]) => void }) {
    const on = (g: (typeof FILE_GROUPS)[number]) => g.types.every((t) => value.includes(t));

    return (
        <div className="flex flex-wrap gap-1.5">
            {FILE_GROUPS.map((g) => (
                <button key={g.key} type="button" aria-pressed={on(g)}
                    onClick={() => onChange(on(g) ? value.filter((t) => !g.types.includes(t)) : [...new Set([...value, ...g.types])])}
                    className={cn('rounded-full border px-3 py-1 text-xs font-medium transition-colors', on(g) ? 'border-brand bg-brand-soft text-brand-ink' : 'border-border text-muted-foreground hover:text-foreground')}>
                    {g.label}
                </button>
            ))}
        </div>
    );
}
