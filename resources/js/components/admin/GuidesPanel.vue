<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

interface Guide {
    id: number;
    title: string;
    slug: string;
    hazard_type: string;
    summary: string;
    content: string;
    country_id: number | null;
    country: string | null;
    is_published: boolean;
    sort_order: number;
}

interface CountryOption {
    id: number;
    iso2: string;
    name: string;
}

const props = defineProps<{
    guides: Guide[];
    countries: CountryOption[];
    hazardTypes: string[];
    filters: { hazard: string; guide_country: string; contact_country: string };
}>();

const editingId = ref<number | 'new' | null>(null);
const viewingId = ref<number | null>(null);

const form = useForm({
    title: '',
    hazard_type: 'general',
    country_id: null as number | null,
    summary: '',
    content: '',
    is_published: false,
    sort_order: 0,
});

const isEditing = computed(() => editingId.value !== null);

function startCreate() {
    form.reset();
    form.clearErrors();
    editingId.value = 'new';
}

function startEdit(guide: Guide) {
    form.clearErrors();
    form.title = guide.title;
    form.hazard_type = guide.hazard_type;
    form.country_id = guide.country_id;
    form.summary = guide.summary;
    form.content = guide.content;
    form.is_published = guide.is_published;
    form.sort_order = guide.sort_order;
    editingId.value = guide.id;
}

function cancel() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => cancel(),
    };

    if (editingId.value === 'new') {
        form.post('/admin/safety-guides', options);
    } else {
        form.put(`/admin/safety-guides/${editingId.value}`, options);
    }
}

function remove(guide: Guide) {
    if (!window.confirm(`Delete the guide "${guide.title}"?`)) {
        return;
    }

    router.delete(`/admin/safety-guides/${guide.id}`, {
        preserveScroll: true,
    });
}

function togglePublished(guide: Guide) {
    router.put(
        `/admin/safety-guides/${guide.id}`,
        {
            title: guide.title,
            hazard_type: guide.hazard_type,
            country_id: guide.country_id,
            summary: guide.summary,
            content: guide.content,
            is_published: !guide.is_published,
            sort_order: guide.sort_order,
        },
        { preserveScroll: true },
    );
}

function applyFilter(key: 'hazard' | 'guide_country', event: Event) {
    router.get(
        '/admin',
        { ...props.filters, [key]: (event.target as HTMLSelectElement).value },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['guides', 'contacts', 'filters'],
        },
    );
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1 text-xs font-medium">
                Hazard
                <select
                    class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                    :value="filters.hazard"
                    @change="applyFilter('hazard', $event)"
                >
                    <option value="">All hazards</option>
                    <option v-for="h in hazardTypes" :key="h" :value="h">
                        {{ h }}
                    </option>
                </select>
            </label>
            <label class="flex flex-col gap-1 text-xs font-medium">
                Country
                <select
                    class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                    :value="filters.guide_country"
                    @change="applyFilter('guide_country', $event)"
                >
                    <option value="">Any</option>
                    <option value="all">All countries (global)</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </label>
            <Button class="ml-auto" size="sm" @click="startCreate"
                >New guide</Button
            >
        </div>

        <form
            v-if="isEditing"
            class="space-y-3 rounded-xl border border-border bg-card p-4"
            @submit.prevent="submit"
        >
            <h3 class="font-semibold">
                {{ editingId === 'new' ? 'New safety guide' : 'Edit guide' }}
            </h3>

            <label class="block text-xs font-medium">
                Title
                <input
                    v-model="form.title"
                    type="text"
                    maxlength="255"
                    class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                />
                <span v-if="form.errors.title" class="text-destructive">{{
                    form.errors.title
                }}</span>
            </label>

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block text-xs font-medium">
                    Hazard
                    <select
                        v-model="form.hazard_type"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    >
                        <option v-for="h in hazardTypes" :key="h" :value="h">
                            {{ h }}
                        </option>
                    </select>
                    <span
                        v-if="form.errors.hazard_type"
                        class="text-destructive"
                        >{{ form.errors.hazard_type }}</span
                    >
                </label>
                <label class="block text-xs font-medium">
                    Country
                    <select
                        v-model="form.country_id"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    >
                        <option :value="null">All countries</option>
                        <option
                            v-for="c in countries"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.name }}
                        </option>
                    </select>
                    <span
                        v-if="form.errors.country_id"
                        class="text-destructive"
                        >{{ form.errors.country_id }}</span
                    >
                </label>
                <label class="block text-xs font-medium">
                    Order
                    <input
                        v-model.number="form.sort_order"
                        type="number"
                        min="0"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    />
                </label>
            </div>

            <label class="block text-xs font-medium">
                Summary
                <input
                    v-model="form.summary"
                    type="text"
                    maxlength="500"
                    class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                />
                <span v-if="form.errors.summary" class="text-destructive">{{
                    form.errors.summary
                }}</span>
            </label>

            <label class="block text-xs font-medium">
                Content (Markdown: headings, lists and links. HTML is removed.)
                <textarea
                    v-model="form.content"
                    rows="10"
                    maxlength="20000"
                    class="mt-1 w-full rounded-md border border-border bg-background p-3 font-mono text-sm"
                ></textarea>
                <span v-if="form.errors.content" class="text-destructive">{{
                    form.errors.content
                }}</span>
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_published" type="checkbox" />
                Published (visible to the public)
            </label>

            <div class="flex gap-2">
                <Button type="submit" size="sm" :disabled="form.processing"
                    >Save</Button
                >
                <Button type="button" variant="outline" size="sm" @click="cancel"
                    >Cancel</Button
                >
            </div>
        </form>

        <div
            v-if="guides.length === 0"
            class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No guides match. Create the first one.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs uppercase">
                    <tr>
                        <th class="p-3">Title</th>
                        <th class="p-3">Hazard</th>
                        <th class="p-3">Country</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="guide in guides" :key="guide.id">
                        <tr class="border-t border-border">
                            <td class="p-3 font-medium">{{ guide.title }}</td>
                            <td class="p-3">{{ guide.hazard_type }}</td>
                            <td class="p-3">
                                {{ guide.country ?? 'All countries' }}
                            </td>
                            <td class="p-3">
                                <span
                                    :class="
                                        guide.is_published
                                            ? 'text-emerald-600'
                                            : 'text-amber-600'
                                    "
                                    >{{
                                        guide.is_published
                                            ? 'Published'
                                            : 'Draft'
                                    }}</span
                                >
                            </td>
                            <td class="space-x-2 p-3 text-right whitespace-nowrap">
                                <button
                                    class="text-xs underline"
                                    @click="
                                        viewingId =
                                            viewingId === guide.id
                                                ? null
                                                : guide.id
                                    "
                                >
                                    View
                                </button>
                                <button
                                    class="text-xs underline"
                                    @click="startEdit(guide)"
                                >
                                    Edit
                                </button>
                                <button
                                    class="text-xs underline"
                                    @click="togglePublished(guide)"
                                >
                                    {{
                                        guide.is_published
                                            ? 'Unpublish'
                                            : 'Publish'
                                    }}
                                </button>
                                <button
                                    class="text-xs text-destructive underline"
                                    @click="remove(guide)"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="viewingId === guide.id" class="bg-muted/30">
                            <td colspan="5" class="p-3 text-sm">
                                <p class="mb-2 text-muted-foreground">
                                    {{ guide.summary }}
                                </p>
                                <pre
                                    class="font-sans whitespace-pre-wrap"
                                    >{{ guide.content }}</pre
                                >
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
