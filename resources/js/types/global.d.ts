import type { Auth } from '@/types/auth';
import type { PlatformBrand, TenantBrand } from '@/types/platform';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            tenant: TenantBrand | null;
            platform: PlatformBrand;
            modules: string[];
            flash: { success?: string | null; error?: string | null };
            [key: string]: unknown;
        };
    }
}
