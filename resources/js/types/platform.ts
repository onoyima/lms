export type TenantBrand = {
    name: string;
    short_name: string;
    slug: string;
    brand: string;
    brand_foreground: string;
    logo_url: string | null;
    accent: string | null;
    accent_foreground: string | null;
    favicon_url: string | null;
    login_title: string | null;
    login_message: string | null;
    welcome_message: string | null;
    contact: { email?: string; phone?: string; address?: string } | null;
};

export type PlatformBrand = {
    name: string;
    short_name: string;
    tagline: string;
    brand: string;
    brand_foreground: string;
    logo_url: string | null;
    favicon_url: string | null;
    support_email: string;
};

export type QuestionOption = {
    label: string;
    html: string;
    correct: boolean;
};

export type ParsedQuestion = {
    number: number;
    type: string;
    stem_html: string;
    options: QuestionOption[];
    explanation_html: string | null;
    answer_text: string | null;
    mark: number;
    topic: string | null;
    difficulty: string | null;
    warnings: string[];
};

export type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};
