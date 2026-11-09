<script setup lang="ts">
import {
    AlertTriangle,
    Clock,
    Cloud,
    CloudDrizzle,
    CloudFog,
    CloudLightning,
    CloudRain,
    Droplets,
    Info,
    RefreshCw,
    Sun,
    Wind,
} from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { SourceFreshness, WeatherCity } from '@/types/disaster';

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

defineProps<{
    weather: WeatherCity[];
    sourceMeta?: SourceFreshness | null;
    isLoading?: boolean;
}>();

function getWeatherIcon(iconName: string) {
    switch (iconName) {
        case 'sun':
        case 'sun-dim':
            return Sun;
        case 'cloud-fog':
            return CloudFog;
        case 'cloud-drizzle':
            return CloudDrizzle;
        case 'cloud-rain':
        case 'cloud-heavy-rain':
            return CloudRain;
        case 'cloud-lightning':
        case 'cloud-lightning-rain':
            return CloudLightning;
        default:
            return Cloud;
    }
}

function formatTime(isoString: string | null | undefined): string {
    if (!isoString) return 'N/A';
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
</script>

<template>
    <section
        id="weather"
        class="border-t border-border/60 bg-muted/20 py-6 sm:py-8"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div
                class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="size-2 rounded-full bg-cyan-500"></span>
                        <h3
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Atmospheric & Meteorological Conditions
                        </h3>
                        <Badge
                            v-if="sourceMeta?.is_fresh"
                            variant="outline"
                            class="border-emerald-500/30 bg-emerald-500/10 text-[10px] font-semibold text-emerald-700 dark:text-emerald-400"
                        >
                            Fresh ({{
                                formatTime(sourceMeta.last_successful_fetch)
                            }})
                        </Badge>
                        <Badge
                            v-else-if="sourceMeta?.is_stale"
                            variant="outline"
                            class="border-amber-500/30 bg-amber-500/10 text-[10px] font-semibold text-amber-700 dark:text-amber-400"
                        >
                            Stale Cache ({{
                                formatTime(sourceMeta.last_successful_fetch)
                            }})
                        </Badge>
                        <Badge
                            v-else-if="sourceMeta?.status === 'Unavailable'"
                            variant="destructive"
                            class="text-[10px] font-semibold"
                        >
                            Unavailable
                        </Badge>
                    </div>
                    <h2
                        class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                    >
                        ASEAN Capital Weather Observations
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        High-resolution surface telemetry and simulations via
                        Open-Meteo
                    </p>
                </div>

                <!-- Advisory Disclaimer -->
                <div
                    class="flex max-w-md items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-[11px] text-amber-700 dark:text-amber-300"
                >
                    <Info class="size-4 shrink-0 text-amber-500" />
                    <span>
                        Standard meteorological forecasts are informational only
                        and should not be treated as official disaster
                        evacuation orders.
                    </span>
                </div>
            </div>

            <!-- Stale Weather Warning Banner -->
            <div
                v-if="
                    sourceMeta &&
                    (sourceMeta.is_stale || sourceMeta.status === 'Stale')
                "
                class="mb-4 flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                <div>
                    <p class="font-semibold">
                        Serving Cached Weather Observations (Stale Data)
                    </p>
                    <p class="mt-0.5">
                        {{
                            sourceMeta.error ||
                            'Live Open-Meteo refresh failed.'
                        }}
                        Last successful update:
                        <strong>{{
                            formatTime(sourceMeta.last_successful_fetch)
                        }}</strong
                        >.
                    </p>
                </div>
            </div>

            <!-- Loading State -->
            <div
                v-if="isLoading && (!weather || weather.length === 0)"
                class="rounded-xl border border-border bg-card/50 p-10 text-center"
            >
                <RefreshCw
                    class="mx-auto mb-2 size-8 animate-spin text-muted-foreground"
                />
                <h3 class="text-sm font-semibold text-foreground">
                    Loading atmospheric telemetry for 11 ASEAN capitals...
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                    Fetching real-time meteorological observations from
                    Open-Meteo.
                </p>
            </div>

            <!-- Empty / Offline State -->
            <div
                v-else-if="!weather || weather.length === 0"
                class="rounded-xl border border-dashed border-border bg-card/50 p-10 text-center"
            >
                <Cloud class="mx-auto mb-2 size-8 text-muted-foreground" />
                <h3 class="text-sm font-semibold text-foreground">
                    Weather telemetry temporarily unavailable
                </h3>
                <p class="mx-auto mt-1 max-w-md text-xs text-muted-foreground">
                    {{
                        sourceMeta?.error ||
                        'Unable to retrieve current atmospheric observations from Open-Meteo.'
                    }}
                    No fake or placeholder weather statistics are shown when the
                    upstream service is unreachable.
                </p>
            </div>

            <!-- Weather Cards Grid for 11 ASEAN Countries -->
            <div
                v-else
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4"
            >
                <Card
                    v-for="item in weather"
                    :key="item.country"
                    class="overflow-hidden border border-border/80 bg-card transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-xs"
                >
                    <CardContent class="space-y-3 p-4">
                        <!-- Country & City -->
                        <div class="flex items-start justify-between">
                            <div>
                                <span
                                    class="font-mono text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                                >
                                    {{ item.iso2 }} • {{ item.country }}
                                </span>
                                <h4
                                    class="text-base leading-tight font-bold text-foreground"
                                >
                                    {{ item.city }}
                                </h4>
                            </div>
                            <div
                                class="flex size-9 items-center justify-center rounded-xl bg-sky-500/10 text-sky-500 ring-1 ring-sky-500/20"
                            >
                                <component
                                    :is="getWeatherIcon(item.icon)"
                                    class="size-5"
                                />
                            </div>
                        </div>

                        <!-- Current Temperature & Condition -->
                        <div class="flex items-baseline justify-between">
                            <div class="flex items-baseline gap-1">
                                <span
                                    class="font-mono text-3xl font-extrabold tracking-tight text-foreground"
                                >
                                    {{
                                        item.temperature_c !== null &&
                                        item.temperature_c !== undefined
                                            ? item.temperature_c
                                            : '—'
                                    }}
                                </span>
                                <span
                                    class="text-base font-semibold text-muted-foreground"
                                    >°C</span
                                >
                            </div>
                            <span
                                class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-foreground"
                            >
                                {{ item.condition }}
                            </span>
                        </div>

                        <!-- Secondary Metrics Grid -->
                        <div
                            class="grid grid-cols-3 gap-2 border-t border-border/50 pt-2 text-[11px]"
                        >
                            <!-- Rainfall -->
                            <div class="space-y-0.5">
                                <span
                                    class="flex items-center gap-1 text-[10px] text-muted-foreground"
                                >
                                    <CloudRain class="size-3 text-blue-500" />
                                    Rain
                                </span>
                                <span
                                    class="font-mono font-bold text-foreground"
                                >
                                    {{ item.precipitation_mm }} mm
                                </span>
                            </div>

                            <!-- Wind Speed -->
                            <div class="space-y-0.5">
                                <span
                                    class="flex items-center gap-1 text-[10px] text-muted-foreground"
                                >
                                    <Wind class="size-3 text-cyan-500" />
                                    Wind
                                </span>
                                <span
                                    class="font-mono font-bold text-foreground"
                                >
                                    {{
                                        item.wind_speed_kmh !== null &&
                                        item.wind_speed_kmh !== undefined
                                            ? item.wind_speed_kmh
                                            : '—'
                                    }}
                                    km/h
                                </span>
                            </div>

                            <!-- Humidity -->
                            <div class="space-y-0.5">
                                <span
                                    class="flex items-center gap-1 text-[10px] text-muted-foreground"
                                >
                                    <Droplets class="size-3 text-teal-500" />
                                    Humidity
                                </span>
                                <span
                                    class="font-mono font-bold text-foreground"
                                >
                                    {{
                                        item.humidity_pct !== null &&
                                        item.humidity_pct !== undefined
                                            ? item.humidity_pct
                                            : '—'
                                    }}%
                                </span>
                            </div>
                        </div>

                        <!-- Daily High / Low Forecast Range & Observation Time -->
                        <div
                            class="space-y-1 border-t border-border/40 pt-1.5 text-[10px] text-muted-foreground"
                        >
                            <div
                                v-if="
                                    item.forecast_max_c !== null &&
                                    item.forecast_min_c !== null
                                "
                                class="flex items-center justify-between"
                            >
                                <span>Today's Forecast Range:</span>
                                <span
                                    class="font-mono font-medium text-foreground"
                                >
                                    {{ item.forecast_min_c }}°C ~
                                    {{ item.forecast_max_c }}°C
                                </span>
                            </div>
                            <div
                                v-if="item.observation_time"
                                class="flex items-center justify-between"
                            >
                                <span class="flex items-center gap-1">
                                    <Clock class="size-2.5" />
                                    Observation Time:
                                </span>
                                <span class="font-mono text-foreground/85">
                                    {{ formatTime(item.observation_time) }}
                                </span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </section>
</template>
