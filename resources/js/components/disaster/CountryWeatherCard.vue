<script setup lang="ts">
import {
    AlertTriangle,
    Clock,
    Cloud,
    CloudDrizzle,
    CloudFog,
    CloudLightning,
    CloudRain,
    Compass,
    Droplets,
    Eye,
    Globe,
    Info,
    ShieldAlert,
    Sun,
    Thermometer,
    Wind,
} from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import type { WeatherCity } from '@/types/disaster';

const props = defineProps<{
    weather: WeatherCity[];
    countryName: string;
    hasActiveWeatherWarnings?: boolean;
    weatherWarningsCount?: number;
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

function formatTime(isoString?: string | null): string {
    if (!isoString) return 'Real-time observation';
    try {
        const d = new Date(isoString);
        return d.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return isoString;
    }
}

const primaryCity = computed(() => {
    return props.weather.length > 0 ? props.weather[0] : null;
});
</script>

<template>
    <div class="space-y-4">
        <!-- Official Severe Weather Warning Alert (Distinguished from normal weather) -->
        <div
            v-if="hasActiveWeatherWarnings"
            class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-xs text-destructive shadow-sm dark:text-destructive-foreground"
        >
            <div
                class="flex items-center gap-2 text-xs font-bold tracking-wider uppercase"
            >
                <AlertTriangle
                    class="size-4 shrink-0 animate-pulse text-destructive"
                />
                <span>Active Severe Meteorological Warning</span>
            </div>
            <p class="mt-1 text-[11px] leading-relaxed text-destructive/90">
                Official hazard alerts (cyclone / flood) are currently active in
                {{ countryName }}. Review the incident bullet points below for
                hypocenter details and affected zones.
            </p>
        </div>

        <!-- Capital City Surface Weather Card -->
        <Card class="overflow-hidden border-border bg-card shadow-sm">
            <CardContent class="space-y-4 p-5 sm:p-6">
                <div
                    class="flex items-center justify-between border-b border-border/60 pb-3"
                >
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="size-2 rounded-full bg-cyan-500"
                            ></span>
                            <h3
                                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                            >
                                Capital Surface Weather
                            </h3>
                        </div>
                        <h4
                            class="mt-0.5 text-base font-extrabold text-foreground"
                        >
                            {{
                                primaryCity
                                    ? `${primaryCity.city}, ${primaryCity.country}`
                                    : `${countryName} Surface Observations`
                            }}
                        </h4>
                    </div>

                    <Badge
                        variant="outline"
                        class="border-border bg-muted/40 font-mono text-[10px]"
                    >
                        Open-Meteo
                    </Badge>
                </div>

                <!-- Attribution Clarification Note -->
                <div
                    class="flex items-start gap-1.5 rounded-lg border border-border/50 bg-muted/20 p-2.5 text-[11px] leading-relaxed text-muted-foreground"
                >
                    <Info
                        class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                    />
                    <span>
                        Measurements represent atmospheric conditions at the
                        capital coordinates (<strong
                            class="font-medium text-foreground"
                            >{{ primaryCity?.city || 'Capital' }}</strong
                        >). Coastal or highland provinces may vary.
                    </span>
                </div>

                <!-- Single City / Primary Capital Display -->
                <div v-if="primaryCity" class="space-y-4">
                    <!-- Current Conditions Box -->
                    <div
                        class="space-y-3 rounded-xl border border-border/60 bg-muted/30 p-4"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                            >
                                Current Atmospheric Status
                            </span>
                            <span
                                class="font-mono text-[10px] text-muted-foreground"
                            >
                                Obs:
                                {{ formatTime(primaryCity.observation_time) }}
                            </span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <component
                                    :is="getWeatherIcon(primaryCity.icon)"
                                    class="size-10 shrink-0 text-cyan-500"
                                />
                                <div>
                                    <div
                                        class="font-mono text-3xl font-black tracking-tight text-foreground"
                                    >
                                        {{
                                            primaryCity.temperature_c !== null
                                                ? `${Math.round(primaryCity.temperature_c)}°C`
                                                : 'N/A'
                                        }}
                                    </div>
                                    <div
                                        class="text-xs font-medium text-foreground/90"
                                    >
                                        {{ primaryCity.condition }}
                                    </div>
                                </div>
                            </div>

                            <div
                                class="space-y-0.5 text-right font-mono text-xs text-muted-foreground"
                            >
                                <div>
                                    Feels:
                                    <strong class="text-foreground">{{
                                        primaryCity.apparent_temperature_c !==
                                        null
                                            ? `${Math.round(primaryCity.apparent_temperature_c)}°C`
                                            : '—'
                                    }}</strong>
                                </div>
                                <div>
                                    Humidity:
                                    <strong class="text-foreground"
                                        >{{
                                            primaryCity.humidity_pct ?? '—'
                                        }}%</strong
                                    >
                                </div>
                                <div>
                                    Wind:
                                    <strong class="text-foreground">{{
                                        primaryCity.wind_speed_kmh !== null
                                            ? `${primaryCity.wind_speed_kmh} km/h`
                                            : '—'
                                    }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 24h Daily Forecast Box (Distinctly Separated) -->
                    <div
                        class="space-y-2.5 rounded-xl border border-border/60 bg-muted/20 p-4"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                            >
                                24-Hour Capital Forecast
                            </span>
                            <Badge
                                variant="outline"
                                class="border-border px-1.5 py-0 text-[9px]"
                            >
                                Projected
                            </Badge>
                        </div>

                        <div
                            class="grid grid-cols-3 gap-2 text-center font-mono text-xs"
                        >
                            <div
                                class="rounded-lg border border-border/50 bg-card p-2"
                            >
                                <span
                                    class="block font-sans text-[10px] text-muted-foreground"
                                    >High Temp</span
                                >
                                <strong class="text-sm text-foreground">
                                    {{
                                        primaryCity.forecast_max_c !== null
                                            ? `${Math.round(primaryCity.forecast_max_c)}°C`
                                            : '—'
                                    }}
                                </strong>
                            </div>

                            <div
                                class="rounded-lg border border-border/50 bg-card p-2"
                            >
                                <span
                                    class="block font-sans text-[10px] text-muted-foreground"
                                    >Low Temp</span
                                >
                                <strong class="text-sm text-foreground">
                                    {{
                                        primaryCity.forecast_min_c !== null
                                            ? `${Math.round(primaryCity.forecast_min_c)}°C`
                                            : '—'
                                    }}
                                </strong>
                            </div>

                            <div
                                class="rounded-lg border border-border/50 bg-card p-2"
                            >
                                <span
                                    class="block font-sans text-[10px] text-muted-foreground"
                                    >Precip. Sum</span
                                >
                                <strong class="text-sm text-foreground">
                                    {{
                                        primaryCity.forecast_precipitation_sum_mm !==
                                        null
                                            ? `${primaryCity.forecast_precipitation_sum_mm} mm`
                                            : '—'
                                    }}
                                </strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Multi-city fallback when "All" is selected -->
                <div v-else-if="weather.length > 1" class="space-y-2">
                    <div
                        v-for="c in weather.slice(0, 5)"
                        :key="c.city"
                        class="flex items-center justify-between rounded-lg border border-border/50 bg-muted/20 p-2.5 font-mono text-xs"
                    >
                        <div class="flex items-center gap-2">
                            <component
                                :is="getWeatherIcon(c.icon)"
                                class="size-4 text-cyan-500"
                            />
                            <span
                                class="font-sans font-medium text-foreground"
                                >{{ c.city }}</span
                            >
                            <span
                                class="font-sans text-[10px] text-muted-foreground"
                                >({{ c.country }})</span
                            >
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-foreground"
                                >{{ Math.round(c.temperature_c ?? 0) }}°C</span
                            >
                            <span
                                class="font-sans text-[10px] text-muted-foreground"
                                >{{ c.condition }}</span
                            >
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="py-6 text-center text-xs text-muted-foreground"
                >
                    Capital meteorological telemetry currently unavailable for
                    {{ countryName }}.
                </div>
            </CardContent>
        </Card>
    </div>
</template>
