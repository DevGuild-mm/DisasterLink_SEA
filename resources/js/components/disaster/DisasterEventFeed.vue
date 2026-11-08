<script setup lang="ts">
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Calendar,
    Clock,
    Compass,
    ExternalLink,
    Filter,
    Flame,
    Globe,
    Layers,
    MapPin,
    Search,
    ShieldAlert,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { AseanCountry, DisasterEvent } from '@/types/disaster';

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const props = defineProps<{
    events: DisasterEvent[];
    countries: Record<string, AseanCountry>;
    selectedCountry: string;
    selectedCategory: string;
}>();

const emit = defineEmits<{
    (e: 'update:selectedCountry', country: string): void;
    (e: 'update:selectedCategory', category: string): void;
    (e: 'select-event', event: DisasterEvent): void;
}>();

const searchQuery = ref('');
const displayLimit = ref(15);

// Filtered and searched events
const filteredEvents = computed(() => {
    const eventList = Array.isArray(props.events) ? props.events : [];
    const countryMap = props.countries || {};

    return eventList.filter((ev) => {
        // Country filter
        if (props.selectedCountry !== 'All') {
            const evCountry = (ev.country || '').toLowerCase();
            const target = props.selectedCountry.toLowerCase();
            if (
                !evCountry.includes(target) &&
                ev.iso3 !== countryMap[props.selectedCountry]?.iso3
            ) {
                return false;
            }
        }

        // Category filter
        if (props.selectedCategory !== 'all') {
            const evCat = (ev.category || '').toLowerCase();
            const target = props.selectedCategory.toLowerCase();
            if (target === 'cyclone') {
                if (!evCat.includes('cyclone') && !evCat.includes('storm'))
                    return false;
            } else if (!evCat.includes(target)) {
                return false;
            }
        }

        // Search text
        if (searchQuery.value.trim()) {
            const query = searchQuery.value.toLowerCase().trim();
            const title = (ev.title || '').toLowerCase();
            const desc = (ev.description || '').toLowerCase();
            const loc = (ev.country || '').toLowerCase();
            if (
                !title.includes(query) &&
                !desc.includes(query) &&
                !loc.includes(query)
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

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return 'Recent';
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

function getCategoryIcon(cat: string) {
    const c = cat.toLowerCase();
    if (c.includes('earthquake')) return Activity;
    if (c.includes('cyclone') || c.includes('storm')) return Wind;
    if (c.includes('flood')) return Waves;
    if (c.includes('wildfire') || c.includes('fire')) return Flame;
    if (c.includes('volcano')) return AlertTriangle;
    return ShieldAlert;
}

function getCategoryColor(cat: string) {
    const c = cat.toLowerCase();
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
                class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
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

                <!-- Search and Filters -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Search Input -->
                    <div class="relative w-full sm:w-64">
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
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="filteredEvents.length === 0"
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
            </div>

            <!-- Feed Grid -->
            <div
                v-else
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <Card
                    v-for="ev in visibleEvents"
                    :key="ev.id"
                    class="group relative flex flex-col justify-between overflow-hidden border border-border/80 bg-card transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-md"
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
                                        getCategoryColor(ev.category),
                                    ]"
                                >
                                    <component
                                        :is="getCategoryIcon(ev.category)"
                                        class="size-3"
                                    />
                                    <span>{{ ev.category }}</span>
                                </span>

                                <div class="flex items-center gap-1.5">
                                    <!-- Severity Badge -->
                                    <Badge
                                        v-if="ev.severity_level === 'Red'"
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

                                    <!-- Time Ago -->
                                    <span
                                        class="flex items-center gap-0.5 font-mono text-[11px] text-muted-foreground"
                                    >
                                        <Clock
                                            class="size-3 text-muted-foreground/70"
                                        />
                                        {{ formatRelativeTime(ev.event_time) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Title -->
                            <h4
                                class="line-clamp-2 text-sm leading-snug font-bold text-foreground transition-colors group-hover:text-primary"
                            >
                                {{ ev.title }}
                            </h4>

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
                                    v-if="ev.magnitude"
                                    class="font-mono font-bold text-foreground"
                                >
                                    M {{ ev.magnitude.toFixed(1) }}
                                </span>
                                <span
                                    v-else-if="ev.severity_text"
                                    class="max-w-[140px] truncate font-mono text-muted-foreground"
                                >
                                    {{ ev.severity_text }}
                                </span>
                            </div>

                            <!-- Depth & Coordinates -->
                            <div
                                v-if="ev.depth_km !== null || ev.coordinates"
                                class="flex items-center justify-between font-mono text-[10px] text-muted-foreground/80"
                            >
                                <span v-if="ev.depth_km !== null">
                                    Depth: {{ ev.depth_km }} km
                                </span>
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
                                            class="py-0.2 inline-block rounded bg-muted px-1.5 font-mono text-[10px] font-medium text-foreground"
                                        >
                                            {{ s }}
                                        </span>
                                    </div>
                                </div>

                                <a
                                    :href="ev.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-[11px] font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400"
                                >
                                    <span>Details</span>
                                    <ExternalLink class="size-3" />
                                </a>
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
