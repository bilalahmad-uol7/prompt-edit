<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, History, ImagePlus, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { create, index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Generate', href: create(), icon: ImagePlus },
    { title: 'History', href: history(), icon: History },
    { title: 'Credits', href: credits(), icon: Wallet },
    ...(page.props.auth.user?.is_admin
        ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="create()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <Link
                v-if="page.props.credits"
                :href="credits()"
                class="mx-2 flex items-center justify-between rounded-md border px-3 py-2 text-sm group-data-[collapsible=icon]:hidden"
            >
                <span class="text-muted-foreground">Available credit</span>
                <MoneyAmount
                    :amount="page.props.credits.available"
                    class="font-medium"
                />
            </Link>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
