<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    BellRing,
    Calendar,
    CheckCircle2,
    Clock,
    Compass,
    Database,
    ExternalLink,
    Filter,
    Flame,
    Globe,
    Info,
    Layers,
    MapPin,
    Phone,
    Radio,
    RefreshCw,
    Shield,
    ShieldAlert,
    ShieldCheck,
    TrendingUp,
    Waves,
    Wind,
    Zap,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import EmergencyResponseCard from '@/components/disaster/EmergencyResponseCard.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type {
    DashboardData,
    EarlyWarningAlert,
    IntelligencePayload,
    ResponseIntelligenceReport,
    RiskAssessment,
    RiskLevel,
} from '@/types/disaster';

const props = defineProps<{
    intelligence: IntelligencePayload;
    initialDashboardData?: DashboardData | null;
    filters?: {
        min_level?: string;
        country?: string;
        category?: string;
        min_magnitude?: string | number | null;
    };
}>();

const {
    data: dashboardData,
    isRefreshing: isDashboardRefreshing,
    syncError,
    countdown,
    isTabVisible,
    hasStaleSources,
    hasUnavailableSources,
    fetchDashboardData,
} = useDisasterTelemetry(props.initialDashboardData);

// Active Tab State
type TabType = 'warnings' | 'risk' | 'analytics' | 'response';
const activeTab = ref<TabType>('warnings');

// Threshold Filter State
const selectedMinLevel = ref<string>(props.filters?.min_level || 'moderate');
const selectedCountry = ref<string>(props.filters?.country || 'All');
const selectedCategory = ref<string>(props.filters?.category || 'All');
const selectedMinMagnitude = ref<string>(
    props.filters?.min_magnitude ? String(props.filters.min_magnitude) : '',
);
const isFiltering = ref(false);

const localIntelligence = ref<IntelligencePayload>(props.intelligence);

// Synchronize local intelligence when Inertia reloads props
watch(
    () => props.intelligence,
    (newVal) => {
        localIntelligence.value = newVal;
    },
);

function applyFilters() {
    isFiltering.value = true;
    router.get(
        '/intelligence',
        {
            min_level: selectedMinLevel.value,
            country: selectedCountry.value,
            category: selectedCategory.value,
            min_magnitude: selectedMinMagnitude.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                isFiltering.value = false;
            },
        },
    );
}

function resetFilters() {
    selectedMinLevel.value = 'moderate';
    selectedCountry.value = 'All';
    selectedCategory.value = 'All';
    selectedMinMagnitude.value = '';
    applyFilters();
}

// Refresh whole page
function handleManualRefresh() {
    fetchDashboardData(true);
    router.reload({ only: ['intelligence', 'initialDashboardData'] });
}

// Computed Summaries
const earlyWarnings = computed(() => localIntelligence.value.early_warnings);
const alertsList = computed(() => earlyWarnings.value?.alerts || []);
const analytics = computed(() => localIntelligence.value.analytics);
const responseIntel = computed(
    () => localIntelligence.value.response_intelligence,
);
const assessedEvents = computed(
    () => localIntelligence.value.assessed_events || [],
);

const criticalAlertsCount = computed(
    () => earlyWarnings.value?.critical_alerts || 0,
);
const highAlertsCount = computed(() => earlyWarnings.value?.high_alerts || 0);

function formatTime(iso: string | null | undefined): string {
    if (!iso) return 'Recent';
    try {
        const d = new Date(iso);
        return d.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function getRiskLevelBadgeClass(level: RiskLevel | string): string {
    switch (level) {
        case 'Critical':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
        case 'High':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'Moderate':
            return 'bg-yellow-500/15 text-yellow-600 dark:text-yellow-400 border-yellow-500/30';
        default:
            return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
    }
}

function getPriorityBadgeClass(tier: string): string {
    switch (tier) {
        case 'Immediate / Critical':
            return 'bg-rose-600 text-white border-rose-700 animate-pulse';
        case 'High Urgency':
            return 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/40';
        case 'Elevated Priority':
            return 'bg-yellow-500/20 text-yellow-600 dark:text-yellow-400 border-yellow-500/40';
        default:
            return 'bg-slate-500/20 text-slate-600 dark:text-slate-400 border-slate-500/40';
    }
}
</script>

<template>
    <Head
        title="Disaster Intelligence & Early Warning System – DisasterLinkSEA"
    >
        <meta
            name="description"
            content="Advanced multi-hazard disaster intelligence, early warning alerts, risk assessment scoring, and ASEAN-11 emergency response intelligence."
        />
    </Head>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased"
    >
        <DisasterHeader
            :last-updated="dashboardData.generated_at"
            :is-refreshing="isDashboardRefreshing || isFiltering"
            :countdown="countdown"
            :is-tab-visible="isTabVisible"
            :active-alerts-count="
                dashboardData.summary?.total_active_alerts?.count ||
                alertsList.length
            "
            :has-stale-sources="hasStaleSources"
            :has-unavailable-sources="hasUnavailableSources"
            :sync-error="syncError"
            @refresh="handleManualRefresh"
        />

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Hero Title & Context Banner -->
            <section
                class="relative mb-6 overflow-hidden rounded-2xl border border-border/80 bg-gradient-to-br from-card via-card to-destructive/5 p-6 shadow-sm"
            >
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border border-destructive/30 bg-destructive/10 px-3 py-1 font-mono text-xs font-semibold text-destructive"
                            >
                                <Radio class="size-3.5 animate-pulse" />
                                STEP 9 INTELLIGENCE ENGINE
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs text-muted-foreground"
                            >
                                ASEAN-11 Regional Telemetry
                            </span>
                        </div>
                        <h1
                            class="text-2xl font-extrabold tracking-tight sm:text-3xl"
                        >
                            Disaster Intelligence & Early Warning System
                        </h1>
                        <p class="max-w-3xl text-sm text-muted-foreground">
                            Algorithmic multi-factor hazard risk scoring,
                            deduplicated early warning alerts, longitudinal
                            ASEAN analytics, and field response prioritization.
                        </p>
                    </div>

                    <!-- Live KPI Cards -->
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:gap-3">
                        <div
                            class="flex flex-col rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-center"
                        >
                            <span
                                class="text-xs font-medium text-rose-600 dark:text-rose-400"
                                >Critical Alerts</span
                            >
                            <span
                                class="font-mono text-2xl font-bold text-rose-700 dark:text-rose-300"
                            >
                                {{ criticalAlertsCount }}
                            </span>
                        </div>
                        <div
                            class="flex flex-col rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-center"
                        >
                            <span
                                class="text-xs font-medium text-amber-600 dark:text-amber-400"
                                >High Alerts</span
                            >
                            <span
                                class="font-mono text-2xl font-bold text-amber-700 dark:text-amber-300"
                            >
                                {{ highAlertsCount }}
                            </span>
                        </div>
                        <div
                            class="flex flex-col rounded-xl border border-border/70 bg-card p-3 text-center shadow-xs"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                >Tracked Events</span
                            >
                            <span
                                class="font-mono text-2xl font-bold text-foreground"
                            >
                                {{ assessedEvents.length }}
                            </span>
                        </div>
                        <div
                            class="flex flex-col rounded-xl border border-border/70 bg-card p-3 text-center shadow-xs"
                        >
                            <span
                                class="text-xs font-medium text-muted-foreground"
                                >Triaged Reports</span
                            >
                            <span
                                class="font-mono text-2xl font-bold text-foreground"
                            >
                                {{ responseIntel.total_reports }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Navigation Tabs Bar -->
            <div
                class="mb-6 flex flex-wrap items-center gap-2 border-b border-border/80 pb-3"
            >
                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'warnings'
                            ? 'bg-destructive text-destructive-foreground shadow-sm'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'warnings'"
                >
                    <BellRing class="size-4" />
                    <span>Early Warnings ({{ alertsList.length }})</span>
                    <span
                        v-if="criticalAlertsCount > 0"
                        class="py-0.2 rounded-full bg-rose-600 px-1.5 text-[10px] text-white"
                    >
                        {{ criticalAlertsCount }} Critical
                    </span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'risk'
                            ? 'bg-destructive text-destructive-foreground shadow-sm'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'risk'"
                >
                    <Activity class="size-4" />
                    <span>Risk Assessment Engine</span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'analytics'
                            ? 'bg-destructive text-destructive-foreground shadow-sm'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'analytics'"
                >
                    <TrendingUp class="size-4" />
                    <span>Disaster Analytics & Trends</span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'response'
                            ? 'bg-destructive text-destructive-foreground shadow-sm'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'response'"
                >
                    <Compass class="size-4" />
                    <span
                        >Response Intelligence ({{
                            responseIntel.immediate_count
                        }}
                        urgent)</span
                    >
                </button>
            </div>

            <!-- TAB 1: EARLY WARNINGS & ALERT MANAGEMENT -->
            <div v-if="activeTab === 'warnings'" class="space-y-6">
                <!-- Threshold Configuration Bar -->
                <div
                    class="rounded-xl border border-border/80 bg-card p-4 shadow-xs"
                >
                    <div
                        class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-center gap-2">
                            <Filter class="size-4 text-destructive" />
                            <h2 class="text-sm font-bold tracking-tight">
                                Configurable Alert Thresholds & Filters
                            </h2>
                        </div>
                        <span class="text-xs text-muted-foreground">
                            Active filter updates early warning alerts and
                            response advisories in real time.
                        </span>
                    </div>

                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    >
                        <!-- Min Severity Threshold -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Minimum Risk Level</label
                            >
                            <select
                                v-model="selectedMinLevel"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="all">
                                    All Active Signals (Low+)
                                </option>
                                <option value="moderate">
                                    Moderate & Above (Recommended)
                                </option>
                                <option value="high">High & Critical</option>
                                <option value="critical">Critical Only</option>
                            </select>
                        </div>

                        <!-- Country Filter -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Target Country</label
                            >
                            <select
                                v-model="selectedCountry"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="All">All ASEAN Nations</option>
                                <option
                                    v-for="(
                                        meta, name
                                    ) in localIntelligence.countries"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ meta.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Category Filter -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Hazard Category</label
                            >
                            <select
                                v-model="selectedCategory"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="All">All Categories</option>
                                <option value="earthquake">Earthquakes</option>
                                <option value="cyclone">
                                    Tropical Cyclones
                                </option>
                                <option value="flood">Floods</option>
                                <option value="storm">Severe Storms</option>
                                <option value="volcano">Volcanoes</option>
                                <option value="wildfire">Wildfires</option>
                            </select>
                        </div>

                        <!-- Min Earthquake Magnitude -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Min Seismic Magnitude</label
                            >
                            <select
                                v-model="selectedMinMagnitude"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="">Any Magnitude</option>
                                <option value="4.5">M 4.5+ (Light)</option>
                                <option value="5.5">M 5.5+ (Moderate)</option>
                                <option value="6.0">M 6.0+ (Strong)</option>
                                <option value="7.0">M 7.0+ (Major)</option>
                            </select>
                        </div>

                        <!-- Filter Reset Action -->
                        <div class="flex items-end">
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-full cursor-pointer text-xs"
                                @click="resetFilters"
                            >
                                Reset Thresholds
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Alert Cards Grid -->
                <div v-if="alertsList.length > 0" class="space-y-4">
                    <div
                        v-for="alert in alertsList"
                        :key="alert.id"
                        class="relative overflow-hidden rounded-xl border border-border/80 bg-card p-5 shadow-xs transition-shadow hover:shadow-md"
                    >
                        <div
                            class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <!-- Alert Header & Content -->
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        :class="[
                                            'rounded-md border px-2.5 py-0.5 font-mono text-xs font-bold uppercase',
                                            getRiskLevelBadgeClass(
                                                alert.severity_level,
                                            ),
                                        ]"
                                    >
                                        {{ alert.severity_level }} ALERT
                                    </span>
                                    <span
                                        class="rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs font-semibold text-muted-foreground"
                                    >
                                        Risk Score: {{ alert.risk_score }}/100
                                    </span>
                                    <span
                                        class="text-xs font-semibold tracking-wider text-rose-600 uppercase dark:text-rose-400"
                                    >
                                        {{ alert.emergency_headline }}
                                    </span>
                                </div>

                                <h3 class="text-lg font-bold tracking-tight">
                                    {{ alert.title }}
                                </h3>

                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        class="flex items-center gap-1 font-medium"
                                    >
                                        <MapPin
                                            class="size-3.5 text-destructive"
                                        />
                                        {{ alert.location }} ({{
                                            alert.country
                                        }})
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <Clock class="size-3.5" />
                                        {{ formatTime(alert.issued_at) }}
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <Database class="size-3.5" />
                                        {{ alert.primary_source }}
                                    </span>
                                </div>

                                <!-- Immediate Life-Safety Action Box -->
                                <div
                                    class="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-900 dark:text-rose-200"
                                >
                                    <div class="flex items-start gap-2">
                                        <AlertOctagon
                                            class="mt-0.5 size-4 shrink-0 text-rose-600 dark:text-rose-400"
                                        />
                                        <div>
                                            <span class="font-bold"
                                                >Immediate Life-Safety Action:
                                            </span>
                                            <span>{{
                                                alert.immediate_action
                                            }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Protective Actions Checklist -->
                                <div
                                    v-if="
                                        alert.protective_actions &&
                                        alert.protective_actions.length > 0
                                    "
                                    class="mt-3 space-y-1.5"
                                >
                                    <h4
                                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        Precautionary Response Directives:
                                    </h4>
                                    <ul class="grid grid-cols-1 gap-1 text-xs">
                                        <li
                                            v-for="(
                                                action, actIdx
                                            ) in alert.protective_actions"
                                            :key="actIdx"
                                            class="flex items-start gap-2 text-foreground/90"
                                        >
                                            <CheckCircle2
                                                class="mt-0.5 size-3.5 shrink-0 text-emerald-500"
                                            />
                                            <span>{{ action }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Telemetry & Contributing Factors -->
                                <div
                                    v-if="
                                        alert.factors &&
                                        alert.factors.length > 0
                                    "
                                    class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-muted-foreground"
                                >
                                    <span
                                        v-for="(f, fIdx) in alert.factors"
                                        :key="fIdx"
                                        class="rounded border border-border/70 bg-muted/40 px-2 py-0.5"
                                    >
                                        {{ f }}
                                    </span>
                                </div>
                            </div>

                            <!-- Actions Column -->
                            <div
                                class="flex shrink-0 flex-row gap-2 lg:flex-col"
                            >
                                <Link
                                    v-if="alert.event_id"
                                    :href="`/events/${alert.event_id}`"
                                    class="inline-flex h-8 items-center justify-center gap-1.5 rounded-md bg-destructive px-3 text-xs font-semibold text-destructive-foreground shadow-xs transition-colors hover:bg-destructive/90"
                                >
                                    <span>Event Telemetry</span>
                                    <ExternalLink class="size-3.5" />
                                </Link>
                                <a
                                    v-if="alert.source_url"
                                    :href="alert.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-border bg-card px-3 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                                >
                                    <span>Provider Source</span>
                                    <ExternalLink class="size-3.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-else
                    class="rounded-xl border border-dashed border-border/80 bg-card p-12 text-center"
                >
                    <ShieldCheck
                        class="mx-auto size-10 text-emerald-500 opacity-80"
                    />
                    <h3 class="mt-3 text-base font-bold">
                        No Active Warnings Match Thresholds
                    </h3>
                    <p
                        class="mx-auto mt-1 max-w-md text-xs text-muted-foreground"
                    >
                        There are currently no qualifying disaster events
                        meeting your minimum risk level ({{ selectedMinLevel }})
                        or country criteria.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        class="mt-4 cursor-pointer text-xs"
                        @click="resetFilters"
                    >
                        Reset Filter Criteria
                    </Button>
                </div>
            </div>

            <!-- TAB 2: RISK ASSESSMENT ENGINE -->
            <div v-else-if="activeTab === 'risk'" class="space-y-6">
                <!-- Methodology Transparency Card -->
                <div
                    class="rounded-xl border border-border/80 bg-card p-5 shadow-xs"
                >
                    <div class="flex items-start gap-3">
                        <Info class="mt-0.5 size-5 shrink-0 text-blue-500" />
                        <div class="space-y-1.5 text-xs">
                            <h3 class="text-sm font-bold text-foreground">
                                Risk Assessment Scoring Methodology
                            </h3>
                            <p class="text-muted-foreground">
                                The engine computes an objective risk score (1 -
                                100) across 4 verifiable physical telemetry
                                dimensions.
                                <strong
                                    >It does not predict future events</strong
                                >, but evaluates the current hazard potential of
                                observed phenomena:
                            </p>
                            <div
                                class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4"
                            >
                                <div
                                    class="rounded-lg border border-border/70 bg-muted/30 p-2.5"
                                >
                                    <span class="font-bold text-foreground"
                                        >1. Hazard Severity (40%)</span
                                    >
                                    <p
                                        class="mt-0.5 text-[11px] text-muted-foreground"
                                    >
                                        Seismic magnitude, focal depth, GDACS
                                        alert tier, storm wind velocity, and
                                        reported flood inundation.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-border/70 bg-muted/30 p-2.5"
                                >
                                    <span class="font-bold text-foreground"
                                        >2. Environmental Context (25%)</span
                                    >
                                    <p
                                        class="mt-0.5 text-[11px] text-muted-foreground"
                                    >
                                        Live atmospheric telemetry from
                                        Open-Meteo: heavy precipitation (mm),
                                        gale wind gusts (km/h), and compounded
                                        soil-saturation.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-border/70 bg-muted/30 p-2.5"
                                >
                                    <span class="font-bold text-foreground"
                                        >3. Geographic Vulnerability (20%)</span
                                    >
                                    <p
                                        class="mt-0.5 text-[11px] text-muted-foreground"
                                    >
                                        Pacific Rim / Sunda subduction zone,
                                        low-lying river deltas (Mekong, Chao
                                        Phraya, Ayeyarwady), and coastal surge
                                        exposure.
                                    </p>
                                </div>
                                <div
                                    class="rounded-lg border border-border/70 bg-muted/30 p-2.5"
                                >
                                    <span class="font-bold text-foreground"
                                        >4. Corroboration (15%)</span
                                    >
                                    <p
                                        class="mt-0.5 text-[11px] text-muted-foreground"
                                    >
                                        Cross-agency sensor overlap (GDACS +
                                        USGS + NASA) and verified on-the-ground
                                        citizen observation reports.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assessed Events List -->
                <div class="space-y-4">
                    <div
                        v-for="event in assessedEvents"
                        :key="event.id"
                        class="rounded-xl border border-border/80 bg-card p-4 shadow-xs transition-shadow hover:shadow-md"
                    >
                        <div
                            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                        >
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        :class="[
                                            'rounded-md border px-2 py-0.5 font-mono text-xs font-bold uppercase',
                                            getRiskLevelBadgeClass(
                                                event.risk_assessment?.level ||
                                                    'Low',
                                            ),
                                        ]"
                                    >
                                        {{
                                            event.risk_assessment?.level ||
                                            'Low'
                                        }}
                                    </span>
                                    <span
                                        class="rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        Score:
                                        {{ event.risk_assessment?.score || 0 }}
                                        / 100
                                    </span>
                                    <span
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        {{ event.category }} •
                                        {{ event.country }}
                                    </span>
                                </div>
                                <h4 class="text-base font-bold">
                                    {{ event.title }}
                                </h4>
                                <div
                                    class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                                >
                                    <span
                                        >{{ event.location }} ({{
                                            event.country
                                        }})</span
                                    >
                                    <span>•</span>
                                    <span>{{
                                        formatTime(event.event_time)
                                    }}</span>
                                    <span>•</span>
                                    <span>Source: {{ event.source }}</span>
                                </div>
                            </div>

                            <!-- Score Breakdown Bars -->
                            <div
                                class="flex min-w-[280px] flex-col gap-1 rounded-lg border border-border/60 bg-muted/20 p-3 text-xs"
                            >
                                <div
                                    class="flex items-center justify-between font-mono text-[11px]"
                                >
                                    <span>Severity:</span>
                                    <span class="font-bold"
                                        >{{
                                            event.risk_assessment?.breakdown
                                                ?.hazard_severity || 0
                                        }}
                                        / 40</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between font-mono text-[11px]"
                                >
                                    <span>Weather Context:</span>
                                    <span class="font-bold"
                                        >{{
                                            event.risk_assessment?.breakdown
                                                ?.environmental_risk || 0
                                        }}
                                        / 25</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between font-mono text-[11px]"
                                >
                                    <span>Vulnerability:</span>
                                    <span class="font-bold"
                                        >{{
                                            event.risk_assessment?.breakdown
                                                ?.vulnerability || 0
                                        }}
                                        / 20</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between font-mono text-[11px]"
                                >
                                    <span>Corroboration:</span>
                                    <span class="font-bold"
                                        >{{
                                            event.risk_assessment?.breakdown
                                                ?.corroboration || 0
                                        }}
                                        / 15</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Factor Explanations -->
                        <div
                            v-if="
                                event.risk_assessment?.factors &&
                                event.risk_assessment.factors.length > 0
                            "
                            class="mt-3 border-t border-border/60 pt-2.5"
                        >
                            <span
                                class="text-[11px] font-semibold text-muted-foreground uppercase"
                                >Key Assessment Factors:</span
                            >
                            <ul
                                class="mt-1 list-inside list-disc space-y-0.5 text-xs text-foreground/80"
                            >
                                <li
                                    v-for="(f, fIdx) in event.risk_assessment
                                        .factors"
                                    :key="fIdx"
                                >
                                    {{ f }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: DISASTER ANALYTICS & TRENDS -->
            <div v-else-if="activeTab === 'analytics'" class="space-y-6">
                <!-- Section 1: Type Distribution -->
                <div
                    class="rounded-xl border border-border/80 bg-card p-5 shadow-xs"
                >
                    <h3 class="mb-3 text-sm font-bold tracking-tight">
                        Disaster Type Distribution & Impact Shares
                    </h3>
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                    >
                        <div
                            v-for="item in analytics.type_distribution"
                            :key="item.category"
                            class="rounded-lg border border-border/70 bg-muted/20 p-3 text-center"
                        >
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                                >{{ item.label }}</span
                            >
                            <div
                                class="my-1 font-mono text-2xl font-bold"
                                :style="{ color: item.color }"
                            >
                                {{ item.count }}
                            </div>
                            <span
                                class="font-mono text-[11px] text-muted-foreground"
                            >
                                {{ item.percentage }}% of events
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Frequency by Country -->
                <div
                    class="rounded-xl border border-border/80 bg-card p-5 shadow-xs"
                >
                    <h3 class="mb-3 text-sm font-bold tracking-tight">
                        Disaster Frequency by ASEAN Country
                    </h3>
                    <div
                        class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div
                            v-for="country in analytics.country_frequency"
                            :key="country.country"
                            class="flex items-center justify-between rounded-lg border border-border/60 bg-muted/10 p-3"
                        >
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="flex size-7 items-center justify-center rounded-md bg-muted font-mono text-xs font-bold text-muted-foreground"
                                >
                                    {{ country.code }}
                                </span>
                                <div>
                                    <h4 class="text-xs font-bold">
                                        {{ country.country }}
                                    </h4>
                                    <span
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        Peak Risk:
                                        {{ country.peak_risk_score }}/100
                                    </span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="font-mono text-sm font-bold">{{
                                    country.total_events
                                }}</span>
                                <span
                                    class="block text-[10px] text-muted-foreground"
                                    >events</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Monthly Trends Timeline -->
                <div
                    v-if="
                        analytics.monthly_trends &&
                        analytics.monthly_trends.length > 0
                    "
                    class="rounded-xl border border-border/80 bg-card p-5 shadow-xs"
                >
                    <h3 class="mb-3 text-sm font-bold tracking-tight">
                        Historical Monthly Incident Trends
                    </h3>
                    <div class="space-y-2">
                        <div
                            v-for="trend in analytics.monthly_trends"
                            :key="trend.period"
                            class="flex items-center gap-4 text-xs"
                        >
                            <span
                                class="w-20 font-mono font-medium text-muted-foreground"
                                >{{ trend.month_name }}</span
                            >
                            <div
                                class="h-4 flex-1 overflow-hidden rounded-full bg-muted/50"
                            >
                                <div
                                    class="h-full rounded-full bg-destructive transition-all"
                                    :style="{
                                        width: `${Math.min(100, (trend.count / Math.max(1, analytics.overview.total_events)) * 300)}%`,
                                    }"
                                ></div>
                            </div>
                            <span class="w-16 text-right font-mono font-bold"
                                >{{ trend.count }} events</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Section 4: ASEAN Comparison Matrix -->
                <div
                    class="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs"
                >
                    <div class="border-b border-border/80 p-4">
                        <h3 class="text-sm font-bold tracking-tight">
                            ASEAN-11 Disaster Vulnerability & Weather Matrix
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table
                            class="w-full text-left text-xs whitespace-nowrap"
                        >
                            <thead
                                class="border-b border-border bg-muted/40 font-semibold text-muted-foreground uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3">Country</th>
                                    <th class="px-4 py-3">Events Tracked</th>
                                    <th class="px-4 py-3">Risk Tier</th>
                                    <th class="px-4 py-3">Dominant Hazard</th>
                                    <th class="px-4 py-3">Capital Weather</th>
                                    <th class="px-4 py-3">NDMA Agency</th>
                                    <th class="px-4 py-3">Emergency Hotline</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                <tr
                                    v-for="row in analytics.asean_comparison_matrix"
                                    :key="row.country"
                                    class="transition-colors hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3 font-bold">
                                        {{ row.country }} ({{ row.code }})
                                    </td>
                                    <td class="px-4 py-3 font-mono">
                                        {{ row.total_events }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            :class="[
                                                'rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase',
                                                getRiskLevelBadgeClass(
                                                    row.risk_tier,
                                                ),
                                            ]"
                                        >
                                            {{ row.risk_tier }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ row.dominant_hazard }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ row.capital }}:
                                        {{
                                            row.capital_weather
                                                .temperature_c !== null
                                                ? `${row.capital_weather.temperature_c}°C`
                                                : '--'
                                        }},
                                        {{ row.capital_weather.condition }}
                                    </td>
                                    <td
                                        class="max-w-[200px] truncate px-4 py-3 text-muted-foreground"
                                    >
                                        {{ row.ndma_agency }}
                                    </td>
                                    <td class="px-4 py-3 font-mono font-medium">
                                        {{ row.hotline }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: RESPONSE INTELLIGENCE & TRIAGE -->
            <div v-else-if="activeTab === 'response'" class="space-y-6">
                <!-- Triage Priority Overview Cards -->
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4 md:grid-cols-4"
                >
                    <div
                        class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-center"
                    >
                        <span
                            class="text-xs font-semibold text-rose-600 dark:text-rose-400"
                            >Immediate / Critical</span
                        >
                        <span
                            class="block font-mono text-3xl font-extrabold text-rose-700 dark:text-rose-300"
                        >
                            {{ responseIntel.immediate_count }}
                        </span>
                        <span class="text-[10px] text-muted-foreground"
                            >Dispatch recommended</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-center"
                    >
                        <span
                            class="text-xs font-semibold text-amber-600 dark:text-amber-400"
                            >High Urgency</span
                        >
                        <span
                            class="block font-mono text-3xl font-extrabold text-amber-700 dark:text-amber-300"
                        >
                            {{ responseIntel.high_count }}
                        </span>
                        <span class="text-[10px] text-muted-foreground"
                            >Priority review</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-yellow-500/30 bg-yellow-500/10 p-4 text-center"
                    >
                        <span
                            class="text-xs font-semibold text-yellow-600 dark:text-yellow-400"
                            >Elevated Priority</span
                        >
                        <span
                            class="block font-mono text-3xl font-extrabold text-yellow-700 dark:text-yellow-300"
                        >
                            {{ responseIntel.elevated_count }}
                        </span>
                        <span class="text-[10px] text-muted-foreground"
                            >Standard queue</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-border/80 bg-card p-4 text-center"
                    >
                        <span
                            class="text-xs font-semibold text-muted-foreground"
                            >Routine Monitoring</span
                        >
                        <span class="block font-mono text-3xl font-extrabold">
                            {{ responseIntel.routine_count }}
                        </span>
                        <span class="text-[10px] text-muted-foreground"
                            >Periodic review</span
                        >
                    </div>
                </div>

                <!-- Triaged Reports Stream -->
                <div
                    v-if="
                        responseIntel.reports &&
                        responseIntel.reports.length > 0
                    "
                    class="space-y-4"
                >
                    <div
                        v-for="rep in responseIntel.reports"
                        :key="rep.id"
                        class="rounded-xl border border-border/80 bg-card p-5 shadow-xs transition-shadow hover:shadow-md"
                    >
                        <div
                            class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        :class="[
                                            'rounded-md border px-2.5 py-0.5 font-mono text-xs font-bold uppercase',
                                            getPriorityBadgeClass(
                                                rep.priority_tier,
                                            ),
                                        ]"
                                    >
                                        {{ rep.priority_tier }}
                                    </span>
                                    <span
                                        class="rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs font-semibold text-muted-foreground"
                                    >
                                        Triage Score: {{ rep.triage_score }}/100
                                    </span>
                                    <span
                                        class="rounded border border-border/70 bg-muted/40 px-2 py-0.5 font-mono text-[11px] uppercase"
                                    >
                                        Status: {{ rep.status }}
                                    </span>
                                </div>

                                <h3 class="text-base font-bold">
                                    {{ rep.title }}
                                </h3>

                                <div
                                    class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                                >
                                    <span class="font-medium text-foreground">
                                        Type: {{ rep.disaster_type }}
                                    </span>
                                    <span>•</span>
                                    <span
                                        >{{ rep.location }},
                                        {{ rep.country }}</span
                                    >
                                    <span>•</span>
                                    <span
                                        >Submitted by:
                                        {{ rep.submitter_name }}</span
                                    >
                                    <span>•</span>
                                    <span>{{
                                        formatTime(rep.incident_time)
                                    }}</span>
                                </div>

                                <!-- Recommended Action Guidance -->
                                <div
                                    class="mt-2 rounded-lg border border-border/70 bg-muted/20 p-2.5 text-xs"
                                >
                                    <span class="font-bold text-foreground"
                                        >Recommended Action:
                                    </span>
                                    <span class="text-foreground/90">{{
                                        rep.recommended_action
                                    }}</span>
                                </div>

                                <!-- Triage Factors -->
                                <div
                                    v-if="
                                        rep.triage_reasons &&
                                        rep.triage_reasons.length > 0
                                    "
                                    class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-muted-foreground"
                                >
                                    <span
                                        v-for="(r, rIdx) in rep.triage_reasons"
                                        :key="rIdx"
                                        class="rounded border border-border/60 bg-muted/30 px-2 py-0.5"
                                    >
                                        {{ r }}
                                    </span>
                                </div>
                            </div>

                            <!-- Right Details Column -->
                            <div class="flex shrink-0 flex-col gap-2">
                                <span
                                    v-if="rep.has_coordinates"
                                    class="inline-flex items-center gap-1 rounded border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                >
                                    <MapPin class="size-3" />
                                    GPS Calibrated
                                </span>
                                <span
                                    v-if="rep.photo_count > 0"
                                    class="inline-flex items-center gap-1 rounded border border-blue-500/30 bg-blue-500/10 px-2.5 py-1 text-xs font-medium text-blue-600 dark:text-blue-400"
                                >
                                    <Layers class="size-3" />
                                    {{ rep.photo_count }} Photos Attached
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-else
                    class="rounded-xl border border-dashed border-border/80 bg-card p-12 text-center"
                >
                    <ShieldCheck
                        class="mx-auto size-10 text-muted-foreground"
                    />
                    <h3 class="mt-3 text-base font-bold">
                        No Community Reports Pending Triage
                    </h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Citizen disaster submissions will be scored and
                        prioritized here as they arrive.
                    </p>
                </div>

                <!-- Emergency Response Guidance Contacts -->
                <EmergencyResponseCard
                    :selected-country="selectedCountry || 'All Southeast Asia'"
                />
            </div>
        </main>

        <DisasterFooter :last-updated="dashboardData.generated_at" />
    </div>
</template>
