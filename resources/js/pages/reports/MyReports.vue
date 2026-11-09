<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    ArrowLeft,
    Calendar,
    CheckCircle2,
    Clock,
    ExternalLink,
    Eye,
    Flame,
    HelpCircle,
    Image as ImageIcon,
    Info,
    MapPin,
    PlusCircle,
    ShieldAlert,
    Trash2,
    Waves,
    Wind,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import { Button } from '@/components/ui/button';
import type { AseanCountry, UserReportItem } from '@/types/disaster';

const props = defineProps<{
    reports: UserReportItem[];
    countries: Record<string, AseanCountry>;
}>();

const activeFilter = ref<
    'all' | 'pending' | 'under_review' | 'verified' | 'rejected'
>('all');
const selectedPhoto = ref<string | null>(null);
const withdrawingId = ref<string | null>(null);

const filteredReports = computed(() => {
    if (activeFilter.value === 'all') return props.reports;
    return props.reports.filter((r) => r.status === activeFilter.value);
});

const counts = computed(() => {
    const list = props.reports;
    return {
        all: list.length,
        pending: list.filter((r) => r.status === 'pending').length,
        under_review: list.filter((r) => r.status === 'under_review').length,
        verified: list.filter((r) => r.status === 'verified').length,
        rejected: list.filter((r) => r.status === 'rejected').length,
    };
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

function withdrawReport(report: UserReportItem) {
    if (
        !confirm(
            `Are you sure you want to withdraw and delete report "${report.title}"? This action cannot be undone.`,
        )
    ) {
        return;
    }

    withdrawingId.value = report.id;
    router.delete(`/my-reports/${report.id}`, {
        preserveScroll: true,
        onFinish: () => {
            withdrawingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="My Disaster Reports - DisasterLinkSEA" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <DisasterHeader
            :last-updated="null"
            :is-refreshing="false"
            :countdown="60"
            :is-tab-visible="true"
            :active-alerts-count="0"
        />

        <main class="flex-1 pb-16">
            <div class="mx-auto max-w-6xl px-4 pt-6 sm:px-6 lg:px-8">
                <!-- Top Breadcrumb & Action -->
                <div
                    class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Link
                        href="/"
                        class="group inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft
                            class="size-3.5 transition-transform group-hover:-translate-x-0.5"
                        />
                        <span>Back to Live Dashboard</span>
                    </Link>

                    <Link
                        href="/reports/create"
                        class="inline-flex items-center gap-2 rounded-xl bg-destructive px-4 py-2 text-xs font-semibold text-destructive-foreground shadow-sm transition-colors hover:bg-destructive/90"
                    >
                        <PlusCircle class="size-4" />
                        <span>Submit New Report</span>
                    </Link>
                </div>

                <!-- Page Header Card -->
                <div
                    class="mb-8 rounded-2xl border border-border/80 bg-gradient-to-br from-card via-card to-primary/5 p-6 shadow-sm sm:p-8"
                >
                    <div
                        class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
                    >
                        <div>
                            <h1
                                class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                            >
                                My Disaster Incident Reports
                            </h1>
                            <p
                                class="mt-1 text-xs text-muted-foreground sm:text-sm"
                            >
                                Track the verification status and administrative
                                review of your citizen observations.
                            </p>
                        </div>
                    </div>

                    <!-- Filter Tabs -->
                    <div
                        class="mt-6 flex flex-wrap gap-2 border-t border-border/60 pt-5"
                    >
                        <button
                            type="button"
                            :class="[
                                'cursor-pointer rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                                activeFilter === 'all'
                                    ? 'bg-foreground font-semibold text-background shadow-xs'
                                    : 'bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground',
                            ]"
                            @click="activeFilter = 'all'"
                        >
                            All Reports ({{ counts.all }})
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                                activeFilter === 'pending'
                                    ? 'bg-amber-500 font-semibold text-white shadow-xs'
                                    : 'bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 dark:text-amber-400',
                            ]"
                            @click="activeFilter = 'pending'"
                        >
                            <span
                                class="size-1.5 rounded-full bg-amber-400"
                            ></span>
                            <span>Pending ({{ counts.pending }})</span>
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                                activeFilter === 'under_review'
                                    ? 'bg-blue-500 font-semibold text-white shadow-xs'
                                    : 'bg-blue-500/10 text-blue-600 hover:bg-blue-500/20 dark:text-blue-400',
                            ]"
                            @click="activeFilter = 'under_review'"
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
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                                activeFilter === 'verified'
                                    ? 'bg-emerald-500 font-semibold text-white shadow-xs'
                                    : 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 dark:text-emerald-400',
                            ]"
                            @click="activeFilter = 'verified'"
                        >
                            <span
                                class="size-1.5 rounded-full bg-emerald-400"
                            ></span>
                            <span>Verified ({{ counts.verified }})</span>
                        </button>
                        <button
                            type="button"
                            :class="[
                                'flex cursor-pointer items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                                activeFilter === 'rejected'
                                    ? 'bg-rose-500 font-semibold text-white shadow-xs'
                                    : 'bg-rose-500/10 text-rose-600 hover:bg-rose-500/20 dark:text-rose-400',
                            ]"
                            @click="activeFilter = 'rejected'"
                        >
                            <span
                                class="size-1.5 rounded-full bg-rose-400"
                            ></span>
                            <span>Rejected ({{ counts.rejected }})</span>
                        </button>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="filteredReports.length === 0"
                    class="rounded-2xl border border-dashed border-border/80 bg-card/50 p-12 text-center"
                >
                    <div
                        class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                    >
                        <AlertCircle class="size-6" />
                    </div>
                    <h3 class="text-sm font-semibold text-foreground">
                        No reports found
                    </h3>
                    <p
                        class="mx-auto mt-1 max-w-sm text-xs text-muted-foreground"
                    >
                        <span v-if="activeFilter === 'all'">
                            You have not submitted any disaster incident reports
                            yet.
                        </span>
                        <span v-else>
                            No reports currently match the "{{ activeFilter }}"
                            filter.
                        </span>
                    </p>
                    <div class="mt-5">
                        <Link
                            href="/reports/create"
                            class="inline-flex items-center gap-2 rounded-xl bg-destructive px-4 py-2 text-xs font-semibold text-destructive-foreground hover:bg-destructive/90"
                        >
                            <PlusCircle class="size-4" />
                            <span>Submit a Disaster Observation</span>
                        </Link>
                    </div>
                </div>

                <!-- Reports Cards Grid -->
                <div v-else class="space-y-4">
                    <div
                        v-for="report in filteredReports"
                        :key="report.id"
                        class="overflow-hidden rounded-2xl border border-border/80 bg-card p-5 shadow-sm transition-shadow hover:shadow-md sm:p-6"
                    >
                        <div
                            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start"
                        >
                            <!-- Left: Header info & category -->
                            <div class="flex-1 space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <!-- Category Pill -->
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-md border border-border/60 bg-muted/60 px-2 py-0.5 text-xs font-semibold text-foreground"
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

                                    <!-- Report ID -->
                                    <span
                                        class="font-mono text-[10px] text-muted-foreground/70"
                                    >
                                        ID: {{ report.id }}
                                    </span>
                                </div>

                                <h2
                                    class="pt-1 text-base font-bold text-foreground sm:text-lg"
                                >
                                    {{ report.title }}
                                </h2>

                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-1 pt-0.5 text-xs text-muted-foreground"
                                >
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
                                        <Calendar class="size-3.5 shrink-0" />
                                        <span
                                            >Observed:
                                            {{
                                                formatDate(report.incident_time)
                                            }}</span
                                        >
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1 font-mono text-[11px]"
                                    >
                                        <Clock class="size-3 shrink-0" />
                                        <span
                                            >Submitted:
                                            {{
                                                formatDate(report.created_at)
                                            }}</span
                                        >
                                    </span>
                                </div>
                            </div>

                            <!-- Right: Action Buttons -->
                            <div class="flex shrink-0 items-center gap-2">
                                <!-- View Public Event Link for Verified Reports -->
                                <Link
                                    v-if="report.status === 'verified'"
                                    :href="`/events/${report.id}`"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                                    title="View public incident page"
                                >
                                    <ExternalLink class="size-3.5" />
                                    <span>View on Map</span>
                                </Link>

                                <!-- Withdraw Button for Pending Reports -->
                                <Button
                                    v-if="report.status === 'pending'"
                                    variant="outline"
                                    size="sm"
                                    class="cursor-pointer gap-1.5 border-border/80 text-xs text-destructive hover:border-destructive/40 hover:bg-destructive/10"
                                    :disabled="withdrawingId === report.id"
                                    @click="withdrawReport(report)"
                                >
                                    <Trash2 class="size-3.5" />
                                    <span>{{
                                        withdrawingId === report.id
                                            ? 'Withdrawing...'
                                            : 'Withdraw Report'
                                    }}</span>
                                </Button>
                            </div>
                        </div>

                        <!-- Description snippet -->
                        <p
                            class="mt-3 text-xs leading-relaxed text-muted-foreground sm:text-sm"
                        >
                            {{ report.description }}
                        </p>

                        <!-- Latitude / Longitude & Photos row -->
                        <div
                            class="mt-4 flex flex-col justify-between gap-3 border-t border-border/60 pt-3 sm:flex-row sm:items-center"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    v-if="
                                        report.latitude !== null &&
                                        report.longitude !== null
                                    "
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    Coordinates:
                                    {{ Number(report.latitude).toFixed(4) }}°,
                                    {{ Number(report.longitude).toFixed(4) }}°
                                </span>
                                <span
                                    v-else
                                    class="text-[11px] text-muted-foreground/60 italic"
                                >
                                    No exact GPS coordinates provided
                                </span>
                            </div>

                            <!-- Photos row -->
                            <div
                                v-if="report.photos && report.photos.length > 0"
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground"
                                >
                                    <ImageIcon class="size-3" />
                                    <span
                                        >{{ report.photos.length }} Photo{{
                                            report.photos.length > 1 ? 's' : ''
                                        }}:</span
                                    >
                                </span>
                                <div class="flex gap-1.5">
                                    <button
                                        v-for="(
                                            photoUrl, pIdx
                                        ) in report.photos"
                                        :key="pIdx"
                                        type="button"
                                        class="group relative size-9 cursor-pointer overflow-hidden rounded-md border border-border bg-muted transition-all hover:ring-2 hover:ring-primary"
                                        title="Click to view full photo"
                                        @click="selectedPhoto = photoUrl"
                                    >
                                        <img
                                            :src="photoUrl"
                                            alt="Observation Photo"
                                            class="size-full object-cover transition-transform group-hover:scale-110"
                                        />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Admin Feedback Callout (if review notes or rejection reason exist) -->
                        <div
                            v-if="
                                report.review_notes || report.rejection_reason
                            "
                            class="mt-4 rounded-xl p-3.5 text-xs"
                            :class="[
                                report.status === 'verified'
                                    ? 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-950 dark:text-emerald-200'
                                    : report.status === 'rejected'
                                      ? 'border border-rose-500/30 bg-rose-500/10 text-rose-950 dark:text-rose-200'
                                      : 'border border-border/70 bg-muted/40 text-foreground',
                            ]"
                        >
                            <div
                                class="mb-1 flex items-center gap-1.5 font-semibold"
                            >
                                <Info class="size-3.5" />
                                <span>Verification Desk Feedback:</span>
                                <span
                                    v-if="report.verified_at"
                                    class="font-mono text-[10px] font-normal opacity-75"
                                >
                                    ({{ formatDate(report.verified_at) }})
                                </span>
                            </div>
                            <p
                                v-if="report.review_notes"
                                class="leading-relaxed"
                            >
                                <strong class="font-medium">Notes:</strong>
                                {{ report.review_notes }}
                            </p>
                            <p
                                v-if="report.rejection_reason"
                                class="leading-relaxed font-medium"
                            >
                                <strong class="font-semibold">Reason:</strong>
                                {{ report.rejection_reason }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Fullscreen Photo Modal -->
        <div
            v-if="selectedPhoto"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            @click="selectedPhoto = null"
        >
            <div
                class="relative max-h-[90vh] max-w-4xl overflow-hidden rounded-2xl border border-border bg-card shadow-2xl"
                @click.stop
            >
                <button
                    type="button"
                    class="absolute top-3 right-3 z-10 flex size-8 cursor-pointer items-center justify-center rounded-full bg-black/70 text-white transition-colors hover:bg-black"
                    @click="selectedPhoto = null"
                >
                    <X class="size-5" />
                </button>
                <img
                    :src="selectedPhoto"
                    alt="Disaster Observation Fullscreen"
                    class="max-h-[85vh] w-auto max-w-full object-contain"
                />
            </div>
        </div>

        <DisasterFooter :last-updated="null" />
    </div>
</template>
