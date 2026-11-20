<script setup lang="ts">
import MoneyAmount from '@/components/MoneyAmount.vue';

/**
 * A labelled headline money figure. Pass `amount: null` to show a dash.
 */
defineProps<{
    label: string;
    amount: string | null | undefined;
}>();
</script>

<template>
    <div class="rounded-xl border bg-card p-5">
        <p class="text-sm text-muted-foreground">{{ label }}</p>
        <p class="mt-1 text-2xl font-semibold">
            <MoneyAmount
                v-if="amount !== null && amount !== undefined"
                :amount="amount"
            />
            <span v-else class="text-muted-foreground">—</span>
        </p>
    </div>
</template>
