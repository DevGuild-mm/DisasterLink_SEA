<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import WeatherMonitoring from '@/components/disaster/WeatherMonitoring.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type { DashboardData } from '@/types/disaster';

const props = defineProps<{
    initialDashboardData?: DashboardData | null;
}>();

const {
    data,
    isRefreshing,
    isLoading,
    syncError,
    countdown,
    isTabVisible,
    hasStaleSources,
    hasUnavailableSources,
    fetchDashboardData,
} = useDisasterTelemetry(props.initialDashboardData);
</script>

<template>
    <Head title="Regional Weather Monitoring – DisasterLinkSEA">
        <meta
            name="description"
            content="Surface weather telemetry and atmospheric observations across all 11 ASEAN capital cities via Open-Meteo."
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
            :has-stale-sources="hasStaleSources"
            :has-unavailable-sources="hasUnavailableSources"
            :sync-error="syncError"
            @refresh="fetchDashboardData(true)"
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
                    @click="fetchDashboardData(true)"
                >
                    <RefreshCw
                        :class="['size-3', isRefreshing && 'animate-spin']"
                    />
                    <span>Retry</span>
                </Button>
            </div>
        </div>

        <main class="flex-1 py-6 sm:py-10">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Weather Monitoring Component -->
                <WeatherMonitoring
                    :weather="data.weather"
                    :source-meta="data.sources.weather"
                    :is-loading="isLoading"
                />
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
