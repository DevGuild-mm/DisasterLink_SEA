<script setup lang="ts">
import { Activity, AlertOctagon, Flame, Waves, Wind } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { DashboardSummary } from '@/types/disaster';

const props = defineProps<{
    summary: DashboardSummary | null;
    totalEvents: number;
}>();

const emit = defineEmits<{
    (e: 'filter-category', category: string): void;
}>();

interface CardConfig {
    id: string;
    title: string;
    count: number | string;
    status: string;
    description: string;
    icon: any;
    colorClass: string;
    badgeVariant: 'default' | 'destructive' | 'secondary' | 'outline';
    filterValue: string;
}

const cards = computed<CardConfig[]>(() => {
    if (!props.summary) {
        return [
            {
                id: 'active_alerts',
                title: 'Active Disaster Alerts',
                count: '—',
                status: 'Connecting',
                description: 'Orange & Red level priority alerts',
                icon: AlertOctagon,
                colorClass:
                    'text-destructive bg-destructive/10 ring-destructive/20',
                badgeVariant: 'destructive',
                filterValue: 'all',
            },
            {
                id: 'earthquakes',
                title: 'Recent Earthquakes',
                count: '—',
                status: 'Connecting',
                description: 'USGS & GDACS seismic records',
                icon: Activity,
                colorClass: 'text-amber-500 bg-amber-500/10 ring-amber-500/20',
                badgeVariant: 'outline',
                filterValue: 'earthquake',
            },
            {
                id: 'cyclones',
                title: 'Tropical Cyclones',
                count: '—',
                status: 'Connecting',
                description: 'Typhoons & tropical depressions',
                icon: Wind,
                colorClass: 'text-cyan-500 bg-cyan-500/10 ring-cyan-500/20',
                badgeVariant: 'outline',
                filterValue: 'cyclone',
            },
            {
                id: 'floods',
                title: 'Flood Events',
                count: '—',
                status: 'Connecting',
                description: 'Riverine inundation & flash floods',
                icon: Waves,
                colorClass: 'text-blue-500 bg-blue-500/10 ring-blue-500/20',
                badgeVariant: 'outline',
                filterValue: 'flood',
            },
            {
                id: 'wildfires',
                title: 'Wildfires & Hotspots',
                count: '—',
                status: 'Connecting',
                description: 'Satellite thermal detections',
                icon: Flame,
                colorClass:
                    'text-orange-500 bg-orange-500/10 ring-orange-500/20',
                badgeVariant: 'outline',
                filterValue: 'wildfire',
            },
        ];
    }

    const s = props.summary;

    return [
        {
            id: 'active_alerts',
            title: 'Active High Alerts',
            count:
                s.total_active_alerts.status === 'Unavailable'
                    ? 'N/A'
                    : s.total_active_alerts.count,
            status: s.total_active_alerts.status,
            description: s.total_active_alerts.description,
            icon: AlertOctagon,
            colorClass: 'text-rose-500 bg-rose-500/10 ring-rose-500/20',
            badgeVariant: 'destructive',
            filterValue: 'all',
        },
        {
            id: 'earthquakes',
            title: 'Seismic Events',
            count:
                s.earthquakes.status === 'Unavailable'
                    ? 'N/A'
                    : s.earthquakes.count,
            status: s.earthquakes.status,
            description: s.earthquakes.description,
            icon: Activity,
            colorClass: 'text-amber-500 bg-amber-500/10 ring-amber-500/20',
            badgeVariant: 'outline',
            filterValue: 'earthquake',
        },
        {
            id: 'cyclones',
            title: 'Tropical Storms',
            count:
                s.tropical_cyclones.status === 'Unavailable'
                    ? 'N/A'
                    : s.tropical_cyclones.count,
            status: s.tropical_cyclones.status,
            description: s.tropical_cyclones.description,
            icon: Wind,
            colorClass: 'text-sky-500 bg-sky-500/10 ring-sky-500/20',
            badgeVariant: 'outline',
            filterValue: 'cyclone',
        },
        {
            id: 'floods',
            title: 'Flood Incidents',
            count: s.floods.status === 'Unavailable' ? 'N/A' : s.floods.count,
            status: s.floods.status,
            description: s.floods.description,
            icon: Waves,
            colorClass: 'text-blue-500 bg-blue-500/10 ring-blue-500/20',
            badgeVariant: 'outline',
            filterValue: 'flood',
        },
        {
            id: 'wildfires',
            title: 'Active Wildfires',
            count:
                s.wildfires.status === 'Unavailable'
                    ? 'N/A'
                    : s.wildfires.count,
            status: s.wildfires.status,
            description: s.wildfires.description,
            icon: Flame,
            colorClass: 'text-orange-500 bg-orange-500/10 ring-orange-500/20',
            badgeVariant: 'outline',
            filterValue: 'wildfire',
        },
    ];
});
</script>

<template>
    <section id="overview" class="py-6 sm:py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div
                class="mb-5 flex flex-col justify-between gap-2 sm:flex-row sm:items-end"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full bg-rose-500"></span>
                        <h2
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Regional Disaster Intelligence
                        </h2>
                    </div>
                    <p
                        class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        Southeast Asia Live Telemetry Overview
                    </p>
                </div>
                <div class="text-xs text-muted-foreground">
                    Aggregated total:
                    <span class="font-mono font-bold text-foreground">{{
                        totalEvents
                    }}</span>
                    verified regional incidents
                </div>
            </div>

            <!-- 5 Summary Cards Grid -->
            <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-5">
                <Card
                    v-for="card in cards"
                    :key="card.id"
                    class="group relative cursor-pointer overflow-hidden border border-border/80 bg-card transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-md"
                    @click="emit('filter-category', card.filterValue)"
                >
                    <CardContent class="p-4 sm:p-5">
                        <div class="flex items-start justify-between">
                            <div class="space-y-1">
                                <span
                                    class="line-clamp-1 text-xs font-medium text-muted-foreground"
                                >
                                    {{ card.title }}
                                </span>
                                <div class="flex items-baseline gap-2">
                                    <span
                                        class="font-mono text-3xl font-extrabold tracking-tight text-foreground"
                                    >
                                        {{ card.count }}
                                    </span>
                                </div>
                            </div>
                            <div
                                :class="[
                                    'flex size-10 shrink-0 items-center justify-center rounded-xl shadow-xs ring-1 transition-transform group-hover:scale-105',
                                    card.colorClass,
                                ]"
                            >
                                <component :is="card.icon" class="size-5" />
                            </div>
                        </div>

                        <!-- Card Footer Description & Status -->
                        <div
                            class="mt-3 flex items-center justify-between border-t border-border/50 pt-2.5 text-[11px]"
                        >
                            <span
                                class="max-w-[130px] truncate text-muted-foreground"
                            >
                                {{ card.description }}
                            </span>
                            <Badge
                                v-if="card.status === 'Unavailable'"
                                variant="outline"
                                class="border-destructive/30 bg-destructive/10 px-1.5 py-0 text-[10px] text-destructive"
                            >
                                Offline
                            </Badge>
                            <Badge
                                v-else-if="card.status === 'Stale'"
                                variant="outline"
                                class="border-amber-500/30 bg-amber-500/10 px-1.5 py-0 text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                Cached
                            </Badge>
                            <span
                                v-else
                                class="inline-flex items-center text-[10px] font-medium text-emerald-600 group-hover:underline dark:text-emerald-400"
                            >
                                View Events &rarr;
                            </span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </section>
</template>
