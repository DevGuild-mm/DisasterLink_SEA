<script setup lang="ts">
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    Database,
    ExternalLink,
    HelpCircle,
    RefreshCw,
    Server,
    ShieldCheck,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
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

const safeSources = computed(() => ({
    gdacs: props.sources?.gdacs ?? {
        source: 'GDACS (UN / EC JRC)',
        status: 'Unavailable' as const,
        last_successful_fetch: null,
        latest_event_time: null,
        documentation_url: 'https://www.gdacs.org/gdacsapi/swagger/index.html',
    },
    usgs: props.sources?.usgs ?? {
        source: 'USGS Earthquake Hazards',
        status: 'Unavailable' as const,
        last_successful_fetch: null,
        latest_event_time: null,
        documentation_url:
            'https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php',
    },
    eonet: props.sources?.eonet ?? {
        source: 'NASA EONET v3',
        status: 'Unavailable' as const,
        last_successful_fetch: null,
        latest_event_time: null,
        documentation_url: 'https://eonet.gsfc.nasa.gov/docs/v3',
    },
    weather: props.sources?.weather ?? {
        source: 'Open-Meteo Weather API',
        status: 'Unavailable' as const,
        last_successful_fetch: null,
        cities_count: 11,
        documentation_url: 'https://open-meteo.com/en/docs',
    },
}));

function formatTimestamp(isoString: string | null): string {
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

function getStatusBadge(status: string) {
    switch (status) {
        case 'Live':
            return {
                variant: 'default' as const,
                class: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border-emerald-500/30',
                icon: CheckCircle2,
                label: 'Live / Synchronized',
            };
        case 'Delayed':
            return {
                variant: 'outline' as const,
                class: 'bg-amber-500/15 text-amber-700 dark:text-amber-400 border-amber-500/30',
                icon: Clock,
                label: 'Delayed Upstream',
            };
        case 'Stale':
            return {
                variant: 'outline' as const,
                class: 'bg-orange-500/15 text-orange-700 dark:text-orange-400 border-orange-500/30',
                icon: RefreshCw,
                label: 'Serving Stale Cache',
            };
        case 'Unavailable':
        default:
            return {
                variant: 'destructive' as const,
                class: 'bg-destructive/15 text-destructive border-destructive/30',
                icon: AlertCircle,
                label: 'Source Offline',
            };
    }
}
</script>

<template>
    <section id="freshness" class="border-t border-border/60 py-6 sm:py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Title -->
            <div
                class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
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
            </div>

            <!-- 4 Sources Status Grid -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <!-- GDACS -->
                <Card class="overflow-hidden border border-border/80 bg-card">
                    <CardContent class="space-y-3 p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <span
                                    class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Multihazard Alerting
                                </span>
                                <h4
                                    class="text-sm leading-tight font-bold text-foreground"
                                >
                                    {{ safeSources.gdacs.source }}
                                </h4>
                            </div>
                            <component
                                :is="
                                    getStatusBadge(safeSources.gdacs.status)
                                        .icon
                                "
                                :class="[
                                    'size-4.5',
                                    safeSources.gdacs.status === 'Live'
                                        ? 'text-emerald-500'
                                        : 'text-amber-500',
                                ]"
                            />
                        </div>

                        <div>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                    getStatusBadge(safeSources.gdacs.status)
                                        .class,
                                ]"
                            >
                                {{
                                    getStatusBadge(safeSources.gdacs.status)
                                        .label
                                }}
                            </span>
                        </div>

                        <div
                            class="space-y-1.5 border-t border-border/50 pt-2 text-[11px] text-muted-foreground"
                        >
                            <div class="flex justify-between">
                                <span>Last Fetch:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.gdacs.last_successful_fetch,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Latest Event:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.gdacs.latest_event_time,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Cache Window:</span>
                                <span class="font-mono text-foreground"
                                    >5 min TTL</span
                                >
                            </div>
                        </div>

                        <div class="border-t border-border/40 pt-1">
                            <a
                                :href="safeSources.gdacs.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>GDACS API Docs</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <!-- USGS -->
                <Card class="overflow-hidden border border-border/80 bg-card">
                    <CardContent class="space-y-3 p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <span
                                    class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Seismology Feed
                                </span>
                                <h4
                                    class="text-sm leading-tight font-bold text-foreground"
                                >
                                    {{ safeSources.usgs.source }}
                                </h4>
                            </div>
                            <component
                                :is="
                                    getStatusBadge(safeSources.usgs.status).icon
                                "
                                :class="[
                                    'size-4.5',
                                    safeSources.usgs.status === 'Live'
                                        ? 'text-emerald-500'
                                        : 'text-amber-500',
                                ]"
                            />
                        </div>

                        <div>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                    getStatusBadge(safeSources.usgs.status)
                                        .class,
                                ]"
                            >
                                {{
                                    getStatusBadge(safeSources.usgs.status)
                                        .label
                                }}
                            </span>
                        </div>

                        <div
                            class="space-y-1.5 border-t border-border/50 pt-2 text-[11px] text-muted-foreground"
                        >
                            <div class="flex justify-between">
                                <span>Last Fetch:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.usgs.last_successful_fetch,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Latest Event:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.usgs.latest_event_time,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Cache Window:</span>
                                <span class="font-mono text-foreground"
                                    >3 min TTL</span
                                >
                            </div>
                        </div>

                        <div class="border-t border-border/40 pt-1">
                            <a
                                :href="safeSources.usgs.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>USGS Earthquake Feed</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <!-- NASA EONET -->
                <Card class="overflow-hidden border border-border/80 bg-card">
                    <CardContent class="space-y-3 p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <span
                                    class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Earth Observatory
                                </span>
                                <h4
                                    class="text-sm leading-tight font-bold text-foreground"
                                >
                                    {{ safeSources.eonet.source }}
                                </h4>
                            </div>
                            <component
                                :is="
                                    getStatusBadge(safeSources.eonet.status)
                                        .icon
                                "
                                :class="[
                                    'size-4.5',
                                    safeSources.eonet.status === 'Live'
                                        ? 'text-emerald-500'
                                        : 'text-amber-500',
                                ]"
                            />
                        </div>

                        <div>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                    getStatusBadge(safeSources.eonet.status)
                                        .class,
                                ]"
                            >
                                {{
                                    getStatusBadge(safeSources.eonet.status)
                                        .label
                                }}
                            </span>
                        </div>

                        <div
                            class="space-y-1.5 border-t border-border/50 pt-2 text-[11px] text-muted-foreground"
                        >
                            <div class="flex justify-between">
                                <span>Last Fetch:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.eonet.last_successful_fetch,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Latest Event:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.eonet.latest_event_time,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Cache Window:</span>
                                <span class="font-mono text-foreground"
                                    >15 min TTL</span
                                >
                            </div>
                        </div>

                        <div class="border-t border-border/40 pt-1">
                            <a
                                :href="safeSources.eonet.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>NASA EONET API</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <!-- Open-Meteo -->
                <Card class="overflow-hidden border border-border/80 bg-card">
                    <CardContent class="space-y-3 p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <span
                                    class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Atmospheric Model
                                </span>
                                <h4
                                    class="text-sm leading-tight font-bold text-foreground"
                                >
                                    {{ safeSources.weather.source }}
                                </h4>
                            </div>
                            <component
                                :is="
                                    getStatusBadge(safeSources.weather.status)
                                        .icon
                                "
                                :class="[
                                    'size-4.5',
                                    safeSources.weather.status === 'Live'
                                        ? 'text-emerald-500'
                                        : 'text-amber-500',
                                ]"
                            />
                        </div>

                        <div>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                    getStatusBadge(safeSources.weather.status)
                                        .class,
                                ]"
                            >
                                {{
                                    getStatusBadge(safeSources.weather.status)
                                        .label
                                }}
                            </span>
                        </div>

                        <div
                            class="space-y-1.5 border-t border-border/50 pt-2 text-[11px] text-muted-foreground"
                        >
                            <div class="flex justify-between">
                                <span>Last Fetch:</span>
                                <strong class="font-mono text-foreground">{{
                                    formatTimestamp(
                                        safeSources.weather
                                            .last_successful_fetch,
                                    )
                                }}</strong>
                            </div>
                            <div class="flex justify-between">
                                <span>Monitored Cities:</span>
                                <strong class="font-mono text-foreground"
                                    >{{
                                        safeSources.weather.cities_count || 11
                                    }}
                                    ASEAN Capitals</strong
                                >
                            </div>
                            <div class="flex justify-between">
                                <span>Cache Window:</span>
                                <span class="font-mono text-foreground"
                                    >15 min TTL</span
                                >
                            </div>
                        </div>

                        <div class="border-t border-border/40 pt-1">
                            <a
                                :href="safeSources.weather.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-[11px] text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>Open-Meteo Docs</span>
                                <ExternalLink class="size-3" />
                            </a>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </section>
</template>
