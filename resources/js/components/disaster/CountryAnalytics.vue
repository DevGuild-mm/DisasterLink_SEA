<script setup lang="ts">
import {
    Activity,
    BarChart3,
    Calendar,
    ChevronRight,
    Flame,
    Globe,
    Info,
    ShieldAlert,
    TrendingUp,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type {
    CountryCategoryStat,
    CountryDistributionStat,
    HistoricalTrendItem,
} from '@/types/disaster';

const props = defineProps<{
    byCategory: CountryCategoryStat[];
    byCountry: CountryDistributionStat[];
    historicalTrends: HistoricalTrendItem[];
    selectedCountry: string;
    totalCountryEvents: number;
}>();

const emit = defineEmits<{
    (e: 'select-country', country: string): void;
}>();

const maxCategoryEvents = computed(() => {
    if (!props.byCategory || props.byCategory.length === 0) return 1;
    return Math.max(...props.byCategory.map((c) => c.total_events), 1);
});

const maxCountryEvents = computed(() => {
    if (!props.byCountry || props.byCountry.length === 0) return 1;
    return Math.max(...props.byCountry.map((c) => c.total_events), 1);
});

const maxTrendEvents = computed(() => {
    if (!props.historicalTrends || props.historicalTrends.length === 0)
        return 1;
    return Math.max(...props.historicalTrends.map((t) => t.total_events), 1);
});

function getCategoryIcon(catName: string) {
    const c = catName.toLowerCase();
    if (c.includes('earthquake')) return Activity;
    if (c.includes('cyclone') || c.includes('storm')) return Wind;
    if (c.includes('flood') || c.includes('wave')) return Waves;
    if (c.includes('wildfire') || c.includes('fire')) return Flame;
    return ShieldAlert;
}
</script>

<template>
    <div class="space-y-6">
        <!-- Analytics 1: Events by Disaster Category -->
        <Card class="border-border bg-card shadow-sm">
            <CardContent class="space-y-4 p-5 sm:p-6">
                <div
                    class="flex items-center justify-between border-b border-border/60 pb-3"
                >
                    <div class="flex items-center gap-2">
                        <BarChart3 class="size-4 text-primary" />
                        <h3
                            class="text-xs font-bold tracking-wider text-foreground uppercase"
                        >
                            Disaster Events by Category
                        </h3>
                    </div>
                    <span class="font-mono text-[11px] text-muted-foreground">
                        {{ byCategory.length }} Categor{{
                            byCategory.length === 1 ? 'y' : 'ies'
                        }}
                    </span>
                </div>

                <div v-if="byCategory.length > 0" class="space-y-3">
                    <div
                        v-for="cat in byCategory"
                        :key="cat.category"
                        class="space-y-1.5"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <span
                                class="flex items-center gap-1.5 font-medium text-foreground"
                            >
                                <component
                                    :is="getCategoryIcon(cat.category)"
                                    class="size-3.5"
                                    :style="{ color: cat.color }"
                                />
                                <span>{{ cat.category }}</span>
                                <Badge
                                    v-if="cat.high_alerts > 0"
                                    variant="destructive"
                                    class="px-1.5 py-0 text-[9px] font-bold uppercase"
                                >
                                    {{ cat.high_alerts }} High Alert{{
                                        cat.high_alerts > 1 ? 's' : ''
                                    }}
                                </Badge>
                            </span>
                            <span
                                class="font-mono text-xs font-bold text-foreground"
                            >
                                {{ cat.total_events }}
                                <span
                                    class="text-[10px] font-normal text-muted-foreground"
                                >
                                    ({{
                                        Math.round(
                                            (cat.total_events /
                                                (totalCountryEvents || 1)) *
                                                100,
                                        )
                                    }}%)
                                </span>
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-muted/60"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :style="{
                                    width: `${Math.max(Math.round((cat.total_events / maxCategoryEvents) * 100), 4)}%`,
                                    backgroundColor: cat.color,
                                }"
                            ></div>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    No active disaster events recorded for this country.
                </div>
            </CardContent>
        </Card>

        <!-- Analytics 2: Regional Comparative Distribution (All 11 ASEAN Nations) -->
        <Card class="border-border bg-card shadow-sm">
            <CardContent class="space-y-4 p-5 sm:p-6">
                <div
                    class="flex items-center justify-between border-b border-border/60 pb-3"
                >
                    <div class="flex items-center gap-2">
                        <Globe class="size-4 text-cyan-500" />
                        <h3
                            class="text-xs font-bold tracking-wider text-foreground uppercase"
                        >
                            Cross-Country Distribution (All 11 ASEAN Nations)
                        </h3>
                    </div>
                    <span class="font-mono text-[10px] text-muted-foreground">
                        Ranked Comparison
                    </span>
                </div>

                <p class="text-[11px] leading-relaxed text-muted-foreground">
                    Comparison of current active hazards across all 11 Southeast
                    Asian countries. Click any country to switch view:
                </p>

                <div class="space-y-2">
                    <button
                        v-for="item in byCountry"
                        :key="item.country"
                        type="button"
                        class="flex w-full cursor-pointer flex-col gap-1.5 rounded-lg border p-2.5 text-left transition-all"
                        :class="[
                            item.country === selectedCountry
                                ? 'border-primary/40 bg-primary/10 ring-1 ring-primary/30'
                                : 'border-border/60 bg-muted/20 hover:border-border hover:bg-muted/40',
                        ]"
                        @click="emit('select-country', item.country)"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <span class="flex items-center gap-2 font-medium">
                                <span
                                    class="rounded border border-border/80 bg-muted px-1.5 py-0.5 font-mono text-[10px] font-bold text-foreground"
                                >
                                    {{ item.code || item.iso3 }}
                                </span>
                                <span
                                    :class="[
                                        item.country === selectedCountry
                                            ? 'font-bold text-primary'
                                            : 'text-foreground',
                                    ]"
                                >
                                    {{ item.country }}
                                </span>
                                <Badge
                                    v-if="item.high_alerts > 0"
                                    variant="destructive"
                                    class="px-1 py-0 text-[9px] font-bold"
                                >
                                    {{ item.high_alerts }} High
                                </Badge>
                            </span>

                            <div
                                class="flex items-center gap-2 font-mono text-xs"
                            >
                                <span class="font-bold text-foreground">
                                    {{ item.total_events }}
                                </span>
                                <ChevronRight
                                    class="size-3 text-muted-foreground"
                                />
                            </div>
                        </div>

                        <!-- Comparative Bar -->
                        <div
                            class="h-1.5 w-full overflow-hidden rounded-full bg-muted/70"
                        >
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                :class="[
                                    item.country === selectedCountry
                                        ? 'bg-primary'
                                        : item.high_alerts > 0
                                          ? 'bg-rose-500'
                                          : 'bg-muted-foreground/60',
                                ]"
                                :style="{
                                    width: `${item.total_events > 0 ? Math.max(Math.round((item.total_events / maxCountryEvents) * 100), 5) : 0}%`,
                                }"
                            ></div>
                        </div>
                    </button>
                </div>
            </CardContent>
        </Card>

        <!-- Analytics 3: Real Historical Trends (Chronological Incident Dates) -->
        <Card class="border-border bg-card shadow-sm">
            <CardContent class="space-y-4 p-5 sm:p-6">
                <div
                    class="flex items-center justify-between border-b border-border/60 pb-3"
                >
                    <div class="flex items-center gap-2">
                        <TrendingUp class="size-4 text-emerald-500" />
                        <h3
                            class="text-xs font-bold tracking-wider text-foreground uppercase"
                        >
                            Chronological Incident Occurrence
                        </h3>
                    </div>
                    <span class="font-mono text-[10px] text-muted-foreground">
                        {{ historicalTrends.length }} Active Date{{
                            historicalTrends.length === 1 ? '' : 's'
                        }}
                    </span>
                </div>

                <div v-if="historicalTrends.length > 0" class="space-y-3">
                    <div
                        v-for="trend in historicalTrends"
                        :key="trend.date"
                        class="space-y-2 rounded-lg border border-border/60 bg-muted/20 p-3"
                    >
                        <div class="flex items-center justify-between text-xs">
                            <span
                                class="flex items-center gap-1.5 font-medium text-foreground"
                            >
                                <Calendar class="size-3.5 text-primary" />
                                <span>{{ trend.formatted_date }}</span>
                            </span>
                            <span
                                class="font-mono text-xs font-bold text-foreground"
                            >
                                {{ trend.total_events }} Incident{{
                                    trend.total_events > 1 ? 's' : ''
                                }}
                            </span>
                        </div>

                        <!-- Bar by Intensity -->
                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-muted/60"
                        >
                            <div
                                class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                                :style="{
                                    width: `${Math.max(Math.round((trend.total_events / maxTrendEvents) * 100), 8)}%`,
                                }"
                            ></div>
                        </div>

                        <!-- Category breakdown tags for this day -->
                        <div
                            class="flex flex-wrap items-center gap-1.5 pt-1 text-[10px] text-muted-foreground"
                        >
                            <span
                                v-for="(cnt, cat) in trend.categories"
                                :key="cat"
                                class="rounded border border-border/50 bg-muted px-1.5 py-0.5 font-mono font-medium text-foreground"
                            >
                                {{ cat }}: {{ cnt }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="flex items-start gap-1.5 rounded-lg border border-border/50 bg-muted/10 p-2.5 text-[11px] text-muted-foreground"
                    >
                        <Info
                            class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                        />
                        <span
                            >Dates shown strictly reflect timestamps published
                            in upstream GDACS, USGS, and NASA feeds without
                            synthetic zero-filling.</span
                        >
                    </div>
                </div>

                <div
                    v-else
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    No incident dates available for this nation's current feed.
                </div>
            </CardContent>
        </Card>
    </div>
</template>
