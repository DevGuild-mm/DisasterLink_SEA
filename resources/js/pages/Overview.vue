<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Globe, RefreshCw, ShieldAlert } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import RegionalOverview from '@/components/disaster/RegionalOverview.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type { DashboardData } from '@/types/disaster';

const props = defineProps<{
    initialDashboardData?: DashboardData | null;
}>();

const {
    data,
    isRefreshing,
    syncError,
    countdown,
    isTabVisible,
    fetchDashboardData,
} = useDisasterTelemetry(props.initialDashboardData);
</script>

<template>
    <Head title="Regional Overview – DisasterLinkSEA">
        <meta
            name="description"
            content="Regional overview of active disaster alerts, earthquakes, cyclones, floods, and wildfires across Southeast Asia."
        />
    </Head>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased"
    >
        <DisasterHeader
            :last-updated="data.generated_at"
            :is-refreshing="isRefreshing"
            :countdown="countdown"
            :is-tab-visible="isTabVisible"
            :active-alerts-count="data.summary?.total_active_alerts?.count || 0"
            @refresh="fetchDashboardData"
        />

        <!-- Resilient API Error / Retry Banner -->
        <div
            v-if="syncError"
            class="border-b border-amber-500/30 bg-amber-500/10 px-4 py-2 text-xs text-amber-700 dark:text-amber-300"
        >
            <div
                class="mx-auto flex max-w-7xl items-center justify-between gap-2"
            >
                <div class="flex items-center gap-2">
                    <AlertTriangle class="size-4 shrink-0 text-amber-500" />
                    <span>{{ syncError }}</span>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 cursor-pointer gap-1 border-amber-500/40 bg-transparent text-xs"
                    :disabled="isRefreshing"
                    @click="fetchDashboardData"
                >
                    <RefreshCw
                        :class="['size-3', isRefreshing && 'animate-spin']"
                    />
                    <span>Retry</span>
                </Button>
            </div>
        </div>

        <main class="flex-1 py-8 sm:py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Page Banner -->
                <div
                    class="mb-6 flex flex-col gap-3 border-b border-border/60 pb-6 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full bg-rose-500"
                            ></span>
                            <span
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Southeast Asia Monitoring Summary
                            </span>
                        </div>
                        <h1
                            class="mt-1 text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
                        >
                            Regional Disaster Overview
                        </h1>
                        <p
                            class="mt-1 text-xs text-muted-foreground sm:text-sm"
                        >
                            Calculated from real-time feeds across GDACS, USGS,
                            and NASA EONET.
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            href="/map"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                        >
                            <Globe class="size-3.5" />
                            <span>View Interactive Map</span>
                        </Link>
                        <Link
                            href="/events"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3.5 text-xs font-semibold text-foreground hover:bg-muted"
                        >
                            <ShieldAlert class="size-3.5" />
                            <span>Events Stream</span>
                        </Link>
                    </div>
                </div>

                <!-- 5 Regional Summary Cards -->
                <RegionalOverview
                    :summary="data.summary"
                    :total-events="data.events.length"
                    @filter-category="router.visit('/events')"
                />
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
