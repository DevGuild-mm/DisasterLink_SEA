<script setup lang="ts">
import { computed } from 'vue';

export interface Guide {
    id: number;
    title: string;
    slug: string;
    hazard_type: string;
    summary: string;
    content_html: string;
    country: string | null;
}

const props = defineProps<{
    guides: Guide[];
    hazardTypes: string[];
    selectedHazard: string | null;
}>();

const emit = defineEmits<{ (e: 'change', hazard: string): void }>();

const hazardLabels: Record<string, string> = {
    earthquake: 'Earthquakes',
    cyclone: 'Tropical cyclones & storms',
    flood: 'Floods',
    volcano: 'Volcanoes',
    wildfire: 'Wildfires',
    drought: 'Drought',
    general: 'General preparedness',
};

// All types: one group per disaster type. One type: just that group.
const groups = computed(() =>
    props.hazardTypes
        .map((type) => ({
            type,
            label: hazardLabels[type] ?? type,
            guides: props.guides.filter((g) => g.hazard_type === type),
        }))
        .filter((group) => group.guides.length > 0),
);

const selectedLabel = computed(() =>
    props.selectedHazard
        ? (hazardLabels[props.selectedHazard] ?? props.selectedHazard)
        : null,
);

function onChange(event: Event) {
    emit('change', (event.target as HTMLSelectElement).value);
}
</script>

<template>
    <section id="guides" class="scroll-mt-24 space-y-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h2 class="text-xl font-bold tracking-tight">Safety guides</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    What to do before, during and after a disaster.
                </p>
            </div>

            <label class="flex flex-col gap-1 text-xs font-medium">
                Disaster type
                <select
                    data-testid="guides-hazard"
                    class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                    :value="selectedHazard ?? ''"
                    @change="onChange"
                >
                    <option value="">All types</option>
                    <option
                        v-for="type in hazardTypes"
                        :key="type"
                        :value="type"
                    >
                        {{ hazardLabels[type] ?? type }}
                    </option>
                </select>
            </label>
        </div>

        <p
            v-if="groups.length === 0"
            data-testid="guides-empty"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            <template v-if="selectedLabel"
                >No safety guides have been published for
                {{ selectedLabel.toLowerCase() }} yet.</template
            >
            <template v-else>No safety guides have been published yet.</template>
        </p>

        <div v-for="group in groups" :key="group.type" class="space-y-3">
            <h3
                v-if="!selectedHazard"
                class="text-base font-semibold text-foreground"
            >
                {{ group.label }}
            </h3>

            <details
                v-for="guide in group.guides"
                :key="guide.id"
                class="rounded-xl border border-border bg-card"
            >
                <summary
                    class="flex cursor-pointer list-none flex-col gap-1 p-4 marker:hidden"
                >
                    <span class="font-medium">{{ guide.title }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        guide.summary
                    }}</span>
                    <span
                        v-if="guide.country"
                        data-testid="guide-country"
                        class="w-fit rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >{{ guide.country }}</span
                    >
                </summary>
                <!-- Server-rendered Markdown: raw HTML is stripped and unsafe links are removed. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    class="guide-content border-t border-border p-4 text-sm leading-relaxed"
                    v-html="guide.content_html"
                ></div>
            </details>
        </div>

        <p class="text-xs text-muted-foreground">
            General guidance only. In an emergency, follow the instructions of
            your national disaster management agency and local authorities.
        </p>
    </section>
</template>

<style scoped>
.guide-content :deep(h1),
.guide-content :deep(h2),
.guide-content :deep(h3) {
    margin-top: 1rem;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.guide-content :deep(h2) {
    font-size: 1rem;
}

.guide-content :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.guide-content :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.guide-content :deep(p) {
    margin-bottom: 0.5rem;
}

.guide-content :deep(a) {
    text-decoration: underline;
}
</style>
