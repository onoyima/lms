import {
    AudioLines,
    ClipboardList,
    FileArchive,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileType,
    LinkIcon,
    MessagesSquare,
    PenLine,
    Presentation,
    PlayCircle,
    Radio,
    BookOpen,
} from 'lucide-react';

/** What a course item is, from the reader's side: decides the icon, the label and the viewer. */
export type MaterialKind =
    | 'pdf'
    | 'word'
    | 'slides'
    | 'sheet'
    | 'image'
    | 'video'
    | 'audio'
    | 'text'
    | 'archive'
    | 'file'
    | 'youtube'
    | 'vimeo'
    | 'drive'
    | 'link'
    | 'live'
    | 'page'
    | 'assignment'
    | 'quiz'
    | 'forum';

type ItemLike = {
    type: string;
    file_name?: string | null;
    file_mime?: string | null;
    url?: string | null;
};

const EXT: Record<string, MaterialKind> = {
    pdf: 'pdf',
    doc: 'word',
    docx: 'word',
    rtf: 'word',
    odt: 'word',
    ppt: 'slides',
    pptx: 'slides',
    odp: 'slides',
    xls: 'sheet',
    xlsx: 'sheet',
    csv: 'sheet',
    ods: 'sheet',
    png: 'image',
    jpg: 'image',
    jpeg: 'image',
    gif: 'image',
    webp: 'image',
    svg: 'image',
    bmp: 'image',
    mp4: 'video',
    webm: 'video',
    mov: 'video',
    m4v: 'video',
    ogv: 'video',
    mp3: 'audio',
    m4a: 'audio',
    wav: 'audio',
    ogg: 'audio',
    aac: 'audio',
    txt: 'text',
    md: 'text',
    zip: 'archive',
    rar: 'archive',
    '7z': 'archive',
};

export function youTubeId(url: string): string | null {
    const m = url.match(
        /(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{6,15})/,
    );

    return m ? m[1] : null;
}

export function vimeoId(url: string): string | null {
    const m = url.match(/vimeo\.com\/(?:video\/)?(\d{5,12})/);

    return m ? m[1] : null;
}

export function driveId(url: string): string | null {
    const m = url.match(
        /drive\.google\.com\/(?:file\/d\/|open\?id=)([A-Za-z0-9_-]{10,})/,
    );

    return m ? m[1] : null;
}

export function materialKind(i: ItemLike): MaterialKind {
    if (['assignment', 'quiz', 'forum', 'live'].includes(i.type)) {
        return i.type as MaterialKind;
    }

    if (i.file_name) {
        const ext = i.file_name.split('.').pop()?.toLowerCase() ?? '';

        if (EXT[ext]) {
            return EXT[ext];
        }

        const mime = i.file_mime ?? '';

        if (mime.startsWith('video/')) {
            return 'video';
        }

        if (mime.startsWith('audio/')) {
            return 'audio';
        }

        if (mime.startsWith('image/')) {
            return 'image';
        }

        return 'file';
    }

    if (i.url) {
        if (youTubeId(i.url)) {
            return 'youtube';
        }

        if (vimeoId(i.url)) {
            return 'vimeo';
        }

        if (driveId(i.url)) {
            return 'drive';
        }

        return i.type === 'video' ? 'video' : 'link';
    }

    return i.type === 'video' ? 'video' : 'page';
}

export const KIND_LABEL: Record<MaterialKind, string> = {
    pdf: 'PDF',
    word: 'Word document',
    slides: 'Slides',
    sheet: 'Spreadsheet',
    image: 'Picture',
    video: 'Video',
    audio: 'Audio',
    text: 'Text',
    archive: 'Zip file',
    file: 'File',
    youtube: 'Video',
    vimeo: 'Video',
    drive: 'Google Drive file',
    link: 'Web link',
    live: 'Live class',
    page: 'Reading',
    assignment: 'Assignment',
    quiz: 'Quiz',
    forum: 'Discussion',
};

export const KIND_ICON: Record<MaterialKind, typeof FileText> = {
    pdf: FileType,
    word: FileText,
    slides: Presentation,
    sheet: FileSpreadsheet,
    image: FileImage,
    video: PlayCircle,
    audio: AudioLines,
    text: FileText,
    archive: FileArchive,
    file: FileText,
    youtube: PlayCircle,
    vimeo: PlayCircle,
    drive: FileText,
    link: LinkIcon,
    live: Radio,
    page: BookOpen,
    assignment: PenLine,
    quiz: ClipboardList,
    forum: MessagesSquare,
};

/** A soft colour per kind, so a long week of materials can be scanned at a glance. */
export const KIND_TONE: Record<MaterialKind, string> = {
    pdf: 'bg-red-500/12 text-red-700 dark:text-red-300',
    word: 'bg-blue-500/12 text-blue-700 dark:text-blue-300',
    slides: 'bg-orange-500/14 text-orange-700 dark:text-orange-300',
    sheet: 'bg-emerald-500/14 text-emerald-700 dark:text-emerald-300',
    image: 'bg-violet-500/12 text-violet-700 dark:text-violet-300',
    video: 'bg-rose-500/12 text-rose-700 dark:text-rose-300',
    youtube: 'bg-rose-500/12 text-rose-700 dark:text-rose-300',
    vimeo: 'bg-rose-500/12 text-rose-700 dark:text-rose-300',
    audio: 'bg-cyan-500/12 text-cyan-700 dark:text-cyan-300',
    text: 'bg-muted text-foreground',
    archive: 'bg-muted text-foreground',
    file: 'bg-muted text-foreground',
    drive: 'bg-amber-500/14 text-amber-700 dark:text-amber-300',
    link: 'bg-sky-500/12 text-sky-700 dark:text-sky-300',
    live: 'bg-rose-500/12 text-rose-700 dark:text-rose-300',
    page: 'bg-brand-soft text-brand-ink',
    assignment: 'bg-amber-500/14 text-amber-700 dark:text-amber-300',
    quiz: 'bg-indigo-500/12 text-indigo-700 dark:text-indigo-300',
    forum: 'bg-teal-500/12 text-teal-700 dark:text-teal-300',
};

export function fileSize(bytes: number | null | undefined): string {
    if (!bytes) {
        return '';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1048576).toFixed(bytes < 10 * 1048576 ? 1 : 0)} MB`;
}

/** Materials, as opposed to things a student does (assessments, discussions). */
export const isMaterial = (k: MaterialKind) =>
    !['assignment', 'quiz', 'forum', 'live'].includes(k);
