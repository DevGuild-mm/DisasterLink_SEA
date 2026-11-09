export type SeverityLevel = 'Red' | 'Orange' | 'Green';

export interface SourceUrl {
    source: string;
    url: string;
}

export interface EventTimelineItem {
    timestamp: string | null;
    title: string;
    description?: string | null;
    source?: string | null;
    type?: 'detection' | 'observation' | 'verification' | 'update';
    severity_level?: SeverityLevel | null;
}

export interface DisasterEvent {
    id: string;
    source: string;
    sources?: string[];
    source_id: string;
    disaster_type?: string;
    category: string;
    raw_type: string;
    title: string;
    description?: string | null;
    country: string;
    location?: string | null;
    iso3?: string | null;
    latitude: number | null;
    longitude: number | null;
    coordinates: [number, number] | null;
    magnitude?: number | null;
    depth_km?: number | null;
    severity?: string | null;
    severity_level?: SeverityLevel | null;
    severity_score?: number | null;
    severity_text?: string | null;
    event_date?: string | null;
    event_time: string | null;
    last_updated_time?: string | null;
    source_url: string;
    source_urls?: SourceUrl[];
    timeline?: EventTimelineItem[];
    is_community_report?: boolean;
    verification_status?: string;
    submitter_name?: string;
    photos?: string[];
    review_notes?: string | null;
}

export type ReportStatus = 'pending' | 'under_review' | 'verified' | 'rejected';

export interface UserReportItem {
    id: string;
    disaster_type: string;
    title: string;
    description: string;
    country: string;
    location: string;
    latitude: number | null;
    longitude: number | null;
    incident_time: string;
    created_at?: string;
    status: ReportStatus;
    photos?: string[];
    review_notes?: string | null;
    rejection_reason?: string | null;
    verified_at?: string | null;
    user?: {
        id: number;
        name: string;
        email: string;
    } | null;
    reviewer?: {
        id: number;
        name: string;
    } | null;
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
    freshness_status?: 'Fresh' | 'Stale' | 'Unavailable';
    connection_status?: string;
    last_successful_fetch: string | null;
    latest_event_time: string | null;
    total_features_fetched?: number;
    sea_events_count?: number;
    cities_count?: number;
    cache_ttl_seconds?: number;
    provider_update_frequency?: string;
    frontend_poll_interval_seconds?: number;
    is_fresh: boolean;
    is_stale: boolean;
    error?: string | null;
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
    generated_at: string | null;
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

export type RiskLevel = 'Critical' | 'High' | 'Moderate' | 'Low';

export interface RiskAssessmentBreakdown {
    hazard_severity: number;
    environmental_risk: number;
    vulnerability: number;
    corroboration: number;
}

export interface RiskAssessment {
    score: number;
    level: RiskLevel;
    badge_color: string;
    breakdown: RiskAssessmentBreakdown;
    factors: string[];
    is_critical: boolean;
    is_high: boolean;
    disclaimer: string;
}

export interface EarlyWarningAlert {
    id: string;
    fingerprint: string;
    event_id: string | null;
    title: string;
    category: string;
    raw_type: string;
    severity_level: RiskLevel;
    risk_score: number;
    badge_color: string;
    country: string;
    location: string;
    coordinates: [number, number] | null;
    latitude: number | null;
    longitude: number | null;
    issued_at: string;
    updated_at: string;
    primary_source: string;
    sources: string[];
    source_url: string | null;
    emergency_headline: string;
    immediate_action: string;
    protective_actions: string[];
    factors: string[];
    breakdown?: RiskAssessmentBreakdown | null;
    is_community_report: boolean;
}

export interface AseanComparisonItem {
    country: string;
    code: string;
    iso3: string;
    capital: string;
    total_events: number;
    critical_events: number;
    high_events: number;
    peak_risk_score: number;
    risk_tier: string;
    dominant_hazard: string;
    capital_weather: {
        temperature_c: number | null;
        condition: string;
        wind_speed_kmh: number | null;
        precipitation_mm: number;
        alert_level: string;
    };
    ndma_agency: string;
    hotline: string;
}

export interface DisasterAnalyticsData {
    overview: {
        total_events: number;
        critical_events: number;
        high_events: number;
        moderate_events: number;
        low_events: number;
        unknown_country_events: number;
    };
    type_distribution: Array<{
        category: string;
        label: string;
        count: number;
        critical_count: number;
        percentage: number;
        color: string;
    }>;
    country_frequency: Array<{
        country: string;
        code: string;
        iso3: string;
        total_events: number;
        critical_events: number;
        high_events: number;
        peak_risk_score: number;
        categories: Record<string, number>;
    }>;
    monthly_trends: Array<{
        period: string;
        month_name: string;
        count: number;
        critical: number;
    }>;
    yearly_trends: Array<{
        year: string;
        count: number;
    }>;
    asean_comparison_matrix: AseanComparisonItem[];
}

export interface ResponseIntelligenceReport {
    id: string;
    title: string;
    disaster_type: string;
    category: string;
    country: string;
    location: string;
    latitude: number | null;
    longitude: number | null;
    has_coordinates: boolean;
    incident_time: string | null;
    created_at: string | null;
    status: string;
    photo_count: number;
    photo_urls: string[];
    submitter_name: string;
    review_notes: string | null;
    rejection_reason?: string | null;
    triage_score: number;
    priority_tier: string;
    badge_color: string;
    triage_reasons: string[];
    recommended_action: string;
    nearest_alert_distance_km: number | null;
}

export interface IntelligencePayload {
    generated_at: string;
    cached: boolean;
    summary: {
        total_events: number;
        active_alerts: number;
        critical_alerts: number;
        high_alerts: number;
        triaged_reports: number;
        immediate_response_needed: number;
    };
    early_warnings: {
        total_alerts: number;
        critical_alerts: number;
        high_alerts: number;
        moderate_alerts: number;
        active_thresholds: {
            min_level: string;
            country: string;
            category: string;
            min_magnitude?: number | null;
        };
        alerts: EarlyWarningAlert[];
    };
    assessed_events: Array<
        DisasterEvent & { risk_assessment?: RiskAssessment }
    >;
    analytics: DisasterAnalyticsData;
    response_intelligence: {
        total_reports: number;
        immediate_count: number;
        high_count: number;
        elevated_count: number;
        routine_count: number;
        reports: ResponseIntelligenceReport[];
    };
    countries: Record<string, AseanCountry>;
    categories: CategoryOption[];
}

export interface CountryCategoryStat {
    category: string;
    total_events: number;
    high_alerts: number;
    color: string;
}

export interface CountryDistributionStat {
    country: string;
    code: string;
    iso3: string;
    total_events: number;
    high_alerts: number;
}

export interface HistoricalTrendItem {
    date: string;
    formatted_date: string;
    total_events: number;
    high_alerts: number;
    categories: Record<string, number>;
    severities: Record<string, number>;
}

export interface CountryDashboardSummary {
    total_events: number;
    high_alerts_count: number;
    red_alerts_count: number;
    orange_alerts_count: number;
    green_alerts_count: number;
    unclassified_count: number;
    categories_count: number;
}

export interface CountryDashboardData {
    generated_at: string | null;
    selected_country: string;
    is_valid_country: boolean;
    requested_country?: string | null;
    country_meta: AseanCountry;
    all_countries: Record<string, AseanCountry>;
    summary: CountryDashboardSummary;
    events: DisasterEvent[];
    weather: WeatherCity[];
    analytics: {
        by_category: CountryCategoryStat[];
        by_country: CountryDistributionStat[];
        historical_trends: HistoricalTrendItem[];
    };
    sources: {
        gdacs: SourceFreshness;
        usgs: SourceFreshness;
        eonet: SourceFreshness;
        weather: SourceFreshness;
    };
}

export type ShelterType =
    | 'shelter'
    | 'hospital'
    | 'relief_center'
    | 'evacuation_center';
export type ShelterStatus = 'open' | 'full' | 'closed' | 'evacuating';
export type ResourceCategory =
    | 'water_food'
    | 'medical'
    | 'rescue_equipment'
    | 'necessities';
export type ResourceStatus = 'adequate' | 'low' | 'critical' | 'depleted';
export type RouteStatus = 'clear' | 'caution' | 'impassable';
export type VolunteerStatus = 'available' | 'deployed' | 'standby';
export type RescueUrgency = 'critical' | 'high' | 'moderate' | 'low';
export type RescueStatus =
    | 'pending'
    | 'dispatched'
    | 'in_progress'
    | 'completed'
    | 'cancelled';

export interface EmergencyResourceItem {
    id: string;
    shelter_id?: string | null;
    category: ResourceCategory;
    item_name: string;
    quantity: number;
    unit: string;
    min_threshold: number;
    status: ResourceStatus;
    is_shortage: boolean;
    notes?: string | null;
    created_at?: string;
    shelter?: {
        id: string;
        name: string;
        country: string;
        city: string;
    } | null;
}

export interface ShelterItem {
    id: string;
    name: string;
    type: ShelterType;
    type_label?: string;
    country: string;
    city: string;
    address: string;
    latitude: number;
    longitude: number;
    coordinates?: [number, number];
    contact_name: string;
    contact_phone: string;
    contact_email?: string | null;
    total_capacity: number;
    current_occupancy: number;
    available_capacity: number;
    occupancy_rate: number;
    status: ShelterStatus;
    facilities?: string[] | null;
    notes?: string | null;
    resources?: EmergencyResourceItem[];
    created_at?: string;
}

export interface RouteWaypoint {
    name: string;
    lat: number;
    lng: number;
    note?: string;
}

export interface EvacuationRouteItem {
    id: string;
    title: string;
    country: string;
    origin: string;
    destination: string;
    distance_km: number;
    status: RouteStatus;
    hazard_warning?: string | null;
    instructions: string;
    waypoints?: RouteWaypoint[] | null;
    is_verified: boolean;
    verified_by?: string | null;
    created_at?: string;
}

export interface VolunteerItem {
    id: string;
    user_id?: number | null;
    name: string;
    email: string;
    phone: string;
    country: string;
    city: string;
    skills?: string[] | null;
    availability_status: VolunteerStatus;
    notes?: string | null;
    rescue_requests_count?: number;
    created_at?: string;
}

export interface RescueRequestItem {
    id: string;
    user_id?: number | null;
    reporter_name: string;
    contact_phone: string;
    country: string;
    location: string;
    latitude?: number | null;
    longitude?: number | null;
    people_count: number;
    has_medical_emergency: boolean;
    urgency: RescueUrgency;
    status: RescueStatus;
    assigned_volunteer_id?: string | null;
    assigned_volunteer?: {
        id: string;
        name: string;
        phone: string;
    } | null;
    situation_description: string;
    resolution_notes?: string | null;
    created_at?: string;
}

export interface CoordinationSummary {
    total_shelters: number;
    open_shelters: number;
    total_capacity: number;
    current_occupancy: number;
    available_capacity: number;
    overall_occupancy_rate: number;
    critical_shortages_count: number;
    low_supplies_count: number;
    active_evacuation_routes: number;
    pending_rescue_requests: number;
    critical_rescue_requests: number;
    total_volunteers: number;
    available_volunteers: number;
}

export interface CoordinationPayload {
    generated_at: string;
    summary: CoordinationSummary;
    shelters: ShelterItem[];
    map_locations: Array<
        ShelterItem & { coordinates: [number, number]; status_badge: string }
    >;
    resources: EmergencyResourceItem[];
    routes: EvacuationRouteItem[];
    rescue_requests: RescueRequestItem[];
    volunteers: VolunteerItem[];
    countries: Record<string, AseanCountry>;
    categories: Array<{ id: string; label: string; icon: string }>;
}
