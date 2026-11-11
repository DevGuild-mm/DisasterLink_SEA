<script setup lang="ts">
import { ExternalLink, Phone } from '@lucide/vue';

export interface Contact {
    id: number;
    agency_name: string;
    category: string;
    phone: string;
    phone_link: string;
    alt_phone: string | null;
    alt_phone_link: string | null;
    website: string | null;
    notes: string | null;
}

export interface CountryOption {
    id: number;
    iso2: string;
    name: string;
}

defineProps<{
    contacts: Contact[];
    countries: CountryOption[];
    selectedCountry: string | null;
}>();

const emit = defineEmits<{ (e: 'change', country: string): void }>();

const categoryLabels: Record<string, string> = {
    police: 'Police',
    fire: 'Fire',
    ambulance: 'Ambulance',
    disaster_agency: 'Disaster agency',
    general: 'General',
};

function onChange(event: Event) {
    emit('change', (event.target as HTMLSelectElement).value);
}
</script>

<template>
    <section id="contacts" class="scroll-mt-24 space-y-4">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <h2 class="text-xl font-bold tracking-tight">
                    Emergency contacts
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Phone numbers of emergency services and disaster agencies.
                </p>
            </div>

            <label class="flex flex-col gap-1 text-xs font-medium">
                Country
                <select
                    data-testid="contacts-country"
                    class="h-9 rounded-md border border-border bg-card px-3 text-sm"
                    :value="selectedCountry ?? ''"
                    @change="onChange"
                >
                    <option value="">Choose a country</option>
                    <option
                        v-for="country in countries"
                        :key="country.id"
                        :value="country.iso2"
                    >
                        {{ country.name }}
                    </option>
                </select>
            </label>
        </div>

        <p
            v-if="!selectedCountry"
            data-testid="contacts-empty-no-country"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            Choose a country to see its emergency contacts.
        </p>

        <p
            v-else-if="contacts.length === 0"
            data-testid="contacts-empty"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            No emergency contacts have been published for this country yet.
        </p>

        <ul v-else class="grid gap-3 sm:grid-cols-2">
            <li
                v-for="contact in contacts"
                :key="contact.id"
                class="rounded-xl border border-border bg-card p-4"
            >
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ categoryLabels[contact.category] ?? contact.category }}
                </p>
                <h3 class="mt-0.5 font-semibold">{{ contact.agency_name }}</h3>

                <a
                    :href="contact.phone_link"
                    class="mt-2 inline-flex items-center gap-1.5 text-lg font-bold text-primary"
                >
                    <Phone class="size-4" />
                    {{ contact.phone }}
                </a>
                <a
                    v-if="contact.alt_phone && contact.alt_phone_link"
                    :href="contact.alt_phone_link"
                    class="mt-1 block text-sm text-primary"
                    >Alternative: {{ contact.alt_phone }}</a
                >

                <p
                    v-if="contact.notes"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ contact.notes }}
                </p>

                <a
                    v-if="contact.website"
                    :href="contact.website"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-2 inline-flex items-center gap-1 text-sm text-primary underline"
                >
                    Website <ExternalLink class="size-3.5" />
                </a>
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">
            Numbers are entered by administrators. Check them against your
            national authority before relying on them.
        </p>
    </section>
</template>
