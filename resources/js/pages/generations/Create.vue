<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CircleAlert, Plus, Sparkles, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import QuoteController from '@/actions/App/Http/Controllers/QuoteController';
import GenerationCard from '@/components/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import { index as creditsIndex } from '@/routes/credits';
import { create, index as generationsIndex } from '@/routes/generations';
import type { Generation, Option, Quote } from '@/types';

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
    limits: { prompt: number; referenceImages: number };
    markupPercent: number;
    pricingError: string | null;
    recent: Generation[];
    hasInFlight: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const page = usePage();
const credits = computed(() => page.props.credits);

const newRequestId = (): string =>
    globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () =>
        Math.floor(Math.random() * 16).toString(16),
    );

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
    // Lets the server recognise a double-submitted form and create only one generation.
    client_request_id: newRequestId(),
});

// Pixelforge counts Unicode characters (code points), not UTF-16 units as String.length does.
const promptLength = computed(() => [...form.prompt].length);
const promptTooLong = computed(() => promptLength.value > props.limits.prompt);
const filledReferences = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

const quote = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    },
    Quote
>({ prompt: '', size: '', quality: '', reference_images: [] });

const refreshQuote = useDebounceFn(() => {
    if (props.pricingError) {
        return;
    }

    quote.prompt = form.prompt;
    quote.size = form.size;
    quote.quality = form.quality;
    quote.reference_images = filledReferences.value;

    quote.cancel();
    quote.post(QuoteController.url()).catch(() => {
        // Validation errors are exposed on quote.errors; anything else leaves the last quote shown.
    });
}, 300);

watch(
    () => [form.prompt, form.size, form.quality, filledReferences.value.length],
    refreshQuote,
    { immediate: true },
);

// Keep the affordability check fresh as other generations settle and release their holds.
watch(() => credits.value?.available, refreshQuote);

const estimate = computed(() => quote.response);
const creditsError = computed(
    () => (form.errors as Record<string, string | undefined>).credits,
);
const cannotAfford = computed(
    () => estimate.value !== null && !estimate.value.affordable,
);

const canSubmit = computed(
    () =>
        !form.processing &&
        !props.pricingError &&
        form.prompt.trim() !== '' &&
        !promptTooLong.value &&
        !cannotAfford.value,
);

// Bound rather than a static attribute: vue-tsc misreads "//" in static component attributes.
const referencePlaceholder = 'https://example.com/reference.png';

function addReference(): void {
    if (form.reference_images.length < props.limits.referenceImages) {
        form.reference_images.push('');
    }
}

function removeReference(position: number): void {
    form.reference_images.splice(position, 1);
}

function submit(): void {
    form.post(GenerationController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
            form.client_request_id = newRequestId();
        },
    });
}

function referenceError(position: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `reference_images.${position}`
    ];
}

useLiveUpdates(() => props.hasInFlight, ['recent', 'hasInFlight']);
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe what you want. You see the price range before you submit and only pay what it actually costs."
        />

        <Alert v-if="pricingError" variant="destructive">
            <CircleAlert />
            <AlertTitle>Generation is paused</AlertTitle>
            <AlertDescription>{{ pricingError }}</AlertDescription>
        </Alert>

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs tabular-nums"
                            :class="
                                promptTooLong
                                    ? 'text-red-600'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ promptLength.toLocaleString() }} /
                            {{ limits.prompt.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        required
                        placeholder="A lighthouse on a cliff at dusk, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        :aria-invalid="!!form.errors.prompt || promptTooLong"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in sizes"
                            :key="size.value"
                            class="flex cursor-pointer flex-col items-center gap-1 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            <span class="font-medium">{{ size.label }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                size.value
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in qualities"
                            :key="quality.value"
                            class="flex cursor-pointer items-center justify-center rounded-md border p-3 text-sm font-medium has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            {{ quality.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label
                            >Reference images
                            <span class="font-normal text-muted-foreground"
                                >(optional)</span
                            ></Label
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                form.reference_images.length >=
                                limits.referenceImages
                            "
                            @click="addReference"
                        >
                            <Plus class="size-3.5" />
                            Add URL
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Up to {{ limits.referenceImages }} public http(s) image
                        URLs. Each one adds a fixed amount of input tokens.
                    </p>
                    <div
                        v-for="(url, position) in form.reference_images"
                        :key="position"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                v-model="form.reference_images[position]"
                                type="url"
                                inputmode="url"
                                :placeholder="referencePlaceholder"
                                :aria-label="`Reference image ${position + 1}`"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${position + 1}`"
                                @click="removeReference(position)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                        <InputError :message="referenceError(position)" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                </div>
            </div>

            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                <div class="space-y-4 rounded-xl border bg-card p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-medium">Estimated cost</h2>
                        <Spinner
                            v-if="quote.processing"
                            class="size-4 text-muted-foreground"
                        />
                    </div>

                    <p v-if="estimate" class="text-2xl font-semibold">
                        <MoneyAmount :amount="estimate.min_charge" />
                        <span class="text-muted-foreground"> – </span>
                        <MoneyAmount :amount="estimate.max_charge" />
                    </p>
                    <p
                        v-else
                        class="text-2xl font-semibold text-muted-foreground"
                    >
                        —
                    </p>

                    <p class="text-xs text-muted-foreground">
                        Text and reasoning tokens are only known once the image
                        is done, so this is a range. You pay the actual provider
                        cost plus {{ markupPercent }}% and never more than the
                        upper bound. Failed generations are free.
                    </p>

                    <dl
                        v-if="estimate"
                        class="grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-3 text-xs tabular-nums"
                    >
                        <dt class="text-muted-foreground">Text tokens</dt>
                        <dd class="text-right">
                            {{ estimate.min_tokens.input_text_tokens }}–{{
                                estimate.max_tokens.input_text_tokens
                            }}
                        </dd>
                        <dt class="text-muted-foreground">
                            Reference image tokens
                        </dt>
                        <dd class="text-right">
                            {{ estimate.max_tokens.input_image_tokens }}
                        </dd>
                        <dt class="text-muted-foreground">Output tokens</dt>
                        <dd class="text-right">
                            {{ estimate.max_tokens.output_tokens }}
                        </dd>
                        <dt class="text-muted-foreground">Reasoning tokens</dt>
                        <dd class="text-right">
                            {{ estimate.min_tokens.reasoning_tokens }}–{{
                                estimate.max_tokens.reasoning_tokens
                            }}
                        </dd>
                    </dl>

                    <div
                        class="flex items-center justify-between border-t pt-3 text-sm"
                    >
                        <span class="text-muted-foreground"
                            >Available credit</span
                        >
                        <MoneyAmount
                            :amount="credits?.available"
                            class="font-medium"
                        />
                    </div>

                    <Alert
                        v-if="cannotAfford || creditsError"
                        variant="destructive"
                    >
                        <CircleAlert />
                        <AlertTitle>Not enough credit</AlertTitle>
                        <AlertDescription>
                            <span v-if="creditsError">{{ creditsError }}</span>
                            <span v-else>
                                This generation can cost up to
                                <MoneyAmount :amount="estimate?.max_charge" />.
                            </span>
                            <Link
                                :href="creditsIndex()"
                                class="font-medium underline underline-offset-4"
                            >
                                Buy credits
                            </Link>
                        </AlertDescription>
                    </Alert>

                    <Button type="submit" class="w-full" :disabled="!canSubmit">
                        <Spinner v-if="form.processing" />
                        <Sparkles v-else class="size-4" />
                        Generate
                    </Button>
                </div>
            </aside>
        </form>

        <section v-if="recent.length" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Recent</h2>
                <Link
                    :href="generationsIndex()"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    View all history
                </Link>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <GenerationCard
                    v-for="generation in recent"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
        </section>
    </div>
</template>
