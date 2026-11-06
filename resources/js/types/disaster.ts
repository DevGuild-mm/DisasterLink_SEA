export type SeverityLevel = 'Red' | 'Orange' | 'Green';

export interface SourceUrl {
    source: string;
    url: string;
}

export interface DisasterEvent {
    id: string;
    source: string;
    sources?: string[];
    source_id: string;
    category: string;
    raw_type: string;
    title: string;
    description?: string;
    country: string;
    iso3?: string | null;
    latitude: number | null;
    longitude: number | null;
    coordinates: [number, number] | null;
    magnitude?: number | null;
    depth_km?: number | null;
    severity_level?: SeverityLevel | null;
    severity_score?: number | null;
    severity_text?: string | null;
    event_time: string | null;
    source_url: string;
    source_urls?: SourceUrl[];
}

export interface WeatherCity {
    country: string;
    city: string;
    iso2: string;
    iso3: string;
    latitude: number;
    longitude: number;
    temperature_c: number | null;
    apparent_temperature_c: number | null;
    humidity_pct: number | null;
    precipitation_mm: number;
    wind_speed_kmh: number | null;
    wind_direction_deg: number | null;
    weather_code: number;
    condition: string;
    icon: string;
    is_day: boolean;
    forecast_max_c: number | null;
    forecast_min_c: number | null;
    forecast_precipitation_sum_mm: number | null;
    observation_time: string;
    data_type: string;
}

export interface SourceFreshness {
    source: string;
    name: string;
    status: 'Live' | 'Delayed' | 'Stale' | 'Unavailable';
    last_successful_fetch: string | null;
    latest_event_time: string | null;
    total_features_fetched?: number;
    sea_events_count?: number;
    cities_count?: number;
    is_fresh: boolean;
    is_stale: boolean;
    error?: string;
    documentation_url: string;
}

export interface SummaryItem {
    count: number;
    status: string;
    description: string;
}

export interface DashboardSummary {
    total_active_alerts: SummaryItem;
    earthquakes: SummaryItem;
    tropical_cyclones: SummaryItem;
    floods: SummaryItem;
    wildfires: SummaryItem;
    volcanoes: SummaryItem;
    total_events: number;
}

export interface AseanCountry {
    name: string;
    code: string;
    iso3: string;
    center: [number, number];
    zoom: number;
    bounds: [[number, number], [number, number]];
}

export interface CategoryOption {
    id: string;
    label: string;
    icon: string;
}

export interface DashboardData {
    generated_at: string;
    summary: DashboardSummary;
    events: DisasterEvent[];
    weather: WeatherCity[];
    sources: {
        gdacs: SourceFreshness;
        usgs: SourceFreshness;
        eonet: SourceFreshness;
        weather: SourceFreshness;
    };
    countries: Record<string, AseanCountry>;
    categories: CategoryOption[];
}
