<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Calendar,
    Check,
    Clock,
    Copy,
    ExternalLink,
    Flame,
    Globe,
    Image as ImageIcon,
    Layers,
    MapPin,
    RefreshCw,
    Share2,
    ShieldAlert,
    Waves,
    Wind,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import EmergencyResponseCard from '@/components/disaster/EmergencyResponseCard.vue';
import EventMiniMap from '@/components/disaster/EventMiniMap.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type { DashboardData, DisasterEvent } from '@/types/disaster';

const props = defineProps<{
    event: DisasterEvent | null;
    eventId: string;
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

// Active event is either the prop event, or updated from real-time telemetry if matching
const activeEvent = computed<DisasterEvent | null>(() => {
    if (!props.event) {
        // Check if fresh telemetry now contains this event
        const match = data.value.events.find(
            (e) => e.id === props.eventId || e.source_id === props.eventId,
        );
        return match || null;
    }

    // Check if live telemetry has an updated version
    const updated = data.value.events.find((e) => e.id === props.event!.id);
    return updated || props.event;
});

const isCopied = ref(false);
const selectedModalPhoto = ref<string | null>(null);

function copyShareUrl() {
    if (typeof window === 'undefined') return;
    navigator.clipboard
        .writeText(window.location.href)
        .then(() => {
            isCopied.value = true;
            setTimeout(() => {
                isCopied.value = false;
            }, 2500);
        })
        .catch(() => {});
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
    if (cat.includes('drought'))
        return 'text-yellow-500 bg-yellow-500/10 border-yellow-500/20';
    return 'text-muted-foreground bg-muted border-border';
}

function getCategoryIcon(category?: string) {
    const cat = (category || '').toLowerCase();
    if (cat.includes('earthquake')) return Activity;
    if (cat.includes('cyclone') || cat.includes('storm')) return Wind;
    if (cat.includes('flood') || cat.includes('wave')) return Waves;
    if (cat.includes('wildfire') || cat.includes('fire')) return Flame;
    if (cat.includes('volcano')) return AlertTriangle;
    return ShieldAlert;
}

function formatDateTime(dateStr?: string | null): string {
    if (!dateStr) return 'Not supplied';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            timeZoneName: 'short',
        });
    } catch {
        return dateStr;
    }
}

function formatUtcTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return '';
        return d.toUTCString();
    } catch {
        return '';
    }
}

// Related events in the same country or category (excluding this event)
const relatedEvents = computed(() => {
    if (!activeEvent.value) return [];
    return data.value.events
        .filter(
            (e) =>
                e.id !== activeEvent.value!.id &&
                (e.country === activeEvent.value!.country ||
                    e.category === activeEvent.value!.category),
        )
        .slice(0, 3);
});
</script>

<template>
    <Head
        :title="
            activeEvent
                ? `${activeEvent.title} – DisasterLinkSEA`
                : `Event Details – DisasterLinkSEA`
        "
    >
        <meta
            name="description"
            :content="
                activeEvent
                    ? `Disaster event details, metrics, location map, and timeline for ${activeEvent.title}.`
                    : 'Disaster event details on DisasterLinkSEA.'
            "
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
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <!-- Navigation Breadcrumb & Back Actions -->
                <div
                    class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-4"
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
                            href="/events"
                            class="transition-colors hover:text-foreground"
                            >Live Events</Link
                        >
                        <span>/</span>
                        <span
                            class="max-w-[200px] truncate font-medium text-foreground sm:max-w-md"
                        >
                            {{ activeEvent ? activeEvent.title : eventId }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            href="/events"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground shadow-sm transition-colors hover:bg-muted"
                        >
                            <ArrowLeft class="size-3.5" />
                            <span>Back to Events</span>
                        </Link>
                        <Link
                            href="/map"
                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground shadow-sm transition-colors hover:bg-muted"
                        >
                            <Globe class="size-3.5" />
                            <span>Disaster Map</span>
                        </Link>
                    </div>
                </div>

                <!-- Case 1: Event Found -->
                <div v-if="activeEvent" class="space-y-6">
                    <!-- Event Hero Header -->
                    <div
                        class="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-7"
                    >
                        <div
                            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="space-y-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-bold tracking-wider uppercase',
                                            getCategoryColor(
                                                activeEvent.disaster_type ||
                                                    activeEvent.category,
                                                activeEvent.severity_level,
                                            ),
                                        ]"
                                    >
                                        <component
                                            :is="
                                                getCategoryIcon(
                                                    activeEvent.disaster_type ||
                                                        activeEvent.category,
                                                )
                                            "
                                            class="size-3.5"
                                        />
                                        <span>{{
                                            activeEvent.disaster_type ||
                                            activeEvent.category
                                        }}</span>
                                    </span>

                                    <Badge
                                        v-if="activeEvent.is_community_report"
                                        variant="outline"
                                        class="border-purple-500/40 bg-purple-500/10 px-2.5 py-1 text-xs font-bold tracking-wider text-purple-600 uppercase dark:text-purple-400"
                                    >
                                        Community Verified
                                    </Badge>
                                    <!-- Severity Badge (Only when supplied) -->
                                    <Badge
                                        v-else-if="
                                            activeEvent.severity_level === 'Red'
                                        "
                                        variant="destructive"
                                        class="px-2.5 py-1 text-xs font-bold tracking-wider uppercase"
                                    >
                                        Red Alert
                                    </Badge>
                                    <Badge
                                        v-else-if="
                                            activeEvent.severity_level ===
                                            'Orange'
                                        "
                                        variant="outline"
                                        class="border-amber-500/40 bg-amber-500/10 px-2.5 py-1 text-xs font-bold tracking-wider text-amber-600 uppercase dark:text-amber-400"
                                    >
                                        Orange Alert
                                    </Badge>
                                    <Badge
                                        v-else-if="
                                            activeEvent.severity_level ===
                                            'Green'
                                        "
                                        variant="outline"
                                        class="border-emerald-500/40 bg-emerald-500/10 px-2.5 py-1 text-xs font-bold tracking-wider text-emerald-600 uppercase dark:text-emerald-400"
                                    >
                                        Green Alert
                                    </Badge>

                                    <!-- Magnitude or Score Badge -->
                                    <Badge
                                        v-if="
                                            activeEvent.magnitude !== null &&
                                            activeEvent.magnitude !== undefined
                                        "
                                        variant="outline"
                                        class="border-border bg-muted/60 font-mono text-xs font-bold text-foreground"
                                    >
                                        Magnitude M
                                        {{ activeEvent.magnitude.toFixed(1) }}
                                    </Badge>

                                    <!-- Official Sources -->
                                    <div class="flex items-center gap-1">
                                        <span
                                            v-for="s in activeEvent.sources || [
                                                activeEvent.source,
                                            ]"
                                            :key="s"
                                            class="rounded border border-border/60 bg-muted px-2 py-0.5 font-mono text-[11px] font-semibold text-foreground"
                                        >
                                            {{ s }}
                                        </span>
                                    </div>
                                </div>

                                <h1
                                    class="text-2xl leading-tight font-extrabold tracking-tight text-foreground sm:text-3xl"
                                >
                                    {{ activeEvent.title }}
                                </h1>

                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span class="flex items-center gap-1">
                                        <MapPin class="size-3.5 text-primary" />
                                        <strong
                                            class="font-semibold text-foreground"
                                            >{{ activeEvent.country }}</strong
                                        >
                                        <template
                                            v-if="
                                                activeEvent.location &&
                                                activeEvent.location.toLowerCase() !==
                                                    activeEvent.country.toLowerCase()
                                            "
                                        >
                                            <span
                                                class="text-muted-foreground/60"
                                                >•</span
                                            >
                                            <span>{{
                                                activeEvent.location
                                            }}</span>
                                        </template>
                                    </span>

                                    <span
                                        class="flex items-center gap-1 font-mono"
                                    >
                                        <Clock
                                            class="size-3.5 text-muted-foreground"
                                        />
                                        <span>{{
                                            formatDateTime(
                                                activeEvent.event_date ||
                                                    activeEvent.event_time,
                                            )
                                        }}</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Header Actions -->
                            <div
                                class="flex flex-wrap items-center gap-2 pt-2 lg:pt-0"
                            >
                                <a
                                    :href="activeEvent.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-xs font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
                                >
                                    <span>Official Bulletin</span>
                                    <ExternalLink class="size-3.5" />
                                </a>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="h-9 gap-1.5 border-border bg-card text-xs font-semibold text-foreground hover:bg-muted"
                                    @click="copyShareUrl"
                                >
                                    <Check
                                        v-if="isCopied"
                                        class="size-3.5 text-emerald-500"
                                    />
                                    <Copy v-else class="size-3.5" />
                                    <span>{{
                                        isCopied ? 'Link Copied' : 'Share'
                                    }}</span>
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- Main Grid: Specifications & Timeline (Left), Map & Provenance (Right) -->
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <!-- Left Column (2 Cols) -->
                        <div class="space-y-6 lg:col-span-2">
                            <!-- Specifications Card -->
                            <Card class="border-border bg-card shadow-sm">
                                <CardContent class="space-y-4 p-5 sm:p-6">
                                    <h3
                                        class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Verified Incident Specifications
                                    </h3>

                                    <div
                                        class="grid grid-cols-1 gap-4 rounded-xl border border-border/50 bg-muted/40 p-4 font-mono text-xs sm:grid-cols-2"
                                    >
                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Disaster Category:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.disaster_type ||
                                                activeEvent.category
                                            }}</strong>
                                        </div>

                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Primary Source:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.source
                                            }}</strong>
                                            <span
                                                v-if="activeEvent.source_id"
                                                class="ml-1 text-muted-foreground"
                                                >({{
                                                    activeEvent.source_id
                                                }})</span
                                            >
                                        </div>

                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Country / Territory:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.country
                                            }}</strong>
                                            <span
                                                v-if="activeEvent.iso3"
                                                class="ml-1 text-muted-foreground"
                                                >[{{ activeEvent.iso3 }}]</span
                                            >
                                        </div>

                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Specific Location:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.location ||
                                                activeEvent.country
                                            }}</strong>
                                        </div>

                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Incident Time (Local):</span
                                            >
                                            <strong class="text-foreground">{{
                                                formatDateTime(
                                                    activeEvent.event_date ||
                                                        activeEvent.event_time,
                                                )
                                            }}</strong>
                                        </div>

                                        <div>
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Incident Time (UTC):</span
                                            >
                                            <strong class="text-foreground">{{
                                                formatUtcTime(
                                                    activeEvent.event_date ||
                                                        activeEvent.event_time,
                                                )
                                            }}</strong>
                                        </div>

                                        <div
                                            v-if="activeEvent.last_updated_time"
                                        >
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Last Updated by Agency:</span
                                            >
                                            <strong class="text-foreground">{{
                                                formatDateTime(
                                                    activeEvent.last_updated_time,
                                                )
                                            }}</strong>
                                        </div>

                                        <div
                                            v-if="
                                                activeEvent.severity ||
                                                activeEvent.severity_text
                                            "
                                        >
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Severity Metric:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.severity ||
                                                activeEvent.severity_text
                                            }}</strong>
                                        </div>

                                        <div
                                            v-if="
                                                activeEvent.magnitude !==
                                                    null &&
                                                activeEvent.magnitude !==
                                                    undefined
                                            "
                                        >
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Seismic Magnitude:</span
                                            >
                                            <strong class="text-foreground"
                                                >M
                                                {{
                                                    activeEvent.magnitude.toFixed(
                                                        1,
                                                    )
                                                }}</strong
                                            >
                                        </div>

                                        <div
                                            v-if="
                                                activeEvent.depth_km !== null &&
                                                activeEvent.depth_km !==
                                                    undefined
                                            "
                                        >
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Hypocenter Depth:</span
                                            >
                                            <strong class="text-foreground"
                                                >{{
                                                    activeEvent.depth_km
                                                }}
                                                km</strong
                                            >
                                        </div>

                                        <div class="sm:col-span-2">
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Geographic Coordinates:</span
                                            >
                                            <strong
                                                v-if="activeEvent.coordinates"
                                                class="text-foreground"
                                            >
                                                {{
                                                    activeEvent.coordinates[0].toFixed(
                                                        4,
                                                    )
                                                }}° N,
                                                {{
                                                    activeEvent.coordinates[1].toFixed(
                                                        4,
                                                    )
                                                }}° E
                                            </strong>
                                            <span
                                                v-else
                                                class="text-muted-foreground italic"
                                            >
                                                Not supplied by upstream source
                                            </span>
                                        </div>

                                        <div v-if="activeEvent.submitter_name">
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Citizen Submitter:</span
                                            >
                                            <strong class="text-foreground">{{
                                                activeEvent.submitter_name
                                            }}</strong>
                                        </div>

                                        <div
                                            v-if="activeEvent.review_notes"
                                            class="sm:col-span-2"
                                        >
                                            <span
                                                class="block font-sans text-[11px] text-muted-foreground"
                                                >Moderator Verification
                                                Notes:</span
                                            >
                                            <p
                                                class="mt-0.5 font-sans text-xs text-foreground"
                                            >
                                                {{ activeEvent.review_notes }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Description Section -->
                                    <div
                                        v-if="activeEvent.description"
                                        class="space-y-1.5 pt-2"
                                    >
                                        <h4
                                            class="text-xs font-semibold text-foreground"
                                        >
                                            Official Description & Impact
                                            Assessment
                                        </h4>
                                        <p
                                            class="rounded-lg border border-border/60 bg-card p-3 text-xs leading-relaxed text-muted-foreground"
                                        >
                                            {{ activeEvent.description }}
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Citizen Photographic Evidence -->
                            <Card
                                v-if="
                                    activeEvent.photos &&
                                    activeEvent.photos.length > 0
                                "
                                class="border-border bg-card shadow-sm"
                            >
                                <CardContent class="space-y-3 p-5 sm:p-6">
                                    <h3
                                        class="flex items-center gap-1.5 text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                    >
                                        <ImageIcon
                                            class="size-3.5 text-purple-500"
                                        />
                                        <span
                                            >Citizen Photographic Evidence ({{
                                                activeEvent.photos.length
                                            }})</span
                                        >
                                    </h3>
                                    <div
                                        class="grid grid-cols-1 gap-3 pt-1 sm:grid-cols-3"
                                    >
                                        <div
                                            v-for="(
                                                photoUrl, pIdx
                                            ) in activeEvent.photos"
                                            :key="pIdx"
                                            class="group relative aspect-video cursor-pointer overflow-hidden rounded-xl border border-border bg-muted transition-all hover:ring-2 hover:ring-purple-500"
                                            @click="
                                                selectedModalPhoto = photoUrl
                                            "
                                        >
                                            <img
                                                :src="photoUrl"
                                                alt="Citizen Observation Evidence"
                                                class="size-full object-cover transition-transform group-hover:scale-105"
                                            />
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Event Timeline Section (Requirement 2) -->
                            <Card class="border-border bg-card shadow-sm">
                                <CardContent class="space-y-4 p-5 sm:p-6">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="flex items-center gap-2">
                                            <Clock
                                                class="size-4 text-primary"
                                            />
                                            <h3
                                                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                            >
                                                Chronological Event Timeline
                                            </h3>
                                        </div>
                                        <span
                                            class="font-mono text-[11px] text-muted-foreground"
                                        >
                                            {{
                                                activeEvent.timeline &&
                                                activeEvent.timeline.length > 0
                                                    ? `${activeEvent.timeline.length} stage(s)`
                                                    : 'Single bulletin'
                                            }}
                                        </span>
                                    </div>

                                    <!-- Available Chronological Updates -->
                                    <div
                                        v-if="
                                            activeEvent.timeline &&
                                            activeEvent.timeline.length > 0
                                        "
                                        class="relative space-y-6 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2.5 before:w-0.5 before:bg-border"
                                    >
                                        <div
                                            v-for="(
                                                item, idx
                                            ) in activeEvent.timeline"
                                            :key="idx"
                                            class="group relative"
                                        >
                                            <!-- Dot -->
                                            <div
                                                :class="[
                                                    'absolute top-1 -left-6 size-3 rounded-full border-2 border-background ring-4 ring-muted',
                                                    idx === 0
                                                        ? 'bg-primary'
                                                        : idx ===
                                                            activeEvent.timeline
                                                                .length -
                                                                1
                                                          ? 'bg-emerald-500'
                                                          : 'bg-muted-foreground',
                                                ]"
                                            ></div>

                                            <div
                                                class="space-y-1 rounded-lg border border-border/70 bg-muted/20 p-3.5 transition-colors group-hover:bg-muted/40"
                                            >
                                                <div
                                                    class="flex flex-wrap items-center justify-between gap-2"
                                                >
                                                    <h5
                                                        class="text-xs font-bold text-foreground"
                                                    >
                                                        {{ item.title }}
                                                    </h5>
                                                    <span
                                                        class="font-mono text-[10px] text-muted-foreground"
                                                    >
                                                        {{
                                                            formatDateTime(
                                                                item.timestamp,
                                                            )
                                                        }}
                                                    </span>
                                                </div>

                                                <p
                                                    v-if="item.description"
                                                    class="text-xs leading-relaxed text-muted-foreground"
                                                >
                                                    {{ item.description }}
                                                </p>

                                                <div
                                                    v-if="item.source"
                                                    class="flex items-center gap-1.5 pt-1 font-mono text-[10px] text-muted-foreground"
                                                >
                                                    <span>Reported by:</span>
                                                    <span
                                                        class="py-0.2 rounded bg-muted px-1.5 font-semibold text-foreground"
                                                        >{{ item.source }}</span
                                                    >
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Timeline Data Unavailable Banner -->
                                    <div
                                        v-else
                                        class="space-y-2 rounded-lg border border-border/60 bg-muted/20 p-4 text-xs text-muted-foreground"
                                    >
                                        <div
                                            class="flex items-center gap-2 font-semibold text-foreground"
                                        >
                                            <AlertCircle
                                                class="size-4 text-amber-500"
                                            />
                                            <span
                                                >Multi-Stage Timeline
                                                Unavailable</span
                                            >
                                        </div>
                                        <p class="leading-relaxed">
                                            The upstream provider (<strong
                                                class="text-foreground"
                                                >{{
                                                    activeEvent.source
                                                }}</strong
                                            >) published this incident as a
                                            standalone bulletin at
                                            <span
                                                class="font-mono font-medium text-foreground"
                                                >{{
                                                    formatDateTime(
                                                        activeEvent.event_date ||
                                                            activeEvent.event_time,
                                                    )
                                                }}</span
                                            >. No subsequent revised bulletins
                                            or multi-pass satellite updates were
                                            published for this event ID.
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Data Provenance & Source URLs -->
                            <Card class="border-border bg-card shadow-sm">
                                <CardContent class="space-y-3 p-5 sm:p-6">
                                    <h3
                                        class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Data Provenance & Agency Reports
                                    </h3>
                                    <p
                                        class="text-xs leading-relaxed text-muted-foreground"
                                    >
                                        This incident is verified against
                                        official Southeast Asian monitoring
                                        streams without synthesized telemetry.
                                        Direct links to original bulletins:
                                    </p>

                                    <div class="space-y-2 pt-1">
                                        <div
                                            v-for="(
                                                sourceItem, sIdx
                                            ) in activeEvent.source_urls || [
                                                {
                                                    source: activeEvent.source,
                                                    url: activeEvent.source_url,
                                                },
                                            ]"
                                            :key="sIdx"
                                            class="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/30 p-2.5 text-xs"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="rounded bg-primary/10 px-2 py-0.5 font-mono text-[10px] font-bold text-primary"
                                                >
                                                    {{ sourceItem.source }}
                                                </span>
                                                <span
                                                    class="max-w-xs truncate font-medium text-foreground sm:max-w-md"
                                                >
                                                    Official
                                                    {{ sourceItem.source }}
                                                    Event Bulletin
                                                </span>
                                            </div>

                                            <a
                                                :href="sourceItem.url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex shrink-0 items-center gap-1 font-semibold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400"
                                            >
                                                <span>View Bulletin</span>
                                                <ExternalLink class="size-3" />
                                            </a>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        <!-- Right Column (1 Col): Interactive Mini Map & Related -->
                        <div class="space-y-6">
                            <!-- Mini Map Component (Requirement 3) -->
                            <EventMiniMap :event="activeEvent" />

                            <!-- Related Incidents in Region -->
                            <Card class="border-border bg-card shadow-sm">
                                <CardContent class="space-y-3 p-5">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <h3
                                            class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Related Incidents
                                        </h3>
                                        <Link
                                            href="/events"
                                            class="text-[11px] font-semibold text-primary hover:underline"
                                        >
                                            All Events &rarr;
                                        </Link>
                                    </div>

                                    <div
                                        v-if="relatedEvents.length > 0"
                                        class="space-y-2.5"
                                    >
                                        <Link
                                            v-for="rel in relatedEvents"
                                            :key="rel.id"
                                            :href="`/events/${rel.id}`"
                                            class="block rounded-lg border border-border/60 bg-muted/20 p-3 transition-colors hover:bg-muted/50"
                                        >
                                            <div
                                                class="mb-1 flex items-center justify-between gap-1 text-[10px]"
                                            >
                                                <span
                                                    class="font-bold text-foreground uppercase"
                                                >
                                                    {{
                                                        rel.disaster_type ||
                                                        rel.category
                                                    }}
                                                </span>
                                                <span
                                                    class="font-mono text-muted-foreground"
                                                >
                                                    {{ rel.country }}
                                                </span>
                                            </div>
                                            <h5
                                                class="line-clamp-1 text-xs font-semibold text-foreground"
                                            >
                                                {{ rel.title }}
                                            </h5>
                                            <div
                                                class="mt-1 flex items-center justify-between font-mono text-[10px] text-muted-foreground"
                                            >
                                                <span>{{
                                                    formatDateTime(
                                                        rel.event_date ||
                                                            rel.event_time,
                                                    )
                                                }}</span>
                                                <span
                                                    v-if="rel.magnitude"
                                                    class="font-bold text-foreground"
                                                    >M
                                                    {{
                                                        rel.magnitude.toFixed(1)
                                                    }}</span
                                                >
                                            </div>
                                        </Link>
                                    </div>

                                    <div
                                        v-else
                                        class="py-2 text-center text-xs text-muted-foreground"
                                    >
                                        No other recent incidents matching
                                        {{ activeEvent.country }} at this
                                        moment.
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Emergency Response Information Component -->
                            <EmergencyResponseCard
                                :selected-country="activeEvent.country"
                            />
                        </div>
                    </div>
                </div>

                <!-- Case 2: Event Not Found / Expired -->
                <div
                    v-else
                    class="space-y-5 rounded-2xl border border-border bg-card p-8 text-center shadow-sm sm:p-12"
                >
                    <div
                        class="mx-auto flex size-14 items-center justify-center rounded-full border border-rose-500/20 bg-rose-500/10 text-rose-500"
                    >
                        <AlertCircle class="size-7" />
                    </div>

                    <div class="mx-auto max-w-md space-y-2">
                        <h2
                            class="text-xl font-extrabold text-foreground sm:text-2xl"
                        >
                            Disaster Event Not Found
                        </h2>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground sm:text-sm"
                        >
                            The event ID
                            <code
                                class="rounded bg-muted px-1.5 py-0.5 font-mono font-bold text-foreground"
                                >{{ eventId }}</code
                            >
                            could not be found in active regional feeds. The
                            event may have occurred outside the active 30-day
                            tracking window, or the identifier might be invalid.
                        </p>
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-center gap-3 pt-2"
                    >
                        <Link
                            href="/events"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-4 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            <ArrowLeft class="size-3.5" />
                            <span>Browse Live Events Stream</span>
                        </Link>
                        <Link
                            href="/map"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-4 text-xs font-semibold text-foreground transition-colors hover:bg-muted"
                        >
                            <Globe class="size-3.5" />
                            <span>Open Disaster Map</span>
                        </Link>
                    </div>

                    <!-- Show current active events as fallback suggestion -->
                    <div
                        v-if="data.events.length > 0"
                        class="mt-8 border-t border-border/60 pt-8 text-left"
                    >
                        <h4
                            class="mb-3 text-center text-xs font-bold tracking-wider text-muted-foreground uppercase sm:text-left"
                        >
                            Currently Active Disasters in Southeast Asia
                        </h4>
                        <div
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <Link
                                v-for="ev in data.events.slice(0, 6)"
                                :key="ev.id"
                                :href="`/events/${ev.id}`"
                                class="rounded-lg border border-border/70 bg-muted/20 p-3 transition-colors hover:bg-muted/40"
                            >
                                <div
                                    class="mb-1 flex items-center justify-between text-[10px]"
                                >
                                    <span
                                        class="font-bold text-foreground uppercase"
                                        >{{
                                            ev.disaster_type || ev.category
                                        }}</span
                                    >
                                    <span
                                        class="font-mono text-muted-foreground"
                                        >{{ ev.country }}</span
                                    >
                                </div>
                                <h5
                                    class="line-clamp-1 text-xs font-semibold text-foreground"
                                >
                                    {{ ev.title }}
                                </h5>
                                <div
                                    class="mt-1 flex items-center justify-between font-mono text-[10px] text-muted-foreground"
                                >
                                    <span>{{
                                        formatDateTime(
                                            ev.event_date || ev.event_time,
                                        )
                                    }}</span>
                                    <span
                                        v-if="ev.magnitude"
                                        class="font-bold text-foreground"
                                        >M {{ ev.magnitude.toFixed(1) }}</span
                                    >
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <DisasterFooter />

        <!-- Fullscreen Photo Modal -->
        <div
            v-if="selectedModalPhoto"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
            @click="selectedModalPhoto = null"
        >
            <div
                class="relative max-h-[90vh] max-w-4xl overflow-hidden rounded-2xl border border-border bg-card shadow-2xl"
                @click.stop
            >
                <button
                    type="button"
                    class="absolute top-3 right-3 z-10 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/70 text-white transition-colors hover:bg-black"
                    @click="selectedModalPhoto = null"
                >
                    <X class="size-5" />
                </button>
                <img
                    :src="selectedModalPhoto"
                    alt="Citizen Observation Evidence Fullscreen"
                    class="max-h-[85vh] w-auto max-w-full object-contain"
                />
            </div>
        </div>
    </div>
</template>
