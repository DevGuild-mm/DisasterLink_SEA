<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AlertCircle,
    ArrowLeft,
    Calendar,
    CheckCircle2,
    ChevronDown,
    Flame,
    Globe,
    HelpCircle,
    Image as ImageIcon,
    Info,
    Loader2,
    MapPin,
    PlusCircle,
    Send,
    ShieldAlert,
    Trash2,
    Upload,
    Waves,
    Wind,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';
import LocationPickerMap from '@/components/disaster/LocationPickerMap.vue';
import { Button } from '@/components/ui/button';
import type { AseanCountry } from '@/types/disaster';

const props = defineProps<{
    countries: Record<string, AseanCountry>;
    disasterTypes: string[];
}>();

const page = usePage();

// Format local datetime for the datetime-local input default (YYYY-MM-DDTHH:mm)
function getLocalDateTimeString(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60000;
    const localTime = new Date(now.getTime() - offset);
    return localTime.toISOString().slice(0, 16);
}

const form = useForm<{
    disaster_type: string;
    title: string;
    description: string;
    country: string;
    location: string;
    incident_time: string;
    latitude: number | null;
    longitude: number | null;
    photos: File[];
}>({
    disaster_type: 'Flood',
    title: '',
    description: '',
    country: 'Philippines',
    location: '',
    incident_time: getLocalDateTimeString(),
    latitude: null,
    longitude: null,
    photos: [],
});

const previewUrls = ref<string[]>([]);
const photoError = ref<string | null>(null);

const activeCountryMeta = computed<AseanCountry | null>(() => {
    return props.countries[form.country] ?? null;
});

const countryCenter = computed<[number, number] | null>(() => {
    return activeCountryMeta.value?.center ?? null;
});

const countryZoom = computed<number>(() => {
    return activeCountryMeta.value?.zoom ?? 6;
});

// Watch country change and clear coordinates if user picks a different country and coords were empty
watch(
    () => form.country,
    () => {
        // Automatically hint user to set marker in selected country
    },
);

const disasterTypeIcons: Record<string, any> = {
    Earthquake: Activity,
    Flood: Waves,
    Storm: Wind,
    Landslide: AlertCircle,
    Wildfire: Flame,
    'Volcanic Activity': ShieldAlert,
    Other: HelpCircle,
};

function selectType(type: string) {
    form.disaster_type = type;
}

function handleCoordsUpdate(coords: { latitude: number; longitude: number }) {
    form.latitude = coords.latitude;
    form.longitude = coords.longitude;
}

function handleFileInput(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) return;

    photoError.value = null;
    const files = Array.from(input.files);

    if (form.photos.length + files.length > 3) {
        photoError.value = 'You can upload a maximum of 3 photos in total.';
        input.value = '';
        return;
    }

    for (const file of files) {
        if (
            !['image/jpeg', 'image/png', 'image/webp', 'image/jpg'].includes(
                file.type,
            )
        ) {
            photoError.value = 'Only JPG, PNG, and WebP images are supported.';
            input.value = '';
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            photoError.value = `Photo "${file.name}" exceeds the 5MB size limit.`;
            input.value = '';
            return;
        }

        form.photos.push(file);
        const url = URL.createObjectURL(file);
        previewUrls.value.push(url);
    }

    input.value = '';
}

function removePhoto(index: number) {
    if (previewUrls.value[index]) {
        URL.revokeObjectURL(previewUrls.value[index]);
    }
    form.photos.splice(index, 1);
    previewUrls.value.splice(index, 1);
    photoError.value = null;
}

function submitReport() {
    form.post('/reports', {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Report Disaster Incident - Community Telemetry" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <DisasterHeader
            :last-updated="null"
            :is-refreshing="false"
            :countdown="60"
            :is-tab-visible="true"
            :active-alerts-count="0"
        />

        <main class="flex-1 pb-16">
            <div class="mx-auto max-w-4xl px-4 pt-6 sm:px-6 lg:px-8">
                <!-- Navigation Breadcrumbs -->
                <div class="mb-6 flex items-center justify-between">
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
                        v-if="$page.props.auth?.user"
                        href="/my-reports"
                        class="text-xs font-semibold text-primary hover:underline"
                    >
                        View My Previous Reports &rarr;
                    </Link>
                </div>

                <!-- Page Header Card -->
                <div
                    class="mb-8 rounded-2xl border border-border/80 bg-gradient-to-br from-card via-card to-destructive/5 p-6 shadow-sm sm:p-8"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-destructive/10 text-destructive shadow-sm ring-1 ring-destructive/25"
                        >
                            <PlusCircle class="size-6" />
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <h1
                                    class="text-xl font-bold tracking-tight text-foreground sm:text-2xl"
                                >
                                    Report Disaster Incident
                                </h1>
                                <span
                                    class="rounded-full bg-destructive/10 px-2 py-0.5 font-mono text-[10px] font-semibold text-destructive uppercase"
                                >
                                    Citizen Telemetry
                                </span>
                            </div>
                            <p
                                class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                            >
                                Submit a real-time hazard or emergency
                                observation across Southeast Asia. Every
                                community report undergoes independent moderator
                                review before being integrated into
                                DisasterLinkSEA live feeds.
                            </p>
                        </div>
                    </div>

                    <!-- Integrity Notice Banner -->
                    <div
                        class="mt-6 flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4 text-xs leading-relaxed text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-4 shrink-0 text-primary" />
                        <div>
                            <strong class="font-semibold text-foreground"
                                >Verification Notice:</strong
                            >
                            To protect public safety and prevent misinformation,
                            reports remain in
                            <span class="font-mono font-medium text-amber-500"
                                >Pending</span
                            >
                            status until verified with authoritative sources or
                            cross-checked by our verification desk. Please
                            provide accurate details and photographs.
                        </div>
                    </div>
                </div>

                <!-- Submission Form -->
                <form @submit.prevent="submitReport" class="space-y-8">
                    <!-- Section 1: Disaster Category -->
                    <div
                        class="space-y-4 rounded-2xl border border-border/80 bg-card p-6 shadow-sm"
                    >
                        <div>
                            <label
                                class="block text-sm font-semibold text-foreground"
                            >
                                Disaster Category
                                <span class="text-destructive">*</span>
                            </label>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Select the primary hazard type you are observing
                                or reporting.
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                            <button
                                v-for="type in disasterTypes"
                                :key="type"
                                type="button"
                                :class="[
                                    'flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 text-left text-xs font-semibold transition-all',
                                    form.disaster_type === type
                                        ? 'border-destructive bg-destructive/10 text-foreground shadow-sm ring-1 ring-destructive/40'
                                        : 'border-border/80 bg-muted/20 text-muted-foreground hover:bg-muted/40 hover:text-foreground',
                                ]"
                                @click="selectType(type)"
                            >
                                <component
                                    :is="disasterTypeIcons[type] || AlertCircle"
                                    :class="[
                                        'size-4 shrink-0',
                                        form.disaster_type === type
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    ]"
                                />
                                <span class="truncate">{{ type }}</span>
                            </button>
                        </div>
                        <p
                            v-if="form.errors.disaster_type"
                            class="text-xs font-medium text-destructive"
                        >
                            {{ form.errors.disaster_type }}
                        </p>
                    </div>

                    <!-- Section 2: Incident Title & Description -->
                    <div
                        class="space-y-5 rounded-2xl border border-border/80 bg-card p-6 shadow-sm"
                    >
                        <!-- Title Field -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label
                                    for="title"
                                    class="block text-sm font-semibold text-foreground"
                                >
                                    Incident Summary Title
                                    <span class="text-destructive">*</span>
                                </label>
                                <span
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ form.title.length }}/150
                                </span>
                            </div>
                            <input
                                id="title"
                                v-model="form.title"
                                type="text"
                                maxlength="150"
                                placeholder="e.g., Heavy flash flooding along main bridge in Davao City"
                                class="mt-1.5 w-full rounded-xl border border-border/80 bg-background px-3.5 py-2.5 text-sm text-foreground transition-colors placeholder:text-muted-foreground/60 focus:border-destructive focus:ring-1 focus:ring-destructive focus:outline-none"
                                :class="
                                    form.errors.title &&
                                    'border-destructive ring-1 ring-destructive'
                                "
                                required
                            />
                            <p
                                v-if="form.errors.title"
                                class="mt-1.5 text-xs font-medium text-destructive"
                            >
                                {{ form.errors.title }}
                            </p>
                        </div>

                        <!-- Description Field -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label
                                    for="description"
                                    class="block text-sm font-semibold text-foreground"
                                >
                                    Detailed Observation & Impact
                                    <span class="text-destructive">*</span>
                                </label>
                                <span
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    {{ form.description.length }}/3000
                                </span>
                            </div>
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="4"
                                maxlength="3000"
                                placeholder="Describe current weather conditions, water levels, road blockages, structural damages, or immediate evacuation alerts..."
                                class="mt-1.5 w-full resize-y rounded-xl border border-border/80 bg-background px-3.5 py-2.5 text-sm text-foreground transition-colors placeholder:text-muted-foreground/60 focus:border-destructive focus:ring-1 focus:ring-destructive focus:outline-none"
                                :class="
                                    form.errors.description &&
                                    'border-destructive ring-1 ring-destructive'
                                "
                                required
                            ></textarea>
                            <p
                                v-if="form.errors.description"
                                class="mt-1.5 text-xs font-medium text-destructive"
                            >
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </div>

                    <!-- Section 3: Geographic Location & Map Picker -->
                    <div
                        class="space-y-5 rounded-2xl border border-border/80 bg-card p-6 shadow-sm"
                    >
                        <div>
                            <h2
                                class="flex items-center gap-2 text-base font-semibold text-foreground"
                            >
                                <MapPin class="size-4 text-destructive" />
                                <span
                                    >Geographic Location & Interactive Pin</span
                                >
                            </h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                Select the ASEAN nation, type the local location
                                name, and click on the map to pin exact
                                coordinates.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <!-- Country Select -->
                            <div>
                                <label
                                    for="country"
                                    class="block text-xs font-semibold tracking-wider text-foreground uppercase"
                                >
                                    Country
                                    <span class="text-destructive">*</span>
                                </label>
                                <div class="relative mt-1.5">
                                    <select
                                        id="country"
                                        v-model="form.country"
                                        class="w-full appearance-none rounded-xl border border-border/80 bg-background px-3.5 py-2.5 pr-10 text-sm text-foreground transition-colors focus:border-destructive focus:ring-1 focus:ring-destructive focus:outline-none"
                                        required
                                    >
                                        <option
                                            v-for="(meta, name) in countries"
                                            :key="name"
                                            :value="name"
                                            :disabled="name === 'All'"
                                        >
                                            {{ meta.name }} ({{ meta.code }})
                                        </option>
                                    </select>
                                    <ChevronDown
                                        class="pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                </div>
                                <p
                                    v-if="form.errors.country"
                                    class="mt-1.5 text-xs font-medium text-destructive"
                                >
                                    {{ form.errors.country }}
                                </p>
                            </div>

                            <!-- Specific Location / Landmark -->
                            <div>
                                <label
                                    for="location"
                                    class="block text-xs font-semibold tracking-wider text-foreground uppercase"
                                >
                                    Location / Landmark
                                    <span class="text-destructive">*</span>
                                </label>
                                <input
                                    id="location"
                                    v-model="form.location"
                                    type="text"
                                    placeholder="e.g., Highway 4, Barangay Matina, Davao"
                                    class="mt-1.5 w-full rounded-xl border border-border/80 bg-background px-3.5 py-2.5 text-sm text-foreground transition-colors placeholder:text-muted-foreground/60 focus:border-destructive focus:ring-1 focus:ring-destructive focus:outline-none"
                                    :class="
                                        form.errors.location &&
                                        'border-destructive ring-1 ring-destructive'
                                    "
                                    required
                                />
                                <p
                                    v-if="form.errors.location"
                                    class="mt-1.5 text-xs font-medium text-destructive"
                                >
                                    {{ form.errors.location }}
                                </p>
                            </div>
                        </div>

                        <!-- Incident Date & Time -->
                        <div>
                            <label
                                for="incident_time"
                                class="block text-xs font-semibold tracking-wider text-foreground uppercase"
                            >
                                Incident Date & Time
                                <span class="text-destructive">*</span>
                            </label>
                            <div class="relative mt-1.5">
                                <input
                                    id="incident_time"
                                    v-model="form.incident_time"
                                    type="datetime-local"
                                    class="w-full rounded-xl border border-border/80 bg-background px-3.5 py-2.5 text-sm text-foreground transition-colors focus:border-destructive focus:ring-1 focus:ring-destructive focus:outline-none sm:w-80"
                                    :class="
                                        form.errors.incident_time &&
                                        'border-destructive ring-1 ring-destructive'
                                    "
                                    required
                                />
                            </div>
                            <p
                                v-if="form.errors.incident_time"
                                class="mt-1.5 text-xs font-medium text-destructive"
                            >
                                {{ form.errors.incident_time }}
                            </p>
                        </div>

                        <!-- Leaflet Interactive Pin Picker -->
                        <div class="space-y-3 pt-2">
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-xs font-semibold text-foreground"
                                >
                                    Map Pin Location (Click map to position):
                                </span>
                                <div
                                    v-if="
                                        form.latitude !== null &&
                                        form.longitude !== null
                                    "
                                    class="font-mono text-[11px] text-muted-foreground"
                                >
                                    Lat: {{ form.latitude.toFixed(5) }}, Lng:
                                    {{ form.longitude.toFixed(5) }}
                                </div>
                                <span
                                    v-else
                                    class="text-[11px] text-muted-foreground italic"
                                >
                                    No map coordinate placed yet
                                </span>
                            </div>

                            <LocationPickerMap
                                :model-value="{
                                    latitude: form.latitude,
                                    longitude: form.longitude,
                                }"
                                :country-center="countryCenter"
                                :country-zoom="countryZoom"
                                @update:model-value="handleCoordsUpdate"
                            />

                            <!-- Manual Coordinate Inputs for Precision -->
                            <div class="grid grid-cols-2 gap-3 pt-1">
                                <div>
                                    <label
                                        class="block text-[11px] font-medium text-muted-foreground"
                                        >Latitude (-90 to 90)</label
                                    >
                                    <input
                                        v-model.number="form.latitude"
                                        type="number"
                                        step="0.000001"
                                        min="-90"
                                        max="90"
                                        placeholder="e.g., 7.0731"
                                        class="mt-1 w-full rounded-lg border border-border/80 bg-background px-3 py-1.5 font-mono text-xs text-foreground focus:border-destructive focus:outline-none"
                                    />
                                    <p
                                        v-if="form.errors.latitude"
                                        class="mt-1 text-[11px] text-destructive"
                                    >
                                        {{ form.errors.latitude }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        class="block text-[11px] font-medium text-muted-foreground"
                                        >Longitude (-180 to 180)</label
                                    >
                                    <input
                                        v-model.number="form.longitude"
                                        type="number"
                                        step="0.000001"
                                        min="-180"
                                        max="180"
                                        placeholder="e.g., 125.6128"
                                        class="mt-1 w-full rounded-lg border border-border/80 bg-background px-3 py-1.5 font-mono text-xs text-foreground focus:border-destructive focus:outline-none"
                                    />
                                    <p
                                        v-if="form.errors.longitude"
                                        class="mt-1 text-[11px] text-destructive"
                                    >
                                        {{ form.errors.longitude }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 4: Photo / Evidence Uploads -->
                    <div
                        class="space-y-4 rounded-2xl border border-border/80 bg-card p-6 shadow-sm"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <h2
                                    class="flex items-center gap-2 text-base font-semibold text-foreground"
                                >
                                    <ImageIcon
                                        class="size-4 text-destructive"
                                    />
                                    <span
                                        >Photographic Evidence (Optional)</span
                                    >
                                </h2>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Upload up to 3 on-the-ground photos (JPG,
                                    PNG, WebP, max 5MB each).
                                </p>
                            </div>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ form.photos.length }}/3 uploaded
                            </span>
                        </div>

                        <!-- Dropzone -->
                        <div
                            v-if="form.photos.length < 3"
                            class="relative flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-border/90 bg-muted/20 px-6 py-8 text-center transition-colors hover:border-destructive/50 hover:bg-muted/30"
                        >
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/jpg"
                                multiple
                                class="absolute inset-0 size-full cursor-pointer opacity-0"
                                @change="handleFileInput"
                            />
                            <Upload
                                class="mb-2 size-8 text-muted-foreground/80"
                            />
                            <div class="text-xs font-semibold text-foreground">
                                Click or drag & drop photos here
                            </div>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                Supported formats: JPG, PNG, WebP (Max 5MB per
                                file)
                            </p>
                        </div>

                        <p
                            v-if="photoError"
                            class="text-xs font-medium text-destructive"
                        >
                            {{ photoError }}
                        </p>
                        <p
                            v-if="form.errors['photos']"
                            class="text-xs font-medium text-destructive"
                        >
                            {{ form.errors['photos'] }}
                        </p>

                        <!-- Previews -->
                        <div
                            v-if="previewUrls.length > 0"
                            class="grid grid-cols-3 gap-3 pt-2"
                        >
                            <div
                                v-for="(url, idx) in previewUrls"
                                :key="idx"
                                class="group relative aspect-video overflow-hidden rounded-xl border border-border bg-muted shadow-xs"
                            >
                                <img
                                    :src="url"
                                    alt="Preview"
                                    class="size-full object-cover"
                                />
                                <button
                                    type="button"
                                    class="absolute top-1.5 right-1.5 flex size-7 cursor-pointer items-center justify-center rounded-lg bg-black/75 text-white opacity-90 transition-opacity hover:bg-destructive hover:opacity-100"
                                    title="Remove this photo"
                                    @click="removePhoto(idx)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                                <div
                                    class="absolute bottom-1 left-1.5 rounded bg-black/60 px-1.5 py-0.5 font-mono text-[9px] text-white"
                                >
                                    Photo {{ idx + 1 }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submission Actions -->
                    <div
                        class="flex flex-col items-center justify-between gap-4 pt-2 sm:flex-row"
                    >
                        <Link
                            href="/"
                            class="order-2 text-xs font-medium text-muted-foreground hover:text-foreground sm:order-1"
                        >
                            Cancel and discard draft
                        </Link>

                        <Button
                            type="submit"
                            size="lg"
                            class="order-1 w-full cursor-pointer gap-2 bg-destructive px-8 py-2.5 font-semibold text-destructive-foreground shadow-md hover:bg-destructive/90 sm:order-2 sm:w-auto"
                            :disabled="form.processing"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            <Send v-else class="size-4" />
                            <span>{{
                                form.processing
                                    ? 'Submitting Report...'
                                    : 'Submit Incident Report'
                            }}</span>
                        </Button>
                    </div>
                </form>
            </div>
        </main>

        <DisasterFooter :last-updated="null" />
    </div>
</template>
