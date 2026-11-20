<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, TriangleAlert } from '@lucide/vue';
import ReportController from '@/actions/App/Http/Controllers/Admin/ReportController';
import Heading from '@/components/Heading.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isNegative } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';

type UserRow = {
    user_id: number | null;
    name: string;
    email: string | null;
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

type Day = {
    date: string;
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
    pixelforge: { generations: number; cost: string; matches: boolean } | null;
    users: UserRow[];
};

defineProps<{
    filters: { from: string; to: string };
    report: {
        days: Day[];
        totals: {
            generations: number;
            charged: string;
            provider_cost: string;
            profit: string;
            pixelforge_cost: string | null;
        };
    };
    usageError: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Revenue & provider costs"
            description="Per UTC day (by completion time, as Pixelforge bills) and per user. Provider costs are cross-checked against Pixelforge's GET /v1/usage for this API key."
        />

        <Form
            v-bind="ReportController.form()"
            class="flex flex-wrap items-end gap-3"
            #default="{ errors, processing }"
        >
            <div class="grid gap-1">
                <Label for="from">From (UTC)</Label>
                <Input
                    id="from"
                    type="date"
                    name="from"
                    :default-value="filters.from"
                />
                <span v-if="errors.from" class="text-xs text-red-600">{{
                    errors.from
                }}</span>
            </div>
            <div class="grid gap-1">
                <Label for="to">To (UTC)</Label>
                <Input
                    id="to"
                    type="date"
                    name="to"
                    :default-value="filters.to"
                />
                <span v-if="errors.to" class="text-xs text-red-600">{{
                    errors.to
                }}</span>
            </div>
            <Button type="submit" variant="outline" :disabled="processing"
                >Apply</Button
            >
        </Form>

        <Alert v-if="usageError" variant="destructive">
            <CircleAlert />
            <AlertTitle>Pixelforge usage unavailable</AlertTitle>
            <AlertDescription>{{ usageError }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Credit spend (charged)
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="report.totals.charged" />
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Provider cost (our records)
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="report.totals.provider_cost" />
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">
                    Provider cost (Pixelforge)
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount
                        v-if="report.totals.pixelforge_cost !== null"
                        :amount="report.totals.pixelforge_cost"
                    />
                    <span v-else class="text-muted-foreground">—</span>
                </p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <p class="text-sm text-muted-foreground">Profit</p>
                <p class="mt-1 text-2xl font-semibold">
                    <MoneyAmount :amount="report.totals.profit" />
                </p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Day / user</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Generations
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Charged
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Profit</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Pixelforge billed
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="day in report.days" :key="day.date">
                        <tr class="border-t bg-muted/20 font-medium">
                            <td class="px-4 py-2">{{ day.date }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ day.generations }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyAmount :amount="day.charged" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyAmount :amount="day.provider_cost" />
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :class="{
                                    'text-red-600': isNegative(day.profit),
                                }"
                            >
                                <MoneyAmount :amount="day.profit" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <span
                                    v-if="day.pixelforge"
                                    class="inline-flex items-center gap-1"
                                >
                                    <MoneyAmount
                                        :amount="day.pixelforge.cost"
                                    />
                                    <CircleCheck
                                        v-if="day.pixelforge.matches"
                                        class="size-4 text-emerald-600"
                                        aria-label="Matches our records"
                                    />
                                    <TriangleAlert
                                        v-else
                                        class="size-4 text-amber-600"
                                        :aria-label="`Mismatch: Pixelforge billed ${day.pixelforge.generations} generations`"
                                    />
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                        <tr
                            v-for="user in day.users"
                            :key="`${day.date}-${user.user_id}`"
                            class="border-t"
                        >
                            <td class="px-4 py-2 pl-8">
                                {{ user.name }}
                                <span
                                    v-if="user.email"
                                    class="text-xs text-muted-foreground"
                                    >{{ user.email }}</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyAmount :amount="user.charged" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyAmount :amount="user.provider_cost" />
                            </td>
                            <td
                                class="px-4 py-2 text-right"
                                :class="{
                                    'text-red-600': isNegative(user.profit),
                                }"
                            >
                                <MoneyAmount :amount="user.profit" />
                            </td>
                            <td />
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
