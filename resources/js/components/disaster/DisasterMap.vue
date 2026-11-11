<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Building2,
    Calendar,
    Compass,
    ExternalLink,
    Eye,
    EyeOff,
    Filter,
    Flame,
    Globe,
    Layers,
    MapPin,
    Minus,
    Plus,
    RefreshCw,
    RotateCcw,
    ShieldAlert,
    Waves,
    Wind,
} from '@lucide/vue';
import type * as LType from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    AseanCountry,
    DashboardData,
    DisasterEvent,
    ShelterItem,
    SourceFreshness,
} from '@/types/disaster';

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
        shelters?: ShelterItem[] | null;
        initialShowShelters?: boolean;
    }>(),
    {
        selectedSeverity: 'all',
        selectedDateRange: 'all',
        isLoading: false,
        sources: null,
        shelters: null,
        initialShowShelters: true,
    },
);

const emit = defineEmits<{
    (e: 'update:selectedCountry', country: string): void;
    (e: 'update:selectedCategory', category: string): void;
    (e: 'update:selectedSeverity', severity: string): void;
    (e: 'update:selectedDateRange', dateRange: string): void;
    (e: 'select-event', event: DisasterEvent): void;
}>();

const isMounted = ref(false);
const mapContainer = ref<HTMLElement | null>(null);
const showLegend = ref(true);
const clusteringEnabled = ref(true);
const listLimit = ref(12);

// Internal fallback refs if parent does not bind v-model:selected-severity / v-model:selected-date-range
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

let L: typeof LType | null = null;
let map: LType.Map | null = null;
let markersLayer: LType.LayerGroup | null = null;
let sheltersLayer: LType.LayerGroup | null = null;
const showSheltersLayer = ref(props.initialShowShelters ?? true);
const markerByEventId = new Map<string, LType.Marker>();

// ASEAN Regional Bounds & Default Center
const ASEAN_CENTER: [number, number] = [10.0, 108.0];
const ASEAN_DEFAULT_ZOOM = 5;
const ASEAN_BOUNDS: [[number, number], [number, number]] = [
    [-11.5, 92.0],
    [28.5, 142.0],
];

// Categories list for in-map filter pills
const categoryOptions = [
    { id: 'all', label: 'All Incidents', icon: Globe },
    { id: 'earthquake', label: 'Earthquakes', icon: Activity },
    { id: 'cyclone', label: 'Cyclones & Storms', icon: Wind },
    { id: 'flood', label: 'Floods', icon: Waves },
    { id: 'wildfire', label: 'Wildfires', icon: Flame },
    { id: 'volcano', label: 'Volcanoes', icon: AlertTriangle },
];

const severityOptions = [
    { id: 'all', label: 'All Severities' },
    { id: 'Red', label: 'Red Alert (Critical)' },
    { id: 'Orange', label: 'Orange Alert (Moderate)' },
    { id: 'Green', label: 'Green Alert (Minor)' },
    { id: 'high', label: 'Red & Orange Alerts' },
    { id: 'unclassified', label: 'Unclassified by Source' },
];

const dateRangeOptions = [
    { id: 'all', label: 'All Dates' },
    { id: '24h', label: 'Last 24 Hours' },
    { id: '7d', label: 'Last 7 Days' },
    { id: '30d', label: 'Last 30 Days' },
];

const eventSources = computed<SourceFreshness[]>(() => {
    if (!props.sources) return [];
    return [
        props.sources.gdacs,
        props.sources.usgs,
    ].filter((s): s is SourceFreshness => Boolean(s));
});

const staleEventSources = computed(() =>
    eventSources.value.filter((s) => s.is_stale || s.status === 'Stale'),
);

const allEventSourcesUnavailable = computed(
    () =>
        eventSources.value.length > 0 &&
        eventSources.value.every((s) => s.status === 'Unavailable'),
);

const hasActiveFilters = computed(
    () =>
        props.selectedCountry !== 'All' ||
        props.selectedCategory !== 'all' ||
        localSeverity.value !== 'all' ||
        localDateRange.value !== 'all',
);

function hasValidCoordinates(ev: DisasterEvent): boolean {
    if (
        ev.coordinates &&
        Array.isArray(ev.coordinates) &&
        ev.coordinates.length === 2
    ) {
        const [lat, lng] = ev.coordinates;
        return (
            typeof lat === 'number' &&
            typeof lng === 'number' &&
            !Number.isNaN(lat) &&
            !Number.isNaN(lng) &&
            lat >= -90 &&
            lat <= 90 &&
            lng >= -180 &&
            lng <= 180
        );
    }
    if (
        typeof ev.latitude === 'number' &&
        typeof ev.longitude === 'number' &&
        !Number.isNaN(ev.latitude) &&
        !Number.isNaN(ev.longitude)
    ) {
        return (
            ev.latitude >= -90 &&
            ev.latitude <= 90 &&
            ev.longitude >= -180 &&
            ev.longitude <= 180
        );
    }
    return false;
}

function getEventCoords(ev: DisasterEvent): [number, number] | null {
    if (!hasValidCoordinates(ev)) return null;
    if (ev.coordinates && ev.coordinates.length === 2) {
        return [ev.coordinates[0], ev.coordinates[1]];
    }
    if (ev.latitude !== null && ev.longitude !== null) {
        return [ev.latitude, ev.longitude];
    }
    return null;
}

// 1. Filter all events (keeping events with AND without valid coordinates for the synchronized list)
const matchingAllEvents = computed<DisasterEvent[]>(() => {
    const eventList = Array.isArray(props.events) ? props.events : [];
    const countryMap = props.countries || {};
    const nowMs = Date.now();

    return eventList.filter((ev) => {
        // ASEAN Country filter
        if (props.selectedCountry !== 'All') {
            const evCountry = (ev.country || '').toLowerCase();
            const evLocation = (ev.location || '').toLowerCase();
            const targetCountry = props.selectedCountry.toLowerCase();
            if (
                !evCountry.includes(targetCountry) &&
                !evLocation.includes(targetCountry) &&
                ev.iso3 !== countryMap[props.selectedCountry]?.iso3
            ) {
                return false;
            }
        }

        // Disaster Type filter
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

        return true;
    });
});

// 2. Map events: only events from matchingAllEvents that have valid latitude & longitude
const filteredMapEvents = computed<DisasterEvent[]>(() =>
    matchingAllEvents.value.filter((ev) => hasValidCoordinates(ev)),
);

const eventsWithoutCoordinatesCount = computed(
    () => matchingAllEvents.value.length - filteredMapEvents.value.length,
);

const visibleListEvents = computed(() =>
    matchingAllEvents.value.slice(0, listLimit.value),
);

// Category color and icon helpers
function getCategoryTheme(
    category: string,
    severityLevel?: string | null,
): {
    color: string;
    bgHex: string;
    borderHex: string;
    label: string;
    shortCode: string;
} {
    const cat = (category || '').toLowerCase();

    let shortCode = 'AL';
    if (cat.includes('earthquake')) shortCode = 'EQ';
    else if (cat.includes('cyclone') || cat.includes('storm')) shortCode = 'TC';
    else if (cat.includes('flood')) shortCode = 'FL';
    else if (cat.includes('wildfire') || cat.includes('fire')) shortCode = 'WF';
    else if (cat.includes('volcano')) shortCode = 'VO';
    else if (cat.includes('drought')) shortCode = 'DR';

    if (severityLevel === 'Red') {
        return {
            color: '#ef4444',
            bgHex: '#fee2e2',
            borderHex: '#dc2626',
            label: 'Red Alert',
            shortCode,
        };
    }

    if (cat.includes('earthquake')) {
        if (severityLevel === 'Orange')
            return {
                color: '#f97316',
                bgHex: '#ffedd5',
                borderHex: '#ea580c',
                label: 'Moderate EQ',
                shortCode,
            };
        return {
            color: '#eab308',
            bgHex: '#fef9c3',
            borderHex: '#ca8a04',
            label: 'Earthquake',
            shortCode,
        };
    }
    if (cat.includes('cyclone') || cat.includes('storm')) {
        return {
            color: '#06b6d4',
            bgHex: '#cffafe',
            borderHex: '#0891b2',
            label: 'Cyclone / Storm',
            shortCode,
        };
    }
    if (cat.includes('flood')) {
        return {
            color: '#3b82f6',
            bgHex: '#dbeafe',
            borderHex: '#2563eb',
            label: 'Flood',
            shortCode,
        };
    }
    if (cat.includes('wildfire') || cat.includes('fire')) {
        return {
            color: '#f97316',
            bgHex: '#ffedd5',
            borderHex: '#c2410c',
            label: 'Wildfire',
            shortCode,
        };
    }
    if (cat.includes('volcano')) {
        return {
            color: '#a855f7',
            bgHex: '#f3e8ff',
            borderHex: '#7e22ce',
            label: 'Volcano',
            shortCode,
        };
    }

    return {
        color: '#64748b',
        bgHex: '#f1f5f9',
        borderHex: '#4755f9',
        label: category,
        shortCode,
    };
}

function getCategoryBadgeClasses(cat: string): string {
    const c = (cat || '').toLowerCase();
    if (c.includes('earthquake'))
        return 'text-amber-600 dark:text-amber-400 bg-amber-500/10 border-amber-500/25';
    if (c.includes('cyclone') || c.includes('storm'))
        return 'text-cyan-600 dark:text-cyan-400 bg-cyan-500/10 border-cyan-500/25';
    if (c.includes('flood'))
        return 'text-blue-600 dark:text-blue-400 bg-blue-500/10 border-blue-500/25';
    if (c.includes('wildfire') || c.includes('fire'))
        return 'text-orange-600 dark:text-orange-400 bg-orange-500/10 border-orange-500/25';
    if (c.includes('volcano'))
        return 'text-purple-600 dark:text-purple-400 bg-purple-500/10 border-purple-500/25';
    return 'text-slate-600 dark:text-slate-400 bg-slate-500/10 border-slate-500/25';
}

function getCategoryIconComponent(cat: string) {
    const c = (cat || '').toLowerCase();
    if (c.includes('earthquake')) return Activity;
    if (c.includes('cyclone') || c.includes('storm')) return Wind;
    if (c.includes('flood')) return Waves;
    if (c.includes('wildfire') || c.includes('fire')) return Flame;
    if (c.includes('volcano')) return AlertTriangle;
    return ShieldAlert;
}

function createMarkerIcon(event: DisasterEvent): LType.DivIcon {
    if (event.is_community_report) {
        const iconHtml = `
            <div style="position:relative; width:30px; height:30px; display:flex; align-items:center; justify-content:center; cursor:pointer;" title="Verified Community Incident">
                <span style="position:absolute; inset:-4px; border-radius:9999px; background:#8b5cf6; opacity:0.35; animation:ping 2s cubic-bezier(0,0,0.2,1) infinite;"></span>
                <div style="
                    width: 26px;
                    height: 26px;
                    border-radius: 9999px;
                    background: linear-gradient(135deg, #7c3aed, #9333ea);
                    border: 2px solid #ffffff;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.35);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #ffffff;
                    font-weight: 800;
                    font-size: 9px;
                    letter-spacing: -0.02em;
                ">
                    <span>CR</span>
                </div>
            </div>
        `;
        return L!.divIcon({
            className: 'disaster-custom-marker',
            html: iconHtml,
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -16],
        });
    }

    const categoryLabel = event.disaster_type || event.category;
    const theme = getCategoryTheme(categoryLabel, event.severity_level);
    const isMajor =
        event.severity_level === 'Red' ||
        (event.magnitude !== null &&
            event.magnitude !== undefined &&
            event.magnitude >= 6.0);

    const pulseRing = isMajor
        ? `<span style="position:absolute; inset:-5px; border-radius:9999px; background:${theme.color}; opacity:0.45; animation:ping 1.5s cubic-bezier(0,0,0.2,1) infinite;"></span>`
        : '';

    // Distinct shape & badge per disaster category
    const isDiamond =
        theme.shortCode === 'VO' || event.severity_level === 'Red';
    const borderRadius = isDiamond ? '6px' : '9999px';
    const rotation = isDiamond ? 'transform: rotate(45deg);' : '';
    const innerRotation = isDiamond ? 'transform: rotate(-45deg);' : '';

    const labelText =
        event.magnitude !== null && event.magnitude !== undefined
            ? event.magnitude.toFixed(1)
            : theme.shortCode;

    const iconHtml = `
        <div style="position:relative; width:30px; height:30px; display:flex; align-items:center; justify-content:center; cursor:pointer;">
            ${pulseRing}
            <div style="
                width: 26px;
                height: 26px;
                border-radius: ${borderRadius};
                ${rotation}
                background-color: ${theme.color};
                border: 2px solid #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.35);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffffff;
                font-weight: 800;
                font-size: 9.5px;
                letter-spacing: -0.02em;
            ">
                <span style="${innerRotation}">${labelText}</span>
            </div>
        </div>
    `;

    return L!.divIcon({
        className: 'disaster-custom-marker',
        html: iconHtml,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16],
    });
}

function createClusterIcon(clusterEvents: DisasterEvent[]): LType.DivIcon {
    const count = clusterEvents.length;
    const hasRed = clusterEvents.some((e) => e.severity_level === 'Red');
    const hasOrange = clusterEvents.some((e) => e.severity_level === 'Orange');

    const bgColor = hasRed ? '#ef4444' : hasOrange ? '#f97316' : '#0284c7';
    const ringColor = hasRed
        ? 'rgba(239, 68, 68, 0.3)'
        : hasOrange
          ? 'rgba(249, 115, 22, 0.3)'
          : 'rgba(2, 132, 199, 0.3)';

    const size = count >= 15 ? 42 : count >= 5 ? 38 : 34;

    const html = `
        <div style="
            width: ${size}px;
            height: ${size}px;
            border-radius: 9999px;
            background: ${ringColor};
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        ">
            <div style="
                width: ${size - 8}px;
                height: ${size - 8}px;
                border-radius: 9999px;
                background-color: ${bgColor};
                border: 2px solid #ffffff;
                box-shadow: 0 3px 8px rgba(0,0,0,0.35);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffffff;
                font-family: monospace;
                font-weight: 800;
                font-size: 11px;
            ">
                ${count}
            </div>
        </div>
    `;

    return L!.divIcon({
        className: 'disaster-custom-marker',
        html,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

function escapeHtml(str: string): string {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildPopupElement(
    ev: DisasterEvent,
    lat: number,
    lng: number,
): HTMLElement {
    const categoryLabel = ev.disaster_type || ev.category;
    const theme = getCategoryTheme(categoryLabel, ev.severity_level);
    const rawTime = ev.event_date || ev.event_time;
    const timeFormatted = rawTime
        ? new Date(rawTime).toLocaleString([], {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : 'Not supplied';

    const hasDistinctLocation =
        ev.location &&
        ev.location.trim() !== '' &&
        ev.location.toLowerCase() !== ev.country.toLowerCase();

    const severityDisplay =
        ev.severity ||
        ev.severity_text ||
        (ev.severity_level ? `${ev.severity_level} Alert` : null);

    const sourcesDisplay = (ev.sources || [ev.source]).join(', ');
    const isCommunity = Boolean(ev.is_community_report);
    const photoThumbnail =
        isCommunity && ev.photos && ev.photos.length > 0
            ? `<div style="margin-bottom:6px; overflow:hidden; border-radius:6px; height:70px; background:#000;">
            <img src="${escapeHtml(ev.photos[0])}" alt="Evidence" style="width:100%; height:100%; object-fit:cover;" />
           </div>`
            : '';
    const badgeBg = isCommunity ? '#8b5cf6' : theme.color;
    const badgeLabel = isCommunity
        ? 'Community Verified'
        : escapeHtml(categoryLabel);

    const popupContent = document.createElement('div');
    popupContent.className =
        'disaster-popup p-1 text-xs space-y-1.5 min-w-[220px] max-w-[260px]';
    popupContent.innerHTML = `
        ${photoThumbnail}
        <div style="display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:4px;">
            <span style="font-weight:700; font-size:10px; text-transform:uppercase; color:${badgeBg}; background:color-mix(in srgb, ${badgeBg} 15%, transparent); border:1px solid color-mix(in srgb, ${badgeBg} 35%, transparent); padding:1px 6px; border-radius:4px;">
                ${badgeLabel}
            </span>
            <span style="font-size:10px; color:var(--muted-foreground); font-family:monospace; font-weight:600;">
                ${escapeHtml(sourcesDisplay)}
            </span>
        </div>
        <h4 style="font-size:12.5px; font-weight:700; line-height:1.35; color:var(--foreground); margin:0 0 4px 0;">
            ${escapeHtml(ev.title)}
        </h4>
        <div style="display:flex; flex-direction:column; gap:2.5px; font-size:11px; color:var(--muted-foreground);">
            <div><strong style="color:var(--foreground);">Country:</strong> ${escapeHtml(ev.country)}</div>
            ${hasDistinctLocation ? `<div><strong style="color:var(--foreground);">Location:</strong> ${escapeHtml(ev.location!)}</div>` : ''}
            ${severityDisplay ? `<div><strong style="color:var(--foreground);">Severity:</strong> <span style="color:${theme.color}; font-weight:700;">${escapeHtml(severityDisplay)}</span></div>` : ''}
            <div><strong style="color:var(--foreground);">Event Date:</strong> ${escapeHtml(timeFormatted)}</div>
            ${ev.depth_km !== null && ev.depth_km !== undefined ? `<div><strong style="color:var(--foreground);">Depth:</strong> ${ev.depth_km} km</div>` : ''}
            <div style="font-family:monospace; font-size:10px; color:var(--muted-foreground); opacity:0.85;">Coords: ${lat.toFixed(3)}°, ${lng.toFixed(3)}°</div>
        </div>
        <div style="margin-top:8px; padding-top:6px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:8px;">
            <a href="/events/${escapeHtml(ev.id)}" data-action="view-details" style="font-size:11px; font-weight:600; color:var(--foreground); background:var(--muted); border:1px solid var(--border); border-radius:5px; padding:3px 8px; text-decoration:none; display:inline-flex; align-items:center; gap:3px;">
                Event Details &rarr;
            </a>
            <a href="${escapeHtml(ev.source_url)}" target="_blank" rel="noopener noreferrer" style="font-size:11px; color:#3b82f6; text-decoration:none; font-weight:600;">
                ${isCommunity ? 'Incident Page &rarr;' : 'Official Source &rarr;'}
            </a>
        </div>
    `;

    const detailsBtn = popupContent.querySelector(
        '[data-action="view-details"]',
    );
    if (detailsBtn) {
        detailsBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            router.visit('/events/' + ev.id);
        });
    }

    return popupContent;
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
        : ASEAN_CENTER;
    const zoom = initialCountry ? initialCountry.zoom : ASEAN_DEFAULT_ZOOM;

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
                    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener noreferrer">CARTO</a>',
                subdomains: 'abcd',
                maxZoom: 19,
            },
        ).addTo(map);
    } else {
        // Official OpenStreetMap HTTPS tile provider with attribution
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);
    }

    L.control.zoom({ position: 'bottomright' }).addTo(map);
    markersLayer = L.layerGroup().addTo(map);
    sheltersLayer = L.layerGroup().addTo(map);

    map.on('zoomend', () => {
        updateMarkers();
        updateSheltersMarkers();
    });

    updateMarkers();
    updateSheltersMarkers();
}

function updateMarkers() {
    if (!L || !map || !markersLayer) return;

    markersLayer.clearLayers();
    markerByEventId.clear();

    const currentZoom = map.getZoom();
    const eventsToPlot = filteredMapEvents.value;

    // Spatial pixel clustering when enabled and zoom < 10
    const shouldCluster =
        clusteringEnabled.value && currentZoom < 10 && eventsToPlot.length > 1;
    const clusterRadiusPx = currentZoom <= 5 ? 46 : currentZoom <= 7 ? 38 : 28;

    interface ClusterGroup {
        events: DisasterEvent[];
        latSum: number;
        lngSum: number;
        point: LType.Point;
    }

    const clusters: ClusterGroup[] = [];

    for (const ev of eventsToPlot) {
        const coords = getEventCoords(ev);
        if (!coords) continue;
        const [lat, lng] = coords;
        const point = map.latLngToLayerPoint([lat, lng]);

        if (!shouldCluster) {
            clusters.push({
                events: [ev],
                latSum: lat,
                lngSum: lng,
                point,
            });
            continue;
        }

        let joinedCluster: ClusterGroup | null = null;
        for (const c of clusters) {
            const dx = c.point.x - point.x;
            const dy = c.point.y - point.y;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist <= clusterRadiusPx) {
                joinedCluster = c;
                break;
            }
        }

        if (joinedCluster) {
            joinedCluster.events.push(ev);
            joinedCluster.latSum += lat;
            joinedCluster.lngSum += lng;
        } else {
            clusters.push({
                events: [ev],
                latSum: lat,
                lngSum: lng,
                point,
            });
        }
    }

    for (const group of clusters) {
        if (!L || !markersLayer) break;

        if (group.events.length === 1) {
            const ev = group.events[0]!;
            const coords = getEventCoords(ev);
            if (!coords) continue;
            const [lat, lng] = coords;

            const marker = L.marker([lat, lng], {
                icon: createMarkerIcon(ev),
                title: ev.title,
            });

            const popupEl = buildPopupElement(ev, lat, lng);
            marker.bindPopup(popupEl, { maxWidth: 280 });

            markersLayer.addLayer(marker);
            markerByEventId.set(ev.id, marker);
        } else {
            const count = group.events.length;
            const centerLat = group.latSum / count;
            const centerLng = group.lngSum / count;

            const clusterMarker = L.marker([centerLat, centerLng], {
                icon: createClusterIcon(group.events),
                title: `${count} disaster events in this area (click to expand)`,
            });

            clusterMarker.on('click', () => {
                if (!map || !L) return;
                const latLngs: LType.LatLngTuple[] = [];
                for (const item of group.events) {
                    const c = getEventCoords(item);
                    if (c) latLngs.push(c);
                }
                if (latLngs.length > 0) {
                    const bounds = L.latLngBounds(latLngs);
                    map.fitBounds(bounds, {
                        padding: [45, 45],
                        maxZoom: Math.min(map.getZoom() + 3, 12),
                        animate: true,
                    });
                }
            });

            markersLayer.addLayer(clusterMarker);
        }
    }
}

// Map Control Actions
function zoomIn() {
    if (!map) return;
    map.zoomIn();
}

function zoomOut() {
    if (!map) return;
    map.zoomOut();
}

function resetMapView() {
    if (!map) return;
    map.setView(ASEAN_CENTER, ASEAN_DEFAULT_ZOOM, { animate: true });
}

function locateAseanRegion() {
    if (!map) return;
    emit('update:selectedCountry', 'All');
    map.fitBounds(ASEAN_BOUNDS, { padding: [25, 25], animate: true });
}

function resetAllFilters() {
    emit('update:selectedCountry', 'All');
    emit('update:selectedCategory', 'all');
    setSeverityFilter('all');
    setDateRangeFilter('all');
    if (map) {
        map.setView(ASEAN_CENTER, ASEAN_DEFAULT_ZOOM, { animate: true });
    }
}

function focusEventOnMap(ev: DisasterEvent) {
    const coords = getEventCoords(ev);
    if (!map || !coords) {
        emit('select-event', ev);
        return;
    }

    const [lat, lng] = coords;
    map.setView([lat, lng], Math.max(map.getZoom(), 10), { animate: true });

    nextTick(() => {
        updateMarkers();
        const existingMarker = markerByEventId.get(ev.id);
        if (existingMarker) {
            existingMarker.openPopup();
        }
    });
}

function formatDateShort(isoString: string | null | undefined): string {
    if (!isoString) return 'Date not supplied';
    if (!isMounted.value) return isoString.slice(0, 10);
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

function updateSheltersMarkers() {
    if (!L || !map || !sheltersLayer) return;
    sheltersLayer.clearLayers();

    if (!showSheltersLayer.value) return;

    const list = props.shelters || [];
    for (const shl of list) {
        if (
            typeof shl.latitude !== 'number' ||
            typeof shl.longitude !== 'number'
        )
            continue;
        if (
            props.selectedCountry !== 'All' &&
            shl.country.toLowerCase() !== props.selectedCountry.toLowerCase()
        ) {
            continue;
        }

        const iconColor =
            shl.type === 'hospital'
                ? '#10b981'
                : shl.type === 'evacuation_center'
                  ? '#0284c7'
                  : shl.type === 'relief_center'
                    ? '#8b5cf6'
                    : '#f59e0b';
        const symbol =
            shl.type === 'hospital'
                ? 'HP'
                : shl.type === 'evacuation_center'
                  ? 'EC'
                  : shl.type === 'relief_center'
                    ? 'RH'
                    : 'SH';

        const shelterIcon = L.divIcon({
            className: 'shelter-custom-marker',
            html: `
                <div style="position:relative; width:28px; height:28px; display:flex; align-items:center; justify-content:center; cursor:pointer;" title="${escapeHtml(shl.name)}">
                    <div style="
                        width: 24px;
                        height: 24px;
                        border-radius: 6px;
                        background-color: ${iconColor};
                        border: 2px solid #ffffff;
                        box-shadow: 0 2px 5px rgba(0,0,0,0.35);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #ffffff;
                        font-weight: 800;
                        font-size: 10px;
                    ">
                        ${symbol}
                    </div>
                </div>
            `,
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14],
        });

        const marker = L.marker([shl.latitude, shl.longitude], {
            icon: shelterIcon,
        });

        const popupEl = document.createElement('div');
        popupEl.className =
            'shelter-popup p-1 text-xs space-y-1.5 min-w-[220px] max-w-[260px]';
        popupEl.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:4px;">
                <span style="font-weight:700; font-size:10px; text-transform:uppercase; color:${iconColor}; background:color-mix(in srgb, ${iconColor} 15%, transparent); border:1px solid color-mix(in srgb, ${iconColor} 35%, transparent); padding:1px 6px; border-radius:4px;">
                    ${escapeHtml(shl.type.replace('_', ' '))}
                </span>
                <span style="font-size:10px; font-weight:700; color:${shl.status === 'open' ? '#10b981' : '#f59e0b'}; text-transform:uppercase;">
                    ${escapeHtml(shl.status)}
                </span>
            </div>
            <h4 style="font-size:12.5px; font-weight:700; line-height:1.35; color:var(--foreground); margin:0 0 4px 0;">
                ${escapeHtml(shl.name)}
            </h4>
            <div style="display:flex; flex-direction:column; gap:2px; font-size:11px; color:var(--muted-foreground);">
                <div><strong style="color:var(--foreground);">Location:</strong> ${escapeHtml(shl.address)}, ${escapeHtml(shl.city)} (${escapeHtml(shl.country)})</div>
                <div><strong style="color:var(--foreground);">Capacity:</strong> ${shl.current_occupancy.toLocaleString()} / ${shl.total_capacity.toLocaleString()} (${shl.available_capacity.toLocaleString()} open)</div>
                <div><strong style="color:var(--foreground);">Contact:</strong> ${escapeHtml(shl.contact_name)} (${escapeHtml(shl.contact_phone)})</div>
            </div>
            <div style="margin-top:8px; padding-top:6px; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
                <a href="/coordination" style="font-size:11px; font-weight:600; color:#0284c7; text-decoration:none;">
                    Coordination &rarr;
                </a>
                <a href="tel:${escapeHtml(shl.contact_phone)}" style="font-size:11px; font-weight:600; color:#10b981; text-decoration:none;">
                    Call Facility &rarr;
                </a>
            </div>
        `;

        marker.bindPopup(popupEl, {
            maxWidth: 280,
            className: 'leaflet-popup-disaster',
        });
        sheltersLayer.addLayer(marker);
    }
}

watch(
    () => props.selectedCountry,
    (newCountry) => {
        if (!map) return;
        const c = (props.countries || {})[newCountry];
        if (c) {
            if (newCountry === 'All') {
                map.setView(ASEAN_CENTER, ASEAN_DEFAULT_ZOOM, {
                    animate: true,
                });
            } else if (c.bounds && c.bounds.length === 2) {
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
        localSeverity,
        localDateRange,
        clusteringEnabled,
    ],
    () => {
        nextTick(() => {
            updateMarkers();
        });
    },
);

watch(
    [() => props.shelters, showSheltersLayer, () => props.selectedCountry],
    () => {
        nextTick(() => {
            updateSheltersMarkers();
        });
    },
);

onMounted(() => {
    isMounted.value = true;
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
        class="space-y-5 rounded-2xl border border-border/70 bg-card/50 p-4 shadow-xs sm:p-6"
    >
        <!-- Section Title & Primary Controls Bar -->
        <div
            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
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
                    Plotting
                    <span class="font-mono font-bold text-foreground">{{
                        filteredMapEvents.length
                    }}</span>
                    verified coordinate markers across ASEAN
                    <template v-if="eventsWithoutCoordinatesCount > 0">
                        ({{ eventsWithoutCoordinatesCount }} additional matching
                        event{{
                            eventsWithoutCoordinatesCount === 1 ? '' : 's'
                        }}
                        without coordinates shown in list below)
                    </template>
                </p>
            </div>

            <!-- Map Action Controls Toolbar -->
            <div class="flex flex-wrap items-center gap-1.5">
                <!-- Locate ASEAN Region Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 border-border bg-card text-xs"
                    title="Fit map bounds to all 11 ASEAN nations"
                    @click="locateAseanRegion"
                >
                    <Compass class="size-3.5 text-primary" />
                    <span>Locate ASEAN</span>
                </Button>

                <!-- Reset Map View Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 border-border bg-card text-xs"
                    title="Reset map center and zoom level"
                    @click="resetMapView"
                >
                    <RotateCcw class="size-3.5" />
                    <span>Reset View</span>
                </Button>

                <!-- Toggle Clustering Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 border-border bg-card text-xs"
                    :title="
                        clusteringEnabled
                            ? 'Disable marker clustering'
                            : 'Enable marker clustering'
                    "
                    @click="clusteringEnabled = !clusteringEnabled"
                >
                    <Layers class="size-3.5" />
                    <span>{{
                        clusteringEnabled ? 'Clustering: On' : 'Clustering: Off'
                    }}</span>
                </Button>

                <!-- Show / Hide Legend Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 border-border bg-card text-xs"
                    :title="showLegend ? 'Hide map legend' : 'Show map legend'"
                    @click="showLegend = !showLegend"
                >
                    <EyeOff v-if="showLegend" class="size-3.5" />
                    <Eye v-else class="size-3.5" />
                    <span>{{
                        showLegend ? 'Hide Legend' : 'Show Legend'
                    }}</span>
                </Button>

                <!-- Reset All Filters Button -->
                <Button
                    v-if="hasActiveFilters"
                    variant="destructive"
                    size="sm"
                    class="h-8 cursor-pointer gap-1.5 text-xs"
                    title="Reset all active map and event list filters"
                    @click="resetAllFilters"
                >
                    <Filter class="size-3.5" />
                    <span>Reset Filters</span>
                </Button>
            </div>
        </div>

        <!-- Multi-Criteria Filter Bar (Country, Severity, Date Range) -->
        <div
            class="grid grid-cols-1 gap-2.5 rounded-xl border border-border/70 bg-card p-3 sm:grid-cols-3"
        >
            <!-- 1. ASEAN Country Filter -->
            <div class="space-y-1">
                <label
                    for="map-country-select"
                    class="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <MapPin class="size-3" />
                    <span>ASEAN Country</span>
                </label>
                <select
                    id="map-country-select"
                    :value="selectedCountry"
                    class="h-8 w-full cursor-pointer rounded-md border border-border bg-background px-2.5 text-xs font-medium text-foreground focus:border-primary focus:outline-none"
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

            <!-- 2. Severity Filter (Source-Supported Only) -->
            <div class="space-y-1">
                <label
                    for="map-severity-select"
                    class="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <ShieldAlert class="size-3" />
                    <span>Severity Classification</span>
                </label>
                <select
                    id="map-severity-select"
                    :value="localSeverity"
                    class="h-8 w-full cursor-pointer rounded-md border border-border bg-background px-2.5 text-xs font-medium text-foreground focus:border-primary focus:outline-none"
                    @change="
                        setSeverityFilter(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option
                        v-for="sev in severityOptions"
                        :key="sev.id"
                        :value="sev.id"
                        class="bg-card text-foreground"
                    >
                        {{ sev.label }}
                    </option>
                </select>
            </div>

            <!-- 3. Event Date Range Filter -->
            <div class="space-y-1">
                <label
                    for="map-date-select"
                    class="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <Calendar class="size-3" />
                    <span>Event Date Range</span>
                </label>
                <select
                    id="map-date-select"
                    :value="localDateRange"
                    class="h-8 w-full cursor-pointer rounded-md border border-border bg-background px-2.5 text-xs font-medium text-foreground focus:border-primary focus:outline-none"
                    @change="
                        setDateRangeFilter(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option
                        v-for="dr in dateRangeOptions"
                        :key="dr.id"
                        :value="dr.id"
                        class="bg-card text-foreground"
                    >
                        {{ dr.label }}
                    </option>
                </select>
            </div>
        </div>

        <!-- Stale Data Notice -->
        <div
            v-if="staleEventSources.length > 0"
            class="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300"
        >
            <AlertTriangle class="size-4 shrink-0 text-amber-500" />
            <span>
                Map includes cached markers from
                <strong>{{
                    staleEventSources.map((s) => s.source).join(', ')
                }}</strong>
                while upstream re-synchronization is in progress.
            </span>
        </div>

        <!-- Disaster Type Category Filter Tabs -->
        <div class="flex flex-wrap items-center gap-1.5 overflow-x-auto pb-1">
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
                class="z-10 h-[440px] w-full sm:h-[520px] lg:h-[580px]"
            ></div>

            <!-- In-Map Quick Zoom & Region Controls Overlay (Top Right) -->
            <div
                class="absolute top-3 right-3 z-20 flex flex-col gap-1.5 rounded-lg border border-border/80 bg-card/95 p-1.5 shadow-md backdrop-blur-md"
            >
                <button
                    type="button"
                    class="flex size-7 cursor-pointer items-center justify-center rounded text-foreground hover:bg-muted"
                    title="Zoom In"
                    aria-label="Zoom In"
                    @click="zoomIn"
                >
                    <Plus class="size-4" />
                </button>
                <button
                    type="button"
                    class="flex size-7 cursor-pointer items-center justify-center rounded text-foreground hover:bg-muted"
                    title="Zoom Out"
                    aria-label="Zoom Out"
                    @click="zoomOut"
                >
                    <Minus class="size-4" />
                </button>
                <div class="my-0.5 h-px bg-border"></div>
                <button
                    type="button"
                    class="flex size-7 cursor-pointer items-center justify-center rounded text-foreground hover:bg-muted"
                    title="Locate ASEAN Region"
                    aria-label="Locate ASEAN Region"
                    @click="locateAseanRegion"
                >
                    <Compass class="size-4 text-primary" />
                </button>
                <button
                    type="button"
                    class="flex size-7 cursor-pointer items-center justify-center rounded text-foreground hover:bg-muted"
                    title="Reset Map View"
                    aria-label="Reset Map View"
                    @click="resetMapView"
                >
                    <RotateCcw class="size-3.5" />
                </button>
                <div class="my-0.5 h-px bg-border"></div>
                <button
                    type="button"
                    :class="[
                        'flex size-7 cursor-pointer items-center justify-center rounded transition-colors',
                        showSheltersLayer
                            ? 'bg-blue-500/20 text-blue-600 dark:text-blue-400'
                            : 'text-muted-foreground hover:bg-muted',
                    ]"
                    :title="
                        showSheltersLayer
                            ? 'Hide Emergency Shelters & Centers Layer'
                            : 'Show Emergency Shelters & Centers Layer'
                    "
                    aria-label="Toggle Shelters Layer"
                    @click="showSheltersLayer = !showSheltersLayer"
                >
                    <Building2 class="size-3.5" />
                </button>
            </div>

            <!-- Floating Legend Overlay (Toggleable on Desktop & Mobile) -->
            <div
                v-if="showLegend"
                class="absolute bottom-4 left-4 z-20 max-w-xs rounded-lg border border-border/80 bg-card/95 p-3 shadow-md backdrop-blur-md"
            >
                <div class="mb-1.5 flex items-center justify-between gap-4">
                    <span
                        class="text-[11px] font-bold tracking-wider text-foreground uppercase"
                    >
                        Disaster Legend
                    </span>
                    <button
                        type="button"
                        class="cursor-pointer text-[10px] text-muted-foreground hover:text-foreground"
                        @click="showLegend = false"
                    >
                        Hide
                    </button>
                </div>
                <div
                    class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px] text-muted-foreground"
                >
                    <div class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 rotate-45 rounded-[2px] bg-rose-500"
                        ></span>
                        <span>Red Alert (Critical)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-amber-500"></span>
                        <span>Earthquake (EQ)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-cyan-500"></span>
                        <span>Cyclone / Storm (TC)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-blue-500"></span>
                        <span>Flood (FL)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 rounded-full bg-orange-500"
                        ></span>
                        <span>Wildfire (WF)</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span
                            class="size-2.5 rotate-45 rounded-[2px] bg-purple-500"
                        ></span>
                        <span>Volcano (VO)</span>
                    </div>
                </div>

                <div class="mt-2 space-y-1 border-t border-border/60 pt-1.5">
                    <span
                        class="text-[10px] font-bold text-muted-foreground uppercase"
                        >Emergency Facilities</span
                    >
                    <div
                        class="grid grid-cols-2 gap-x-3 gap-y-1 text-[10px] text-muted-foreground"
                    >
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-2.5 items-center justify-center rounded bg-emerald-500 text-[8px] leading-none font-bold text-white"
                                >H</span
                            >
                            <span>Hospital</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-2.5 items-center justify-center rounded bg-sky-600 text-[8px] leading-none font-bold text-white"
                                >E</span
                            >
                            <span>Evac Center</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-2.5 items-center justify-center rounded bg-purple-500 text-[8px] leading-none font-bold text-white"
                                >R</span
                            >
                            <span>Relief Hub</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-2.5 items-center justify-center rounded bg-amber-500 text-[8px] leading-none font-bold text-white"
                                >S</span
                            >
                            <span>Shelter</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading State Overlay -->
            <div
                v-if="isLoading && events.length === 0"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/75 p-4 text-center backdrop-blur-xs"
            >
                <RefreshCw
                    class="mb-2 size-8 animate-spin text-muted-foreground"
                />
                <p class="text-sm font-semibold text-foreground">
                    Loading verified geospatial telemetry...
                </p>
            </div>

            <!-- Error State Overlay if all event sources are offline -->
            <div
                v-else-if="
                    allEventSourcesUnavailable && filteredMapEvents.length === 0
                "
                class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/80 p-4 text-center backdrop-blur-xs"
            >
                <div
                    class="mb-2 flex size-12 items-center justify-center rounded-full bg-destructive/15 text-destructive"
                >
                    <AlertCircle class="size-6" />
                </div>
                <p class="text-sm font-semibold text-foreground">
                    Upstream geospatial feeds are currently unavailable
                </p>
                <p class="mt-1 max-w-sm text-xs text-muted-foreground">
                    Unable to reach GDACS and USGS. Map markers
                    will populate automatically once connectivity is restored.
                </p>
            </div>

            <!-- Empty State Overlay if 0 events matching filter -->
            <div
                v-else-if="filteredMapEvents.length === 0"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-background/75 p-4 text-center backdrop-blur-xs"
            >
                <div
                    class="mb-2 flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                    <Filter class="size-6" />
                </div>
                <p class="text-sm font-semibold text-foreground">
                    No map markers match the selected filters
                </p>
                <p class="mt-1 max-w-sm text-xs text-muted-foreground">
                    No active incidents with verified coordinates match Country
                    "{{ selectedCountry }}", Category "{{ selectedCategory }}",
                    Severity "{{ localSeverity }}", and Date Range "{{
                        localDateRange
                    }}".
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-3 cursor-pointer text-xs"
                    @click="resetAllFilters"
                >
                    Reset All Filters
                </Button>
            </div>
        </div>

        <!-- Synchronized Corresponding Event List (Includes events with & without coordinates) -->
        <div class="border-t border-border/60 pt-4">
            <div
                class="mb-3 flex flex-col justify-between gap-2 sm:flex-row sm:items-center"
            >
                <div>
                    <h3 class="text-sm font-bold text-foreground">
                        Synchronized Disaster Event List ({{
                            matchingAllEvents.length
                        }})
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Updated live with map filters • Click any incident to
                        locate its pin on the map or inspect full details
                    </p>
                </div>
                <div
                    v-if="hasActiveFilters"
                    class="flex items-center gap-2 text-xs"
                >
                    <span class="text-muted-foreground">Active filters:</span>
                    <Badge
                        v-if="selectedCountry !== 'All'"
                        variant="outline"
                        class="text-[10px]"
                    >
                        {{ selectedCountry }}
                    </Badge>
                    <Badge
                        v-if="selectedCategory !== 'all'"
                        variant="outline"
                        class="text-[10px] uppercase"
                    >
                        {{ selectedCategory }}
                    </Badge>
                    <Badge
                        v-if="localSeverity !== 'all'"
                        variant="outline"
                        class="text-[10px]"
                    >
                        Severity: {{ localSeverity }}
                    </Badge>
                    <Badge
                        v-if="localDateRange !== 'all'"
                        variant="outline"
                        class="text-[10px]"
                    >
                        Range: {{ localDateRange }}
                    </Badge>
                </div>
            </div>

            <!-- Synchronized List Empty State -->
            <div
                v-if="matchingAllEvents.length === 0"
                class="rounded-xl border border-dashed border-border p-8 text-center"
            >
                <p class="text-xs font-medium text-muted-foreground">
                    No disaster events in the list match the current filter
                    combination.
                </p>
            </div>

            <!-- Synchronized Event Rows Grid -->
            <div
                v-else
                class="grid grid-cols-1 gap-2.5 md:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="ev in visibleListEvents"
                    :key="ev.id"
                    class="group flex flex-col justify-between rounded-xl border border-border/80 bg-card p-3 transition-all hover:border-border hover:shadow-xs"
                >
                    <div class="space-y-1.5">
                        <div
                            class="flex items-center justify-between gap-2 text-[11px]"
                        >
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-semibold',
                                    getCategoryBadgeClasses(
                                        ev.disaster_type || ev.category,
                                    ),
                                ]"
                            >
                                <component
                                    :is="
                                        getCategoryIconComponent(
                                            ev.disaster_type || ev.category,
                                        )
                                    "
                                    class="size-3"
                                />
                                <span>{{
                                    ev.disaster_type || ev.category
                                }}</span>
                            </span>

                            <div class="flex items-center gap-1">
                                <Badge
                                    v-if="ev.is_community_report"
                                    variant="outline"
                                    class="border-purple-500/40 bg-purple-500/10 px-1.5 py-0 text-[9px] font-bold text-purple-600 uppercase dark:text-purple-400"
                                >
                                    Community Verified
                                </Badge>
                                <Badge
                                    v-else-if="ev.severity_level === 'Red'"
                                    variant="destructive"
                                    class="px-1.5 py-0 text-[9px] font-bold uppercase"
                                >
                                    Red Alert
                                </Badge>
                                <Badge
                                    v-else-if="ev.severity_level === 'Orange'"
                                    variant="outline"
                                    class="border-amber-500/40 bg-amber-500/10 px-1.5 py-0 text-[9px] font-bold text-amber-600 uppercase dark:text-amber-400"
                                >
                                    Orange Alert
                                </Badge>
                                <Badge
                                    v-else-if="ev.severity_level === 'Green'"
                                    variant="outline"
                                    class="border-emerald-500/40 bg-emerald-500/10 px-1.5 py-0 text-[9px] font-bold text-emerald-600 uppercase dark:text-emerald-400"
                                >
                                    Green Alert
                                </Badge>

                                <span
                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-foreground"
                                >
                                    {{ (ev.sources || [ev.source]).join(', ') }}
                                </span>
                            </div>
                        </div>

                        <h4
                            class="line-clamp-1 text-xs font-bold text-foreground group-hover:text-primary"
                            :title="ev.title"
                        >
                            {{ ev.title }}
                        </h4>

                        <div
                            class="flex flex-wrap items-center justify-between gap-1 text-[11px] text-muted-foreground"
                        >
                            <span class="truncate">
                                <strong class="text-foreground">{{
                                    ev.country
                                }}</strong>
                                <template
                                    v-if="
                                        ev.location &&
                                        ev.location.toLowerCase() !==
                                            ev.country.toLowerCase() &&
                                        ev.location.toLowerCase() !==
                                            ev.title.toLowerCase()
                                    "
                                >
                                    • {{ ev.location }}
                                </template>
                            </span>
                            <span
                                v-if="ev.severity || ev.severity_text"
                                class="font-mono text-[10px] font-semibold text-foreground"
                            >
                                {{ ev.severity || ev.severity_text }}
                            </span>
                        </div>
                    </div>

                    <div
                        class="mt-2.5 flex items-center justify-between border-t border-border/50 pt-2 text-[11px]"
                    >
                        <span
                            class="font-mono text-[10px] text-muted-foreground"
                        >
                            {{
                                formatDateShort(ev.event_date || ev.event_time)
                            }}
                        </span>

                        <div class="flex items-center gap-2">
                            <button
                                v-if="hasValidCoordinates(ev)"
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1 font-medium text-primary hover:underline"
                                @click="focusEventOnMap(ev)"
                            >
                                <MapPin class="size-3" />
                                <span>Locate Pin</span>
                            </button>
                            <span
                                v-else
                                class="text-[10px] text-muted-foreground italic"
                            >
                                List only (no coords)
                            </span>

                            <Link
                                :href="'/events/' + ev.id"
                                class="cursor-pointer font-medium text-foreground hover:text-primary hover:underline"
                            >
                                Details &rarr;
                            </Link>

                            <a
                                :href="ev.source_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-0.5 font-medium text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>Source</span>
                                <ExternalLink class="size-2.5" />
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="matchingAllEvents.length > listLimit"
                class="mt-4 text-center"
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="cursor-pointer text-xs"
                    @click="listLimit += 12"
                >
                    Show More Matching Events (Showing {{ listLimit }} of
                    {{ matchingAllEvents.length }})
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
