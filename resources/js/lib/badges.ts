import { Award, BookOpen, Medal, Shield, Star, Trophy } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/** The symbols a badge can carry (CertificateTemplate::ICONS). */
export const BADGE_ICONS: Record<string, LucideIcon> = { award: Award, medal: Medal, star: Star, shield: Shield, book: BookOpen, trophy: Trophy };
