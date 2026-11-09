<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    Bed,
    Building2,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Compass,
    ExternalLink,
    Filter,
    HeartHandshake,
    HeartPulse,
    Home,
    Layers,
    LifeBuoy,
    MapPin,
    Package,
    Phone,
    PlusCircle,
    RefreshCw,
    Search,
    Send,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Truck,
    UserCheck,
    Users,
    Utensils,
    Waves,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import EmergencyResponseCard from '@/components/disaster/EmergencyResponseCard.vue';
import { useDisasterTelemetry } from '@/composables/useDisasterTelemetry';
import type {
    CoordinationPayload,
    DashboardData,
    EmergencyResourceItem,
    EvacuationRouteItem,
    RescueRequestItem,
    ShelterItem,
    VolunteerItem,
} from '@/types/disaster';

const props = defineProps<{
    coordination: CoordinationPayload;
    initialDashboardData?: DashboardData | null;
    filters?: {
        country?: string;
        shelter_type?: string;
        resource_category?: string;
    };
}>();

const page = usePage();
const currentUser = computed(() => page.props.auth?.user);
const isAdmin = computed(() => currentUser.value?.role === 'admin');

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

// Active Tab
type TabKey = 'shelters' | 'resources' | 'routes' | 'rescue';
const activeTab = ref<TabKey>('shelters');

// Filter state
const selectedCountry = ref<string>(props.filters?.country || 'All');
const selectedShelterType = ref<string>(props.filters?.shelter_type || 'all');
const selectedResourceCategory = ref<string>(
    props.filters?.resource_category || 'all',
);
const isFiltering = ref(false);

const localCoordination = ref<CoordinationPayload>(props.coordination);

watch(
    () => props.coordination,
    (newVal) => {
        localCoordination.value = newVal;
    },
);

function applyFilters() {
    isFiltering.value = true;
    router.get(
        '/coordination',
        {
            country: selectedCountry.value,
            shelter_type: selectedShelterType.value,
            resource_category: selectedResourceCategory.value,
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
    selectedCountry.value = 'All';
    selectedShelterType.value = 'all';
    selectedResourceCategory.value = 'all';
    applyFilters();
}

function handleManualRefresh() {
    fetchDashboardData(true);
    router.reload({ only: ['coordination', 'initialDashboardData'] });
}

// Data Lists
const shelters = computed(() => localCoordination.value.shelters || []);
const resources = computed(() => localCoordination.value.resources || []);
const routes = computed(() => localCoordination.value.routes || []);
const rescueRequests = computed(
    () => localCoordination.value.rescue_requests || [],
);
const volunteers = computed(() => localCoordination.value.volunteers || []);
const summary = computed(() => localCoordination.value.summary);

// --- MODALS STATE ---
// 1. Volunteer Registration Modal
const showVolunteerModal = ref(false);
const volunteerForm = useForm({
    name: currentUser.value?.name || '',
    email: currentUser.value?.email || '',
    phone: '',
    country: 'Philippines',
    city: '',
    skills: [] as string[],
    notes: '',
});

const skillOptions = [
    'First Aid / EMT',
    'Search & Rescue',
    'Boat Operation',
    'Warehouse Logistics',
    'Medical Trauma Care',
    'Translation / Multi-language',
    'Heavy Vehicle Driving',
    'Mental Health Support',
];

function toggleSkill(skill: string) {
    const idx = volunteerForm.skills.indexOf(skill);
    if (idx === -1) {
        volunteerForm.skills.push(skill);
    } else {
        volunteerForm.skills.splice(idx, 1);
    }
}

function submitVolunteer() {
    volunteerForm.post('/volunteers/register', {
        preserveScroll: true,
        onSuccess: () => {
            showVolunteerModal.value = false;
            volunteerForm.reset('phone', 'city', 'skills', 'notes');
        },
    });
}

// 2. Rescue Assistance Request Modal
const showRescueModal = ref(false);
const rescueForm = useForm({
    reporter_name: currentUser.value?.name || '',
    contact_phone: '',
    country: 'Philippines',
    location: '',
    latitude: null as number | null,
    longitude: null as number | null,
    people_count: 1,
    has_medical_emergency: false,
    urgency: 'high',
    situation_description: '',
});

function submitRescueRequest() {
    rescueForm.post('/rescue-requests', {
        preserveScroll: true,
        onSuccess: () => {
            showRescueModal.value = false;
            rescueForm.reset(
                'location',
                'situation_description',
                'latitude',
                'longitude',
            );
        },
    });
}

// 3. Admin: Distribute Supplies Modal
const showDistributeModal = ref(false);
const activeResourceForDist = ref<EmergencyResourceItem | null>(null);
const distForm = useForm({
    recipient: '',
    quantity: 1,
    distributed_by: currentUser.value?.name || 'Emergency Coordinator',
    notes: '',
});

function openDistributeModal(res: EmergencyResourceItem) {
    activeResourceForDist.value = res;
    distForm.quantity = 1;
    distForm.recipient = '';
    distForm.notes = '';
    showDistributeModal.value = true;
}

function submitDistribution() {
    if (!activeResourceForDist.value) return;
    distForm.post(
        `/admin/resources/${activeResourceForDist.value.id}/distribute`,
        {
            preserveScroll: true,
            onSuccess: () => {
                showDistributeModal.value = false;
            },
        },
    );
}

// 4. Admin: Update Shelter Status / Occupancy Modal
const showShelterEditModal = ref(false);
const activeShelterForEdit = ref<ShelterItem | null>(null);
const shelterEditForm = useForm({
    total_capacity: 100,
    current_occupancy: 0,
    status: 'open',
    contact_phone: '',
    notes: '',
});

function openShelterEditModal(shl: ShelterItem) {
    activeShelterForEdit.value = shl;
    shelterEditForm.total_capacity = shl.total_capacity;
    shelterEditForm.current_occupancy = shl.current_occupancy;
    shelterEditForm.status = shl.status;
    shelterEditForm.contact_phone = shl.contact_phone;
    shelterEditForm.notes = shl.notes || '';
    showShelterEditModal.value = true;
}

function submitShelterEdit() {
    if (!activeShelterForEdit.value) return;
    shelterEditForm.put(`/admin/shelters/${activeShelterForEdit.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showShelterEditModal.value = false;
        },
    });
}

// 5. Admin: Update Rescue Request Status Modal
const showRescueAdminModal = ref(false);
const activeRescueForAdmin = ref<RescueRequestItem | null>(null);
const rescueAdminForm = useForm({
    status: 'pending',
    assigned_volunteer_id: '',
    resolution_notes: '',
});

function openRescueAdminModal(req: RescueRequestItem) {
    activeRescueForAdmin.value = req;
    rescueAdminForm.status = req.status;
    rescueAdminForm.assigned_volunteer_id = req.assigned_volunteer_id || '';
    rescueAdminForm.resolution_notes = req.resolution_notes || '';
    showRescueAdminModal.value = true;
}

function submitRescueAdmin() {
    if (!activeRescueForAdmin.value) return;
    rescueAdminForm.patch(
        `/admin/rescue-requests/${activeRescueForAdmin.value.id}/status`,
        {
            preserveScroll: true,
            onSuccess: () => {
                showRescueAdminModal.value = false;
            },
        },
    );
}

function getShelterStatusBadge(status: string): string {
    switch (status) {
        case 'open':
            return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
        case 'full':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'evacuating':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30 animate-pulse';
        default:
            return 'bg-slate-500/15 text-slate-600 dark:text-slate-400 border-slate-500/30';
    }
}

function getResourceStatusBadge(status: string): string {
    switch (status) {
        case 'adequate':
            return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
        case 'low':
            return 'bg-yellow-500/15 text-yellow-600 dark:text-yellow-400 border-yellow-500/30';
        case 'critical':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'depleted':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30 animate-pulse';
        default:
            return 'bg-slate-500/15 text-slate-600 border-slate-500/30';
    }
}

function getRouteStatusBadge(status: string): string {
    switch (status) {
        case 'clear':
            return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
        case 'caution':
            return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
        case 'impassable':
            return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
        default:
            return 'bg-slate-500/15 text-slate-600 border-slate-500/30';
    }
}

function getUrgencyBadge(urgency: string): string {
    switch (urgency) {
        case 'critical':
            return 'bg-rose-600 text-white border-rose-700 animate-pulse';
        case 'high':
            return 'bg-amber-500/20 text-amber-600 dark:text-amber-400 border-amber-500/40';
        case 'moderate':
            return 'bg-yellow-500/20 text-yellow-600 dark:text-yellow-400 border-yellow-500/40';
        default:
            return 'bg-slate-500/20 text-slate-600 dark:text-slate-400 border-slate-500/40';
    }
}

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
</script>

<template>
    <Head
        title="Emergency Resource & Evacuation Coordination – DisasterLinkSEA"
    >
        <meta
            name="description"
            content="Emergency shelter management, relief inventory tracking, hazard-aware evacuation routes, and volunteer rescue coordination across ASEAN."
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
                dashboardData.summary?.total_active_alerts?.count || 0
            "
            :has-stale-sources="hasStaleSources"
            :has-unavailable-sources="hasUnavailableSources"
            :sync-error="syncError"
            @refresh="handleManualRefresh"
        />

        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Hero Title & Emergency Stats Banner -->
            <section
                class="relative mb-6 overflow-hidden rounded-2xl border border-border/80 bg-gradient-to-br from-card via-card to-blue-500/5 p-6 shadow-xs"
            >
                <div
                    class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border border-blue-500/30 bg-blue-500/10 px-3 py-1 font-mono text-xs font-semibold text-blue-600 dark:text-blue-400"
                            >
                                <LifeBuoy class="size-3.5 animate-spin" />
                                STEP 11 COORDINATION SYSTEM
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs text-muted-foreground"
                            >
                                ASEAN-11 Civil Defense Grid
                            </span>
                        </div>
                        <h1
                            class="text-2xl font-extrabold tracking-tight sm:text-3xl"
                        >
                            Emergency Resource & Evacuation Coordination
                        </h1>
                        <p class="max-w-3xl text-sm text-muted-foreground">
                            Real-time shelter capacity and occupancy tracking,
                            critical medical & food stock inventories, verified
                            hazard-aware evacuation transit, and volunteer
                            rescue dispatch.
                        </p>
                    </div>

                    <!-- Action CTAs -->
                    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-9 cursor-pointer gap-1.5 px-3.5 text-xs font-semibold shadow-xs"
                            @click="showRescueModal = true"
                        >
                            <AlertOctagon class="size-4" />
                            <span>Request Rescue Assistance</span>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 cursor-pointer gap-1.5 border-border bg-card px-3.5 text-xs font-semibold text-foreground hover:bg-muted"
                            @click="showVolunteerModal = true"
                        >
                            <HeartHandshake class="size-4 text-emerald-500" />
                            <span>Register as Volunteer</span>
                        </Button>
                    </div>
                </div>

                <!-- Live KPI Metrics Strip -->
                <div
                    class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6"
                >
                    <div
                        class="rounded-xl border border-border/70 bg-card p-3 text-center shadow-xs"
                    >
                        <span class="text-xs font-medium text-muted-foreground"
                            >Total Capacity</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-foreground"
                        >
                            {{ summary.total_capacity.toLocaleString() }}
                        </span>
                        <span class="block text-[10px] text-muted-foreground"
                            >{{ summary.total_shelters }} facilities</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-center"
                    >
                        <span
                            class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                            >Available Space</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-emerald-700 dark:text-emerald-300"
                        >
                            {{ summary.available_capacity.toLocaleString() }}
                        </span>
                        <span
                            class="block text-[10px] text-emerald-600/80 dark:text-emerald-400/80"
                            >Safe beds ready</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-border/70 bg-card p-3 text-center shadow-xs"
                    >
                        <span class="text-xs font-medium text-muted-foreground"
                            >Current Occupancy</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-foreground"
                        >
                            {{ summary.overall_occupancy_rate }}%
                        </span>
                        <span class="block text-[10px] text-muted-foreground"
                            >{{
                                summary.current_occupancy.toLocaleString()
                            }}
                            housed</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-center"
                    >
                        <span
                            class="text-xs font-medium text-rose-600 dark:text-rose-400"
                            >Supply Shortages</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-rose-700 dark:text-rose-300"
                        >
                            {{ summary.critical_shortages_count }}
                        </span>
                        <span
                            class="block text-[10px] text-rose-600/80 dark:text-rose-400/80"
                            >Critical restocks</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-center"
                    >
                        <span
                            class="text-xs font-medium text-amber-600 dark:text-amber-400"
                            >Rescue Requests</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-amber-700 dark:text-amber-300"
                        >
                            {{ summary.pending_rescue_requests }}
                        </span>
                        <span
                            class="block text-[10px] text-amber-600/80 dark:text-amber-400/80"
                            >{{ summary.critical_rescue_requests }} urgent</span
                        >
                    </div>
                    <div
                        class="rounded-xl border border-border/70 bg-card p-3 text-center shadow-xs"
                    >
                        <span class="text-xs font-medium text-muted-foreground"
                            >Volunteers Ready</span
                        >
                        <span
                            class="font-mono text-xl font-extrabold text-foreground"
                        >
                            {{ summary.available_volunteers }}
                        </span>
                        <span class="block text-[10px] text-muted-foreground"
                            >{{ summary.total_volunteers }} registered</span
                        >
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
                        activeTab === 'shelters'
                            ? 'bg-blue-600 text-white shadow-xs'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'shelters'"
                >
                    <Building2 class="size-4" />
                    <span>Shelters & Centers ({{ shelters.length }})</span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'resources'
                            ? 'bg-blue-600 text-white shadow-xs'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'resources'"
                >
                    <Package class="size-4" />
                    <span>Relief Supplies ({{ resources.length }})</span>
                    <span
                        v-if="summary.critical_shortages_count > 0"
                        class="py-0.2 rounded-full bg-rose-600 px-1.5 text-[10px] text-white"
                    >
                        {{ summary.critical_shortages_count }} Shortage
                    </span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'routes'
                            ? 'bg-blue-600 text-white shadow-xs'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'routes'"
                >
                    <Compass class="size-4" />
                    <span>Evacuation Routes ({{ routes.length }})</span>
                </button>

                <button
                    type="button"
                    :class="[
                        'flex cursor-pointer items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-all',
                        activeTab === 'rescue'
                            ? 'bg-blue-600 text-white shadow-xs'
                            : 'bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground',
                    ]"
                    @click="activeTab = 'rescue'"
                >
                    <HeartPulse class="size-4" />
                    <span>Rescue & Volunteers</span>
                    <span
                        v-if="summary.critical_rescue_requests > 0"
                        class="py-0.2 rounded-full bg-rose-600 px-1.5 text-[10px] text-white"
                    >
                        {{ summary.critical_rescue_requests }} Urgent
                    </span>
                </button>
            </div>

            <!-- TAB 1: SHELTERS & EVACUATION CENTERS -->
            <div v-if="activeTab === 'shelters'" class="space-y-6">
                <!-- Filter & Search Controls -->
                <div
                    class="flex flex-col gap-3 rounded-xl border border-border/80 bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div
                        class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                    >
                        <!-- Country Filter -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >ASEAN Country</label
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
                                    ) in localCoordination.countries"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ meta.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Shelter Type Filter -->
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-muted-foreground"
                                >Facility Type</label
                            >
                            <select
                                v-model="selectedShelterType"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="all">All Facility Types</option>
                                <option value="evacuation_center">
                                    Evacuation Centers
                                </option>
                                <option value="shelter">
                                    Emergency Shelters
                                </option>
                                <option value="hospital">
                                    Emergency Hospitals
                                </option>
                                <option value="relief_center">
                                    Relief Logistics Hubs
                                </option>
                            </select>
                        </div>

                        <!-- Reset Action -->
                        <div class="flex items-end">
                            <Button
                                variant="outline"
                                size="sm"
                                class="w-full cursor-pointer text-xs"
                                @click="resetFilters"
                            >
                                Reset Filters
                            </Button>
                        </div>
                    </div>

                    <!-- Map Cross Link -->
                    <div class="flex items-end">
                        <Link
                            href="/map"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-xs font-medium text-foreground hover:bg-muted"
                        >
                            <Layers class="size-3.5 text-blue-500" />
                            <span>View on Interactive Map</span>
                        </Link>
                    </div>
                </div>

                <!-- Shelters Grid -->
                <div
                    v-if="shelters.length > 0"
                    class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="shelter in shelters"
                        :key="shelter.id"
                        class="flex flex-col justify-between rounded-xl border border-border/80 bg-card p-5 shadow-xs transition-shadow hover:shadow-md"
                    >
                        <div class="space-y-3">
                            <!-- Type & Status Row -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="rounded-md border border-border/70 bg-muted/60 px-2.5 py-0.5 font-mono text-[11px] font-bold text-muted-foreground uppercase"
                                >
                                    {{ shelter.type.replace('_', ' ') }}
                                </span>
                                <span
                                    :class="[
                                        'rounded-md border px-2 py-0.5 font-mono text-[11px] font-bold uppercase',
                                        getShelterStatusBadge(shelter.status),
                                    ]"
                                >
                                    {{ shelter.status }}
                                </span>
                            </div>

                            <!-- Name & Location -->
                            <div>
                                <h3
                                    class="text-base font-bold tracking-tight text-foreground"
                                >
                                    {{ shelter.name }}
                                </h3>
                                <p
                                    class="mt-1 flex items-start gap-1 text-xs text-muted-foreground"
                                >
                                    <MapPin
                                        class="mt-0.5 size-3.5 shrink-0 text-destructive"
                                    />
                                    <span
                                        >{{ shelter.address }},
                                        {{ shelter.city }},
                                        {{ shelter.country }}</span
                                    >
                                </p>
                            </div>

                            <!-- Capacity & Occupancy Meter -->
                            <div
                                class="space-y-1.5 rounded-lg border border-border/60 bg-muted/20 p-3 text-xs"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-foreground"
                                        >Current Occupancy:</span
                                    >
                                    <span class="font-mono font-bold">
                                        {{
                                            shelter.current_occupancy.toLocaleString()
                                        }}
                                        /
                                        {{
                                            shelter.total_capacity.toLocaleString()
                                        }}
                                    </span>
                                </div>

                                <!-- Progress Bar -->
                                <div
                                    class="h-2 w-full overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="[
                                            shelter.occupancy_rate >= 90
                                                ? 'bg-rose-500'
                                                : shelter.occupancy_rate >= 70
                                                  ? 'bg-amber-500'
                                                  : 'bg-emerald-500',
                                        ]"
                                        :style="{
                                            width: `${Math.min(100, shelter.occupancy_rate)}%`,
                                        }"
                                    ></div>
                                </div>

                                <div
                                    class="flex items-center justify-between text-[11px] text-muted-foreground"
                                >
                                    <span
                                        >{{ shelter.occupancy_rate }}%
                                        capacity</span
                                    >
                                    <span
                                        class="font-semibold text-emerald-600 dark:text-emerald-400"
                                    >
                                        {{
                                            shelter.available_capacity.toLocaleString()
                                        }}
                                        spaces available
                                    </span>
                                </div>
                            </div>

                            <!-- Facilities Amenities Badges -->
                            <div
                                v-if="
                                    shelter.facilities &&
                                    shelter.facilities.length > 0
                                "
                                class="flex flex-wrap gap-1 text-[10px]"
                            >
                                <span
                                    v-for="(f, fIdx) in shelter.facilities"
                                    :key="fIdx"
                                    class="rounded border border-border/60 bg-muted/40 px-1.5 py-0.5 text-muted-foreground"
                                >
                                    {{ f }}
                                </span>
                            </div>

                            <!-- Contact Info -->
                            <div
                                class="flex flex-col gap-1 border-t border-border/60 pt-2.5 text-xs text-muted-foreground"
                            >
                                <div class="flex items-center gap-1.5">
                                    <Users class="size-3.5 text-blue-500" />
                                    <span>{{ shelter.contact_name }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <Phone class="size-3.5 text-emerald-500" />
                                    <a
                                        :href="`tel:${shelter.contact_phone}`"
                                        class="font-mono font-medium text-foreground hover:underline"
                                    >
                                        {{ shelter.contact_phone }}
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Admin Operations Bar -->
                        <div
                            v-if="isAdmin"
                            class="mt-4 flex items-center justify-between border-t border-border/60 pt-3"
                        >
                            <span class="text-[11px] text-muted-foreground"
                                >Admin Actions</span
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-7 cursor-pointer text-xs"
                                @click="openShelterEditModal(shelter)"
                            >
                                Update Status
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-else
                    class="rounded-xl border border-dashed border-border/80 bg-card p-12 text-center"
                >
                    <Building2
                        class="mx-auto size-10 text-muted-foreground opacity-80"
                    />
                    <h3 class="mt-3 text-base font-bold">No Shelters Found</h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        No facilities match your country or facility type
                        filter.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        class="mt-3 text-xs"
                        @click="resetFilters"
                    >
                        Reset Filter
                    </Button>
                </div>
            </div>

            <!-- TAB 2: EMERGENCY RESOURCE MANAGEMENT -->
            <div v-else-if="activeTab === 'resources'" class="space-y-6">
                <!-- Critical Supply Shortage Notice Banner -->
                <div
                    v-if="summary.critical_shortages_count > 0"
                    class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-900 dark:text-rose-200"
                >
                    <div class="flex items-start gap-2.5">
                        <AlertOctagon
                            class="mt-0.5 size-5 shrink-0 text-rose-600 dark:text-rose-400"
                        />
                        <div>
                            <span class="text-sm font-bold"
                                >Critical Resource Shortage Alert:
                            </span>
                            <span
                                >{{ summary.critical_shortages_count }} critical
                                supply item(s) are currently depleted or below
                                safe minimum thresholds across regional relief
                                centers. Priority resupply dispatch
                                required.</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Resources Table Card -->
                <div
                    class="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs"
                >
                    <div
                        class="flex flex-col gap-3 border-b border-border/80 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <h3 class="text-sm font-bold tracking-tight">
                                Regional Emergency Relief Inventory
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                Stock levels, safe reserves, and distribution
                                tracking across food, medical, and rescue gear.
                            </p>
                        </div>

                        <!-- Category Filter Select -->
                        <div class="flex items-center gap-2">
                            <select
                                v-model="selectedResourceCategory"
                                class="rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="all">All Supply Types</option>
                                <option value="water_food">
                                    Food & Potable Water
                                </option>
                                <option value="medical">
                                    Medical & Trauma Care
                                </option>
                                <option value="rescue_equipment">
                                    Rescue Equipment
                                </option>
                                <option value="necessities">
                                    Emergency Necessities
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table
                            class="w-full text-left text-xs whitespace-nowrap"
                        >
                            <thead
                                class="border-b border-border bg-muted/40 font-semibold text-muted-foreground uppercase"
                            >
                                <tr>
                                    <th class="px-4 py-3">Item Name</th>
                                    <th class="px-4 py-3">Category</th>
                                    <th class="px-4 py-3">Facility Location</th>
                                    <th class="px-4 py-3">Stock Level</th>
                                    <th class="px-4 py-3">
                                        Min Safe Threshold
                                    </th>
                                    <th class="px-4 py-3">Status</th>
                                    <th
                                        v-if="isAdmin"
                                        class="px-4 py-3 text-right"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                <tr
                                    v-for="res in resources"
                                    :key="res.id"
                                    class="transition-colors hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3 font-bold">
                                        {{ res.item_name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="rounded border border-border/70 bg-muted/40 px-2 py-0.5 text-[11px]"
                                        >
                                            {{ res.category.replace('_', ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">
                                        {{
                                            res.shelter?.name ||
                                            'Regional Strategic Reserve'
                                        }}
                                        <span v-if="res.shelter"
                                            >({{ res.shelter.city }},
                                            {{ res.shelter.country }})</span
                                        >
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-sm font-bold"
                                    >
                                        {{ res.quantity.toLocaleString() }}
                                        {{ res.unit }}
                                    </td>
                                    <td
                                        class="px-4 py-3 font-mono text-muted-foreground"
                                    >
                                        {{ res.min_threshold.toLocaleString() }}
                                        {{ res.unit }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            :class="[
                                                'rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase',
                                                getResourceStatusBadge(
                                                    res.status,
                                                ),
                                            ]"
                                        >
                                            {{ res.status }}
                                        </span>
                                    </td>
                                    <td
                                        v-if="isAdmin"
                                        class="px-4 py-3 text-right"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-7 cursor-pointer text-xs"
                                            @click="openDistributeModal(res)"
                                        >
                                            Distribute
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: EVACUATION ROUTES & GUIDELINES -->
            <div v-else-if="activeTab === 'routes'" class="space-y-6">
                <!-- Verified Routes Integrity Disclaimer -->
                <div
                    class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs text-emerald-900 dark:text-emerald-200"
                >
                    <div class="flex items-start gap-2.5">
                        <ShieldCheck
                            class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        <div>
                            <span class="text-sm font-bold"
                                >Verified Transit Corridors Only:
                            </span>
                            <span
                                >DisasterLink_SEA strictly enforces verified
                                civil protection and municipal evacuation
                                corridors.
                                <strong
                                    >Fictional routes are never
                                    generated.</strong
                                >
                                Every route below is surveyed for flood
                                clearance and seismic integrity.</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Routes Cards Grid -->
                <div class="space-y-4">
                    <div
                        v-for="route in routes"
                        :key="route.id"
                        class="rounded-xl border border-border/80 bg-card p-5 shadow-xs transition-shadow hover:shadow-md"
                    >
                        <div
                            class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        :class="[
                                            'rounded-md border px-2.5 py-0.5 font-mono text-xs font-bold uppercase',
                                            getRouteStatusBadge(route.status),
                                        ]"
                                    >
                                        ROAD STATUS: {{ route.status }}
                                    </span>
                                    <span
                                        class="rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        {{ route.distance_km }} km Transit
                                    </span>
                                    <span
                                        class="text-xs font-semibold text-muted-foreground"
                                    >
                                        {{ route.country }}
                                    </span>
                                </div>

                                <h3 class="text-base font-bold">
                                    {{ route.title }}
                                </h3>

                                <div
                                    class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground"
                                >
                                    <span
                                        ><strong>Origin:</strong>
                                        {{ route.origin }}</span
                                    >
                                    <span>➔</span>
                                    <span
                                        ><strong>Safe Reception Hub:</strong>
                                        {{ route.destination }}</span
                                    >
                                </div>

                                <!-- Hazard Warning Callout -->
                                <div
                                    v-if="route.hazard_warning"
                                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200"
                                >
                                    <div class="flex items-start gap-2">
                                        <AlertTriangle
                                            class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
                                        />
                                        <span>{{ route.hazard_warning }}</span>
                                    </div>
                                </div>

                                <!-- Step-by-step Instructions -->
                                <div class="space-y-1">
                                    <h4
                                        class="text-xs font-semibold text-muted-foreground uppercase"
                                    >
                                        Evacuation Transit Directives:
                                    </h4>
                                    <p
                                        class="text-xs whitespace-pre-line text-foreground/90"
                                    >
                                        {{ route.instructions }}
                                    </p>
                                </div>

                                <!-- Verified Waypoints -->
                                <div
                                    v-if="
                                        route.waypoints &&
                                        route.waypoints.length > 0
                                    "
                                    class="mt-3 space-y-1"
                                >
                                    <h4
                                        class="text-xs font-semibold text-muted-foreground uppercase"
                                    >
                                        Verified Route Checkpoints:
                                    </h4>
                                    <div
                                        class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3"
                                    >
                                        <div
                                            v-for="(
                                                wp, wpIdx
                                            ) in route.waypoints"
                                            :key="wpIdx"
                                            class="rounded border border-border/70 bg-muted/30 p-2 text-xs"
                                        >
                                            <span class="block font-bold"
                                                >{{ wpIdx + 1 }}.
                                                {{ wp.name }}</span
                                            >
                                            <span
                                                class="block text-[11px] text-muted-foreground"
                                                >{{ wp.note }}</span
                                            >
                                        </div>
                                    </div>
                                </div>

                                <div class="text-[11px] text-muted-foreground">
                                    Verified by:
                                    <strong>{{
                                        route.verified_by ||
                                        'Civil Protection Authority'
                                    }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: RESCUE REQUESTS & VOLUNTEER MANAGEMENT -->
            <div v-else-if="activeTab === 'rescue'" class="space-y-6">
                <!-- Two Columns Layout -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Column 1: Citizen Rescue Assistance Requests -->
                    <div class="space-y-4">
                        <div
                            class="flex items-center justify-between border-b border-border/80 pb-2"
                        >
                            <div>
                                <h3 class="text-base font-bold">
                                    Rescue Assistance Queue
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Prioritized citizen requests for evacuation
                                    and urgent medical assistance.
                                </p>
                            </div>
                            <Button
                                variant="destructive"
                                size="sm"
                                class="h-8 cursor-pointer text-xs"
                                @click="showRescueModal = true"
                            >
                                <PlusCircle class="mr-1 size-3.5" />
                                <span>New Request</span>
                            </Button>
                        </div>

                        <!-- Requests List -->
                        <div v-if="rescueRequests.length > 0" class="space-y-3">
                            <div
                                v-for="req in rescueRequests"
                                :key="req.id"
                                class="rounded-xl border border-border/80 bg-card p-4 shadow-xs"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="space-y-1">
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                :class="[
                                                    'rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase',
                                                    getUrgencyBadge(
                                                        req.urgency,
                                                    ),
                                                ]"
                                            >
                                                {{ req.urgency }} PRIORITY
                                            </span>
                                            <span
                                                class="rounded border border-border/70 bg-muted/40 px-2 py-0.5 font-mono text-[10px] uppercase"
                                            >
                                                {{ req.status }}
                                            </span>
                                            <span
                                                v-if="req.has_medical_emergency"
                                                class="rounded border border-rose-500/40 bg-rose-500/15 px-2 py-0.5 text-[10px] font-bold text-rose-600 dark:text-rose-400"
                                            >
                                                MEDICAL EMERGENCY
                                            </span>
                                        </div>
                                        <h4 class="text-sm font-bold">
                                            {{ req.people_count }} people
                                            stranded at {{ req.location }}
                                        </h4>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ req.situation_description }}
                                        </p>
                                        <div
                                            class="flex flex-wrap items-center gap-3 pt-1 text-[11px] text-muted-foreground"
                                        >
                                            <span
                                                >Contact:
                                                {{ req.reporter_name }} ({{
                                                    req.contact_phone
                                                }})</span
                                            >
                                            <span>•</span>
                                            <span
                                                >Reported:
                                                {{
                                                    formatTime(req.created_at)
                                                }}</span
                                            >
                                        </div>

                                        <div
                                            v-if="req.assigned_volunteer"
                                            class="mt-2 rounded border border-blue-500/30 bg-blue-500/10 p-2 text-xs text-blue-900 dark:text-blue-200"
                                        >
                                            <span>Assigned Responder: </span
                                            ><strong
                                                >{{
                                                    req.assigned_volunteer.name
                                                }}
                                                ({{
                                                    req.assigned_volunteer
                                                        .phone
                                                }})</strong
                                            >
                                        </div>
                                    </div>

                                    <Button
                                        v-if="isAdmin"
                                        variant="outline"
                                        size="sm"
                                        class="h-7 cursor-pointer text-xs"
                                        @click="openRescueAdminModal(req)"
                                    >
                                        Dispatch
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="rounded-xl border border-dashed border-border/80 bg-card p-8 text-center text-xs text-muted-foreground"
                        >
                            No pending rescue requests in this area.
                        </div>
                    </div>

                    <!-- Column 2: Volunteer Management & Roster -->
                    <div class="space-y-4">
                        <div
                            class="flex items-center justify-between border-b border-border/80 pb-2"
                        >
                            <div>
                                <h3 class="text-base font-bold">
                                    Registered Volunteer Pool
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Certified search & rescue personnel, EMTs,
                                    and logistics volunteers.
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                class="h-8 cursor-pointer text-xs"
                                @click="showVolunteerModal = true"
                            >
                                <HeartHandshake class="mr-1 size-3.5" />
                                <span>Sign Up</span>
                            </Button>
                        </div>

                        <!-- Volunteers Roster -->
                        <div v-if="volunteers.length > 0" class="space-y-3">
                            <div
                                v-for="vol in volunteers"
                                :key="vol.id"
                                class="rounded-xl border border-border/80 bg-card p-4 shadow-xs"
                            >
                                <div class="flex items-start justify-between">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <h4 class="text-sm font-bold">
                                                {{ vol.name }}
                                            </h4>
                                            <span
                                                :class="[
                                                    'py-0.2 rounded px-2 font-mono text-[10px] font-bold uppercase',
                                                    vol.availability_status ===
                                                    'available'
                                                        ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                        : vol.availability_status ===
                                                            'deployed'
                                                          ? 'bg-blue-500/15 text-blue-600 dark:text-blue-400'
                                                          : 'bg-slate-500/15 text-slate-600 dark:text-slate-400',
                                                ]"
                                            >
                                                {{ vol.availability_status }}
                                            </span>
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ vol.city }}, {{ vol.country }} •
                                            {{ vol.phone }}
                                        </p>
                                        <div
                                            v-if="
                                                vol.skills &&
                                                vol.skills.length > 0
                                            "
                                            class="flex flex-wrap gap-1 pt-1"
                                        >
                                            <span
                                                v-for="(s, sIdx) in vol.skills"
                                                :key="sIdx"
                                                class="rounded border border-border/60 bg-muted/40 px-1.5 py-0.5 text-[10px] text-muted-foreground"
                                            >
                                                {{ s }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="rounded-xl border border-dashed border-border/80 bg-card p-8 text-center text-xs text-muted-foreground"
                        >
                            No volunteers registered yet. Sign up to join the
                            response network!
                        </div>
                    </div>
                </div>

                <!-- Emergency Response Agency Contacts -->
                <EmergencyResponseCard
                    :selected-country="selectedCountry || 'All Southeast Asia'"
                />
            </div>
        </main>

        <!-- MODAL 1: Volunteer Registration Modal -->
        <Dialog v-model:open="showVolunteerModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Register as Emergency Volunteer</DialogTitle>
                    <DialogDescription>
                        Join the regional disaster response roster. Your
                        readiness helps save lives during acute floods, quakes,
                        and typhoons.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="space-y-3 py-2 text-xs"
                    @submit.prevent="submitVolunteer"
                >
                    <div>
                        <label class="mb-1 block font-medium">Full Name</label>
                        <input
                            v-model="volunteerForm.name"
                            type="text"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="e.g. Maria Santos"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Email Address</label
                            >
                            <input
                                v-model="volunteerForm.email"
                                type="email"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                placeholder="name@domain.com"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Phone Number</label
                            >
                            <input
                                v-model="volunteerForm.phone"
                                type="text"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                placeholder="+63 917 123 4567"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >ASEAN Country</label
                            >
                            <select
                                v-model="volunteerForm.country"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option
                                    v-for="(
                                        meta, name
                                    ) in localCoordination.countries"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ meta.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >City / Municipality</label
                            >
                            <input
                                v-model="volunteerForm.city"
                                type="text"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                placeholder="e.g. Quezon City"
                            />
                        </div>
                    </div>

                    <!-- Skills Checkboxes -->
                    <div>
                        <label class="mb-1 block font-medium"
                            >Specialized Skills & Certifications</label
                        >
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="skill in skillOptions"
                                :key="skill"
                                type="button"
                                :class="[
                                    'flex cursor-pointer items-center gap-1.5 rounded border px-2 py-1 text-left text-[11px] transition-colors',
                                    volunteerForm.skills.includes(skill)
                                        ? 'border-blue-500 bg-blue-500/15 font-semibold text-blue-700 dark:text-blue-300'
                                        : 'border-border bg-card text-muted-foreground hover:bg-muted',
                                ]"
                                @click="toggleSkill(skill)"
                            >
                                <Check
                                    v-if="volunteerForm.skills.includes(skill)"
                                    class="size-3 text-blue-500"
                                />
                                <span>{{ skill }}</span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-medium"
                            >Notes & Equipment</label
                        >
                        <textarea
                            v-model="volunteerForm.notes"
                            rows="2"
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="e.g. 4x4 vehicle available, drone operator certified"
                        ></textarea>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="showVolunteerModal = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                            :disabled="volunteerForm.processing"
                        >
                            Confirm Registration
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- MODAL 2: Citizen Rescue Request Modal -->
        <Dialog v-model:open="showRescueModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-rose-600 dark:text-rose-400"
                        >Request Emergency Rescue Assistance</DialogTitle
                    >
                    <DialogDescription>
                        Direct dispatch notification to regional emergency
                        operations centers. Provide accurate location and
                        contact information.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="space-y-3 py-2 text-xs"
                    @submit.prevent="submitRescueRequest"
                >
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Contact Name</label
                            >
                            <input
                                v-model="rescueForm.reporter_name"
                                type="text"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                placeholder="Your name"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Contact Telephone</label
                            >
                            <input
                                v-model="rescueForm.contact_phone"
                                type="text"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                                placeholder="Phone number"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-medium"
                            >Stranded Location & Address</label
                        >
                        <input
                            v-model="rescueForm.location"
                            type="text"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Street, Landmark, Barangay, City"
                        />
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Country</label
                            >
                            <select
                                v-model="rescueForm.country"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option
                                    v-for="(
                                        meta, name
                                    ) in localCoordination.countries"
                                    :key="name"
                                    :value="name"
                                >
                                    {{ meta.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >People Stranded</label
                            >
                            <input
                                v-model="rescueForm.people_count"
                                type="number"
                                min="1"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Urgency</label
                            >
                            <select
                                v-model="rescueForm.urgency"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="critical">
                                    Critical (Life Threat)
                                </option>
                                <option value="high">High</option>
                                <option value="moderate">Moderate</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                    </div>

                    <!-- Medical Emergency Toggle -->
                    <div
                        class="flex items-center gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 p-2.5"
                    >
                        <input
                            id="med-toggle"
                            v-model="rescueForm.has_medical_emergency"
                            type="checkbox"
                            class="size-4 rounded border-border text-rose-600 focus:ring-rose-500"
                        />
                        <label
                            for="med-toggle"
                            class="cursor-pointer font-medium text-rose-900 dark:text-rose-200"
                        >
                            Urgent medical care or oxygen/insulin dependency
                            required
                        </label>
                    </div>

                    <div>
                        <label class="mb-1 block font-medium"
                            >Situation Details & Water Level</label
                        >
                        <textarea
                            v-model="rescueForm.situation_description"
                            rows="3"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Describe current hazards (water depth, structural damage, elderly/infants trapped)..."
                        ></textarea>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="showRescueModal = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            :disabled="rescueForm.processing"
                        >
                            Transmit Rescue Request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- MODAL 3: Admin Distribute Supplies Modal -->
        <Dialog v-model:open="showDistributeModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Log Relief Supply Distribution</DialogTitle>
                    <DialogDescription>
                        Record dispatch of
                        <strong
                            >{{ activeResourceForDist?.item_name }} ({{
                                activeResourceForDist?.quantity
                            }}
                            available)</strong
                        >.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="space-y-3 py-2 text-xs"
                    @submit.prevent="submitDistribution"
                >
                    <div>
                        <label class="mb-1 block font-medium"
                            >Recipient Group / Shelter Zone</label
                        >
                        <input
                            v-model="distForm.recipient"
                            type="text"
                            required
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="e.g. Barangay San Jose Evacuees (Zone 3)"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Quantity Distributed</label
                            >
                            <input
                                v-model="distForm.quantity"
                                type="number"
                                min="1"
                                :max="activeResourceForDist?.quantity || 1"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Authorized Officer</label
                            >
                            <input
                                v-model="distForm.distributed_by"
                                type="text"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium">Notes</label>
                        <textarea
                            v-model="distForm.notes"
                            rows="2"
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Dispatch receipt number or vehicle ID..."
                        ></textarea>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="showDistributeModal = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                            :disabled="distForm.processing"
                        >
                            Record Distribution
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- MODAL 4: Admin Edit Shelter Modal -->
        <Dialog v-model:open="showShelterEditModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Update Shelter Status & Capacity</DialogTitle>
                    <DialogDescription>
                        Adjust live occupancy and operational availability for
                        <strong>{{ activeShelterForEdit?.name }}</strong
                        >.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="space-y-3 py-2 text-xs"
                    @submit.prevent="submitShelterEdit"
                >
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Total Capacity</label
                            >
                            <input
                                v-model="shelterEditForm.total_capacity"
                                type="number"
                                min="1"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Current Occupancy</label
                            >
                            <input
                                v-model="shelterEditForm.current_occupancy"
                                type="number"
                                min="0"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium">Status</label>
                            <select
                                v-model="shelterEditForm.status"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="open">Open (Accepting)</option>
                                <option value="full">Full (At Capacity)</option>
                                <option value="evacuating">Evacuating</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Contact Telephone</label
                            >
                            <input
                                v-model="shelterEditForm.contact_phone"
                                type="text"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium"
                            >Operational Notes</label
                        >
                        <textarea
                            v-model="shelterEditForm.notes"
                            rows="2"
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                        ></textarea>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="showShelterEditModal = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                            :disabled="shelterEditForm.processing"
                        >
                            Save Changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- MODAL 5: Admin Rescue Dispatch Modal -->
        <Dialog v-model:open="showRescueAdminModal">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >Update Rescue Dispatch & Assignment</DialogTitle
                    >
                    <DialogDescription>
                        Manage operational status for rescue at
                        <strong>{{ activeRescueForAdmin?.location }}</strong
                        >.
                    </DialogDescription>
                </DialogHeader>

                <form
                    class="space-y-3 py-2 text-xs"
                    @submit.prevent="submitRescueAdmin"
                >
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block font-medium"
                                >Mission Status</label
                            >
                            <select
                                v-model="rescueAdminForm.status"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="pending">Pending</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium"
                                >Assign Volunteer</label
                            >
                            <select
                                v-model="rescueAdminForm.assigned_volunteer_id"
                                class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            >
                                <option value="">None Assigned</option>
                                <option
                                    v-for="vol in volunteers"
                                    :key="vol.id"
                                    :value="vol.id"
                                >
                                    {{ vol.name }} ({{ vol.city }})
                                </option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium"
                            >Resolution / Field Notes</label
                        >
                        <textarea
                            v-model="rescueAdminForm.resolution_notes"
                            rows="3"
                            class="w-full rounded-md border border-input bg-background px-3 py-1.5 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
                            placeholder="Rescue boat dispatched, evacuees transferred to Amoranto shelter..."
                        ></textarea>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="showRescueAdminModal = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            size="sm"
                            class="bg-blue-600 text-white hover:bg-blue-700"
                            :disabled="rescueAdminForm.processing"
                        >
                            Update Mission Status
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <DisasterFooter :last-updated="dashboardData.generated_at" />
    </div>
</template>
