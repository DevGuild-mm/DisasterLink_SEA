<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Calendar,
    Clock,
    ExternalLink,
    Filter,
    Flame,
    Globe,
    MapPin,
    RefreshCw,
    RotateCcw,
    Search,
    ShieldAlert,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type {
    AseanCountry,
    DashboardData,
    DisasterEvent,
    SourceFreshness,
} from '@/types/disaster';

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const props = withDefaults(
    defineProps<{
        events: DisasterEvent[];
        countries: Record<string, AseanCountry>;
        selectedCountry: string;
        selectedCategory: string;
        selectedSeverity?: string;
        selectedDateRange?: string;
        isLoading?: boolean;
        sources?: DashboardData['sources'] | null;
    }>(),
    {
        selectedSeverity: 'all',
        selectedDateRange: 'all',
        isLoading: false,
        sources: null,
    },
);

const emit = defineEmits<{
    (e: 'update:selectedCountry', country: string): void;
    (e: 'update:selectedCategory', category: string): void;
    (e: 'update:selectedSeverity', severity: string): void;
    (e: 'update:selectedDateRange', dateRange: string): void;
    (e: 'select-event', event: DisasterEvent): void;
}>();

const searchQuery = ref('');
const displayLimit = ref(15);

const localSeverity = ref<string>(props.selectedSeverity || 'all');
const localDateRange = ref<string>(props.selectedDateRange || 'all');

watch(
    () => props.selectedSeverity,
    (val) => {
        if (val !== undefined) localSeverity.value = val;
    },
);
watch(
    () => props.selectedDateRange,
    (val) => {
        if (val !== undefined) localDateRange.value = val;
    },
);

function setSeverityFilter(val: string) {
    localSeverity.value = val;
    emit('update:selectedSeverity', val);
}

function setDateRangeFilter(val: string) {
    localDateRange.value = val;
    emit('update:selectedDateRange', val);
}

function resetAllFilters() {
    searchQuery.value = '';
    emit('update:selectedCountry', 'All');
    emit('update:selectedCategory', 'all');
    setSeverityFilter('all');
    setDateRangeFilter('all');
}

const hasActiveFilters = computed(
    () =>
        searchQuery.value.trim() !== '' ||
        props.selectedCountry !== 'All' ||
        props.selectedCategory !== 'all' ||
        localSeverity.value !== 'all' ||
        localDateRange.value !== 'all',
);

const categoryOptions = [
    { id: 'all', label: 'All Incidents', icon: Globe },
    { id: 'earthquake', label: 'Earthquakes', icon: Activity },
    { id: 'cyclone', label: 'Cyclones & Storms', icon: Wind },
    { id: 'flood', label: 'Floods', icon: Waves },
    { id: 'wildfire', label: 'Wildfires', icon: Flame },
    { id: 'volcano', label: 'Volcanoes', icon: AlertTriangle },
];

const eventSources = computed<SourceFreshness[]>(() => {
    if (!props.sources) return [];
    return [
        props.sources.gdacs,
        props.sources.usgs,
        props.sources.eonet,
    ].filter((s): s is SourceFreshness => Boolean(s));
});

const staleEventSources = computed(() =>
    eventSources.value.filter((s) => s.is_stale || s.status === 'Stale'),
);

const unavailableEventSources = computed(() =>
    eventSources.value.filter((s) => s.status === 'Unavailable'),
);

const allEventSourcesUnavailable = computed(
    () =>
        eventSources.value.length > 0 &&
        unavailableEventSources.value.length === eventSources.value.length,
);

// Filtered and searched events
const filteredEvents = computed(() => {
    const eventList = Array.isArray(props.events) ? props.events : [];
    const countryMap = props.countries || {};
    const nowMs = Date.now();

    return eventList.filter((ev) => {
        // Country filter
        if (props.selectedCountry !== 'All') {
            const evCountry = (ev.country || '').toLowerCase();
            const evLocation = (ev.location || '').toLowerCase();
            const target = props.selectedCountry.toLowerCase();
            if (
                !evCountry.includes(target) &&
                !evLocation.includes(target) &&
                ev.iso3 !== countryMap[props.selectedCountry]?.iso3
            ) {
                return false;
            }
        }

        // Category filter
        if (props.selectedCategory !== 'all') {
            const evCat = (ev.disaster_type || ev.category || '').toLowerCase();
            const target = props.selectedCategory.toLowerCase();
            if (target === 'cyclone') {
                if (!evCat.includes('cyclone') && !evCat.includes('storm'))
                    return false;
            } else if (!evCat.includes(target)) {
                return false;
            }
        }

        // Severity filter (using only real provider-supplied severity)
        if (localSeverity.value !== 'all') {
            const sevLevel = ev.severity_level ?? null;
            const hasAnySeverity = Boolean(
                sevLevel || ev.severity || ev.severity_text,
            );
            if (localSeverity.value === 'unclassified') {
                if (hasAnySeverity) return false;
            } else if (localSeverity.value === 'high') {
                if (sevLevel !== 'Red' && sevLevel !== 'Orange') return false;
            } else if (sevLevel !== localSeverity.value) {
                return false;
            }
        }

        // Event Date Range filter
        if (localDateRange.value !== 'all') {
            const rawDate = ev.event_date || ev.event_time;
            if (!rawDate) return false;
            const eventMs = new Date(rawDate).getTime();
            if (Number.isNaN(eventMs)) return false;

            const ageHours = (nowMs - eventMs) / (1000 * 60 * 60);
            if (localDateRange.value === '24h' && ageHours > 24) return false;
            if (localDateRange.value === '7d' && ageHours > 24 * 7)
                return false;
            if (localDateRange.value === '30d' && ageHours > 24 * 30)
                return false;
        }

        // Search text
        if (searchQuery.value.trim()) {
            const query = searchQuery.value.toLowerCase().trim();
            const title = (ev.title || '').toLowerCase();
            const desc = (ev.description || '').toLowerCase();
            const country = (ev.country || '').toLowerCase();
            const loc = (ev.location || '').toLowerCase();
            const src = (ev.source || '').toLowerCase();
            if (
                !title.includes(query) &&
                !desc.includes(query) &&
                !country.includes(query) &&
                !loc.includes(query) &&
                !src.includes(query)
            ) {
                return false;
            }
        }

        return true;
    });
});

const visibleEvents = computed(() => {
    return filteredEvents.value.slice(0, displayLimit.value);
});

function formatRelativeTime(isoString: string | null | undefined): string {
    if (!isoString) return 'Time not supplied';
    if (!isMounted.value) return isoString.slice(0, 10);
    try {
        const date = new Date(isoString);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMins = Math.floor(diffMs / (1000 * 60));
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 30) return `${diffDays}d ago`;

        return date.toLocaleDateString([], {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    } catch {
        return isoString;
    }
}

function formatDateTime(isoString: string | null | undefined): string {
    if (!isoString) return 'Not supplied';
    if (!isMounted.value) return isoString.replace('T', ' ').slice(0, 16);
    try {
        return new Date(isoString).toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return isoString;
    }
}

function getCategoryIcon(cat: string) {
    const c = (cat || '').toLowerCase();
    if (c.includes('earthquake')) return Activity;
    if (c.includes('cyclone') || c.includes('storm')) return Wind;
    if (c.includes('flood')) return Waves;
    if (c.includes('wildfire') || c.includes('fire')) return Flame;
    if (c.includes('volcano')) return AlertTriangle;
    return ShieldAlert;
}

function getCategoryColor(cat: string) {
    const c = (cat || '').toLowerCase();
    if (c.includes('earthquake'))
        return 'text-amber-500 bg-amber-500/10 border-amber-500/20';
    if (c.includes('cyclone') || c.includes('storm'))
        return 'text-cyan-500 bg-cyan-500/10 border-cyan-500/20';
    if (c.includes('flood'))
        return 'text-blue-500 bg-blue-500/10 border-blue-500/20';
    if (c.includes('wildfire') || c.includes('fire'))
        return 'text-orange-500 bg-orange-500/10 border-orange-500/20';
    if (c.includes('volcano'))
        return 'text-purple-500 bg-purple-500/10 border-purple-500/20';
    return 'text-slate-500 bg-slate-500/10 border-slate-500/20';
}
</script>

<template>
    <section id="events" class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Header & Search Controls -->
            <div
                class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full bg-blue-500"></span>
                        <h3
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Real-Time Incident Stream
                        </h3>
                    </div>
                    <h2
                        class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        Latest Disaster Events & Bulletins
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Sorted newest first • Verified reports from GDACS, USGS,
                        and NASA EONET
                    </p>
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Search Input -->
                    <div class="relative w-full sm:w-56">
                        <Search
                            class="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Filter by keyword / location..."
                            class="h-8 w-full rounded-lg border border-border bg-card pr-3 pl-8 text-xs text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none"
                        />
                    </div>

                    <!-- Country Filter -->
                    <div
                        class="flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-xs shadow-xs"
                    >
                        <MapPin class="size-3.5 text-muted-foreground" />
                        <select
                            :value="selectedCountry"
                            aria-label="Filter by ASEAN country"
                            class="cursor-pointer bg-transparent text-xs font-medium text-foreground focus:outline-none"
                            @change="
                                emit(
                                    'update:selectedCountry',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="(c, key) in countries"
                                :key="key"
                                :value="key"
                                class="bg-card text-foreground"
                            >
                                {{ c.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Severity Filter -->
                    <div
                        class="flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-xs shadow-xs"
                    >
                        <ShieldAlert class="size-3.5 text-muted-foreground" />
                        <select
                            :value="localSeverity"
                            aria-label="Filter by severity"
                            class="cursor-pointer bg-transparent text-xs font-medium text-foreground focus:outline-none"
                            @change="
                                setSeverityFilter(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="all" class="bg-card text-foreground">
                                All Severities
                            </option>
                            <option value="Red" class="bg-card text-foreground">
                                Red Alert
                            </option>
                            <option
                                value="Orange"
                                class="bg-card text-foreground"
                            >
                                Orange Alert
                            </option>
                            <option
                                value="Green"
                                class="bg-card text-foreground"
                            >
                                Green Alert
                            </option>
                            <option
                                value="high"
                                class="bg-card text-foreground"
                            >
                                Red & Orange
                            </option>
                            <option
                                value="unclassified"
                                class="bg-card text-foreground"
                            >
                                Unclassified
                            </option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div
                        class="flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-xs shadow-xs"
                    >
                        <Calendar class="size-3.5 text-muted-foreground" />
                        <select
                            :value="localDateRange"
                            aria-label="Filter by event date range"
                            class="cursor-pointer bg-transparent text-xs font-medium text-foreground focus:outline-none"
                            @change="
                                setDateRangeFilter(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="all" class="bg-card text-foreground">
                                All Dates
                            </option>
                            <option value="24h" class="bg-card text-foreground">
                                Last 24 Hours
                            </option>
                            <option value="7d" class="bg-card text-foreground">
                                Last 7 Days
                            </option>
                            <option value="30d" class="bg-card text-foreground">
                                Last 30 Days
                            </option>
                        </select>
                    </div>

                    <!-- Reset Filters Button -->
                    <Button
                        v-if="hasActiveFilters"
                        variant="outline"
                        size="sm"
                        class="h-8 cursor-pointer gap-1 border-border bg-card text-xs"
                        @click="resetAllFilters"
                    >
                        <RotateCcw class="size-3" />
                        <span>Reset</span>
                    </Button>
                </div>
            </div>

            <!-- Category Filter Pills -->
            <div
                class="mb-5 flex flex-wrap items-center gap-1.5 overflow-x-auto pb-1"
            >
                <button
                    v-for="cat in categoryOptions"
                    :key="cat.id"
                    type="button"
                    :class="[
                        'inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-all',
                        selectedCategory === cat.id
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'border border-border/80 bg-card text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="emit('update:selectedCategory', cat.id)"
                >
                    <component :is="cat.icon" class="size-3" />
                    <span>{{ cat.label }}</span>
                </button>
            </div>

            <!-- Stale / Partial Source Notice -->
            <div
                v-if="
                    staleEventSources.length > 0 ||
                    (unavailableEventSources.length > 0 &&
                        !allEventSourcesUnavailable)
                "
                class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-300"
            >
                <div class="flex items-start gap-2.5">
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-amber-500"
                    />
                    <div class="space-y-1">
                        <p class="font-semibold">
                            Partial or Stale Upstream Telemetry Notice
                        </p>
                        <p v-if="staleEventSources.length > 0">
                            Serving cached events for:
                            <strong>{{
                                staleEventSources
                                    .map(
                                        (s) =>
                                            `${s.source} (last updated ${formatDateTime(s.last_successful_fetch)})`,
                                    )
                                    .join(', ')
                            }}</strong
                            >.
                        </p>
                        <p v-if="unavailableEventSources.length > 0">
                            Temporarily unreachable upstream source(s):
                            <strong>{{
                                unavailableEventSources
                                    .map((s) => s.source)
                                    .join(', ')
                            }}</strong
                            >. Showing verified data from remaining connected
                            providers.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div
                v-if="isLoading && events.length === 0"
                class="rounded-xl border border-border bg-card/50 p-12 text-center"
            >
                <RefreshCw
                    class="mx-auto mb-3 size-8 animate-spin text-muted-foreground"
                />
                <h3 class="text-sm font-semibold text-foreground">
                    Synchronizing real-time disaster feeds...
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                    Querying GDACS, USGS Earthquake Hazards, and NASA EONET for
                    Southeast Asia incidents.
                </p>
            </div>

            <!-- Error / All Sources Unavailable State -->
            <div
                v-else-if="allEventSourcesUnavailable && events.length === 0"
                class="rounded-xl border border-destructive/40 bg-destructive/5 p-12 text-center"
            >
                <div
                    class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-destructive/15 text-destructive"
                >
                    <AlertCircle class="size-6" />
                </div>
                <h3 class="text-sm font-semibold text-foreground">
                    Upstream disaster feeds are currently unavailable
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                    Could not retrieve live or cached event data from GDACS,
                    USGS, or NASA EONET. DisasterLinkSEA never displays fake or
                    fabricated events when upstream providers are offline.
                </p>
            </div>

            <!-- Empty State (Sources Online, 0 Matching Events) -->
            <div
                v-else-if="filteredEvents.length === 0"
                class="rounded-xl border border-dashed border-border p-12 text-center"
            >
                <div
                    class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <Filter class="size-6" />
                </div>
                <h3 class="text-sm font-semibold text-foreground">
                    No matching disaster events found
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                    No active incident records match the current filters. Note:
                    an absence of reported events does not certify complete
                    safety; always refer to local civil defense authorities.
                </p>
                <Button
                    v-if="hasActiveFilters"
                    variant="outline"
                    size="sm"
                    class="mt-3 cursor-pointer text-xs"
                    @click="resetAllFilters"
                >
                    Reset All Filters
                </Button>
            </div>

            <!-- Feed Grid -->
            <div
                v-else
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card
                    v-for="ev in visibleEvents"
                    :key="ev.id"
                    class="group relative flex cursor-pointer flex-col justify-between overflow-hidden border border-border/80 bg-card transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-md"
                    @click="router.visit('/events/' + ev.id)"
                >
                    <CardContent
                        class="flex h-full flex-col justify-between space-y-3 p-4"
                    >
                        <!-- Top Metadata Row -->
                        <div>
                            <div
                                class="mb-2 flex items-center justify-between gap-2"
                            >
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                        getCategoryColor(
                                            ev.disaster_type || ev.category,
                                        ),
                                    ]"
                                >
                                    <component
                                        :is="
                                            getCategoryIcon(
                                                ev.disaster_type || ev.category,
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
                                    <!-- Severity Badge (Only when supplied by provider) -->
                                    <Badge
                                        v-else-if="ev.severity_level === 'Red'"
                                        variant="destructive"
                                        class="px-1.5 py-0 text-[10px] font-bold tracking-wider uppercase"
                                    >
                                        Red Alert
                                    </Badge>
                                    <Badge
                                        v-else-if="
                                            ev.severity_level === 'Orange'
                                        "
                                        variant="outline"
                                        class="border-amber-500/40 bg-amber-500/10 px-1.5 py-0 text-[10px] font-bold text-amber-600 uppercase dark:text-amber-400"
                                    >
                                        Orange Alert
                                    </Badge>
                                    <Badge
                                        v-else-if="
                                            ev.severity_level === 'Green'
                                        "
                                        variant="outline"
                                        class="border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0 text-[10px] font-bold text-emerald-600 uppercase dark:text-emerald-400"
                                    >
                                        Green Alert
                                    </Badge>

                                    <!-- Event Date / Time Ago -->
                                    <span
                                        class="flex items-center gap-0.5 font-mono text-[11px] text-muted-foreground"
                                        :title="
                                            formatDateTime(
                                                ev.event_date || ev.event_time,
                                            )
                                        "
                                    >
                                        <Clock
                                            class="size-3 text-muted-foreground/70"
                                        />
                                        {{
                                            formatRelativeTime(
                                                ev.event_date || ev.event_time,
                                            )
                                        }}
                                    </span>
                                </div>
                            </div>

                            <!-- Title -->
                            <h4
                                class="line-clamp-2 text-sm leading-snug font-bold text-foreground transition-colors group-hover:text-primary"
                            >
                                {{ ev.title }}
                            </h4>

                            <!-- Specific Location (if distinct from country) -->
                            <p
                                v-if="
                                    ev.location &&
                                    ev.location.toLowerCase() !==
                                        ev.country.toLowerCase() &&
                                    ev.location.toLowerCase() !==
                                        ev.title.toLowerCase()
                                "
                                class="mt-1 line-clamp-1 text-[11px] font-medium text-foreground/80"
                            >
                                {{ ev.location }}
                            </p>

                            <!-- Description / Context -->
                            <p
                                v-if="ev.description"
                                class="mt-1.5 line-clamp-2 text-xs text-muted-foreground"
                            >
                                {{ ev.description }}
                            </p>
                        </div>

                        <!-- Technical Metrics Details -->
                        <div class="space-y-2 border-t border-border/50 pt-2">
                            <div
                                class="flex flex-wrap items-center justify-between gap-1 text-[11px] text-muted-foreground"
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
                                    v-if="
                                        ev.magnitude !== null &&
                                        ev.magnitude !== undefined
                                    "
                                    class="font-mono font-bold text-foreground"
                                >
                                    M {{ ev.magnitude.toFixed(1) }}
                                </span>
                                <span
                                    v-else-if="ev.severity || ev.severity_text"
                                    class="max-w-[150px] truncate font-mono text-muted-foreground"
                                >
                                    {{ ev.severity || ev.severity_text }}
                                </span>
                            </div>

                            <!-- Depth & Coordinates (only when supplied) -->
                            <div
                                v-if="
                                    (ev.depth_km !== null &&
                                        ev.depth_km !== undefined) ||
                                    ev.coordinates
                                "
                                class="flex items-center justify-between font-mono text-[10px] text-muted-foreground/80"
                            >
                                <span
                                    v-if="
                                        ev.depth_km !== null &&
                                        ev.depth_km !== undefined
                                    "
                                >
                                    Depth: {{ ev.depth_km }} km
                                </span>
                                <span v-else></span>
                                <span v-if="ev.coordinates">
                                    {{ ev.coordinates[0].toFixed(2) }}°,
                                    {{ ev.coordinates[1].toFixed(2) }}°
                                </span>
                            </div>

                            <!-- Source Footnote & External Link -->
                            <div
                                class="flex items-center justify-between pt-1 text-[11px]"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span
                                        class="text-[10px] text-muted-foreground"
                                        >Source:</span
                                    >
                                    <div class="flex items-center gap-1">
                                        <span
                                            v-for="s in ev.sources || [
                                                ev.source,
                                            ]"
                                            :key="s"
                                            :class="[
                                                'py-0.2 inline-block rounded px-1.5 font-mono text-[10px] font-medium',
                                                s === 'Community Report'
                                                    ? 'border border-purple-500/30 bg-purple-500/15 font-semibold text-purple-600 dark:text-purple-400'
                                                    : 'bg-muted text-foreground',
                                            ]"
                                        >
                                            {{ s }}
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2.5">
                                    <Link
                                        :href="'/events/' + ev.id"
                                        class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                                        @click.stop
                                    >
                                        <span>Details &rarr;</span>
                                    </Link>

                                    <a
                                        :href="ev.source_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400"
                                        @click.stop
                                    >
                                        <span>Bulletin</span>
                                        <ExternalLink class="size-3" />
                                    </a>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Load More Button -->
            <div
                v-if="filteredEvents.length > displayLimit"
                class="mt-6 text-center"
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="cursor-pointer border-border px-6 text-xs"
                    @click="displayLimit += 15"
                >
                    Load More Events (Showing {{ displayLimit }} of
                    {{ filteredEvents.length }})
                </Button>
            </div>
        </div>
    </section>
</template>
