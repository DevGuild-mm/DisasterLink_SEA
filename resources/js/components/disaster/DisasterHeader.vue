<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    Clock,
    CloudSun,
    Database,
    Globe,
    Home,
    LayoutDashboard,
    Menu,
    Moon,
    RefreshCw,
    ShieldAlert,
    Sun,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

defineProps<{
    /** When the page data was built (generated_at). */
    lastUpdated: string | null;
    /** The last successful GDACS/USGS sync (data_as_of). */
    dataAsOf?: string | null;
    isRefreshing: boolean;
    countdown: number;
    isTabVisible: boolean;
    activeAlertsCount: number;
    hasStaleSources?: boolean;
    hasUnavailableSources?: boolean;
    syncError?: string | null;
}>();

const emit = defineEmits<{
    (e: 'refresh'): void;
}>();

const mobileMenuOpen = ref(false);
const isMounted = ref(false);
const page = usePage();
const currentPath = computed(() => {
    const raw = page.url || '/';
    return raw.split('?')[0] || '/';
});

const { resolvedAppearance, updateAppearance } = useAppearance();
const isDark = computed(
    () => isMounted.value && resolvedAppearance.value === 'dark',
);

onMounted(() => {
    isMounted.value = true;
});

function toggleDarkMode() {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}

const navItems = [
    { name: 'Home', href: '/', icon: Home },
    { name: 'Disaster Map', href: '/map', icon: Globe },
    { name: 'Live Events', href: '/events', icon: ShieldAlert },
    { name: 'Weather', href: '/weather', icon: CloudSun },
    { name: 'Data Freshness', href: '/freshness', icon: Database },
];

function isItemActive(href: string): boolean {
    if (href === '/') {
        return (
            currentPath.value === '/' ||
            currentPath.value === '' ||
            currentPath.value === '/overview'
        );
    }
    return (
        currentPath.value === href || currentPath.value.startsWith(`${href}/`)
    );
}

function formatTime(isoString: string | null): string {
    if (!isoString) return 'Not available';
    if (!isMounted.value) return 'Live';
    try {
        const d = new Date(isoString);
        return d.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch {
        return isoString;
    }
}
</script>

<template>
    <header
        class="sticky top-0 z-40 w-full border-b border-border/80 bg-background/95 backdrop-blur-md transition-colors duration-200"
    >
        <div
            class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
        >
            <!-- Brand Logo & Name -->
            <Link
                href="/"
                class="group flex shrink-0 items-center gap-2.5 focus:outline-none"
            >
                <div
                    class="relative flex size-9 items-center justify-center rounded-lg bg-destructive/10 text-destructive ring-1 ring-destructive/25 transition-colors group-hover:bg-destructive/15"
                >
                    <ShieldAlert class="size-5" />
                    <span class="absolute -top-0.5 -right-0.5 flex size-2.5">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-destructive opacity-75"
                        ></span>
                        <span
                            class="relative inline-flex size-2.5 rounded-full bg-destructive"
                        ></span>
                    </span>
                </div>
                <div class="flex flex-col">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="text-base font-bold tracking-tight text-foreground"
                        >
                            DisasterLink<span class="text-destructive"
                                >SEA</span
                            >
                        </span>
                        <span
                            class="hidden rounded border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 font-mono text-[9px] leading-none font-semibold tracking-wider text-destructive uppercase xl:inline-block"
                        >
                            ASEAN-11
                        </span>
                    </div>
                    <span
                        class="hidden text-[10px] leading-tight text-muted-foreground xl:block"
                    >
                        Real-Time Disaster Intelligence
                    </span>
                </div>
            </Link>

            <!-- Desktop Navigation Links -->
            <nav
                aria-label="Main Navigation"
                class="hidden items-center gap-1 lg:flex xl:gap-1.5"
            >
                <Link
                    v-for="item in navItems"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'relative flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs whitespace-nowrap transition-colors duration-150',
                        isItemActive(item.href)
                            ? 'font-semibold text-foreground after:absolute after:inset-x-3 after:-bottom-[19px] after:h-0.5 after:rounded-full after:bg-destructive'
                            : 'font-medium text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                    ]"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'size-3.5 shrink-0 transition-colors',
                            isItemActive(item.href)
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        ]"
                    />
                    <span>{{ item.name }}</span>
                    <span
                        v-if="
                            item.href === '/intelligence' &&
                            activeAlertsCount > 0
                        "
                        class="py-0.2 ml-0.5 rounded-full bg-destructive/15 px-1.5 font-mono text-[10px] font-bold text-destructive"
                    >
                        {{ activeAlertsCount }}
                    </span>
                </Link>
            </nav>

            <!-- Right Actions: Sync Status, Reload data, Dark Mode Toggle, Mobile Menu -->
            <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                <!-- Live Sync Pill -->
                <div
                    class="hidden items-center gap-2 rounded-md border border-border/70 bg-muted/40 px-2.5 py-1 text-[11px] text-muted-foreground md:flex"
                    :title="
                        !isTabVisible
                            ? 'Polling paused while browser tab is hidden'
                            : hasStaleSources
                              ? 'One or more sources have not synced recently'
                              : 'Page reloads stored data every 60 seconds'
                    "
                >
                    <span class="flex size-2">
                        <span
                            :class="[
                                'inline-flex size-2 rounded-full',
                                !isTabVisible
                                    ? 'bg-amber-400'
                                    : syncError || hasUnavailableSources
                                      ? 'bg-rose-500'
                                      : hasStaleSources
                                        ? 'bg-orange-500'
                                        : 'animate-pulse bg-emerald-500',
                            ]"
                        ></span>
                    </span>
                    <span class="font-mono whitespace-nowrap">
                        <template v-if="!isTabVisible">Paused</template>
                        <template v-else-if="isRefreshing">Loading…</template>
                        <template v-else-if="hasStaleSources"
                            >Stale • {{ countdown }}s</template
                        >
                        <template v-else>{{ countdown }}s</template>
                    </span>
                    <span class="hidden text-border xl:inline">|</span>
                    <div
                        class="hidden items-center gap-1 xl:flex"
                        :title="`Page data loaded ${formatTime(lastUpdated)}. Sources last synced ${formatTime(dataAsOf ?? null)}.`"
                    >
                        <Clock class="size-3 text-muted-foreground/70" />
                        <span class="font-mono whitespace-nowrap"
                            >Data as of {{ formatTime(dataAsOf ?? null) }}</span
                        >
                    </div>
                </div>

                <!-- Reload data button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 border-border/80 bg-card px-2.5 text-xs font-medium text-foreground hover:bg-muted"
                    :disabled="isRefreshing"
                    title="Reload the latest stored data. Does not contact GDACS or USGS."
                    @click="emit('refresh')"
                >
                    <RefreshCw
                        :class="[
                            'size-3.5',
                            isRefreshing && 'animate-spin text-destructive',
                        ]"
                    />
                    <span class="hidden sm:inline">Reload data</span>
                </Button>
                <!-- Dark Mode / Light Mode Toggle Button -->
                <Button
                    variant="outline"
                    size="icon"
                    class="size-8 cursor-pointer border-border/80 bg-card text-foreground hover:bg-muted"
                    :aria-label="
                        isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode'
                    "
                    :title="
                        isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode'
                    "
                    @click="toggleDarkMode"
                >
                    <Sun
                        v-if="isDark"
                        class="size-4 text-amber-400 transition-transform duration-200 hover:rotate-12"
                    />
                    <Moon
                        v-else
                        class="size-4 text-slate-700 transition-transform duration-200 hover:-rotate-12 dark:text-slate-300"
                    />
                </Button>

                <!-- Optional Authenticated Dashboard Link -->
                <Link
                    v-if="$page.props.auth?.user"
                    href="/dashboard"
                    class="hidden h-8 items-center gap-1.5 rounded-md border border-border/80 bg-card px-2.5 text-xs font-medium text-foreground hover:bg-muted sm:inline-flex"
                >
                    <LayoutDashboard class="size-3.5" />
                    <span class="hidden xl:inline">Account</span>
                </Link>

                <!-- Mobile / Tablet Menu Button -->
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8 lg:hidden"
                    aria-label="Toggle navigation menu"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <Menu v-if="!mobileMenuOpen" class="size-5" />
                    <X v-else class="size-5" />
                </Button>
            </div>
        </div>

        <!-- Mobile & Tablet Drawer Navigation -->
        <div
            v-if="mobileMenuOpen"
            class="border-b border-border bg-background px-4 py-3 shadow-lg lg:hidden"
        >
            <div class="flex flex-col gap-1">
                <div
                    class="mb-2 flex items-center justify-between border-b border-border/60 pb-2 text-xs text-muted-foreground"
                >
                    <span class="flex items-center gap-1.5 font-mono">
                        <span
                            :class="[
                                'size-2 rounded-full',
                                !isTabVisible
                                    ? 'bg-amber-400'
                                    : hasStaleSources
                                      ? 'bg-orange-500'
                                      : 'bg-emerald-500',
                            ]"
                        ></span>
                        <template v-if="!isTabVisible"
                            >Auto-Sync Paused</template
                        >
                        <template v-else-if="isRefreshing">Loading…</template>
                        <template v-else>Auto-Sync: {{ countdown }}s</template>
                    </span>
                    <span class="font-mono"
                        >Data as of: {{ formatTime(dataAsOf ?? null) }}</span
                    >
                </div>

                <Link
                    v-for="item in navItems"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                        isItemActive(item.href)
                            ? 'bg-destructive/10 font-semibold text-foreground'
                            : 'font-medium text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="mobileMenuOpen = false"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            'size-4 shrink-0',
                            isItemActive(item.href)
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        ]"
                    />
                    <span>{{ item.name }}</span>
                    <span
                        v-if="
                            item.href === '/intelligence' &&
                            activeAlertsCount > 0
                        "
                        class="py-0.2 ml-0.5 rounded-full bg-destructive/15 px-1.5 font-mono text-[10px] font-bold text-destructive"
                    >
                        {{ activeAlertsCount }}
                    </span>
                </Link>

                <div class="my-1 border-t border-border/60"></div>

                <!-- Mobile Theme Toggle Row -->
                <div
                    class="mt-2 flex items-center justify-between border-t border-border/60 pt-2.5"
                >
                    <span class="text-xs font-medium text-muted-foreground">
                        Appearance Theme
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-8 cursor-pointer gap-1.5 border-border/80 bg-card text-xs"
                        @click="toggleDarkMode"
                    >
                        <Sun v-if="isDark" class="size-3.5 text-amber-400" />
                        <Moon v-else class="size-3.5 text-slate-700" />
                        <span>{{ isDark ? 'Dark Mode' : 'Light Mode' }}</span>
                    </Button>
                </div>
            </div>
        </div>
    </header>
</template>
