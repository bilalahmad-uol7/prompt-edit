export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type TokenCounts = {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
};

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min_charge: string;
    estimated_max_charge: string;
    charged: string | null;
    tokens: TokenCounts | null;
    waiting_reason: string | null;
    error: { code: string | null; message: string | null } | null;
    can_retry: boolean;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
};

export type Option = { value: string; label: string };

export type Quote = {
    min_charge: string;
    max_charge: string;
    min_tokens: TokenCounts;
    max_tokens: TokenCounts;
    available: string;
    affordable: boolean;
};

export type Credits = {
    balance: string;
    held: string;
    available: string;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};
