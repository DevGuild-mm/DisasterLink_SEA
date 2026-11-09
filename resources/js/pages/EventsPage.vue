<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, ExternalLink, RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DisasterEventFeed from '@/components/disaster/DisasterEventFeed.vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type { DashboardData, DisasterEvent } from '@/types/disaster';

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

const selectedCountry = ref<string>('All');
const selectedCategory = ref<string>('all');
const selectedSeverity = ref<string>('all');
const selectedDateRange = ref<string>('all');
const activeModalEvent = ref<DisasterEvent | null>(null);

function handleSelectEvent(event: DisasterEvent) {
    activeModalEvent.value = event;
}
</script>

<template>
    <Head title="Live Disaster Events – DisasterLinkSEA">
        <meta
            name="description"
            content="Filterable real-time disaster incident stream with verified coordinates, alert levels, and official bulletins."
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
                <!-- Disaster Event Feed Component -->
                <DisasterEventFeed
                    :events="data.events"
                    :countries="data.countries"
                    :selected-country="selectedCountry"
                    :selected-category="selectedCategory"
                    :selected-severity="selectedSeverity"
                    :selected-date-range="selectedDateRange"
                    :is-loading="isLoading"
                    :sources="data.sources"
                    @update:selected-country="selectedCountry = $event"
                    @update:selected-category="selectedCategory = $event"
                    @update:selected-severity="selectedSeverity = $event"
                    @update:selected-date-range="selectedDateRange = $event"
                    @select-event="handleSelectEvent"
                />
            </div>
        </main>

        <!-- Event Details Modal Dialog -->
        <Dialog
            :open="activeModalEvent !== null"
            @update:open="activeModalEvent = null"
        >
            <DialogContent v-if="activeModalEvent" class="sm:max-w-md">
                <DialogHeader>
                    <div class="mb-1 flex items-center gap-2">
                        <Badge
                            variant="outline"
                            class="text-xs font-bold uppercase"
                        >
                            {{ activeModalEvent.category }}
                        </Badge>
                        <Badge
                            v-if="activeModalEvent.severity_level === 'Red'"
                            variant="destructive"
                            class="text-xs"
                        >
                            Red Alert
                        </Badge>
                        <Badge
                            v-else-if="
                                activeModalEvent.severity_level === 'Orange'
                            "
                            variant="outline"
                            class="border-amber-500/40 bg-amber-500/10 text-xs text-amber-600 dark:text-amber-400"
                        >
                            Orange Alert
                        </Badge>
                    </div>
                    <DialogTitle class="text-base leading-snug font-bold">
                        {{ activeModalEvent.title }}
                    </DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Reported by {{ activeModalEvent.source }} •
                        {{ activeModalEvent.country }}
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3 py-2 text-xs">
                    <p
                        v-if="activeModalEvent.description"
                        class="leading-relaxed text-muted-foreground"
                    >
                        {{ activeModalEvent.description }}
                    </p>

                    <div
                        class="grid grid-cols-2 gap-2 rounded-lg bg-muted/50 p-3 font-mono text-[11px]"
                    >
                        <div>
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Country:</span
                            >
                            <strong>{{ activeModalEvent.country }}</strong>
                        </div>
                        <div v-if="activeModalEvent.location">
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Location:</span
                            >
                            <strong>{{ activeModalEvent.location }}</strong>
                        </div>
                        <div>
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Event Date:</span
                            >
                            <strong>{{
                                activeModalEvent.event_date ||
                                activeModalEvent.event_time
                                    ? new Date(
                                          (activeModalEvent.event_date ||
                                              activeModalEvent.event_time)!,
                                      ).toLocaleString()
                                    : 'Not supplied'
                            }}</strong>
                        </div>
                        <div v-if="activeModalEvent.last_updated_time">
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Last Updated:</span
                            >
                            <strong>{{
                                new Date(
                                    activeModalEvent.last_updated_time,
                                ).toLocaleString()
                            }}</strong>
                        </div>
                        <div v-if="activeModalEvent.magnitude">
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Magnitude:</span
                            >
                            <strong>M {{ activeModalEvent.magnitude }}</strong>
                        </div>
                        <div
                            v-else-if="
                                activeModalEvent.severity ||
                                activeModalEvent.severity_text
                            "
                        >
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Severity:</span
                            >
                            <strong>{{
                                activeModalEvent.severity ||
                                activeModalEvent.severity_text
                            }}</strong>
                        </div>
                        <div v-if="activeModalEvent.depth_km !== null">
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Hypocenter Depth:</span
                            >
                            <strong>{{ activeModalEvent.depth_km }} km</strong>
                        </div>
                        <div
                            v-if="activeModalEvent.coordinates"
                            class="col-span-2"
                        >
                            <span
                                class="block text-[10px] text-muted-foreground"
                                >Geographical Coordinates:</span
                            >
                            <strong
                                >{{
                                    activeModalEvent.coordinates[0].toFixed(4)
                                }}° N,
                                {{
                                    activeModalEvent.coordinates[1].toFixed(4)
                                }}° E</strong
                            >
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between border-t border-border pt-2"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-xs"
                        @click="activeModalEvent = null"
                    >
                        Close
                    </Button>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="'/events/' + activeModalEvent.id"
                            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-muted"
                        >
                            <span>Dedicated Page &rarr;</span>
                        </Link>
                        <a
                            :href="activeModalEvent.source_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                        >
                            <span>Official Bulletin</span>
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <DisasterFooter />
    </div>
</template>
