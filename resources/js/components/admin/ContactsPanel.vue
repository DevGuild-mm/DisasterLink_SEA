<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

interface Contact {
    id: number;
    country_id: number;
    country: string | null;
    agency_name: string;
    category: string;
    phone: string;
    alt_phone: string | null;
    website: string | null;
    notes: string | null;
    is_published: boolean;
    sort_order: number;
}

interface CountryOption {
    id: number;
    iso2: string;
    name: string;
}

const props = defineProps<{
    contacts: Contact[];
    countries: CountryOption[];
    categories: string[];
    filters: { hazard: string; guide_country: string; contact_country: string };
}>();

const editingId = ref<number | 'new' | null>(null);
const viewingId = ref<number | null>(null);

const form = useForm({
    country_id: null as number | null,
    agency_name: '',
    category: 'general',
    phone: '',
    alt_phone: '',
    website: '',
    notes: '',
    is_published: false,
    sort_order: 0,
});

const isEditing = computed(() => editingId.value !== null);

function startCreate() {
    form.reset();
    form.clearErrors();
    editingId.value = 'new';
}

function startEdit(contact: Contact) {
    form.clearErrors();
    form.country_id = contact.country_id;
    form.agency_name = contact.agency_name;
    form.category = contact.category;
    form.phone = contact.phone;
    form.alt_phone = contact.alt_phone ?? '';
    form.website = contact.website ?? '';
    form.notes = contact.notes ?? '';
    form.is_published = contact.is_published;
    form.sort_order = contact.sort_order;
    editingId.value = contact.id;
}

function cancel() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => cancel() };

    if (editingId.value === 'new') {
        form.post('/admin/emergency-contacts', options);
    } else {
        form.put(`/admin/emergency-contacts/${editingId.value}`, options);
    }
}

function remove(contact: Contact) {
    if (!window.confirm(`Delete the contact "${contact.agency_name}"?`)) {
        return;
    }

    router.delete(`/admin/emergency-contacts/${contact.id}`, {
        preserveScroll: true,
    });
}

function togglePublished(contact: Contact) {
    router.put(
        `/admin/emergency-contacts/${contact.id}`,
        {
            country_id: contact.country_id,
            agency_name: contact.agency_name,
            category: contact.category,
            phone: contact.phone,
            alt_phone: contact.alt_phone,
            website: contact.website,
            notes: contact.notes,
            is_published: !contact.is_published,
            sort_order: contact.sort_order,
        },
        { preserveScroll: true },
    );
}

function applyFilter(event: Event) {
    router.get(
        '/admin',
        {
            ...props.filters,
            contact_country: (event.target as HTMLSelectElement).value,
        },
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
                Country
                <select
                    class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                    :value="filters.contact_country"
                    @change="applyFilter"
                >
                    <option value="">All countries</option>
                    <option v-for="c in countries" :key="c.id" :value="c.id">
                        {{ c.name }}
                    </option>
                </select>
            </label>
            <Button class="ml-auto" size="sm" @click="startCreate"
                >New contact</Button
            >
        </div>

        <form
            v-if="isEditing"
            class="space-y-3 rounded-xl border border-border bg-card p-4"
            @submit.prevent="submit"
        >
            <h3 class="font-semibold">
                {{
                    editingId === 'new'
                        ? 'New emergency contact'
                        : 'Edit contact'
                }}
            </h3>

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block text-xs font-medium">
                    Country
                    <select
                        v-model="form.country_id"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    >
                        <option :value="null" disabled>Choose…</option>
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
                <label class="block text-xs font-medium sm:col-span-2">
                    Agency
                    <input
                        v-model="form.agency_name"
                        type="text"
                        maxlength="255"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    />
                    <span
                        v-if="form.errors.agency_name"
                        class="text-destructive"
                        >{{ form.errors.agency_name }}</span
                    >
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block text-xs font-medium">
                    Category
                    <select
                        v-model="form.category"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    >
                        <option v-for="c in categories" :key="c" :value="c">
                            {{ c }}
                        </option>
                    </select>
                </label>
                <label class="block text-xs font-medium">
                    Phone
                    <input
                        v-model="form.phone"
                        type="text"
                        maxlength="50"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    />
                    <span v-if="form.errors.phone" class="text-destructive">{{
                        form.errors.phone
                    }}</span>
                </label>
                <label class="block text-xs font-medium">
                    Alternative phone
                    <input
                        v-model="form.alt_phone"
                        type="text"
                        maxlength="50"
                        class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                    />
                    <span
                        v-if="form.errors.alt_phone"
                        class="text-destructive"
                        >{{ form.errors.alt_phone }}</span
                    >
                </label>
            </div>

            <label class="block text-xs font-medium">
                Website (https only)
                <input
                    v-model="form.website"
                    type="url"
                    maxlength="500"
                    class="mt-1 h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                />
                <span v-if="form.errors.website" class="text-destructive">{{
                    form.errors.website
                }}</span>
            </label>

            <label class="block text-xs font-medium">
                Notes
                <textarea
                    v-model="form.notes"
                    rows="3"
                    maxlength="1000"
                    class="mt-1 w-full rounded-md border border-border bg-background p-3 text-sm"
                ></textarea>
            </label>

            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_published" type="checkbox" />
                    Published
                </label>
                <label class="flex items-center gap-2 text-xs">
                    Order
                    <input
                        v-model.number="form.sort_order"
                        type="number"
                        min="0"
                        class="h-8 w-20 rounded-md border border-border bg-background px-2 text-sm"
                    />
                </label>
            </div>

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
            v-if="contacts.length === 0"
            class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No contacts match. Create the first one.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-xs uppercase">
                    <tr>
                        <th class="p-3">Agency</th>
                        <th class="p-3">Country</th>
                        <th class="p-3">Category</th>
                        <th class="p-3">Phone</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="contact in contacts" :key="contact.id">
                        <tr class="border-t border-border">
                            <td class="p-3 font-medium">
                                {{ contact.agency_name }}
                            </td>
                            <td class="p-3">{{ contact.country }}</td>
                            <td class="p-3">{{ contact.category }}</td>
                            <td class="p-3">{{ contact.phone }}</td>
                            <td class="p-3">
                                <span
                                    :class="
                                        contact.is_published
                                            ? 'text-emerald-600'
                                            : 'text-amber-600'
                                    "
                                    >{{
                                        contact.is_published
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
                                            viewingId === contact.id
                                                ? null
                                                : contact.id
                                    "
                                >
                                    View
                                </button>
                                <button
                                    class="text-xs underline"
                                    @click="startEdit(contact)"
                                >
                                    Edit
                                </button>
                                <button
                                    class="text-xs underline"
                                    @click="togglePublished(contact)"
                                >
                                    {{
                                        contact.is_published
                                            ? 'Unpublish'
                                            : 'Publish'
                                    }}
                                </button>
                                <button
                                    class="text-xs text-destructive underline"
                                    @click="remove(contact)"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr
                            v-if="viewingId === contact.id"
                            class="bg-muted/30"
                        >
                            <td colspan="6" class="space-y-1 p-3 text-sm">
                                <p v-if="contact.alt_phone">
                                    Alternative phone: {{ contact.alt_phone }}
                                </p>
                                <p v-if="contact.website">
                                    Website: {{ contact.website }}
                                </p>
                                <p v-if="contact.notes">{{ contact.notes }}</p>
                                <p
                                    v-if="
                                        !contact.alt_phone &&
                                        !contact.website &&
                                        !contact.notes
                                    "
                                    class="text-muted-foreground"
                                >
                                    No further details.
                                </p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
