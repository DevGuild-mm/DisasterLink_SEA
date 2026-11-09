import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import type { DashboardData, SourceFreshness } from '@/types/disaster';

const DEFAULT_DASHBOARD_DATA: DashboardData = {
    generated_at: null,
    summary: {
        total_active_alerts: {
            count: 0,
            status: 'Unavailable',
            description: 'Orange & Red priority alerts across ASEAN',
        },
        earthquakes: {
            count: 0,
            status: 'Unavailable',
            description: 'Seismic events (USGS & GDACS)',
        },
        tropical_cyclones: {
            count: 0,
            status: 'Unavailable',
            description: 'Active cyclones & severe storms',
        },
        floods: {
            count: 0,
            status: 'Unavailable',
            description: 'Active flood warnings',
        },
        wildfires: {
            count: 0,
            status: 'Unavailable',
            description: 'Wildfire & thermal hotspots',
        },
        volcanoes: {
            count: 0,
            status: 'Unavailable',
            description: 'Volcanic activity alerts',
        },
        total_events: 0,
    },
    events: [],
    weather: [],
    countries: {
        All: {
            name: 'All Southeast Asia (ASEAN 11)',
            code: 'ALL',
            iso3: 'ALL',
            center: [10.0, 108.0],
            zoom: 5,
            bounds: [
                [-11.5, 92.0],
                [28.5, 142.0],
            ],
        },
    },
    categories: [
        { id: 'all', label: 'All Categories', icon: 'globe' },
        { id: 'earthquake', label: 'Earthquakes', icon: 'activity' },
        { id: 'cyclone', label: 'Tropical Cyclones', icon: 'wind' },
        { id: 'flood', label: 'Floods', icon: 'waves' },
        { id: 'wildfire', label: 'Wildfires', icon: 'flame' },
        { id: 'volcano', label: 'Volcanoes', icon: 'triangle-alert' },
        { id: 'storm', label: 'Severe Storms', icon: 'cloud-lightning' },
    ],
    sources: {
        gdacs: {
            source: 'GDACS',
            name: 'Global Disaster Alert and Coordination System (UN / EC JRC)',
            status: 'Unavailable',
            freshness_status: 'Unavailable',
            connection_status: 'Awaiting telemetry sync',
            last_successful_fetch: null,
            latest_event_time: null,
            cache_ttl_seconds: 300,
            provider_update_frequency:
                'Every 5–15 minutes (event-driven bulletins)',
            frontend_poll_interval_seconds: 60,
            is_fresh: false,
            is_stale: false,
            documentation_url:
                'https://www.gdacs.org/gdacsapi/swagger/index.html',
        },
        usgs: {
            source: 'USGS',
            name: 'United States Geological Survey (Earthquake Hazards Program)',
            status: 'Unavailable',
            freshness_status: 'Unavailable',
            connection_status: 'Awaiting telemetry sync',
            last_successful_fetch: null,
            latest_event_time: null,
            cache_ttl_seconds: 180,
            provider_update_frequency: 'Every 1 minute (all_hour.geojson feed)',
            frontend_poll_interval_seconds: 60,
            is_fresh: false,
            is_stale: false,
            documentation_url:
                'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
        },
        eonet: {
            source: 'NASA EONET',
            name: 'NASA Earth Observatory Natural Event Tracker (EONET v3)',
            status: 'Unavailable',
            freshness_status: 'Unavailable',
            connection_status: 'Awaiting telemetry sync',
            last_successful_fetch: null,
            latest_event_time: null,
            cache_ttl_seconds: 900,
            provider_update_frequency:
                'Sub-daily satellite & partner agency updates',
            frontend_poll_interval_seconds: 60,
            is_fresh: false,
            is_stale: false,
            documentation_url: 'https://eonet.gsfc.nasa.gov/api/v3/events',
        },
        weather: {
            source: 'Open-Meteo',
            name: 'Open-Meteo Weather Forecast API',
            status: 'Unavailable',
            freshness_status: 'Unavailable',
            connection_status: 'Awaiting telemetry sync',
            last_successful_fetch: null,
            latest_event_time: null,
            cities_count: 0,
            cache_ttl_seconds: 900,
            provider_update_frequency:
                'Every 15–60 minutes (numerical weather model)',
            frontend_poll_interval_seconds: 60,
            is_fresh: false,
            is_stale: false,
            documentation_url: 'https://open-meteo.com/en/docs',
        },
    },
};

function normalizeSourceFreshness(
    raw: Partial<SourceFreshness> | undefined,
    fallback: SourceFreshness,
): SourceFreshness {
    if (!raw || typeof raw !== 'object') {
        return { ...fallback };
    }

    const status = raw.status ?? fallback.status;
    const isFresh =
        typeof raw.is_fresh === 'boolean' ? raw.is_fresh : status === 'Live';
    const isStale =
        typeof raw.is_stale === 'boolean' ? raw.is_stale : status === 'Stale';

    let freshnessStatus: 'Fresh' | 'Stale' | 'Unavailable' = 'Unavailable';
    if (raw.freshness_status) {
        freshnessStatus = raw.freshness_status;
    } else if (isFresh) {
        freshnessStatus = 'Fresh';
    } else if (isStale) {
        freshnessStatus = 'Stale';
    }

    return {
        ...fallback,
        ...raw,
        status,
        is_fresh: isFresh,
        is_stale: isStale,
        freshness_status: freshnessStatus,
    };
}

function normalizeDashboardData(
    raw?: Partial<DashboardData> | null,
): DashboardData {
    if (!raw || typeof raw !== 'object') {
        return { ...DEFAULT_DASHBOARD_DATA };
    }

    return {
        generated_at: raw.generated_at ?? DEFAULT_DASHBOARD_DATA.generated_at,
        summary: raw.summary ?? DEFAULT_DASHBOARD_DATA.summary,
        events: Array.isArray(raw.events) ? raw.events : [],
        weather: Array.isArray(raw.weather) ? raw.weather : [],
        countries:
            raw.countries && typeof raw.countries === 'object'
                ? raw.countries
                : DEFAULT_DASHBOARD_DATA.countries,
        categories: Array.isArray(raw.categories)
            ? raw.categories
            : DEFAULT_DASHBOARD_DATA.categories,
        sources: {
            gdacs: normalizeSourceFreshness(
                raw.sources?.gdacs,
                DEFAULT_DASHBOARD_DATA.sources.gdacs,
            ),
            usgs: normalizeSourceFreshness(
                raw.sources?.usgs,
                DEFAULT_DASHBOARD_DATA.sources.usgs,
            ),
            eonet: normalizeSourceFreshness(
                raw.sources?.eonet,
                DEFAULT_DASHBOARD_DATA.sources.eonet,
            ),
            weather: normalizeSourceFreshness(
                raw.sources?.weather,
                DEFAULT_DASHBOARD_DATA.sources.weather,
            ),
        },
    };
}

export function useDisasterTelemetry(
    initialData?: Partial<DashboardData> | null,
) {
    const data = ref<DashboardData>(normalizeDashboardData(initialData));
    const isRefreshing = ref(false);
    const syncError = ref<string | null>(null);
    const countdown = ref(60);

    const isMounted = ref(false);
    const visibility = useDocumentVisibility();
    const isTabVisible = computed(() =>
        isMounted.value ? visibility.value === 'visible' : true,
    );

    const isLoading = computed(
        () => isRefreshing.value && !data.value.generated_at,
    );

    const sourceList = computed(() => [
        data.value.sources.gdacs,
        data.value.sources.usgs,
        data.value.sources.eonet,
        data.value.sources.weather,
    ]);

    const hasStaleSources = computed(() =>
        sourceList.value.some((s) => s.is_stale || s.status === 'Stale'),
    );

    const hasUnavailableSources = computed(() =>
        sourceList.value.some((s) => s.status === 'Unavailable'),
    );

    const allEventSourcesUnavailable = computed(
        () =>
            data.value.sources.gdacs.status === 'Unavailable' &&
            data.value.sources.usgs.status === 'Unavailable' &&
            data.value.sources.eonet.status === 'Unavailable',
    );

    let abortController: AbortController | null = null;

    async function fetchDashboardData(force = false) {
        if (isRefreshing.value) return;

        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();
        isRefreshing.value = true;

        try {
            const endpoint = force
                ? '/api/disaster-dashboard?refresh=1'
                : '/api/disaster-dashboard';

            const response = await fetch(endpoint, {
                signal: abortController.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const freshData: DashboardData = await response.json();
            data.value = normalizeDashboardData(freshData);
            syncError.value = null;
            countdown.value = 60;
        } catch (err: unknown) {
            if (err instanceof Error && err.name === 'AbortError') {
                return;
            }
            syncError.value =
                'Unable to reach live telemetry endpoint. Showing most recent verified cached data.';
        } finally {
            isRefreshing.value = false;
        }
    }

    onMounted(() => {
        isMounted.value = true;
        if (!initialData || !initialData.generated_at) {
            void fetchDashboardData(false);
        }
    });

    const { pause: pauseTicker, resume: resumeTicker } = useIntervalFn(() => {
        if (!isTabVisible.value) return;

        if (countdown.value > 1) {
            countdown.value--;
        } else {
            countdown.value = 60;
            void fetchDashboardData(false);
        }
    }, 1000);

    watch(visibility, (newVal) => {
        if (newVal === 'visible') {
            resumeTicker();
            void fetchDashboardData(false);
        } else {
            pauseTicker();
        }
    });

    watch(
        () => initialData,
        (newInitial) => {
            if (newInitial) {
                data.value = normalizeDashboardData(newInitial);
            }
        },
    );

    return {
        data,
        isRefreshing,
        isLoading,
        syncError,
        countdown,
        isTabVisible,
        hasStaleSources,
        hasUnavailableSources,
        allEventSourcesUnavailable,
        fetchDashboardData,
    };
}
