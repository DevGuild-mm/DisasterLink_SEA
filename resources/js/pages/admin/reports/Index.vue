<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    ChevronDown,
    Clock,
    ExternalLink,
    Eye,
    Flame,
    HelpCircle,
    Image as ImageIcon,
    Info,
    Loader2,
    MapPin,
    RotateCcw,
    Search,
    ShieldAlert,
    ShieldCheck,
    User as UserIcon,
    Waves,
    Wind,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import LocationPickerMap from '@/components/disaster/LocationPickerMap.vue';
import { Button } from '@/components/ui/button';
import type { AseanCountry, UserReportItem } from '@/types/disaster';

interface PaginatedReports {
    data: UserReportItem[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    reports: PaginatedReports;
    counts: {
        total: number;
        pending: number;
        under_review: number;
        verified: number;
        rejected: number;
    };
    filters: {
        status?: string;
        country?: string;
        disaster_type?: string;
        search?: string;
    };
    countries: Record<string, AseanCountry>;
    disasterTypes: string[];
}>();

// Local filter state
const currentStatus = ref(props.filters.status || 'all');
const currentCountry = ref(props.filters.country || 'all');
const currentType = ref(props.filters.disaster_type || 'all');
const searchTerm = ref(props.filters.search || '');

// Photo preview modal
const activePhoto = ref<string | null>(null);

// Map preview expansion per report
const expandedMapId = ref<string | null>(null);

// Verification modal state
const verifyingReport = ref<UserReportItem | null>(null);
const rejectingReport = ref<UserReportItem | null>(null);

const verifyForm = useForm({
    status: 'verified',
    review_notes: '',
});

const rejectForm = useForm({
    status: 'rejected',
    rejection_reason: '',
    review_notes: '',
});

const disasterTypeIcons: Record<string, any> = {
    Earthquake: Activity,
    Flood: Waves,
    Storm: Wind,
    Landslide: AlertCircle,
    Wildfire: Flame,
    'Volcanic Activity': ShieldAlert,
    Other: HelpCircle,
};

function applyFilters() {
    router.get(
        '/admin/reports',
        {
            status: currentStatus.value,
            country: currentCountry.value,
            disaster_type: currentType.value,
            search: searchTerm.value ? searchTerm.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function handleStatusTab(status: string) {
    currentStatus.value = status;
    applyFilters();
}

function formatDate(iso: string | null | undefined): string {
    if (!iso) return 'N/A';
    try {
        const d = new Date(iso);
        return d.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function markUnderReview(report: UserReportItem) {
    router.patch(
        `/admin/reports/${report.id}/status`,
        {
            status: 'under_review',
        },
        {
            preserveScroll: true,
        },
    );
}

function openVerifyModal(report: UserReportItem) {
    verifyingReport.value = report;
    verifyForm.review_notes = '';
}

function submitVerification() {
    if (!verifyingReport.value) return;
    verifyForm.patch(`/admin/reports/${verifyingReport.value.id}/status`, {
        preserveScroll: true,
        onSuccess: () => {
            verifyingReport.value = null;
        },
    });
}

function openRejectModal(report: UserReportItem) {
    rejectingReport.value = report;
    rejectForm.rejection_reason = '';
    rejectForm.review_notes = '';
}

function submitRejection() {
    if (!rejectingReport.value) return;
    rejectForm.patch(`/admin/reports/${rejectingReport.value.id}/status`, {
        preserveScroll: true,
        onSuccess: () => {
            rejectingReport.value = null;
        },
    });
}

function toggleMap(reportId: string) {
    expandedMapId.value = expandedMapId.value === reportId ? null : reportId;
}
</script>

<template>
    <Head title="Admin Disaster Reports Verification Desk - DisasterLinkSEA" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <DisasterHeader
            :last-updated="null"
            :is-refreshing="false"
            :countdown="60"
            :is-tab-visible="true"
            :active-alerts-count="0"
        />

        <main class="flex-1 pb-16">
            <div class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
                <!-- Top Navigation Link -->
                <div class="mb-6 flex items-center justify-between">
                    <Link
                        href="/"
                        class="group inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft
                            class="size-3.5 transition-transform group-hover:-translate-x-0.5"
                        />
                        <span>Return to Public Dashboard</span>
                    </Link>

                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-amber-600 dark:text-amber-400"
                        >
                            <ShieldCheck class="size-3.5" />
                            <span>Moderator Authorization Active</span>
                        </span>
                    </div>
                </div>

                <!-- Admin Banner & Metric Cards -->
                <div
                    class="mb-8 rounded-2xl border border-border/80 bg-gradient-to-br from-card via-card to-amber-500/5 p-6 shadow-sm sm:p-8"
                >
                    <div
                        class="flex flex-col justify-between gap-4 md:flex-row md:items-center"
                    >
                        <div>
                            <div class="flex items-center gap-2.5">
                                <h1
                                    class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                                >
                                    Disaster Report Verification Desk
                                </h1>
                                <span
                                    class="rounded-md bg-amber-500/20 px-2 py-0.5 font-mono text-xs font-bold text-amber-700 dark:text-amber-400"
                                >
                                    Admin
                                </span>
                            </div>
                            <p
                                class="mt-1 text-xs text-muted-foreground sm:text-sm"
                            >
                                Verify and moderate citizen disaster
                                observations before publishing them to live
                                feeds, map telemetry, and country analytics.
                            </p>
                        </div>
                    </div>

                    <!-- Metrics Grid -->
                    <div
                        class="mt-6 grid grid-cols-2 gap-3 border-t border-border/60 pt-6 sm:grid-cols-5"
                    >
                        <div
                            class="rounded-xl border border-border/70 bg-card p-3 shadow-xs"
                        >
                            <span
                                class="text-[11px] font-medium text-muted-foreground"
                                >Total Reports</span
                            >
                            <div
                                class="mt-1 font-mono text-xl font-bold text-foreground"
                            >
                                {{ counts.total }}
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 shadow-xs"
                        >
                            <span
                                class="text-[11px] font-medium text-amber-600 dark:text-amber-400"
                                >Pending Review</span
                            >
                            <div
                                class="mt-1 font-mono text-xl font-bold text-amber-600 dark:text-amber-400"
                            >
                                {{ counts.pending }}
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-blue-500/30 bg-blue-500/5 p-3 shadow-xs"
                        >
                            <span
                                class="text-[11px] font-medium text-blue-600 dark:text-blue-400"
                                >Under Review</span
                            >
                            <div
                                class="mt-1 font-mono text-xl font-bold text-blue-600 dark:text-blue-400"
                            >
                                {{ counts.under_review }}
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 shadow-xs"
                        >
                            <span
                                class="text-[11px] font-medium text-emerald-600 dark:text-emerald-400"
                                >Verified & Published</span
                            >
                            <div
                                class="mt-1 font-mono text-xl font-bold text-emerald-600 dark:text-emerald-400"
                            >
                                {{ counts.verified }}
                            </div>
                        </div>

                        <div
                            class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-3 shadow-xs"
                        >
                            <span
                                class="text-[11px] font-medium text-rose-600 dark:text-rose-400"
                                >Rejected</span
                            >
                            <div
                                class="mt-1 font-mono text-xl font-bold text-rose-600 dark:text-rose-400"
                            >
                                {{ counts.rejected }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Bar -->
                <div
                    class="mb-6 space-y-4 rounded-2xl border border-border/80 bg-card p-4 shadow-sm sm:p-5"
                >
                    <!-- Status Filter Tabs -->
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            :class="[
                                'cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currentStatus === 'all'
                                    ? 'bg-foreground text-background shadow-xs'
                                    : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground',
                            ]"
                            @click="handleStatusTab('all')"
                        >
                            All ({{ counts.total }})
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currentStatus === 'pending'
                                    ? 'bg-amber-500 text-white shadow-xs'
                                    : 'bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 dark:text-amber-400',
                            ]"
                            @click="handleStatusTab('pending')"
                        >
                            <span
                                class="size-1.5 rounded-full bg-amber-400"
                            ></span>
                            <span>Pending ({{ counts.pending }})</span>
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currentStatus === 'under_review'
                                    ? 'bg-blue-500 text-white shadow-xs'
                                    : 'bg-blue-500/10 text-blue-600 hover:bg-blue-500/20 dark:text-blue-400',
                            ]"
                            @click="handleStatusTab('under_review')"
                        >
                            <span
                                class="size-1.5 rounded-full bg-blue-400"
                            ></span>
                            <span
                                >Under Review ({{ counts.under_review }})</span
                            >
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currentStatus === 'verified'
                                    ? 'bg-emerald-500 text-white shadow-xs'
                                    : 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 dark:text-emerald-400',
                            ]"
                            @click="handleStatusTab('verified')"
                        >
                            <span
                                class="size-1.5 rounded-full bg-emerald-400"
                            ></span>
                            <span>Verified ({{ counts.verified }})</span>
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                currentStatus === 'rejected'
                                    ? 'bg-rose-500 text-white shadow-xs'
                                    : 'bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 dark:text-rose-400',
                            ]"
                            @click="handleStatusTab('rejected')"
                        >
                            <span
                                class="size-1.5 rounded-full bg-rose-400"
                            ></span>
                            <span>Rejected ({{ counts.rejected }})</span>
                        </button>
                    </div>

                    <!-- Country, Type, Search Controls -->
                    <div
                        class="grid grid-cols-1 gap-3 border-t border-border/60 pt-1 sm:grid-cols-3"
                    >
                        <!-- Country Dropdown -->
                        <div>
                            <label
                                class="mb-1 block text-[11px] font-medium text-muted-foreground"
                                >Filter by Country</label
                            >
                            <select
                                v-model="currentCountry"
                                class="w-full rounded-lg border border-border/80 bg-background px-3 py-2 text-xs text-foreground focus:border-amber-500 focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="all">All ASEAN Countries</option>
                                <option
                                    v-for="(meta, name) in countries"
                                    :key="name"
                                    :value="name"
                                    :disabled="name === 'All'"
                                >
                                    {{ meta.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Disaster Type Dropdown -->
                        <div>
                            <label
                                class="mb-1 block text-[11px] font-medium text-muted-foreground"
                                >Filter by Disaster Type</label
                            >
                            <select
                                v-model="currentType"
                                class="w-full rounded-lg border border-border/80 bg-background px-3 py-2 text-xs text-foreground focus:border-amber-500 focus:outline-none"
                                @change="applyFilters"
                            >
                                <option value="all">
                                    All Disaster Categories
                                </option>
                                <option
                                    v-for="t in disasterTypes"
                                    :key="t"
                                    :value="t"
                                >
                                    {{ t }}
                                </option>
                            </select>
                        </div>

                        <!-- Search Box -->
                        <div>
                            <label
                                class="mb-1 block text-[11px] font-medium text-muted-foreground"
                                >Search Keyword / Submitter</label
                            >
                            <div class="relative">
                                <input
                                    v-model="searchTerm"
                                    type="text"
                                    placeholder="Search title, ID, or user..."
                                    class="w-full rounded-lg border border-border/80 bg-background py-2 pr-3 pl-8 text-xs text-foreground placeholder:text-muted-foreground/60 focus:border-amber-500 focus:outline-none"
                                    @keydown.enter="applyFilters"
                                />
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reports Listing -->
                <div
                    v-if="reports.data.length === 0"
                    class="rounded-2xl border border-dashed border-border/80 bg-card/40 p-12 text-center"
                >
                    <ShieldAlert
                        class="mx-auto mb-2 size-8 text-muted-foreground"
                    />
                    <h3 class="text-sm font-semibold text-foreground">
                        No reports found matching your criteria
                    </h3>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Try resetting your search filters or status selection.
                    </p>
                </div>

                <div v-else class="space-y-4">
                    <div
                        v-for="report in reports.data"
                        :key="report.id"
                        class="overflow-hidden rounded-2xl border border-border/80 bg-card p-5 shadow-sm transition-shadow hover:shadow-md sm:p-6"
                    >
                        <div
                            class="flex flex-col justify-between gap-5 lg:flex-row lg:items-start"
                        >
                            <!-- Left: Report Details -->
                            <div class="flex-1 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <!-- Category Pill -->
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-md border border-border/60 bg-muted px-2 py-0.5 text-xs font-semibold text-foreground"
                                    >
                                        <component
                                            :is="
                                                disasterTypeIcons[
                                                    report.disaster_type
                                                ] || AlertCircle
                                            "
                                            class="size-3.5 text-destructive"
                                        />
                                        <span>{{ report.disaster_type }}</span>
                                    </span>

                                    <!-- Status Pill -->
                                    <span
                                        v-if="report.status === 'pending'"
                                        class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-amber-600 dark:text-amber-400"
                                    >
                                        <span
                                            class="size-1.5 animate-pulse rounded-full bg-amber-500"
                                        ></span>
                                        <span>Pending Review</span>
                                    </span>
                                    <span
                                        v-else-if="
                                            report.status === 'under_review'
                                        "
                                        class="inline-flex items-center gap-1 rounded-md border border-blue-500/30 bg-blue-500/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-blue-600 dark:text-blue-400"
                                    >
                                        <span
                                            class="size-1.5 animate-pulse rounded-full bg-blue-500"
                                        ></span>
                                        <span>Under Review</span>
                                    </span>
                                    <span
                                        v-else-if="report.status === 'verified'"
                                        class="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-emerald-600 dark:text-emerald-400"
                                    >
                                        <CheckCircle2 class="size-3" />
                                        <span>Verified & Published</span>
                                    </span>
                                    <span
                                        v-else-if="report.status === 'rejected'"
                                        class="inline-flex items-center gap-1 rounded-md border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-600 dark:text-rose-400"
                                    >
                                        <X class="size-3" />
                                        <span>Rejected</span>
                                    </span>

                                    <span
                                        class="font-mono text-[10px] text-muted-foreground/70"
                                    >
                                        ID: {{ report.id }}
                                    </span>
                                </div>

                                <h2
                                    class="text-base font-bold text-foreground sm:text-lg"
                                >
                                    {{ report.title }}
                                </h2>

                                <!-- Submitter & Metadata Row -->
                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        class="inline-flex items-center gap-1 font-medium text-foreground"
                                    >
                                        <UserIcon
                                            class="size-3.5 text-muted-foreground"
                                        />
                                        <span
                                            >Submitter:
                                            {{ report.user?.name || 'Unknown' }}
                                            ({{
                                                report.user?.email || 'N/A'
                                            }})</span
                                        >
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <MapPin
                                            class="size-3.5 shrink-0 text-destructive"
                                        />
                                        <span
                                            >{{ report.location }},
                                            {{ report.country }}</span
                                        >
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Clock class="size-3.5 shrink-0" />
                                        <span
                                            >Incident:
                                            {{
                                                formatDate(report.incident_time)
                                            }}</span
                                        >
                                    </span>
                                </div>

                                <p
                                    class="pt-1 text-xs leading-relaxed text-muted-foreground sm:text-sm"
                                >
                                    {{ report.description }}
                                </p>

                                <!-- Photos & Coordinates -->
                                <div
                                    class="flex flex-wrap items-center gap-4 pt-2"
                                >
                                    <!-- Photos -->
                                    <div
                                        v-if="
                                            report.photos &&
                                            report.photos.length > 0
                                        "
                                        class="flex items-center gap-2"
                                    >
                                        <span
                                            class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground"
                                        >
                                            <ImageIcon class="size-3" />
                                            <span
                                                >Photos ({{
                                                    report.photos.length
                                                }}):</span
                                            >
                                        </span>
                                        <div class="flex gap-1.5">
                                            <button
                                                v-for="(
                                                    photoUrl, pIdx
                                                ) in report.photos"
                                                :key="pIdx"
                                                type="button"
                                                class="group relative size-10 cursor-pointer overflow-hidden rounded-md border border-border bg-muted transition-all hover:ring-2 hover:ring-amber-500"
                                                title="Inspect submitted image"
                                                @click="activePhoto = photoUrl"
                                            >
                                                <img
                                                    :src="photoUrl"
                                                    alt="Photo"
                                                    class="size-full object-cover transition-transform group-hover:scale-110"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                    <span
                                        v-else
                                        class="text-[11px] text-muted-foreground/60 italic"
                                    >
                                        No photos attached
                                    </span>

                                    <!-- Map Toggle Button if coordinates exist -->
                                    <div
                                        v-if="
                                            report.latitude !== null &&
                                            report.longitude !== null
                                        "
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-7 cursor-pointer gap-1.5 border-border/80 font-mono text-[11px] text-muted-foreground hover:text-foreground"
                                            type="button"
                                            @click="toggleMap(report.id)"
                                        >
                                            <MapPin
                                                class="size-3 text-destructive"
                                            />
                                            <span
                                                >{{
                                                    Number(
                                                        report.latitude,
                                                    ).toFixed(4)
                                                }}°,
                                                {{
                                                    Number(
                                                        report.longitude,
                                                    ).toFixed(4)
                                                }}°</span
                                            >
                                            <span>{{
                                                expandedMapId === report.id
                                                    ? '(Hide Map)'
                                                    : '(View Map)'
                                            }}</span>
                                        </Button>
                                    </div>
                                </div>

                                <!-- Collapsible Map Preview -->
                                <div
                                    v-if="
                                        expandedMapId === report.id &&
                                        report.latitude !== null &&
                                        report.longitude !== null
                                    "
                                    class="mt-3"
                                >
                                    <LocationPickerMap
                                        :model-value="{
                                            latitude: report.latitude,
                                            longitude: report.longitude,
                                        }"
                                        :readonly="true"
                                        height-class="h-52"
                                    />
                                </div>

                                <!-- Existing Review Notes / Rejection Reason -->
                                <div
                                    v-if="
                                        report.review_notes ||
                                        report.rejection_reason
                                    "
                                    class="mt-2 space-y-1 rounded-xl border border-border/70 bg-muted/30 p-3 text-xs"
                                >
                                    <div
                                        class="flex items-center gap-1.5 font-semibold text-foreground"
                                    >
                                        <Info class="size-3.5" />
                                        <span>Current Review Feedback:</span>
                                        <span
                                            v-if="report.reviewer"
                                            class="font-normal text-muted-foreground"
                                        >
                                            by {{ report.reviewer.name }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="report.review_notes"
                                        class="text-muted-foreground"
                                    >
                                        <strong
                                            class="font-medium text-foreground"
                                            >Notes:</strong
                                        >
                                        {{ report.review_notes }}
                                    </p>
                                    <p
                                        v-if="report.rejection_reason"
                                        class="font-medium text-rose-600 dark:text-rose-400"
                                    >
                                        <strong class="font-semibold"
                                            >Rejection Reason:</strong
                                        >
                                        {{ report.rejection_reason }}
                                    </p>
                                </div>
                            </div>

                            <!-- Right: Verification Action Buttons -->
                            <div
                                class="flex shrink-0 flex-col gap-2 pt-2 sm:flex-row lg:flex-col lg:pt-0"
                            >
                                <!-- Link to Live Event if Verified -->
                                <Link
                                    v-if="report.status === 'verified'"
                                    :href="`/events/${report.id}`"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3.5 py-1.5 text-xs font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                                    title="View published event in citizen feeds"
                                >
                                    <ExternalLink class="size-3.5" />
                                    <span>View on Map</span>
                                </Link>

                                <!-- Mark Under Review (if pending) -->
                                <Button
                                    v-if="report.status === 'pending'"
                                    variant="outline"
                                    size="sm"
                                    class="cursor-pointer gap-1.5 border-blue-500/30 bg-blue-500/5 text-xs font-semibold text-blue-600 hover:bg-blue-500/10 dark:text-blue-400"
                                    type="button"
                                    @click="markUnderReview(report)"
                                >
                                    <Clock class="size-3.5" />
                                    <span>Start Review</span>
                                </Button>

                                <!-- Verify Button (available if NOT rejected) -->
                                <Button
                                    v-if="
                                        report.status !== 'rejected' &&
                                        report.status !== 'verified'
                                    "
                                    size="sm"
                                    class="cursor-pointer gap-1.5 bg-emerald-600 text-xs font-semibold text-white shadow-xs hover:bg-emerald-700"
                                    type="button"
                                    @click="openVerifyModal(report)"
                                >
                                    <CheckCircle2 class="size-3.5" />
                                    <span>Verify Report</span>
                                </Button>

                                <!-- Reject Button (available if NOT rejected) -->
                                <Button
                                    v-if="report.status !== 'rejected'"
                                    variant="outline"
                                    size="sm"
                                    class="cursor-pointer gap-1.5 border-rose-500/30 text-xs font-semibold text-rose-600 hover:bg-rose-500/10 dark:text-rose-400"
                                    type="button"
                                    @click="openRejectModal(report)"
                                >
                                    <X class="size-3.5" />
                                    <span>Reject Report</span>
                                </Button>

                                <!-- Locked notice if already rejected -->
                                <div
                                    v-if="report.status === 'rejected'"
                                    class="rounded-lg border border-rose-500/20 bg-rose-500/10 px-2.5 py-1 text-center font-mono text-[10px] font-semibold text-rose-600 dark:text-rose-400"
                                >
                                    Locked (Rejected)
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div
                        v-if="reports.last_page > 1"
                        class="flex items-center justify-between border-t border-border/60 pt-4"
                    >
                        <div class="text-xs text-muted-foreground">
                            Showing page {{ reports.current_page }} of
                            {{ reports.last_page }} ({{ reports.total }}
                            reports)
                        </div>
                        <div class="flex gap-2">
                            <Link
                                v-if="reports.prev_page_url"
                                :href="reports.prev_page_url"
                                class="rounded-lg border border-border/80 bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                            >
                                &larr; Previous
                            </Link>
                            <Link
                                v-if="reports.next_page_url"
                                :href="reports.next_page_url"
                                class="rounded-lg border border-border/80 bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                            >
                                Next &rarr;
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Verification Confirmation Modal -->
        <div
            v-if="verifyingReport"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        >
            <div
                class="relative w-full max-w-lg space-y-4 rounded-2xl border border-border bg-card p-6 shadow-2xl"
            >
                <div class="flex items-start justify-between">
                    <div
                        class="flex items-center gap-2.5 text-emerald-600 dark:text-emerald-400"
                    >
                        <CheckCircle2 class="size-6" />
                        <h2 class="text-lg font-bold text-foreground">
                            Verify & Publish Incident
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="verifyingReport = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p class="text-xs leading-relaxed text-muted-foreground">
                    You are confirming that
                    <strong class="text-foreground"
                        >"{{ verifyingReport.title }}"</strong
                    >
                    in {{ verifyingReport.location }},
                    {{ verifyingReport.country }} has been validated. This will
                    immediately display the report as a verified community event
                    across the live map and public feeds.
                </p>

                <div>
                    <label
                        class="mb-1 block text-xs font-semibold text-foreground"
                    >
                        Verification Notes (Optional):
                    </label>
                    <textarea
                        v-model="verifyForm.review_notes"
                        rows="3"
                        placeholder="e.g., Cross-referenced with local disaster mitigation agency alert..."
                        class="w-full rounded-xl border border-border/80 bg-background px-3 py-2 text-xs text-foreground placeholder:text-muted-foreground/60 focus:border-emerald-500 focus:outline-none"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer"
                        type="button"
                        @click="verifyingReport = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        class="cursor-pointer gap-1.5 bg-emerald-600 font-semibold text-white hover:bg-emerald-700"
                        :disabled="verifyForm.processing"
                        type="button"
                        @click="submitVerification"
                    >
                        <Loader2
                            v-if="verifyForm.processing"
                            class="size-3.5 animate-spin"
                        />
                        <span>Confirm Verification</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Rejection Confirmation Modal -->
        <div
            v-if="rejectingReport"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        >
            <div
                class="relative w-full max-w-lg space-y-4 rounded-2xl border border-border bg-card p-6 shadow-2xl"
            >
                <div class="flex items-start justify-between">
                    <div
                        class="flex items-center gap-2.5 text-rose-600 dark:text-rose-400"
                    >
                        <X class="size-6" />
                        <h2 class="text-lg font-bold text-foreground">
                            Reject Disaster Report
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="rejectingReport = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p class="text-xs leading-relaxed text-muted-foreground">
                    You are rejecting report
                    <strong class="text-foreground"
                        >"{{ rejectingReport.title }}"</strong
                    >. Rejected reports are excluded from all public feeds and
                    cannot be re-verified.
                </p>

                <div>
                    <label
                        class="mb-1 block text-xs font-semibold text-foreground"
                    >
                        Reason for Rejection
                        <span class="text-destructive">*</span>:
                    </label>
                    <select
                        v-model="rejectForm.rejection_reason"
                        class="mb-2 w-full rounded-xl border border-border/80 bg-background px-3 py-2 text-xs text-foreground focus:border-rose-500 focus:outline-none"
                        required
                    >
                        <option value="">
                            Select standard rejection reason...
                        </option>
                        <option
                            value="Duplicate of existing official agency alert"
                        >
                            Duplicate of existing official agency alert
                        </option>
                        <option
                            value="Unverifiable observations or conflicting satellite imagery"
                        >
                            Unverifiable observations or conflicting satellite
                            imagery
                        </option>
                        <option
                            value="Incorrect location coordinates or out-of-scope jurisdiction"
                        >
                            Incorrect location coordinates or out-of-scope
                            jurisdiction
                        </option>
                        <option
                            value="Spam, fabricated details, or promotional content"
                        >
                            Spam, fabricated details, or promotional content
                        </option>
                        <option
                            value="Incident has concluded and no longer presents an active hazard"
                        >
                            Incident has concluded and no longer presents an
                            active hazard
                        </option>
                    </select>

                    <textarea
                        v-model="rejectForm.review_notes"
                        rows="2"
                        placeholder="Additional moderator comments (optional)..."
                        class="w-full rounded-xl border border-border/80 bg-background px-3 py-2 text-xs text-foreground placeholder:text-muted-foreground/60 focus:border-rose-500 focus:outline-none"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="cursor-pointer"
                        type="button"
                        @click="rejectingReport = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        class="cursor-pointer gap-1.5 bg-rose-600 font-semibold text-white hover:bg-rose-700"
                        :disabled="
                            rejectForm.processing ||
                            !rejectForm.rejection_reason
                        "
                        type="button"
                        @click="submitRejection"
                    >
                        <Loader2
                            v-if="rejectForm.processing"
                            class="size-3.5 animate-spin"
                        />
                        <span>Confirm Rejection</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Fullscreen Photo Modal -->
        <div
            v-if="activePhoto"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
            @click="activePhoto = null"
        >
            <div
                class="relative max-h-[90vh] max-w-4xl overflow-hidden rounded-2xl border border-border bg-card shadow-2xl"
                @click.stop
            >
                <button
                    type="button"
                    class="absolute top-3 right-3 z-10 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/70 text-white transition-colors hover:bg-black"
                    @click="activePhoto = null"
                >
                    <X class="size-5" />
                </button>
                <img
                    :src="activePhoto"
                    alt="Inspection Fullscreen"
                    class="max-h-[85vh] w-auto max-w-full object-contain"
                />
            </div>
        </div>

        <DisasterFooter :last-updated="null" />
    </div>
</template>
