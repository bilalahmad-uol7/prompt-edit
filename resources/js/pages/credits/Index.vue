<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { computed } from 'vue';
import CreditController from '@/actions/App/Http/Controllers/CreditController';
import Heading from '@/components/Heading.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { Button } from '@/components/ui/button';
import { formatUsd, isNegative } from '@/lib/money';
import { index } from '@/routes/credits';

type Transaction = {
    id: number;
    type: string;
    label: string;
    amount: string;
    balance_after: string;
    prompt: string | null;
    created_at: string;
};

type Paginator<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
};

defineProps<{
    packs: string[];
    transactions: Paginator<Transaction>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();
const credits = computed(() => page.props.credits);
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Credits are US dollars. While a generation runs, its maximum possible cost is held; once it finishes you are charged the actual cost and the rest is released."
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Available</p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="credits?.available" />
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Held for running generations
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="credits?.held" />
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Balance</p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="credits?.balance" />
                </p>
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Buy credits</h2>
            <p class="text-sm text-muted-foreground">
                This is a demo: no payment is taken, credits are added
                instantly.
            </p>
            <div class="flex flex-wrap gap-3">
                <Button
                    v-for="pack in packs"
                    :key="pack"
                    variant="outline"
                    as-child
                >
                    <Link
                        v-bind="CreditController.store()"
                        :data="{ amount: pack }"
                        as="button"
                        preserve-scroll
                    >
                        <CreditCard class="size-4" />
                        Add {{ formatUsd(pack, 2) }}
                    </Link>
                </Button>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Activity</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date</th>
                            <th class="px-4 py-2 font-medium">Description</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Amount
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Balance after
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="border-t"
                        >
                            <td
                                class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{
                                    new Date(
                                        transaction.created_at,
                                    ).toLocaleString()
                                }}
                            </td>
                            <td class="max-w-md px-4 py-2">
                                {{ transaction.label }}
                                <span
                                    v-if="transaction.prompt"
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ transaction.prompt }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :class="
                                    isNegative(transaction.amount)
                                        ? ''
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                            >
                                <MoneyAmount :amount="transaction.amount" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyAmount
                                    :amount="transaction.balance_after"
                                />
                            </td>
                        </tr>
                        <tr v-if="!transactions.data.length">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No activity yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="transactions.last_page > 1"
                class="flex justify-end gap-2"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!transactions.prev_page_url"
                    as-child
                >
                    <Link
                        v-if="transactions.prev_page_url"
                        :href="transactions.prev_page_url"
                        preserve-scroll
                        >Previous</Link
                    >
                    <span v-else>Previous</span>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!transactions.next_page_url"
                    as-child
                >
                    <Link
                        v-if="transactions.next_page_url"
                        :href="transactions.next_page_url"
                        preserve-scroll
                        >Next</Link
                    >
                    <span v-else>Next</span>
                </Button>
            </div>
        </section>
    </div>
</template>
