<script setup lang="ts">
import {
    Compass,
    ExternalLink,
    Globe,
    MapPin,
    MapPinOff,
    Minus,
    Plus,
    RotateCcw,
} from '@lucide/vue';
import type * as LType from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { DisasterEvent } from '@/types/disaster';

const props = defineProps<{
    event: DisasterEvent;
}>();

const mapContainer = ref<HTMLDivElement | null>(null);
let map: LType.Map | null = null;
let marker: LType.Marker | null = null;
let L: typeof LType | null = null;

const hasCoords = () => {
    const lat = props.event.latitude ?? props.event.coordinates?.[0];
    const lng = props.event.longitude ?? props.event.coordinates?.[1];
    return (
        typeof lat === 'number' &&
        typeof lng === 'number' &&
        !isNaN(lat) &&
        !isNaN(lng)
    );
};

function getCategoryColor(category?: string, severity?: string | null): string {
    if (severity === 'Red') return '#ef4444';
    if (severity === 'Orange') return '#f97316';
    const cat = (category || '').toLowerCase();
    if (cat.includes('earthquake')) return '#f97316';
    if (cat.includes('cyclone') || cat.includes('storm')) return '#06b6d4';
    if (cat.includes('flood') || cat.includes('wave')) return '#3b82f6';
    if (cat.includes('wildfire') || cat.includes('fire')) return '#e11d48';
    if (cat.includes('volcano')) return '#a855f7';
    if (cat.includes('drought')) return '#eab308';
    return '#64748b';
}

function escapeHtml(str: string): string {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function initMiniMap() {
    if (typeof window === 'undefined' || !mapContainer.value || !hasCoords())
        return;

    if (!L) {
        const leafletModule = await import('leaflet');
        L = (leafletModule.default ?? leafletModule) as typeof LType;
    }

    if (!mapContainer.value) return;

    if (map) {
        map.remove();
        map = null;
    }

    const lat = props.event.latitude ?? props.event.coordinates![0];
    const lng = props.event.longitude ?? props.event.coordinates![1];
    const color = getCategoryColor(
        props.event.disaster_type || props.event.category,
        props.event.severity_level,
    );

    map = L.map(mapContainer.value, {
        center: [lat, lng],
        zoom: 7,
        zoomControl: false,
        attributionControl: true,
        scrollWheelZoom: false,
    });

    const isDark = document.documentElement.classList.contains('dark');
    const tileUrl = isDark
        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
        : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    const attribution = isDark
        ? '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OSM</a> contributors &copy; <a href="https://carto.com/" target="_blank">CARTO</a>'
        : '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors';

    L.tileLayer(tileUrl, {
        attribution,
        maxZoom: 18,
    }).addTo(map);

    const isRed = props.event.severity_level === 'Red';
    const isVolcano = (props.event.category || '')
        .toLowerCase()
        .includes('volcano');
    const isDiamond = isRed || isVolcano;

    let markerBadge = '';
    if (props.event.magnitude) {
        markerBadge = props.event.magnitude.toFixed(1);
    } else {
        const cat = (
            props.event.disaster_type ||
            props.event.category ||
            ''
        ).toLowerCase();
        if (cat.includes('earthquake')) markerBadge = 'EQ';
        else if (cat.includes('cyclone') || cat.includes('storm'))
            markerBadge = 'TC';
        else if (cat.includes('flood')) markerBadge = 'FL';
        else if (cat.includes('wildfire')) markerBadge = 'WF';
        else if (cat.includes('volcano')) markerBadge = 'VO';
        else markerBadge = '!';
    }

    const iconHtml = `
        <div style="position:relative; width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
            ${isRed ? `<div style="position:absolute; inset:-4px; border-radius:${isDiamond ? '6px' : '9999px'}; background:${color}; opacity:0.35; animation:ping 1.5s cubic-bezier(0, 0, 0.2, 1) infinite; transform:${isDiamond ? 'rotate(45deg)' : 'none'};"></div>` : ''}
            <div style="
                width:30px; height:30px;
                background-color:${color};
                border:2.5px solid #ffffff;
                box-shadow:0 4px 10px rgba(0,0,0,0.35);
                display:flex; align-items:center; justify-content:center;
                color:#ffffff; font-family:monospace; font-weight:800; font-size:11px;
                border-radius:${isDiamond ? '6px' : '9999px'};
                transform:${isDiamond ? 'rotate(45deg)' : 'none'};
            ">
                <span style="${isDiamond ? 'transform:rotate(-45deg); display:inline-block;' : ''}">${markerBadge}</span>
            </div>
        </div>
    `;

    const icon = L.divIcon({
        className: 'disaster-custom-mini-marker',
        html: iconHtml,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
        popupAnchor: [0, -18],
    });

    marker = L.marker([lat, lng], { icon }).addTo(map);

    const popupHtml = `
        <div style="font-size:11px; font-family:sans-serif; min-width:180px;">
            <div style="font-weight:700; color:${color}; font-size:10px; text-transform:uppercase; margin-bottom:2px;">
                ${escapeHtml(props.event.disaster_type || props.event.category)}
            </div>
            <div style="font-weight:700; font-size:12px; margin-bottom:4px; line-height:1.3;">
                ${escapeHtml(props.event.title)}
            </div>
            <div style="color:var(--muted-foreground); font-family:monospace; font-size:10px;">
                ${lat.toFixed(4)}° N, ${lng.toFixed(4)}° E
            </div>
        </div>
    `;
    marker.bindPopup(popupHtml);
}

function zoomIn() {
    map?.zoomIn();
}

function zoomOut() {
    map?.zoomOut();
}

function resetView() {
    if (!map || !hasCoords()) return;
    const lat = props.event.latitude ?? props.event.coordinates![0];
    const lng = props.event.longitude ?? props.event.coordinates![1];
    map.setView([lat, lng], 7);
}

onMounted(() => {
    void nextTick(() => {
        void initMiniMap();
    });
});

watch(
    () => props.event,
    () => {
        void nextTick(() => {
            void initMiniMap();
        });
    },
    { deep: true },
);

onUnmounted(() => {
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl border border-border bg-card shadow-sm"
    >
        <div
            class="flex items-center justify-between border-b border-border/80 px-4 py-3"
        >
            <div class="flex items-center gap-2">
                <MapPin class="size-4 text-primary" />
                <h3
                    class="text-xs font-bold tracking-wider text-foreground uppercase"
                >
                    Incident Geospatial Location
                </h3>
            </div>
            <div
                v-if="hasCoords()"
                class="flex items-center gap-1 font-mono text-[11px] text-muted-foreground"
            >
                <span
                    >{{ (event.latitude ?? event.coordinates![0]).toFixed(3) }}°
                    N,
                    {{ (event.longitude ?? event.coordinates![1]).toFixed(3) }}°
                    E</span
                >
            </div>
        </div>

        <!-- Coordinates available: Leaflet mini-map -->
        <div v-if="hasCoords()" class="relative h-64 w-full sm:h-72">
            <div ref="mapContainer" class="z-0 size-full bg-muted/40"></div>

            <!-- Mini Map Floating Controls -->
            <div
                class="absolute right-3 bottom-3 z-10 flex flex-col gap-1.5 shadow-md"
            >
                <Button
                    variant="outline"
                    size="icon"
                    class="size-7 rounded-md border-border bg-background/90 text-foreground backdrop-blur-sm hover:bg-background"
                    title="Zoom in"
                    @click="zoomIn"
                >
                    <Plus class="size-3.5" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-7 rounded-md border-border bg-background/90 text-foreground backdrop-blur-sm hover:bg-background"
                    title="Zoom out"
                    @click="zoomOut"
                >
                    <Minus class="size-3.5" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-7 rounded-md border-border bg-background/90 text-foreground backdrop-blur-sm hover:bg-background"
                    title="Reset view"
                    @click="resetView"
                >
                    <RotateCcw class="size-3" />
                </Button>
            </div>

            <!-- View on regional map button -->
            <div class="absolute top-3 left-3 z-10">
                <a
                    href="/map"
                    class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background/90 px-2.5 py-1 text-[11px] font-semibold text-foreground shadow-sm backdrop-blur-sm hover:bg-background"
                >
                    <Globe class="size-3 text-primary" />
                    <span>View in Full Regional Map</span>
                </a>
            </div>
        </div>

        <!-- Coordinates missing: Graceful informative placeholder -->
        <div
            v-else
            class="flex flex-col items-center justify-center bg-muted/20 p-8 text-center"
        >
            <div
                class="mb-3 flex size-12 items-center justify-center rounded-full border border-amber-500/20 bg-amber-500/10 text-amber-500"
            >
                <MapPinOff class="size-6" />
            </div>
            <h4 class="text-sm font-semibold text-foreground">
                Exact Coordinates Unavailable
            </h4>
            <p
                class="mt-1.5 max-w-sm text-xs leading-relaxed text-muted-foreground"
            >
                This incident was reported by {{ event.source }} as a broad
                regional bulletin or advisory for
                <strong class="font-medium text-foreground">{{
                    event.country
                }}</strong>
                without pinpoint latitude and longitude coordinates.
            </p>
            <div class="mt-4 flex items-center gap-2">
                <a
                    href="/map"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-muted"
                >
                    <Globe class="size-3.5" />
                    <span>Open Regional Map</span>
                </a>
            </div>
        </div>
    </div>
</template>

<style>
.disaster-custom-mini-marker {
    background: transparent;
    border: none;
}
</style>
