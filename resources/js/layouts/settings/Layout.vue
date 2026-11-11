<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Palette, ShieldCheck, UserRound } from '@lucide/vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const tabs = [
    { title: 'Profile', href: editProfile(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: ShieldCheck },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased"
    >
        <DisasterHeader
            :last-updated="null"
            :is-refreshing="false"
            :countdown="60"
            :is-tab-visible="true"
            :active-alerts-count="0"
        />

        <main class="flex-1 py-6 sm:py-10">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <header class="space-y-2">
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
                        Account Settings
                    </h1>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        Manage your account information and your disaster alert
                        preferences.
                    </p>
                </header>

                <nav
                    aria-label="Settings"
                    class="flex gap-1 overflow-x-auto overflow-y-hidden shadow-[inset_0_-1px_0_var(--border)] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
                    <Link
                        v-for="tab in tabs"
                        :key="toUrl(tab.href)"
                        :href="tab.href"
                        :aria-current="
                            isCurrentOrParentUrl(tab.href) ? 'page' : undefined
                        "
                        :class="[
                            'inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition-colors',
                            isCurrentOrParentUrl(tab.href)
                                ? 'border-destructive font-semibold text-foreground'
                                : 'border-transparent font-medium text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        <component :is="tab.icon" class="size-4" />
                        {{ tab.title }}
                    </Link>
                </nav>

                <div class="space-y-6">
                    <slot />
                </div>
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
