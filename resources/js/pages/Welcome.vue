<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    CloudSun,
    Database,
    ExternalLink,
    Globe,
    RefreshCw,
    ShieldAlert,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    hasStaleSources,
    hasUnavailableSources,
    allEventSourcesUnavailable,
    fetchDashboardData,
} = useDisasterTelemetry(props.initialDashboardData);

const keyFeatures = [
    {
        title: 'Real-Time Disaster Monitoring',
        description:
            'Continuous 60-second multi-source synchronization of seismic events, volcanic activity, floods, and wildfires across Southeast Asia.',
        icon: Activity,
        href: '/overview',
        color: 'text-rose-500 bg-rose-500/10 border-rose-500/20',
    },
    {
        title: 'Interactive Regional Map',
        description:
            'Explore verified event coordinates on Leaflet with accurate ASEAN country boundaries, severity markers, and zero broken tiles.',
        icon: Globe,
        href: '/map',
        color: 'text-blue-500 bg-blue-500/10 border-blue-500/20',
    },
    {
        title: 'Live Weather Telemetry',
        description:
            'Surface observations and daily forecasts across all 11 ASEAN capital cities powered by high-resolution Open-Meteo models.',
        icon: CloudSun,
        href: '/weather',
        color: 'text-amber-500 bg-amber-500/10 border-amber-500/20',
    },
    {
        title: 'Disaster Event Bulletins',
        description:
            'Filterable incident stream tracking magnitudes, hypocenter depth, alert levels, and official links to GDACS, USGS, and NASA.',
        icon: ShieldAlert,
        href: '/events',
        color: 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
    },
];

const dataSources = [
    {
        name: 'GDACS',
        org: 'United Nations & European Commission JRC',
        coverage:
            'Multi-hazard alerting: Earthquakes, Tropical Cyclones, Floods, Volcanoes',
        url: 'https://www.gdacs.org',
    },
    {
        name: 'USGS Earthquake Hazards',
        org: 'United States Geological Survey',
        coverage:
            'Global real-time seismic feeds, Richter magnitudes, and hypocenter depth',
        url: 'https://earthquake.usgs.gov',
    },
    {
        name: 'Open-Meteo',
        org: 'Open Meteorological Simulation API',
        coverage:
            'Atmospheric telemetry: temperature, precipitation, wind speed, and humidity',
        url: 'https://open-meteo.com',
    },
];
</script>

<template>
    <Head
        title="DisasterLinkSEA – Real-Time Southeast Asia Disaster Monitoring"
    >
        <meta
            name="description"
            content="Official real-time disaster intelligence, seismic telemetry, and environmental monitoring for Southeast Asia (ASEAN-11)."
        />
    </Head>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased selection:bg-destructive selection:text-white"
    >
        <!-- Header Navigation & Live Refresh Status Bar -->
        <DisasterHeader
            :last-updated="data.generated_at"
            :data-as-of="data.data_as_of"
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

        <main class="flex-1">
            <!-- A. Hero Section -->
            <section
                class="relative overflow-hidden border-b border-border/60 bg-gradient-to-b from-muted/50 via-background to-background py-14 sm:py-20"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="grid grid-cols-1 items-center gap-12 lg:grid-cols-12"
                    >
                        <!-- Hero Text Content -->
                        <div class="space-y-6 lg:col-span-7">
                            <div
                                class="inline-flex items-center gap-2 rounded-full border border-border/80 bg-card/90 px-3.5 py-1 text-xs font-medium text-muted-foreground shadow-2xs backdrop-blur-xs"
                            >
                                <span
                                    class="size-2 animate-pulse rounded-full bg-destructive"
                                ></span>
                                <span
                                    >Multi-Source Satellite & Seismological
                                    Feeds</span
                                >
                                <span class="text-border">|</span>
                                <span
                                    class="font-mono font-semibold text-foreground"
                                    >1-Minute Sync</span
                                >
                            </div>

                            <div class="space-y-3">
                                <h1
                                    class="text-4xl font-extrabold tracking-tight text-foreground sm:text-5xl lg:text-6xl"
                                >
                                    DisasterLink<span class="text-destructive"
                                        >SEA</span
                                    >
                                </h1>
                                <p
                                    class="text-lg font-medium text-foreground/90 sm:text-xl"
                                >
                                    Real-Time Disaster & Environmental
                                    Intelligence for Southeast Asia
                                </p>
                            </div>

                            <p
                                class="max-w-xl text-sm leading-relaxed text-muted-foreground sm:text-base"
                            >
                                An open regional intelligence platform
                                consolidating verified bulletins from GDACS,
                                USGS, and Open-Meteo across all 11
                                ASEAN member nations. Track earthquakes, storms,
                                floods, and atmospheric conditions with genuine
                                scientific accuracy.
                            </p>

                            <!-- CTAs -->
                            <div class="flex flex-wrap items-center gap-3 pt-2">
                                <Link
                                    href="/map"
                                    class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 hover:shadow-md"
                                >
                                    <Globe class="size-4" />
                                    <span>Explore Disaster Map</span>
                                </Link>

                                <Link
                                    href="/events"
                                    class="inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg border border-border bg-card px-5 text-sm font-semibold text-foreground transition-all hover:bg-muted"
                                >
                                    <ShieldAlert class="size-4" />
                                    <span>View Latest Events</span>
                                </Link>
                            </div>

                            <!-- Live Telemetry Status Ticker -->
                            <div
                                class="flex flex-wrap items-center gap-4 border-t border-border/60 pt-4 text-xs text-muted-foreground"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="size-2 rounded-full bg-emerald-500"
                                    ></span>
                                    <span
                                        >Verified Incidents:
                                        <strong
                                            class="font-mono text-foreground"
                                            >{{ data.events.length }}</strong
                                        ></span
                                    >
                                </div>
                                <span class="text-border">•</span>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        >Monitored Capitals:
                                        <strong
                                            class="font-mono text-foreground"
                                            >{{ data.weather.length }}</strong
                                        ></span
                                    >
                                </div>
                                <span class="text-border">•</span>
                                <div class="flex items-center gap-1.5">
                                    <Link
                                        href="/overview"
                                        class="flex items-center gap-1 font-medium text-foreground hover:text-destructive hover:underline"
                                    >
                                        <span>Regional Overview</span>
                                        <ArrowRight class="size-3" />
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Hero Graphic / Live Summary Card Preview -->
                        <div class="lg:col-span-5">
                            <Card
                                class="overflow-hidden border border-border/80 bg-card shadow-lg"
                            >
                                <CardContent class="space-y-5 p-6">
                                    <div
                                        class="flex items-center justify-between border-b border-border/60 pb-3"
                                    >
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex size-8 items-center justify-center rounded-lg bg-destructive/10 text-destructive"
                                            >
                                                <ShieldAlert class="size-4.5" />
                                            </div>
                                            <div>
                                                <h3
                                                    class="text-sm leading-tight font-bold text-foreground"
                                                >
                                                    ASEAN-11 Live Status
                                                </h3>
                                                <span
                                                    class="text-[11px] text-muted-foreground"
                                                    >Automated Regional
                                                    Ingestion</span
                                                >
                                            </div>
                                        </div>
                                        <Badge
                                            v-if="
                                                (data.summary
                                                    ?.total_active_alerts
                                                    ?.count || 0) > 0
                                            "
                                            variant="destructive"
                                            class="text-[10px] font-bold uppercase"
                                        >
                                            {{
                                                data.summary.total_active_alerts
                                                    .count
                                            }}
                                            Urgent Alerts
                                        </Badge>
                                        <Badge
                                            v-else
                                            variant="outline"
                                            class="border-emerald-500/40 bg-emerald-500/10 text-[10px] font-bold text-emerald-600 dark:text-emerald-400"
                                        >
                                            Normal Baseline
                                        </Badge>
                                    </div>

                                    <!-- Quick Stat Tiles -->
                                    <div
                                        class="grid grid-cols-2 gap-3 font-mono"
                                    >
                                        <div
                                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                                        >
                                            <span
                                                class="block font-sans text-[10px] font-medium text-muted-foreground uppercase"
                                                >Earthquakes</span
                                            >
                                            <span
                                                class="text-2xl font-extrabold text-foreground"
                                                >{{
                                                    data.summary?.earthquakes
                                                        ?.status ===
                                                    'Unavailable'
                                                        ? 'N/A'
                                                        : (data.summary
                                                              ?.earthquakes
                                                              ?.count ?? 0)
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                                        >
                                            <span
                                                class="block font-sans text-[10px] font-medium text-muted-foreground uppercase"
                                                >Floods & Storms</span
                                            >
                                            <span
                                                class="text-2xl font-extrabold text-foreground"
                                                >{{
                                                    data.summary?.floods
                                                        ?.status ===
                                                        'Unavailable' &&
                                                    data.summary
                                                        ?.tropical_cyclones
                                                        ?.status ===
                                                        'Unavailable'
                                                        ? 'N/A'
                                                        : (data.summary?.floods
                                                              ?.count ?? 0) +
                                                          (data.summary
                                                              ?.tropical_cyclones
                                                              ?.count ?? 0)
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                                        >
                                            <span
                                                class="block font-sans text-[10px] font-medium text-muted-foreground uppercase"
                                                >Wildfires / Hotspots</span
                                            >
                                            <span
                                                class="text-2xl font-extrabold text-foreground"
                                                >{{
                                                    data.summary?.wildfires
                                                        ?.status ===
                                                    'Unavailable'
                                                        ? 'N/A'
                                                        : (data.summary
                                                              ?.wildfires
                                                              ?.count ?? 0)
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            class="rounded-lg border border-border/50 bg-muted/40 p-3"
                                        >
                                            <span
                                                class="block font-sans text-[10px] font-medium text-muted-foreground uppercase"
                                                >Active Incidents</span
                                            >
                                            <span
                                                class="text-2xl font-extrabold text-destructive"
                                                >{{
                                                    allEventSourcesUnavailable
                                                        ? 'N/A'
                                                        : data.events.length
                                                }}</span
                                            >
                                        </div>
                                    </div>

                                    <!-- Quick Link Callout -->
                                    <div
                                        class="flex items-center justify-between rounded-lg border border-border/70 bg-muted/30 p-3.5 text-xs"
                                    >
                                        <div class="space-y-0.5">
                                            <span
                                                class="font-bold text-foreground"
                                                >Interactive Map Ready</span
                                            >
                                            <p
                                                class="text-[11px] text-muted-foreground"
                                            >
                                                Geospatial markers with real
                                                coordinates
                                            </p>
                                        </div>
                                        <Link
                                            href="/map"
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-foreground hover:text-destructive hover:underline"
                                        >
                                            <span>Open Map</span>
                                            <ArrowRight class="size-3.5" />
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </section>

            <!-- B. Regional Overview Cards (Home / Overview) -->
            <div class="border-b border-border/60 bg-muted/15">
                <RegionalOverview
                    :summary="data.summary"
                    :total-events="data.events.length"
                    @filter-category="router.visit('/events')"
                />
            </div>

            <!-- C. About DisasterLinkSEA Section -->
            <section class="border-b border-border/60 py-14 sm:py-16">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="max-w-3xl space-y-4">
                        <div class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full bg-destructive"
                            ></span>
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Mission & Architecture
                            </h2>
                        </div>
                        <h3
                            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                        >
                            About DisasterLinkSEA
                        </h3>
                        <p
                            class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                        >
                            Southeast Asia is situated across the Pacific "Ring
                            of Fire" and active Western Pacific typhoon
                            corridors, making ASEAN one of the most
                            disaster-exposed regions globally. DisasterLinkSEA
                            was created to provide a unified, transparent data
                            bridge that correlates real-time earthquake
                            hypocenters, cyclone trajectories, flood warnings,
                            and satellite thermal detections.
                        </p>
                        <p
                            class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                        >
                            By pulling directly from verified governmental and
                            scientific APIs without fabricating dummy records,
                            the platform provides emergency planners,
                            researchers, and citizens with dependable
                            environmental situational awareness.
                        </p>
                    </div>
                </div>
            </section>

            <!-- C. Key Features Section -->
            <section
                class="border-b border-border/60 bg-muted/20 py-14 sm:py-16"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-10 text-left">
                        <div class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full bg-blue-500"
                            ></span>
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Platform Capabilities
                            </h2>
                        </div>
                        <h3
                            class="mt-1 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                        >
                            Core Monitoring Capabilities
                        </h3>
                        <p
                            class="mt-1 text-xs text-muted-foreground sm:text-sm"
                        >
                            Explore dedicated modules tailored for regional
                            disaster intelligence.
                        </p>
                    </div>

                    <!-- 4 Concise Feature Cards -->
                    <div
                        class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <Link
                            v-for="feat in keyFeatures"
                            :key="feat.title"
                            :href="feat.href"
                            class="group block h-full cursor-pointer rounded-xl border border-border/80 bg-card p-5 transition-all duration-200 hover:-translate-y-1 hover:border-border hover:shadow-md"
                        >
                            <div
                                class="flex h-full flex-col justify-between space-y-4"
                            >
                                <div class="space-y-3">
                                    <div
                                        :class="[
                                            'flex size-11 items-center justify-center rounded-xl border',
                                            feat.color,
                                        ]"
                                    >
                                        <component
                                            :is="feat.icon"
                                            class="size-5.5"
                                        />
                                    </div>
                                    <h4
                                        class="text-base font-bold text-foreground transition-colors group-hover:text-destructive"
                                    >
                                        {{ feat.title }}
                                    </h4>
                                    <p
                                        class="text-xs leading-relaxed text-muted-foreground"
                                    >
                                        {{ feat.description }}
                                    </p>
                                </div>
                                <div
                                    class="flex items-center gap-1.5 border-t border-border/50 pt-2 text-xs font-semibold text-foreground group-hover:text-destructive"
                                >
                                    <span>Access Module</span>
                                    <ArrowRight
                                        class="size-3.5 transition-transform group-hover:translate-x-1"
                                    />
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>
            </section>

            <!-- D. Data Sources Section -->
            <section class="py-14 sm:py-16">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-10 text-left">
                        <div class="flex items-center gap-2">
                            <span
                                class="size-2 rounded-full bg-emerald-500"
                            ></span>
                            <h2
                                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                                Verified Upstream Telemetry
                            </h2>
                        </div>
                        <h3
                            class="mt-1 text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                        >
                            Integrated Scientific APIs
                        </h3>
                        <p
                            class="mt-1 text-xs text-muted-foreground sm:text-sm"
                        >
                            DisasterLinkSEA is built on genuine public APIs
                            without fabricated numbers or simulated events.
                        </p>
                    </div>

                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <Card
                            v-for="src in dataSources"
                            :key="src.name"
                            class="border border-border/80 bg-card"
                        >
                            <CardContent class="space-y-2.5 p-4">
                                <div class="flex items-center justify-between">
                                    <span
                                        class="text-sm font-bold text-foreground"
                                        >{{ src.name }}</span
                                    >
                                    <a
                                        :href="src.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-muted-foreground hover:text-foreground"
                                    >
                                        <ExternalLink class="size-3.5" />
                                    </a>
                                </div>
                                <span
                                    class="block text-[11px] leading-tight font-medium text-foreground/80"
                                >
                                    {{ src.org }}
                                </span>
                                <p
                                    class="text-[11px] leading-relaxed text-muted-foreground"
                                >
                                    {{ src.coverage }}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    <div class="mt-8 text-center">
                        <Link
                            href="/freshness"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-foreground hover:text-destructive hover:underline"
                        >
                            <Database class="size-3.5" />
                            <span
                                >View Real-Time API Health & Freshness
                                Diagnostics &rarr;</span
                            >
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <!-- E. Professional Footer -->
        <DisasterFooter />
    </div>
</template>
