<script setup lang="ts">
import { Compass, MapPin, Minus, Plus, RotateCcw } from '@lucide/vue';
import type * as LType from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

interface Coordinates {
    latitude: number | null;
    longitude: number | null;
}

const props = withDefaults(
    defineProps<{
        modelValue: Coordinates;
        countryCenter?: [number, number] | null;
        countryZoom?: number | null;
        readonly?: boolean;
        heightClass?: string;
    }>(),
    {
        countryCenter: null,
        countryZoom: 6,
        readonly: false,
        heightClass: 'h-64 sm:h-72',
    },
);

const emit = defineEmits<{
    (
        e: 'update:modelValue',
        value: { latitude: number; longitude: number },
    ): void;
}>();

const mapContainer = ref<HTMLDivElement | null>(null);
let map: LType.Map | null = null;
let marker: LType.Marker | null = null;
let L: typeof LType | null = null;

const defaultCenter: [number, number] = [10.0, 108.0]; // Southeast Asia center

function createPinIcon(leaflet: typeof LType): LType.DivIcon {
    return leaflet.divIcon({
        className: 'custom-location-pin',
        html: `
            <div style="position: relative; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">
                <div style="position: absolute; width: 14px; height: 14px; border-radius: 9999px; background: rgba(239, 68, 68, 0.35); animation: ping 1.5s cubic-bezier(0, 0, 0.2, 1) infinite;"></div>
                <div style="width: 28px; height: 28px; border-radius: 9999px; background: #dc2626; color: white; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3); border: 2px solid white;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                </div>
            </div>
        `,
        iconSize: [36, 36],
        iconAnchor: [18, 32],
    });
}

async function initMap() {
    if (typeof window === 'undefined' || !mapContainer.value) return;

    if (!L) {
        const leafletModule = await import('leaflet');
        L = (leafletModule.default ?? leafletModule) as typeof LType;
    }

    if (!mapContainer.value) return;

    if (map) {
        map.remove();
        map = null;
        marker = null;
    }

    const initialCenter =
        props.modelValue.latitude !== null &&
        props.modelValue.longitude !== null
            ? ([props.modelValue.latitude, props.modelValue.longitude] as [
                  number,
                  number,
              ])
            : props.countryCenter || defaultCenter;

    const initialZoom =
        props.modelValue.latitude !== null &&
        props.modelValue.longitude !== null
            ? 9
            : props.countryZoom || 5;

    map = L.map(mapContainer.value, {
        center: initialCenter,
        zoom: initialZoom,
        zoomControl: false,
        attributionControl: true,
        scrollWheelZoom: true,
    });

    const isDark = document.documentElement.classList.contains('dark');
    const tileUrl = isDark
        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
        : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const attribution = isDark
        ? '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OSM</a> &copy; <a href="https://carto.com/" target="_blank">CARTO</a>'
        : '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>';

    L.tileLayer(tileUrl, {
        attribution,
        maxZoom: 18,
    }).addTo(map);

    // If initial coordinates exist, place marker
    if (
        props.modelValue.latitude !== null &&
        props.modelValue.longitude !== null
    ) {
        marker = L.marker(
            [props.modelValue.latitude, props.modelValue.longitude],
            {
                icon: createPinIcon(L),
            },
        ).addTo(map);
    }

    // Map click handler
    if (!props.readonly) {
        map.on('click', (e: LType.LeafletMouseEvent) => {
            if (!map || !L) return;
            const lat = Math.round(e.latlng.lat * 1000000) / 1000000;
            const lng = Math.round(e.latlng.lng * 1000000) / 1000000;

            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], {
                    icon: createPinIcon(L),
                }).addTo(map);
            }

            emit('update:modelValue', { latitude: lat, longitude: lng });
        });
    }

    nextTick(() => {
        map?.invalidateSize();
    });
}

function handleZoomIn() {
    map?.zoomIn();
}

function handleZoomOut() {
    map?.zoomOut();
}

function handleResetView() {
    if (!map) return;
    if (
        props.modelValue.latitude !== null &&
        props.modelValue.longitude !== null
    ) {
        map.setView(
            [props.modelValue.latitude, props.modelValue.longitude],
            10,
        );
    } else if (props.countryCenter) {
        map.setView(props.countryCenter, props.countryZoom || 6);
    } else {
        map.setView(defaultCenter, 5);
    }
}

// Watch external coordinate changes
watch(
    () => [props.modelValue.latitude, props.modelValue.longitude],
    ([newLat, newLng]) => {
        if (!map || !L) return;
        if (
            typeof newLat === 'number' &&
            typeof newLng === 'number' &&
            !isNaN(newLat) &&
            !isNaN(newLng)
        ) {
            if (marker) {
                marker.setLatLng([newLat, newLng]);
            } else {
                marker = L.marker([newLat, newLng], {
                    icon: createPinIcon(L),
                }).addTo(map);
            }
        } else if (marker) {
            marker.remove();
            marker = null;
        }
    },
);

// Watch country center changes
watch(
    () => props.countryCenter,
    (newCenter) => {
        if (!map || !newCenter) return;
        // Only re-center if user hasn't explicitly set coordinates
        if (
            props.modelValue.latitude === null ||
            props.modelValue.longitude === null
        ) {
            map.flyTo(newCenter, props.countryZoom || 6, { duration: 1 });
        }
    },
);

onMounted(() => {
    initMap();
});

onUnmounted(() => {
    if (map) {
        map.remove();
        map = null;
        marker = null;
    }
});
</script>

<template>
    <div
        class="relative w-full overflow-hidden rounded-xl border border-border/80 bg-muted/20 shadow-sm"
    >
        <div ref="mapContainer" :class="['z-10 w-full', heightClass]"></div>

        <!-- Help overlay tooltip -->
        <div
            v-if="!readonly"
            class="pointer-events-none absolute top-3 left-3 z-20 flex items-center gap-1.5 rounded-lg border border-border/80 bg-background/90 px-2.5 py-1 text-[11px] font-medium text-foreground shadow-sm backdrop-blur-sm"
        >
            <MapPin class="size-3 animate-bounce text-destructive" />
            <span>Click map to place or reposition incident marker</span>
        </div>

        <!-- Floating Controls -->
        <div class="absolute right-3 bottom-3 z-20 flex flex-col gap-1.5">
            <Button
                variant="outline"
                size="icon"
                class="size-8 cursor-pointer rounded-lg border-border/80 bg-background/95 text-foreground shadow-sm hover:bg-muted"
                title="Zoom In"
                type="button"
                @click="handleZoomIn"
            >
                <Plus class="size-4" />
            </Button>
            <Button
                variant="outline"
                size="icon"
                class="size-8 cursor-pointer rounded-lg border-border/80 bg-background/95 text-foreground shadow-sm hover:bg-muted"
                title="Zoom Out"
                type="button"
                @click="handleZoomOut"
            >
                <Minus class="size-4" />
            </Button>
            <Button
                variant="outline"
                size="icon"
                class="size-8 cursor-pointer rounded-lg border-border/80 bg-background/95 text-foreground shadow-sm hover:bg-muted"
                title="Center on Coordinates"
                type="button"
                @click="handleResetView"
            >
                <RotateCcw class="size-3.5" />
            </Button>
        </div>
    </div>
</template>

<style scoped>
:deep(.custom-location-pin) {
    background: transparent;
    border: none;
}
</style>
