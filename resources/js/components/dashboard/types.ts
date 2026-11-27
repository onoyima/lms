import type { Day } from '@/components/dashboard/exam-day';

/** What the dashboard receives from DashboardController; shared by both dashboard layouts. */
export type ModuleCard = { key: string; name: string; description: string; enabled: boolean };
export type Decision = { kind: 'exam_day' | 'approvals' | 'requests'; tone: 'critical' | 'warning' | 'info'; icon: string; title: string; detail: string; href: string; action: string };
export type ExamWithPapers = { id: number; title: string; students: number; papers: { id: number; type: string; label: string; status: string; starts_at: string | null; ends_at: string | null; candidates: number; writing: number; submitted: number }[] };
export type Glance = { students: number; students_week: number; students_trend: number[]; pass_rate: number | null; pass_trend: number[]; results_to_approve: number | null; results_ready: number | null; open_incidents: number | null; urgent_incidents: number | null };
export type Panels = {
    institution?: Record<string, number | string | boolean | null>;
    upcoming?: { id: number; title: string; starts_at: string; ends_at: string; status: string; venue: string | null; candidates: number }[];
    oversight?: { scope: string; courses: number; reviews_waiting: number; results_to_approve: number; pass_rate: number | null; marking_backlog: number; courses_needing_attention: { id: number; code: string; title: string }[] };
    teaching?: { courses: number; to_mark: number; unanswered_threads: number; students: number };
    student?: { courses: number; unread: number; due: { id: number; title: string; type: string; due_at: string; code: string }[] | null; exams: { id: number; title: string; starts_at: string; ends_at?: string }[] | null };
    casework?: { requests?: number; hardware?: number; anomalies?: number } | null;
    day?: Day;
    decisions?: Decision[];
    exams?: ExamWithPapers[];
    glance?: Glance;
};
export type Layout = { hidden: string[]; order: string[] };
