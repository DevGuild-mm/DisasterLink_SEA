<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import ContactsPanel from '@/components/admin/ContactsPanel.vue';
import FreshnessPanel from '@/components/admin/FreshnessPanel.vue';
import GuidesPanel from '@/components/admin/GuidesPanel.vue';
import DisasterFooter from '@/components/disaster/DisasterFooter.vue';
import DisasterHeader from '@/components/disaster/DisasterHeader.vue';

defineProps<{
    overview: {
        users_total: number;
        users_verified: number;
        users_admin: number;
        guides_total: number;
        guides_published: number;
        contacts_total: number;
        contacts_published: number;
    };
    guides: Array<{
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
    }>;
    contacts: Array<{
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
    }>;
    countries: Array<{ id: number; iso2: string; name: string }>;
    hazardTypes: string[];
    categories: string[];
    filters: { hazard: string; guide_country: string; contact_country: string };
}>();

type Tab = 'overview' | 'guides' | 'contacts' | 'freshness';

const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'overview', label: 'Overview' },
    { id: 'guides', label: 'Safety Guides' },
    { id: 'contacts', label: 'Emergency Contacts' },
    { id: 'freshness', label: 'Data Freshness' },
];

const tab = ref<Tab>('overview');
</script>

<template>
    <Head title="Admin – DisasterLinkSEA" />

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
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <h1 class="text-2xl font-bold tracking-tight">Admin</h1>

                <div
                    class="flex flex-wrap gap-1 border-b border-border"
                    role="tablist"
                >
                    <button
                        v-for="item in tabs"
                        :key="item.id"
                        role="tab"
                        :aria-selected="tab === item.id"
                        :class="[
                            '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                            tab === item.id
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        @click="tab = item.id"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <section
                    v-if="tab === 'overview'"
                    class="grid gap-4 sm:grid-cols-3"
                >
                    <article class="rounded-xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground uppercase">
                            Registered users
                        </p>
                        <p class="text-3xl font-bold">
                            {{ overview.users_total }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ overview.users_verified }} verified ·
                            {{ overview.users_admin }} admin
                        </p>
                    </article>
                    <article class="rounded-xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground uppercase">
                            Safety guides
                        </p>
                        <p class="text-3xl font-bold">
                            {{ overview.guides_total }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ overview.guides_published }} published
                        </p>
                    </article>
                    <article class="rounded-xl border border-border bg-card p-4">
                        <p class="text-xs text-muted-foreground uppercase">
                            Emergency contacts
                        </p>
                        <p class="text-3xl font-bold">
                            {{ overview.contacts_total }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ overview.contacts_published }} published
                        </p>
                    </article>
                </section>

                <GuidesPanel
                    v-else-if="tab === 'guides'"
                    :guides="guides"
                    :countries="countries"
                    :hazard-types="hazardTypes"
                    :filters="filters"
                />

                <ContactsPanel
                    v-else-if="tab === 'contacts'"
                    :contacts="contacts"
                    :countries="countries"
                    :categories="categories"
                    :filters="filters"
                />

                <FreshnessPanel v-else />
            </div>
        </main>

        <DisasterFooter />
    </div>
</template>
