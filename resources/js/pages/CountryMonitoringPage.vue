<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowRight,
    BarChart3,
    Calendar,
    Clock,
    Compass,
    ExternalLink,
    Filter,
    Flame,
    Globe,
    Layers,
    MapPin,
    RefreshCw,
    Search,
    ShieldAlert,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import CountryAnalytics from '@/components/disaster/CountryAnalytics.vue';
import CountryWeatherCard from '@/components/disaster/CountryWeatherCard.vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import EmergencyResponseCard from '@/components/disaster/EmergencyResponseCard.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type {
    CountryDashboardData,
    DashboardData,
    DisasterEvent,
} from '@/types/disaster';

const props = defineProps<{
    countryData: CountryDashboardData;
    selectedCountry: string;
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
    fetchDashboardData,
} = useDisasterTelemetry(props.initialDashboardData);

const searchQuery = ref('');
const selectedCategoryFilter = ref('all');

const countryList = [
    { key: 'All', name: 'All Southeast Asia', code: 'ALL' },
    { key: 'Brunei', name: 'Brunei', code: 'BN' },
    { key: 'Cambodia', name: 'Cambodia', code: 'KH' },
    { key: 'Indonesia', name: 'Indonesia', code: 'ID' },
    { key: 'Laos', name: 'Laos', code: 'LA' },
    { key: 'Malaysia', name: 'Malaysia', code: 'MY' },
    { key: 'Myanmar', name: 'Myanmar', code: 'MM' },
    { key: 'Philippines', name: 'Philippines', code: 'PH' },
    { key: 'Singapore', name: 'Singapore', code: 'SG' },
    { key: 'Thailand', name: 'Thailand', code: 'TH' },
    { key: 'Timor-Leste', name: 'Timor-Leste', code: 'TL' },
    { key: 'Vietnam', name: 'Vietnam', code: 'VN' },
];

function countrySlug(countryKey: string): string {
    return countryKey.toLowerCase().replace(/\s+/g, '-');
}

function selectCountry(countryKey: string) {
    const slug = countrySlug(countryKey);
    const targetUrl = slug === 'all' ? '/countries' : `/countries/${slug}`;
    router.visit(targetUrl, {
        preserveScroll: true,
        preserveState: true,
    });
}

function getEventCountForCountry(countryKey: string): number {
    if (countryKey === 'All') {
        return (
            props.countryData.analytics.by_country?.reduce(
                (acc, c) => acc + c.total_events,
                0,
            ) ?? props.countryData.summary.total_events
        );
    }
    const item = props.countryData.analytics.by_country?.find(
        (c) => c.country.toLowerCase() === countryKey.toLowerCase(),
    );
    return item?.total_events ?? 0;
}

function getCategoryColor(category?: string, severity?: string | null): string {
    if (severity === 'Red')
        return 'text-rose-500 bg-rose-500/10 border-rose-500/20';
    if (severity === 'Orange')
        return 'text-amber-500 bg-amber-500/10 border-amber-500/20';
    const cat = (category || '').toLowerCase();
    if (cat.includes('earthquake'))
        return 'text-orange-500 bg-orange-500/10 border-orange-500/20';
    if (cat.includes('cyclone') || cat.includes('storm'))
        return 'text-cyan-500 bg-cyan-500/10 border-cyan-500/20';
    if (cat.includes('flood') || cat.includes('wave'))
        return 'text-blue-500 bg-blue-500/10 border-blue-500/20';
    if (cat.includes('wildfire') || cat.includes('fire'))
        return 'text-rose-500 bg-rose-500/10 border-rose-500/20';
    if (cat.includes('volcano'))
        return 'text-purple-500 bg-purple-500/10 border-purple-500/20';
    return 'text-muted-foreground bg-muted border-border';
}

function getCategoryIcon(category?: string) {
    const cat = (category || '').toLowerCase();
    if (cat.includes('earthquake')) return Activity;
    if (cat.includes('cyclone') || cat.includes('storm')) return Wind;
    if (cat.includes('flood') || cat.includes('wave')) return Waves;
    if (cat.includes('wildfire') || cat.includes('fire')) return Flame;
    return ShieldAlert;
}

function formatDateTime(dateStr?: string | null): string {
    if (!dateStr) return 'Not supplied';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateStr;
    }
}

// Available categories from current country events
const availableCategories = computed(() => {
    const set = new Set<string>();
    props.countryData.events.forEach((e) => {
        if (e.category) set.add(e.category);
    });
    return Array.from(set);
});

// Filtered country events
const filteredEvents = computed(() => {
    let result = props.countryData.events;

    if (selectedCategoryFilter.value !== 'all') {
        result = result.filter(
            (e) =>
                (e.category || '').toLowerCase() ===
                selectedCategoryFilter.value.toLowerCase(),
        );
    }

    if (searchQuery.value.trim() !== '') {
        const q = searchQuery.value.toLowerCase().trim();
        result = result.filter(
            (e) =>
                e.title.toLowerCase().includes(q) ||
                (e.location && e.location.toLowerCase().includes(q)) ||
                (e.description && e.description.toLowerCase().includes(q)),
        );
    }

    return result;
});

// Check if there are active storm or flood warnings in this country
const hasWeatherWarnings = computed(() => {
    return props.countryData.events.some((e) => {
        const cat = (e.category || '').toLowerCase();
        const sev = e.severity_level;
        return (
            (cat.includes('cyclone') ||
                cat.includes('flood') ||
                cat.includes('storm')) &&
            (sev === 'Red' || sev === 'Orange')
        );
    });
});
</script>

<template>
    <Head
        :title="`${countryData.country_meta.name} Disaster Monitoring – DisasterLinkSEA`"
    >
        <meta
            name="description"
            :content="`Disaster monitoring dashboard, analytics, active event telemetry, and capital weather for ${countryData.country_meta.name}.`"
        />
    </Head>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased"
    >
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

        <main class="flex-1 py-6 sm:py-10">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Navigation Breadcrumbs -->
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-4"
                >
                    <div
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Link
                            href="/"
                            class="transition-colors hover:text-foreground"
                            >Home</Link
                        >
                        <span>/</span>
                        <Link
                            href="/countries"
                            class="transition-colors hover:text-foreground"
                            >Country Monitoring</Link
                        >
                        <span>/</span>
                        <span class="font-medium text-foreground">
                            {{ countryData.country_meta.name }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            href="/map"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground shadow-sm transition-colors hover:bg-muted"
                        >
                            <Globe class="size-3.5" />
                            <span>View Disaster Map</span>
                        </Link>
                        <Link
                            href="/events"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground shadow-sm transition-colors hover:bg-muted"
                        >
                            <ShieldAlert class="size-3.5" />
                            <span>Live Events Stream</span>
                        </Link>
                    </div>
                </div>

                <!-- Invalid Country Fallback Banner -->
                <div
                    v-if="!countryData.is_valid_country"
                    class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-800 dark:text-amber-200"
                >
                    <div class="flex items-center gap-2 font-bold">
                        <AlertCircle class="size-4 shrink-0 text-amber-500" />
                        <span
                            >Unknown Country Identifier: "{{
                                countryData.requested_country
                            }}"</span
                        >
                    </div>
                    <p class="mt-1 text-[11px] leading-relaxed">
                        The requested territory is not an ASEAN member state.
                        Showing aggregated telemetry for All Southeast Asia
                        below. You can select any of the 11 official nations
                        from the selector below.
                    </p>
                </div>

                <!-- Country Selector Pills (All 11 ASEAN Nations + All) -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span
                            class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                        >
                            Select ASEAN Nation (11 Member States)
                        </span>
                        <span
                            class="font-mono text-[11px] text-muted-foreground"
                        >
                            Active Incidents Displayed
                        </span>
                    </div>

                    <div
                        class="flex scrollbar-thin items-center gap-1.5 overflow-x-auto pb-2"
                    >
                        <button
                            v-for="c in countryList"
                            :key="c.key"
                            type="button"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold whitespace-nowrap transition-all"
                            :class="[
                                selectedCountry === c.key
                                    ? 'border-primary bg-primary text-primary-foreground shadow-sm ring-2 ring-primary/20'
                                    : 'border-border bg-card text-foreground hover:border-border/80 hover:bg-muted',
                            ]"
                            @click="selectCountry(c.key)"
                        >
                            <span>{{ c.name }}</span>
                            <span
                                class="py-0.2 rounded-full px-1.5 font-mono text-[10px] font-bold"
                                :class="[
                                    selectedCountry === c.key
                                        ? 'bg-primary-foreground/20 text-primary-foreground'
                                        : getEventCountForCountry(c.key) > 0
                                          ? 'border border-rose-500/20 bg-rose-500/10 text-rose-600 dark:text-rose-400'
                                          : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                {{ getEventCountForCountry(c.key) }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Country Header Banner & KPI Cards -->
                <div
                    class="space-y-6 rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-7"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded border border-border bg-muted px-2 py-0.5 font-mono text-xs font-bold text-foreground"
                                >
                                    {{ countryData.country_meta.code }} •
                                    {{ countryData.country_meta.iso3 }}
                                </span>
                                <Badge
                                    v-if="
                                        countryData.summary.high_alerts_count >
                                        0
                                    "
                                    variant="destructive"
                                    class="text-xs font-bold tracking-wider uppercase"
                                >
                                    {{ countryData.summary.high_alerts_count }}
                                    High Priority Alert{{
                                        countryData.summary.high_alerts_count >
                                        1
                                            ? 's'
                                            : ''
                                    }}
                                </Badge>
                                <Badge
                                    v-else
                                    variant="outline"
                                    class="border-emerald-500/40 bg-emerald-500/10 text-xs font-bold text-emerald-600 uppercase dark:text-emerald-400"
                                >
                                    Normal Advisory Status
                                </Badge>
                            </div>

                            <h1
                                class="mt-2 text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
                            >
                                {{ countryData.country_meta.name }} Disaster &
                                Climate Telemetry
                            </h1>
                            <p
                                class="mt-1 text-xs text-muted-foreground sm:text-sm"
                            >
                                Real-time disaster hazard monitoring, seismic
                                records, satellite hotspot tracking, and capital
                                atmospheric telemetry.
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <Link
                                :href="`/map?country=${countryData.selected_country}`"
                                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-xs font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                            >
                                <Globe class="size-3.5" />
                                <span>Focus on Map</span>
                            </Link>
                        </div>
                    </div>

                    <!-- 4 KPI Metrics Cards -->
                    <div
                        class="grid grid-cols-2 gap-3 border-t border-border/60 pt-2 sm:grid-cols-4"
                    >
                        <div
                            class="rounded-xl border border-border/50 bg-muted/30 p-3.5"
                        >
                            <span
                                class="block font-sans text-[11px] text-muted-foreground"
                                >Active Incidents</span
                            >
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span
                                    class="font-mono text-2xl font-black text-foreground"
                                >
                                    {{ countryData.summary.total_events }}
                                </span>
                                <span class="text-[10px] text-muted-foreground"
                                    >records</span
                                >
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-border/50 bg-muted/30 p-3.5"
                        >
                            <span
                                class="block font-sans text-[11px] text-muted-foreground"
                                >High Alert Incidents</span
                            >
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span
                                    class="font-mono text-2xl font-black"
                                    :class="
                                        countryData.summary.high_alerts_count >
                                        0
                                            ? 'text-rose-500'
                                            : 'text-foreground'
                                    "
                                >
                                    {{ countryData.summary.high_alerts_count }}
                                </span>
                                <span class="text-[10px] text-muted-foreground"
                                    >Red / Orange</span
                                >
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-border/50 bg-muted/30 p-3.5"
                        >
                            <span
                                class="block font-sans text-[11px] text-muted-foreground"
                                >Hazard Categories</span
                            >
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span
                                    class="font-mono text-2xl font-black text-foreground"
                                >
                                    {{ countryData.summary.categories_count }}
                                </span>
                                <span class="text-[10px] text-muted-foreground"
                                    >types active</span
                                >
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-border/50 bg-muted/30 p-3.5"
                        >
                            <span
                                class="block font-sans text-[11px] text-muted-foreground"
                                >Telemetry Status</span
                            >
                            <div class="mt-1 flex items-baseline gap-1.5">
                                <span
                                    class="flex items-center gap-1 text-sm font-bold text-emerald-600 dark:text-emerald-400"
                                >
                                    <span
                                        class="size-2 rounded-full bg-emerald-500"
                                    ></span>
                                    <span>Synchronized</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Two-Column Layout: Analytics (Left) & Weather/Quick Links (Right) -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Left: Analytics & Charts (2 cols) -->
                    <div class="space-y-6 lg:col-span-2">
                        <CountryAnalytics
                            :by-category="countryData.analytics.by_category"
                            :by-country="countryData.analytics.by_country"
                            :historical-trends="
                                countryData.analytics.historical_trends
                            "
                            :selected-country="countryData.selected_country"
                            :total-country-events="
                                countryData.summary.total_events
                            "
                            @select-country="selectCountry"
                        />
                    </div>

                    <!-- Right: Weather & Regional Actions (1 col) -->
                    <div class="space-y-6">
                        <CountryWeatherCard
                            :weather="countryData.weather"
                            :country-name="countryData.country_meta.name"
                            :has-active-weather-warnings="hasWeatherWarnings"
                        />

                        <!-- Direct Map Shortcut Card -->
                        <Card class="border-border bg-card shadow-sm">
                            <CardContent class="space-y-3 p-5">
                                <h3
                                    class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Geospatial Navigation
                                </h3>
                                <p
                                    class="text-xs leading-relaxed text-muted-foreground"
                                >
                                    Switch to the full interactive Leaflet map
                                    to view pin clusters and bounding
                                    coordinates across Southeast Asia:
                                </p>
                                <div class="pt-1">
                                    <Link
                                        :href="`/map?country=${countryData.selected_country}`"
                                        class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary py-2 text-xs font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                                    >
                                        <Globe class="size-3.5" />
                                        <span
                                            >Open in Interactive Disaster
                                            Map</span
                                        >
                                    </Link>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Emergency Response Information Card -->
                <EmergencyResponseCard
                    :selected-country="countryData.selected_country"
                />

                <!-- Active Events Incident Stream for this Country -->
                <div class="space-y-4 border-t border-border/60 pt-4">
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full bg-rose-500"
                                ></span>
                                <h3
                                    class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    Active Disaster Bulletins ({{
                                        countryData.country_meta.name
                                    }})
                                </h3>
                            </div>
                            <h4
                                class="mt-0.5 text-lg font-bold text-foreground"
                            >
                                {{ filteredEvents.length }} Verified Event{{
                                    filteredEvents.length === 1 ? '' : 's'
                                }}
                            </h4>
                        </div>

                        <!-- Search and Filter Controls -->
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="relative w-full sm:w-56">
                                <Search
                                    class="absolute top-2.5 left-2.5 size-3.5 text-muted-foreground"
                                />
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    placeholder="Search country incidents..."
                                    class="h-8.5 w-full rounded-lg border border-border bg-card pr-3 pl-8 text-xs text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none"
                                />
                            </div>

                            <!-- Category Filter Dropdown -->
                            <select
                                v-model="selectedCategoryFilter"
                                class="h-8.5 rounded-lg border border-border bg-card px-2.5 text-xs text-foreground focus:border-primary focus:outline-none"
                            >
                                <option value="all">All Categories</option>
                                <option
                                    v-for="cat in availableCategories"
                                    :key="cat"
                                    :value="cat"
                                >
                                    {{ cat }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Events Grid -->
                    <div
                        v-if="filteredEvents.length > 0"
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <Card
                            v-for="ev in filteredEvents"
                            :key="ev.id"
                            class="group relative flex cursor-pointer flex-col justify-between overflow-hidden border border-border/80 bg-card transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-md"
                            @click="router.visit('/events/' + ev.id)"
                        >
                            <CardContent
                                class="flex h-full flex-col justify-between space-y-3 p-4"
                            >
                                <div>
                                    <div
                                        class="mb-2 flex items-center justify-between gap-2"
                                    >
                                        <span
                                            :class="[
                                                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                                getCategoryColor(
                                                    ev.disaster_type ||
                                                        ev.category,
                                                    ev.severity_level,
                                                ),
                                            ]"
                                        >
                                            <component
                                                :is="
                                                    getCategoryIcon(
                                                        ev.disaster_type ||
                                                            ev.category,
                                                    )
                                                "
                                                class="size-3"
                                            />
                                            <span>{{
                                                ev.disaster_type || ev.category
                                            }}</span>
                                        </span>

                                        <div class="flex items-center gap-1.5">
                                            <Badge
                                                v-if="ev.is_community_report"
                                                variant="outline"
                                                class="border-purple-500/40 bg-purple-500/10 px-1.5 py-0 text-[10px] font-bold tracking-wider text-purple-600 uppercase dark:text-purple-400"
                                            >
                                                Community Verified
                                            </Badge>
                                            <Badge
                                                v-else-if="
                                                    ev.severity_level === 'Red'
                                                "
                                                variant="destructive"
                                                class="px-1.5 py-0 text-[10px] font-bold tracking-wider uppercase"
                                            >
                                                Red Alert
                                            </Badge>
                                            <Badge
                                                v-else-if="
                                                    ev.severity_level ===
                                                    'Orange'
                                                "
                                                variant="outline"
                                                class="border-amber-500/40 bg-amber-500/10 px-1.5 py-0 text-[10px] font-bold text-amber-600 uppercase dark:text-amber-400"
                                            >
                                                Orange Alert
                                            </Badge>
                                            <Badge
                                                v-else-if="
                                                    ev.severity_level ===
                                                    'Green'
                                                "
                                                variant="outline"
                                                class="border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0 text-[10px] font-bold text-emerald-600 uppercase dark:text-emerald-400"
                                            >
                                                Green Alert
                                            </Badge>

                                            <span
                                                class="flex items-center gap-0.5 font-mono text-[11px] text-muted-foreground"
                                            >
                                                <Clock
                                                    class="size-3 text-muted-foreground/70"
                                                />
                                                <span>{{
                                                    formatDateTime(
                                                        ev.event_date ||
                                                            ev.event_time,
                                                    )
                                                }}</span>
                                            </span>
                                        </div>
                                    </div>

                                    <h4
                                        class="line-clamp-2 text-sm leading-snug font-bold text-foreground transition-colors group-hover:text-primary"
                                    >
                                        {{ ev.title }}
                                    </h4>

                                    <p
                                        v-if="
                                            ev.location &&
                                            ev.location.toLowerCase() !==
                                                ev.country.toLowerCase()
                                        "
                                        class="mt-1 line-clamp-1 text-[11px] font-medium text-foreground/80"
                                    >
                                        {{ ev.location }}
                                    </p>

                                    <p
                                        v-if="ev.description"
                                        class="mt-1.5 line-clamp-2 text-xs text-muted-foreground"
                                    >
                                        {{ ev.description }}
                                    </p>
                                </div>

                                <div
                                    class="space-y-2 border-t border-border/50 pt-2 text-[11px]"
                                >
                                    <div
                                        class="flex items-center justify-between text-muted-foreground"
                                    >
                                        <span class="flex items-center gap-1">
                                            <MapPin
                                                class="size-3 text-muted-foreground/80"
                                            />
                                            <strong
                                                class="font-medium text-foreground"
                                                >{{ ev.country }}</strong
                                            >
                                        </span>

                                        <span
                                            v-if="ev.magnitude"
                                            class="font-mono font-bold text-foreground"
                                        >
                                            M {{ ev.magnitude.toFixed(1) }}
                                        </span>
                                        <span
                                            v-else-if="
                                                ev.severity || ev.severity_text
                                            "
                                            class="font-mono text-[10px]"
                                        >
                                            {{
                                                ev.severity || ev.severity_text
                                            }}
                                        </span>
                                    </div>

                                    <div
                                        class="flex items-center justify-between pt-1"
                                    >
                                        <span
                                            class="py-0.2 rounded bg-muted px-1.5 font-mono text-[10px] font-semibold text-foreground"
                                        >
                                            {{
                                                (
                                                    ev.sources || [ev.source]
                                                ).join(', ')
                                            }}
                                        </span>

                                        <div class="flex items-center gap-2">
                                            <Link
                                                :href="`/events/${ev.id}`"
                                                class="text-[11px] font-semibold text-primary hover:underline"
                                                @click.stop
                                            >
                                                <span>Details &rarr;</span>
                                            </Link>
                                            <a
                                                :href="ev.source_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-muted-foreground hover:text-foreground"
                                                @click.stop
                                            >
                                                <ExternalLink class="size-3" />
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Clean Empty State for Country with 0 events -->
                    <div
                        v-else
                        class="space-y-3 rounded-xl border border-border/70 bg-card p-8 text-center sm:p-12"
                    >
                        <div
                            class="mx-auto flex size-12 items-center justify-center rounded-full border border-emerald-500/20 bg-emerald-500/10 text-emerald-500"
                        >
                            <ShieldAlert class="size-6" />
                        </div>
                        <h4 class="text-sm font-bold text-foreground">
                            No Active Disaster Hazards in
                            {{ countryData.country_meta.name }}
                        </h4>
                        <p
                            class="mx-auto max-w-md text-xs leading-relaxed text-muted-foreground"
                        >
                            Stored GDACS and USGS feeds indicate zero unmanaged high or moderate
                            natural hazard bulletins for
                            {{ countryData.country_meta.name }} at this time.
                        </p>
                        <div class="pt-2">
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                                @click="selectCountry('All')"
                            >
                                <span
                                    >View all Southeast Asia active incidents
                                    &rarr;</span
                                >
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
