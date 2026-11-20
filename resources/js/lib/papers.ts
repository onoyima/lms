import { FlaskConical, GraduationCap, Layers, ListChecks, Mic, PenLine } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** How each kind of paper is shown: its icon, a one-line explanation and a usual length. */
export const paperLook: Record<string, { icon: LucideIcon; hint: string; minutes: number }> = {
    cbt: { icon: ListChecks, hint: 'Multiple choice and other questions marked by the computer', minutes: 60 },
    essay: { icon: PenLine, hint: 'Written answers marked by examiners', minutes: 120 },
    mixed: { icon: Layers, hint: 'Objective and written questions in one paper', minutes: 120 },
    practical: { icon: FlaskConical, hint: 'Stations or tasks scored by examiners', minutes: 90 },
    viva: { icon: Mic, hint: 'Oral exam scored by a panel', minutes: 30 },
    research: { icon: GraduationCap, hint: 'Project or thesis defence scored by examiners', minutes: 60 },
};

export const isOnScreen = (type: string) => ['cbt', 'essay', 'mixed'].includes(type);
