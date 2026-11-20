<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ImageOff, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { Button } from '@/components/ui/button';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const imageFailed = ref(false);
watch(
    () => props.generation.image_url,
    () => (imageFailed.value = false),
);

const inFlight = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.generation.status),
);

const aspect = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size] ?? 'aspect-square',
);

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(),
);
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card">
        <div
            class="relative flex items-center justify-center bg-muted/50"
            :class="aspect"
        >
            <img
                v-if="generation.image_url && !imageFailed"
                :src="generation.image_url"
                :alt="generation.prompt"
                loading="lazy"
                class="h-full w-full object-cover"
                @error="imageFailed = true"
            />
            <div
                v-else-if="generation.status === 'succeeded'"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <span v-if="generation.image_expired">
                    The image link expired before we could save a copy.
                </span>
                <span v-else>Image unavailable right now.</span>
            </div>
            <div
                v-else-if="inFlight"
                class="h-full w-full animate-pulse bg-gradient-to-br from-muted to-muted/30"
            />
            <div
                v-else
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <span>No image</span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <GenerationStatusBadge :status="generation.status" />
                <span class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }}
                </span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p
                v-if="generation.waiting_reason"
                class="text-xs text-amber-700 dark:text-amber-300"
            >
                {{ generation.waiting_reason }}
            </p>

            <p
                v-if="generation.error"
                class="text-xs text-red-700 dark:text-red-300"
            >
                {{ generation.error.message }}
            </p>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimate</dt>
                    <dd>
                        <MoneyAmount
                            :amount="generation.estimated_min_charge"
                        />
                        –
                        <MoneyAmount
                            :amount="generation.estimated_max_charge"
                        />
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Charged</dt>
                    <dd class="font-medium">
                        <MoneyAmount
                            v-if="generation.charged !== null"
                            :amount="generation.charged"
                        />
                        <span v-else class="text-muted-foreground">
                            Pending
                        </span>
                    </dd>
                </div>
            </dl>

            <details v-if="generation.tokens" class="text-xs">
                <summary class="cursor-pointer text-muted-foreground">
                    Token usage
                </summary>
                <ul class="mt-1 space-y-0.5 tabular-nums">
                    <li>Text: {{ generation.tokens.input_text_tokens }}</li>
                    <li>
                        Reference images:
                        {{ generation.tokens.input_image_tokens }}
                    </li>
                    <li>Output: {{ generation.tokens.output_tokens }}</li>
                    <li>Reasoning: {{ generation.tokens.reasoning_tokens }}</li>
                </ul>
            </details>

            <div class="flex items-center justify-between">
                <time
                    class="text-xs text-muted-foreground"
                    :datetime="generation.created_at"
                >
                    {{ createdAt }}
                </time>
                <Button
                    v-if="generation.can_retry"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        v-bind="GenerationController.retry(generation.id)"
                        as="button"
                        preserve-scroll
                    >
                        <RotateCcw class="size-3.5" />
                        Retry
                    </Link>
                </Button>
            </div>
        </div>
    </article>
</template>
