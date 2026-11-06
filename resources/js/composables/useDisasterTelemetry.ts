import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import type { DashboardData } from '@/types/disaster';

const DEFAULT_DASHBOARD_DATA: DashboardData = {
    generated_at: new Date().toISOString(),
    summary: {
        total_active_alerts: {
            count: 0,
            status: 'Live',
            description: 'Orange & Red priority alerts across ASEAN',
        },
        earthquakes: {
            count: 0,
            status: 'Live',
            description: 'Seismic events (USGS & GDACS)',
        },
        tropical_cyclones: {
            count: 0,
            status: 'Live',
            description: 'Active cyclones & severe storms',
        },
        floods: {
            count: 0,
            status: 'Live',
            description: 'Active flood warnings',
        },
        wildfires: {
            count: 0,
            status: 'Live',
            description: 'Wildfire & thermal hotspots',
        },
        volcanoes: {
            count: 0,
            status: 'Live',
            description: 'Volcanic activity alerts',
        },
    },
    events: [],
    weather: [],
    countries: {
        All: {
            name: 'All Southeast Asia (ASEAN 11)',
            iso2: 'SEA',
            iso3: 'SEA',
            capital: 'Regional View',
            center: [10.0, 108.0],
            zoom: 5,
            bounds: [
                [-11.5, 92.0],
                [28.5, 141.5],
            ],
        },
    },
    sources: {
        gdacs: {
            source: 'GDACS (UN / EC JRC)',
            status: 'Live',
            last_successful_fetch: null,
            latest_event_time: null,
            documentation_url:
                'https://www.gdacs.org/gdacsapi/swagger/index.html',
        },
        usgs: {
            source: 'USGS Earthquake Hazards',
            status: 'Live',
            last_successful_fetch: null,
            latest_event_time: null,
            documentation_url:
                'https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php',
        },
        eonet: {
            source: 'NASA EONET v3',
            status: 'Live',
            last_successful_fetch: null,
            latest_event_time: null,
            documentation_url: 'https://eonet.gsfc.nasa.gov/docs/v3',
        },
        weather: {
            source: 'Open-Meteo Weather API',
            status: 'Live',
            last_successful_fetch: null,
            cities_count: 11,
            documentation_url: 'https://open-meteo.com/en/docs',
        },
    },
};

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
        sources:
            raw.sources && typeof raw.sources === 'object'
                ? {
                      gdacs:
                          raw.sources.gdacs ??
                          DEFAULT_DASHBOARD_DATA.sources.gdacs,
                      usgs:
                          raw.sources.usgs ??
                          DEFAULT_DASHBOARD_DATA.sources.usgs,
                      eonet:
                          raw.sources.eonet ??
                          DEFAULT_DASHBOARD_DATA.sources.eonet,
                      weather:
                          raw.sources.weather ??
                          DEFAULT_DASHBOARD_DATA.sources.weather,
                  }
                : DEFAULT_DASHBOARD_DATA.sources,
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

    onMounted(() => {
        isMounted.value = true;
    });

    let abortController: AbortController | null = null;

    async function fetchDashboardData() {
        if (isRefreshing.value) return;

        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();
        isRefreshing.value = true;

        try {
            const response = await fetch('/api/disaster-dashboard', {
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
                'Unable to reach live telemetry endpoint. Showing most recent cached data.';
        } finally {
            isRefreshing.value = false;
        }
    }

    const { pause: pauseTicker, resume: resumeTicker } = useIntervalFn(() => {
        if (!isTabVisible.value) return;

        if (countdown.value > 1) {
            countdown.value--;
        } else {
            countdown.value = 60;
            void fetchDashboardData();
        }
    }, 1000);

    watch(visibility, (newVal) => {
        if (newVal === 'visible') {
            resumeTicker();
            void fetchDashboardData();
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
        syncError,
        countdown,
        isTabVisible,
        fetchDashboardData,
    };
}
