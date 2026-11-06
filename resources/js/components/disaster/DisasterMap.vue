<script setup lang="ts">
import type * as LType from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    Activity,
    AlertTriangle,
    Filter,
    Flame,
    Globe,
    MapPin,
    RotateCcw,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { AseanCountry, DisasterEvent } from '@/types/disaster';

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

const mapContainer = ref<HTMLElement | null>(null);
let L: typeof LType | null = null;
let map: LType.Map | null = null;
let markersLayer: LType.LayerGroup | null = null;

// Categories list for in-map filter pills
const categoryOptions = [
    { id: 'all', label: 'All Incidents', icon: Globe },
    { id: 'earthquake', label: 'Earthquakes', icon: Activity },
    { id: 'cyclone', label: 'Cyclones & Storms', icon: Wind },
    { id: 'flood', label: 'Floods', icon: Waves },
    { id: 'wildfire', label: 'Wildfires', icon: Flame },
    { id: 'volcano', label: 'Volcanoes', icon: AlertTriangle },
];

// Filter events by country and category
const filteredEvents = computed(() => {
    const eventList = Array.isArray(props.events) ? props.events : [];
    const countryMap = props.countries || {};

    return eventList.filter((ev) => {
        // Country filter
        if (props.selectedCountry !== 'All') {
            const evCountry = (ev.country || '').toLowerCase();
            const targetCountry = props.selectedCountry.toLowerCase();
            if (
                !evCountry.includes(targetCountry) &&
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

        // Must have real coordinates
        return (
            ev.coordinates &&
            ev.coordinates.length === 2 &&
            !isNaN(ev.coordinates[0]) &&
            !isNaN(ev.coordinates[1])
        );
    });
});

// Category color and icon helpers
function getCategoryTheme(
    category: string,
    severityLevel?: string | null,
): {
    color: string;
    bgHex: string;
    borderHex: string;
    label: string;
} {
    const cat = category.toLowerCase();

    if (severityLevel === 'Red') {
        return {
            color: '#ef4444',
            bgHex: '#fee2e2',
            borderHex: '#dc2626',
            label: 'Red Alert',
        };
    }

    if (cat.includes('earthquake')) {
        if (severityLevel === 'Orange')
            return {
                color: '#f97316',
                bgHex: '#ffedd5',
                borderHex: '#ea580c',
                label: 'Moderate EQ',
            };
        return {
            color: '#eab308',
            bgHex: '#fef9c3',
            borderHex: '#ca8a04',
            label: 'Seismic',
        };
    }
    if (cat.includes('cyclone') || cat.includes('storm')) {
        return {
            color: '#06b6d4',
            bgHex: '#cffafe',
            borderHex: '#0891b2',
            label: 'Cyclone',
        };
    }
    if (cat.includes('flood')) {
        return {
            color: '#3b82f6',
            bgHex: '#dbeafe',
            borderHex: '#2563eb',
            label: 'Flood',
        };
    }
    if (cat.includes('wildfire') || cat.includes('fire')) {
        return {
            color: '#f97316',
            bgHex: '#ffedd5',
            borderHex: '#c2410c',
            label: 'Wildfire',
        };
    }
    if (cat.includes('volcano')) {
        return {
            color: '#a855f7',
            bgHex: '#f3e8ff',
            borderHex: '#7e22ce',
            label: 'Volcano',
        };
    }

    return {
        color: '#64748b',
        bgHex: '#f1f5f9',
        borderHex: '#475569',
        label: category,
    };
}

function createMarkerIcon(event: DisasterEvent): LType.DivIcon {
    const theme = getCategoryTheme(event.category, event.severity_level);
    const isMajor =
        event.severity_level === 'Red' ||
        (event.magnitude && event.magnitude >= 6.0);

    const pulseRing = isMajor
        ? `<span style="position:absolute; inset:-4px; border-radius:9999px; background:${theme.color}; opacity:0.4; animation:ping 1.5s cubic-bezier(0,0,0.2,1) infinite;"></span>`
        : '';

    const iconHtml = `
        <div style="position:relative; width:28px; height:28px; display:flex; align-items:center; justify-content:center; cursor:pointer;">
            ${pulseRing}
            <div style="
                width: 24px;
                height: 24px;
                border-radius: 9999px;
                background-color: ${theme.color};
                border: 2px solid #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffffff;
                font-weight: 700;
                font-size: 10px;
            ">
                ${event.magnitude ? event.magnitude.toFixed(1) : ''}
            </div>
        </div>
    `;

    return L!.divIcon({
        className: 'disaster-custom-marker',
        html: iconHtml,
        iconSize: [28, 28],
        iconAnchor: [14, 14],
        popupAnchor: [0, -16],
    });
}

async function initMap() {
    if (typeof window === 'undefined' || !mapContainer.value || map) return;

    const leafletModule = await import('leaflet');
    L = (leafletModule.default ?? leafletModule) as typeof LType;

    if (!mapContainer.value || map) return;

    const countryMap = props.countries || {};
    const initialCountry =
        countryMap[props.selectedCountry] || countryMap['All'];
    const center: LType.LatLngTuple = initialCountry
        ? [initialCountry.center[0], initialCountry.center[1]]
        : [10.0, 108.0];
    const zoom = initialCountry ? initialCountry.zoom : 5;

    map = L.map(mapContainer.value, {
        center: center,
        zoom: zoom,
        minZoom: 3,
        maxZoom: 16,
        zoomControl: false,
    });

    const cartoApiKey = (
        import.meta.env.VITE_CARTO_API_KEY as string | undefined
    )?.trim();

    if (cartoApiKey) {
        L.tileLayer(
            `https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png?api_key=${encodeURIComponent(cartoApiKey)}`,
            {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank">CARTO</a>',
                subdomains: 'abcd',
                maxZoom: 19,
            },
        ).addTo(map);
    } else {
        // Fallback: Official OpenStreetMap HTTPS tile provider
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);
    }

    L.control.zoom({ position: 'bottomright' }).addTo(map);
    markersLayer = L.layerGroup().addTo(map);

    updateMarkers();
}

function updateMarkers() {
    if (!L || !map || !markersLayer) return;

    markersLayer.clearLayers();

    filteredEvents.value.forEach((ev) => {
        if (!ev.coordinates || !L) return;

        const [lat, lng] = ev.coordinates;
        const marker = L.marker([lat, lng], {
            icon: createMarkerIcon(ev),
            title: ev.title,
        });

        const theme = getCategoryTheme(ev.category, ev.severity_level);
        const timeFormatted = ev.event_time
            ? new Date(ev.event_time).toLocaleString()
            : 'Recent';

        const popupContent = document.createElement('div');
        popupContent.className =
            'disaster-popup p-1 text-xs space-y-1.5 max-w-[240px]';
        popupContent.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:4px;">
                <span style="font-weight:700; font-size:10px; text-transform:uppercase; color:${theme.color}; background:color-mix(in srgb, ${theme.color} 15%, transparent); border:1px solid color-mix(in srgb, ${theme.color} 35%, transparent); padding:1px 6px; border-radius:4px;">
                    ${ev.category}
                </span>
                <span style="font-size:10px; color:var(--muted-foreground); font-family:monospace;">
                    ${ev.source}
                </span>
            </div>
            <h4 style="font-size:12px; font-weight:700; line-height:1.35; color:var(--foreground); margin:0;">
                ${ev.title}
            </h4>
            <div style="display:flex; flex-direction:column; gap:2px; font-size:11px; color:var(--muted-foreground);">
                <div><strong style="color:var(--foreground);">Location:</strong> ${ev.country}</div>
                <div><strong style="color:var(--foreground);">Time:</strong> ${timeFormatted}</div>
                ${ev.severity_text ? `<div><strong style="color:var(--foreground);">Alert:</strong> <span style="color:${theme.color}; font-weight:600;">${ev.severity_text}</span></div>` : ''}
                ${ev.depth_km !== null && ev.depth_km !== undefined ? `<div><strong style="color:var(--foreground);">Depth:</strong> ${ev.depth_km} km</div>` : ''}
                <div style="font-family:monospace; font-size:10px; color:var(--muted-foreground); opacity:0.85;">Coords: ${lat.toFixed(3)}, ${lng.toFixed(3)}</div>
            </div>
            <div style="margin-top:6px; padding-top:6px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <a href="${ev.source_url}" target="_blank" rel="noopener noreferrer" style="font-size:11px; color:#3b82f6; text-decoration:none; font-weight:600;">
                    Official Source &rarr;
                </a>
            </div>
        `;

        marker.bindPopup(popupContent, { maxWidth: 260 });
        marker.on('click', () => {
            emit('select-event', ev);
        });

        markersLayer!.addLayer(marker);
    });
}

function resetMapView() {
    if (!map) return;
    emit('update:selectedCountry', 'All');
    emit('update:selectedCategory', 'all');
    map.setView([10.0, 108.0], 5, { animate: true });
}

watch(
    () => props.selectedCountry,
    (newCountry) => {
        if (!map) return;
        const c = (props.countries || {})[newCountry];
        if (c) {
            if (c.bounds && c.bounds.length === 2) {
                const b: LType.LatLngBoundsExpression = [
                    [c.bounds[0][0], c.bounds[0][1]],
                    [c.bounds[1][0], c.bounds[1][1]],
                ];
                map.fitBounds(b, { padding: [30, 30], animate: true });
            } else if (c.center) {
                const p: LType.LatLngTuple = [c.center[0], c.center[1]];
                map.setView(p, c.zoom, { animate: true });
            }
        }
    },
);

watch(
    [
        () => props.events,
        () => props.selectedCategory,
        () => props.selectedCountry,
    ],
    () => {
        nextTick(() => {
            updateMarkers();
        });
    },
);

onMounted(() => {
    void initMap();
});

onUnmounted(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <section
        id="map"
        class="rounded-2xl border border-border/70 bg-card/50 p-4 shadow-xs sm:p-6"
    >
        <!-- Section Title & Controls Bar -->
        <div
            class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <div>
                <div class="flex items-center gap-2">
                    <span
                        class="size-2 animate-ping rounded-full bg-destructive"
                    ></span>
                    <h3
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Live Geospatial Visualization
                    </h3>
                </div>
                <h2
                    class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                >
                    Interactive Southeast Asia Disaster Map
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Displaying
                    <span class="font-mono font-bold text-foreground">{{
                        filteredEvents.length
                    }}</span>
                    verified coordinates across ASEAN
                </p>
            </div>

            <!-- Country Selector & Category Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Country Dropdown -->
                <div
                    class="flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-xs shadow-xs"
                >
                    <MapPin class="size-3.5 text-muted-foreground" />
                    <label for="country-select" class="sr-only"
                        >Select Country</label
                    >
                    <select
                        id="country-select"
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

                <!-- Reset View Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1 border-border bg-card text-xs"
                    title="Reset Map to Regional View"
                    @click="resetMapView"
                >
                    <RotateCcw class="size-3" />
                    <span>Reset</span>
                </Button>
            </div>
        </div>

        <!-- In-Map Category Filter Tabs -->
        <div
            class="mb-3 flex flex-wrap items-center gap-1.5 overflow-x-auto pb-1"
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

        <!-- Map View Container -->
        <div
            class="relative overflow-hidden rounded-xl border border-border shadow-sm"
        >
            <!-- Map Leaflet Canvas -->
            <div
                ref="mapContainer"
                class="z-10 h-[460px] w-full sm:h-[540px] lg:h-[600px]"
            ></div>

            <!-- Floating Legend & Info Overlay -->
            <div
                class="absolute bottom-4 left-4 z-20 hidden max-w-xs rounded-lg border border-border/80 bg-card/95 p-3 shadow-md backdrop-blur-md sm:block"
            >
                <span
                    class="mb-1.5 block text-[11px] font-bold tracking-wider text-foreground uppercase"
                >
                    Disaster Legend
                </span>
                <div
                    class="grid grid-cols-2 gap-1.5 text-[11px] text-muted-foreground"
                >
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-rose-500"></span>
                        <span>Red Alert (Critical)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-amber-500"></span>
                        <span>Earthquake</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-sky-500"></span>
                        <span>Tropical Cyclone</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-blue-500"></span>
                        <span>Flood</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 rounded-full bg-orange-500"
                        ></span>
                        <span>Wildfire</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 rounded-full bg-purple-500"
                        ></span>
                        <span>Volcano</span>
                    </div>
                </div>
            </div>

            <!-- Empty State Overlay if 0 events matching filter -->
            <div
                v-if="filteredEvents.length === 0"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/75 p-4 text-center backdrop-blur-xs"
            >
                <div
                    class="mb-2 flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <Filter class="size-6" />
                </div>
                <p class="text-sm font-semibold text-foreground">
                    No events match the selected filters
                </p>
                <p class="mt-1 max-w-sm text-xs text-muted-foreground">
                    No active incidents recorded for
                    {{ selectedCountry }} under category "{{
                        selectedCategory
                    }}".
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-3 text-xs"
                    @click="resetMapView"
                >
                    Reset Filters
                </Button>
            </div>
        </div>
    </section>
</template>

<style>
/* Leaflet Custom Marker, Popup & Dark Mode Styles */
.disaster-custom-marker {
    background: transparent;
    border: none;
}
.leaflet-container {
    background-color: hsl(210 25% 94%);
    font-family: inherit;
}
.dark .leaflet-container {
    background-color: hsl(222 47% 9%);
}
.dark .leaflet-tile-pane {
    filter: brightness(0.82) contrast(1.08) saturate(0.85);
}
.leaflet-popup-content-wrapper {
    background-color: var(--card) !important;
    color: var(--card-foreground) !important;
    border-radius: 0.5rem;
    box-shadow:
        0 10px 15px -3px rgba(0, 0, 0, 0.25),
        0 4px 6px -4px rgba(0, 0, 0, 0.15);
    border: 1px solid var(--border);
}
.leaflet-popup-tip {
    background-color: var(--card) !important;
}
.leaflet-popup-close-button {
    color: var(--muted-foreground) !important;
}
.leaflet-popup-close-button:hover {
    color: var(--foreground) !important;
}
.leaflet-popup-content {
    margin: 8px 10px !important;
    line-height: 1.4;
}
.leaflet-bar a {
    background-color: var(--card) !important;
    color: var(--foreground) !important;
    border-bottom-color: var(--border) !important;
}
.leaflet-bar a:hover {
    background-color: var(--muted) !important;
    color: var(--foreground) !important;
}
.leaflet-control-attribution {
    background-color: color-mix(
        in srgb,
        var(--background) 85%,
        transparent
    ) !important;
    color: var(--muted-foreground) !important;
    backdrop-filter: blur(4px);
}
.leaflet-control-attribution a {
    color: var(--foreground) !important;
}
</style>
