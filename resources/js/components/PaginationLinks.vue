<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

/**
 * Previous / next navigation for a Laravel resource collection page.
 */
defineProps<{
    links: Paginated<unknown>['links'];
    meta: Paginated<unknown>['meta'];
}>();
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex items-center justify-between text-sm"
        aria-label="Pagination"
    >
        <span class="text-muted-foreground">
            Page {{ meta.current_page }} of {{ meta.last_page }} ·
            {{ meta.total }} total
        </span>
        <div class="flex gap-2">
            <Button
                v-for="page in [
                    { label: 'Previous', href: links.prev },
                    { label: 'Next', href: links.next },
                ]"
                :key="page.label"
                variant="outline"
                size="sm"
                :disabled="!page.href"
                as-child
            >
                <Link v-if="page.href" :href="page.href" preserve-scroll>
                    {{ page.label }}
                </Link>
                <span v-else>{{ page.label }}</span>
            </Button>
        </div>
    </nav>
</template>
