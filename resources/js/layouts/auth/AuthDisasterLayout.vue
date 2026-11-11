<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, BellRing, BookOpen, Globe } from '@lucide/vue';
import BrandLogo from '@/components/BrandLogo.vue';
import { home, login, register } from '@/routes';

/**
 * The standalone DisasterLink layout for login, registration and the other authentication pages. It does not use the
 * starter sidebar or the admin layout. `toggle` shows the Login | Register switch on those two pages only.
 */
defineProps<{
    title?: string;
    description?: string;
    toggle?: 'login' | 'register' | null;
}>();

const highlights = [
    {
        icon: BellRing,
        text: 'Email alerts for significant disasters reported in your country',
    },
    {
        icon: Globe,
        text: 'Live map, events and weather across the 11 ASEAN countries',
    },
    {
        icon: BookOpen,
        text: 'Safety guides and emergency contacts in one place',
    },
];
</script>

<template>
    <div class="flex min-h-svh bg-background text-foreground antialiased">
        <!-- Brand panel (large screens) -->
        <aside
            class="relative hidden w-[44%] max-w-xl flex-col justify-between overflow-hidden bg-slate-900 p-10 text-slate-100 lg:flex"
            aria-label="DisasterLink SEA"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-red-500/15 blur-3xl"
            ></div>

            <Link :href="home()" class="group relative flex items-center gap-3">
                <BrandLogo force-dark class="size-12" />
                <span class="flex flex-col leading-tight">
                    <span class="text-xl font-bold tracking-tight">
                        DisasterLink<span class="text-red-400">SEA</span>
                    </span>
                    <span class="text-xs text-slate-400"
                        >Real-Time Disaster Intelligence</span
                    >
                </span>
            </Link>

            <div class="relative space-y-8">
                <p
                    class="text-3xl leading-tight font-bold tracking-tight text-balance"
                >
                    Stay Informed.<br />Stay Prepared.<br />Stay Safe.
                </p>
                <ul class="space-y-4">
                    <li
                        v-for="item in highlights"
                        :key="item.text"
                        class="flex items-start gap-3 text-sm text-slate-300"
                    >
                        <component
                            :is="item.icon"
                            class="mt-0.5 size-4 shrink-0 text-red-400"
                        />
                        <span>{{ item.text }}</span>
                    </li>
                </ul>
            </div>

            <p class="relative text-xs leading-relaxed text-slate-400">
                Alerts are country-level. They do not mean your city or area is
                directly affected: check the event details and official sources.
            </p>
        </aside>

        <!-- Form column -->
        <main class="flex flex-1 flex-col">
            <div
                class="flex items-center justify-between px-6 pt-6 sm:px-10 sm:pt-8"
            >
                <Link
                    :href="home()"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                    data-test="back-to-home"
                >
                    <ArrowLeft class="size-4" />
                    Back to home
                </Link>

                <!-- Brand (small screens, where the panel is hidden) -->
                <Link
                    :href="home()"
                    class="flex items-center gap-2 lg:hidden"
                    aria-label="DisasterLink SEA home"
                >
                    <BrandLogo class="size-9" />
                    <span class="text-base font-bold tracking-tight">
                        DisasterLink<span class="text-destructive">SEA</span>
                    </span>
                </Link>
            </div>

            <div class="flex flex-1 items-center justify-center px-6 py-8 sm:px-10">
                <div class="w-full max-w-sm space-y-6">
                    <nav
                        v-if="toggle"
                        class="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1 text-sm font-medium"
                        aria-label="Log in or register"
                    >
                        <Link
                            :href="login()"
                            :aria-current="toggle === 'login' ? 'page' : undefined"
                            :class="[
                                'rounded-md px-3 py-1.5 text-center transition-colors',
                                toggle === 'login'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            data-test="toggle-login"
                            >Log in</Link
                        >
                        <Link
                            :href="register()"
                            :aria-current="toggle === 'register' ? 'page' : undefined"
                            :class="[
                                'rounded-md px-3 py-1.5 text-center transition-colors',
                                toggle === 'register'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            data-test="toggle-register"
                            >Register</Link
                        >
                    </nav>

                    <div class="space-y-1.5">
                        <h1 class="text-2xl font-bold tracking-tight">
                            {{ title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>

                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
