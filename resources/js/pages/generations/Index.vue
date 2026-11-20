<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import GenerationCard from '@/components/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import { create, index } from '@/routes/generations';
import type { Generation, GenerationStatus, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    filters: { status: GenerationStatus | null };
    hasInFlight: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const statusFilters: { value: GenerationStatus | null; label: string }[] = [
    { value: null, label: 'All' },
    { value: 'pending', label: 'Submitting' },
    { value: 'queued', label: 'Queued' },
    { value: 'processing', label: 'Processing' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

useLiveUpdates(() => props.hasInFlight, ['generations', 'hasInFlight']);
</script>

<template>
    <Head title="History" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every generation with its live status, estimate and what you were actually charged."
            />
            <Button as-child>
                <Link :href="create()">
                    <ImagePlus class="size-4" />
                    New generation
                </Link>
            </Button>
        </div>

        <nav class="flex flex-wrap gap-2" aria-label="Filter by status">
            <Link
                v-for="filter in statusFilters"
                :key="filter.label"
                :href="index({ query: { status: filter.value } })"
                preserve-scroll
                class="rounded-full border px-3 py-1 text-sm"
                :class="
                    filters.status === filter.value
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-accent'
                "
            >
                {{ filter.label }}
            </Link>
        </nav>

        <div
            v-if="generations.data.length"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="text-muted-foreground">No generations here yet.</p>
            <Button as-child variant="outline">
                <Link :href="create()">Create your first image</Link>
            </Button>
        </div>

        <PaginationLinks :links="generations.links" :meta="generations.meta" />
    </div>
</template>
