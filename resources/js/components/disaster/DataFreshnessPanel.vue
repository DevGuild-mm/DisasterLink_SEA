<script setup lang="ts">
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    ExternalLink,
    Info,
    RefreshCw,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import type { SourceFreshness } from '@/types/disaster';

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const props = defineProps<{
    sources?: {
        gdacs?: SourceFreshness;
        usgs?: SourceFreshness;
        eonet?: SourceFreshness;
        weather?: SourceFreshness;
    } | null;
}>();

const sourceCards = computed(() => {
    const gdacs: SourceFreshness = props.sources?.gdacs ?? {
        source: 'GDACS',
        name: 'Global Disaster Alert and Coordination System (UN / EC JRC)',
        status: 'Unavailable',
        freshness_status: 'Unavailable',
        connection_status: 'Offline / Not Initialized',
        last_successful_fetch: null,
        latest_event_time: null,
        cache_ttl_seconds: 300,
        provider_update_frequency:
            'Every 5–15 minutes (event-driven bulletins)',
        frontend_poll_interval_seconds: 60,
        is_fresh: false,
        is_stale: false,
        documentation_url: 'https://www.gdacs.org/gdacsapi/swagger/index.html',
    };

    const usgs: SourceFreshness = props.sources?.usgs ?? {
        source: 'USGS',
        name: 'United States Geological Survey (Earthquake Hazards Program)',
        status: 'Unavailable',
        freshness_status: 'Unavailable',
        connection_status: 'Offline / Not Initialized',
        last_successful_fetch: null,
        latest_event_time: null,
        cache_ttl_seconds: 180,
        provider_update_frequency: 'Every 1 minute (all_hour.geojson feed)',
        frontend_poll_interval_seconds: 60,
        is_fresh: false,
        is_stale: false,
        documentation_url:
            'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
    };

    const eonet: SourceFreshness = props.sources?.eonet ?? {
        source: 'NASA EONET',
        name: 'NASA Earth Observatory Natural Event Tracker (EONET v3)',
        status: 'Unavailable',
        freshness_status: 'Unavailable',
        connection_status: 'Offline / Not Initialized',
        last_successful_fetch: null,
        latest_event_time: null,
        cache_ttl_seconds: 900,
        provider_update_frequency:
            'Sub-daily satellite & partner agency updates',
        frontend_poll_interval_seconds: 60,
        is_fresh: false,
        is_stale: false,
        documentation_url: 'https://eonet.gsfc.nasa.gov/api/v3/events',
    };

    const weather: SourceFreshness = props.sources?.weather ?? {
        source: 'Open-Meteo',
        name: 'Open-Meteo Weather Forecast API',
        status: 'Unavailable',
        freshness_status: 'Unavailable',
        connection_status: 'Offline / Not Initialized',
        last_successful_fetch: null,
        latest_event_time: null,
        cities_count: 0,
        cache_ttl_seconds: 900,
        provider_update_frequency:
            'Every 15–60 minutes (numerical weather model)',
        frontend_poll_interval_seconds: 60,
        is_fresh: false,
        is_stale: false,
        documentation_url: 'https://open-meteo.com/en/docs',
    };

    return [
        {
            key: 'gdacs',
            role: 'Multi-Hazard Alerting',
            meta: gdacs,
            docsLabel: 'GDACS Swagger API',
        },
        {
            key: 'usgs',
            role: 'Seismology Feed',
            meta: usgs,
            docsLabel: 'USGS GeoJSON Feed',
        },
        {
            key: 'eonet',
            role: 'Earth Observatory',
            meta: eonet,
            docsLabel: 'NASA EONET v3 API',
        },
        {
            key: 'weather',
            role: 'Atmospheric Model',
            meta: weather,
            docsLabel: 'Open-Meteo API Docs',
        },
    ];
});

function formatTimestamp(isoString: string | null | undefined): string {
    if (!isoString) return 'Not available';
    if (!isMounted.value) return isoString.replace('T', ' ').slice(0, 19);
    try {
        return new Date(isoString).toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch {
        return isoString;
    }
}

function formatCacheWindow(seconds?: number): string {
    if (!seconds || seconds <= 0) return '5 min TTL';
    const mins = Math.round(seconds / 60);
    return `${mins} min TTL (${seconds}s)`;
}

function getFreshnessState(meta: SourceFreshness): {
    freshnessLabel: 'Fresh' | 'Stale' | 'Unavailable';
    statusLabel: string;
    badgeClass: string;
    icon: any;
    iconClass: string;
} {
    if (meta.is_fresh || meta.freshness_status === 'Fresh') {
        return {
            freshnessLabel: 'Fresh',
            statusLabel: 'Live / Synchronized',
            badgeClass:
                'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border-emerald-500/30',
            icon: CheckCircle2,
            iconClass: 'text-emerald-500',
        };
    }

    if (
        meta.is_stale ||
        meta.freshness_status === 'Stale' ||
        meta.status === 'Stale'
    ) {
        return {
            freshnessLabel: 'Stale',
            statusLabel: 'Stale / Serving Cache',
            badgeClass:
                'bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30',
            icon: RefreshCw,
            iconClass: 'text-amber-500',
        };
    }

    if (meta.status === 'Delayed') {
        return {
            freshnessLabel: 'Stale',
            statusLabel: 'Delayed Upstream',
            badgeClass:
                'bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30',
            icon: Clock,
            iconClass: 'text-amber-500',
        };
    }

    return {
        freshnessLabel: 'Unavailable',
        statusLabel: 'Unavailable / Offline',
        badgeClass: 'bg-destructive/15 text-destructive border-destructive/30',
        icon: AlertCircle,
        iconClass: 'text-destructive',
    };
}
</script>

<template>
    <section id="freshness" class="border-t border-border/60 py-6 sm:py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Title -->
            <div
                class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        <h3
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Telemetry & Transparency Diagnostics
                        </h3>
                    </div>
                    <h2
                        class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        API Data Provenance & Freshness Status
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Independent external telemetry health monitoring with
                        caching and fallback verification
                    </p>
                </div>

                <!-- Cadence Explanation Callout -->
                <div
                    class="flex max-w-xl items-start gap-2 rounded-lg border border-border/80 bg-muted/40 px-3 py-2 text-[11px] text-muted-foreground"
                >
                    <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                    <span>
                        <strong class="text-foreground"
                            >Polling vs. Provider Cadence:</strong
                        >
                        The frontend checks Laravel every
                        <strong class="font-mono text-foreground">60s</strong>
                        (pausing when the tab is hidden), while each scientific
                        agency updates its upstream feed on its own operational
                        schedule shown below.
                    </span>
                </div>
            </div>

            <!-- 4 Sources Status Grid -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Card
                    v-for="item in sourceCards"
                    :key="item.key"
                    class="overflow-hidden border border-border/80 bg-card"
                >
                    <CardContent
                        class="flex h-full flex-col justify-between space-y-3 p-4"
                    >
                        <div class="space-y-3">
                            <!-- Source Name & Status Icon -->
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <span
                                        class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                    >
                                        {{ item.role }} • {{ item.meta.source }}
                                    </span>
                                    <h4
                                        class="mt-0.5 text-sm leading-tight font-bold text-foreground"
                                    >
                                        {{ item.meta.name || item.meta.source }}
                                    </h4>
                                </div>
                                <component
                                    :is="getFreshnessState(item.meta).icon"
                                    :class="[
                                        'size-4.5 shrink-0',
                                        getFreshnessState(item.meta).iconClass,
                                    ]"
                                />
                            </div>

                            <!-- Status & Freshness Classification Badges -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                        getFreshnessState(item.meta).badgeClass,
                                    ]"
                                >
                                    {{
                                        getFreshnessState(item.meta).statusLabel
                                    }}
                                </span>
                                <span
                                    class="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] font-bold text-foreground uppercase"
                                >
                                    Data:
                                    {{
                                        getFreshnessState(item.meta)
                                            .freshnessLabel
                                    }}
                                </span>
                            </div>

                            <!-- Detailed Telemetry Metrics -->
                            <div
                                class="space-y-1.5 border-t border-border/50 pt-2 text-[11px] text-muted-foreground"
                            >
                                <div class="flex justify-between gap-2">
                                    <span>Connection Status:</span>
                                    <strong
                                        class="text-right font-mono text-foreground"
                                    >
                                        {{
                                            item.meta.connection_status ||
                                            item.meta.status
                                        }}
                                    </strong>
                                </div>

                                <div class="flex justify-between gap-2">
                                    <span>Last Successful Update:</span>
                                    <strong
                                        class="text-right font-mono text-foreground"
                                    >
                                        {{
                                            formatTimestamp(
                                                item.meta.last_successful_fetch,
                                            )
                                        }}
                                    </strong>
                                </div>

                                <div
                                    v-if="item.key !== 'weather'"
                                    class="flex justify-between gap-2"
                                >
                                    <span>Latest Event Time:</span>
                                    <strong
                                        class="text-right font-mono text-foreground"
                                    >
                                        {{
                                            formatTimestamp(
                                                item.meta.latest_event_time,
                                            )
                                        }}
                                    </strong>
                                </div>

                                <div
                                    v-if="item.key !== 'weather'"
                                    class="flex justify-between gap-2"
                                >
                                    <span>ASEAN Events Matched:</span>
                                    <strong
                                        class="text-right font-mono text-foreground"
                                    >
                                        {{ item.meta.sea_events_count ?? 0 }}
                                        (of
                                        {{
                                            item.meta.total_features_fetched ??
                                            0
                                        }}
                                        global)
                                    </strong>
                                </div>

                                <div v-else class="flex justify-between gap-2">
                                    <span>Monitored Capitals:</span>
                                    <strong
                                        class="text-right font-mono text-foreground"
                                    >
                                        {{ item.meta.cities_count ?? 0 }} / 11
                                        ASEAN Cities
                                    </strong>
                                </div>

                                <div class="flex justify-between gap-2">
                                    <span>Provider Update Cadence:</span>
                                    <span
                                        class="text-right font-mono text-[10px] text-foreground"
                                    >
                                        {{
                                            item.meta
                                                .provider_update_frequency ||
                                            'Variable'
                                        }}
                                    </span>
                                </div>

                                <div class="flex justify-between gap-2">
                                    <span>Backend Cache Window:</span>
                                    <span class="font-mono text-foreground">
                                        {{
                                            formatCacheWindow(
                                                item.meta.cache_ttl_seconds,
                                            )
                                        }}
                                    </span>
                                </div>
                            </div>

                            <!-- Error message if Stale or Unavailable -->
                            <div
                                v-if="item.meta.error"
                                class="rounded-md border border-destructive/30 bg-destructive/10 px-2.5 py-1.5 text-[10px] leading-snug text-destructive"
                            >
                                {{ item.meta.error }}
                            </div>
                        </div>

                        <div class="border-t border-border/40 pt-2">
                            <a
                                :href="item.meta.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>{{ item.docsLabel }}</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </section>
</template>
