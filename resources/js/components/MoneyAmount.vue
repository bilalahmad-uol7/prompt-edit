<script setup lang="ts">
import { computed } from 'vue';
import { exactUsd, formatUsd } from '@/lib/money';

const props = withDefaults(
    defineProps<{
        amount: string | null | undefined;
        decimals?: number;
    }>(),
    { decimals: 4 },
);

const formatted = computed(() => formatUsd(props.amount, props.decimals));
const exact = computed(() => exactUsd(props.amount));
</script>

<template>
    <span class="tabular-nums" :title="exact">{{ formatted }}</span>
</template>
