<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const config = computed(
    () =>
        ({
            pending: {
                label: 'Submitting',
                icon: Clock,
                class: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200',
                spin: false,
            },
            queued: {
                label: 'Queued',
                icon: Clock,
                class: 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-200',
                spin: false,
            },
            processing: {
                label: 'Processing',
                icon: LoaderCircle,
                class: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-200',
                spin: true,
            },
            succeeded: {
                label: 'Succeeded',
                icon: CircleCheck,
                class: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200',
                spin: false,
            },
            failed: {
                label: 'Failed',
                icon: CircleX,
                class: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200',
                spin: false,
            },
        })[props.status],
);
</script>

<template>
    <Badge
        variant="outline"
        :class="['gap-1 border-transparent', config.class]"
    >
        <component
            :is="config.icon"
            class="size-3"
            :class="{ 'animate-spin': config.spin }"
        />
        {{ config.label }}
    </Badge>
</template>
