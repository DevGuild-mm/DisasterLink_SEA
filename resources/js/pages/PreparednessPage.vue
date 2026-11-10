<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import ContactsSection from '@/components/preparedness/ContactsSection.vue';
import type {
    Contact,
    CountryOption,
} from '@/components/preparedness/ContactsSection.vue';
import GuidesSection from '@/components/preparedness/GuidesSection.vue';
import type { Guide } from '@/components/preparedness/GuidesSection.vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';

const props = defineProps<{
    contacts: Contact[];
    guides: Guide[];
    countries: CountryOption[];
    selectedCountry: string | null;
    defaultCountry: string | null;
    selectedHazard: string | null;
    hazardTypes: string[];
}>();

/**
 * Both filters live in the URL, so refresh and Back/Forward restore them. Every change sends both current
 * values, so changing one filter never resets the other.
 *
 * `country=` (present but empty) is a deliberate "no country". It is only needed when the visitor has a saved
 * country, which would otherwise come back as the default.
 */
function visit(
    country: string | null,
    hazard: string | null,
    section: 'contacts' | 'guides',
) {
    const query: Record<string, string> = {};

    if (country !== null || props.defaultCountry !== null) {
        query.country = country ?? '';
    }

    if (hazard) {
        query.hazard = hazard;
    }

    router.get(`/preparedness#${section}`, query, {
        preserveScroll: true,
        preserveState: true,
    });
}

function changeCountry(country: string) {
    visit(country || null, props.selectedHazard, 'contacts');
}

function changeHazard(hazard: string) {
    visit(props.selectedCountry, hazard || null, 'guides');
}
</script>

<template>
    <Head title="Be Prepared – DisasterLinkSEA">
        <meta
            name="description"
            content="Emergency contacts and safety guides for earthquakes, cyclones, floods, volcanoes and wildfires in Southeast Asia."
        />
    </Head>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground antialiased"
    >
        <DisasterHeader
            :last-updated="null"
            :is-refreshing="false"
            :countdown="60"
            :is-tab-visible="true"
            :active-alerts-count="0"
        />

        <main class="flex-1 py-6 sm:py-10">
            <div class="mx-auto max-w-4xl space-y-10 px-4 sm:px-6 lg:px-8">
                <header class="space-y-3">
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
                        Be prepared
                    </h1>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        Know who to call and what to do before a disaster
                        happens. Choose your country for emergency contacts, and
                        a disaster type for safety guides.
                    </p>
                    <nav
                        aria-label="On this page"
                        class="flex flex-wrap gap-2 text-sm"
                    >
                        <a
                            href="#contacts"
                            class="rounded-full border border-border bg-card px-3 py-1 font-medium hover:bg-muted"
                            >Emergency contacts</a
                        >
                        <a
                            href="#guides"
                            class="rounded-full border border-border bg-card px-3 py-1 font-medium hover:bg-muted"
                            >Safety guides</a
                        >
                    </nav>
                </header>

                <ContactsSection
                    :contacts="contacts"
                    :countries="countries"
                    :selected-country="selectedCountry"
                    @change="changeCountry"
                />

                <GuidesSection
                    :guides="guides"
                    :hazard-types="hazardTypes"
                    :selected-hazard="selectedHazard"
                    @change="changeHazard"
                />
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
