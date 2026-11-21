import '../../css/landing.css';
import { Head } from '@inertiajs/react';
import CampusLanding from '@/components/landing/campus';
import MidnightLanding from '@/components/landing/midnight';
import PaperLanding from '@/components/landing/paper';
import type { Plan } from '@/components/landing/sections';
import { Toaster } from '@/components/ui/sonner';

/** The public website, in the design the platform administrator chose (Platform branding). */
export default function Welcome({ plans = [], design = 'paper', demo = false }: { plans: Plan[]; design?: 'paper' | 'campus' | 'midnight'; demo?: boolean }) {
    return (
        <>
            <Head title="Computer-based exams for universities and colleges" />
            <div className={`landing landing-${design}`}>
                {design === 'campus' ? <CampusLanding plans={plans} demo={demo} /> : design === 'midnight' ? <MidnightLanding plans={plans} demo={demo} /> : <PaperLanding plans={plans} demo={demo} />}
            </div>
            <Toaster />
        </>
    );
}
