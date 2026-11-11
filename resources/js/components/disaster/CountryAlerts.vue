<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, ExternalLink, Info } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { login, register } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { send } from '@/routes/verification';

export type CountryAlert = {
    id: string;
    type: string;
    title: string;
    location: string | null;
    severity: string | null;
    severity_text: string | null;
    source: string;
    source_url: string | null;
    alerted_at: string | null;
    occurred_at: string | null;
};

export type CountryAlertsData = {
    country: { id: number; iso2: string; name: string } | null;
    alerts: CountryAlert[];
    window_days: number;
};

/** `null` for a guest (no personal data is sent to guests). */
defineProps<{
    countryAlerts?: CountryAlertsData | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const unverified = computed(
    () => user.value !== null && !user.value.email_verified_at,
);
const linkSent = computed(() => page.props.status === 'verification-link-sent');

// Server-rendered text is UTC; the browser's local time replaces it after mount (no hydration mismatch).
const mounted = ref(false);
onMounted(() => {
    mounted.value = true;
});

function formatTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (!mounted.value) {
        return `${date.toISOString().slice(0, 16).replace('T', ' ')} UTC`;
    }

    return date.toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <section
        class="border-b border-border/60 py-10 sm:py-12"
        aria-labelledby="country-alerts-heading"
        data-test="country-alerts"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <!-- Guest: registration promotion -->
            <div
                v-if="!user"
                class="rounded-xl border border-border bg-card p-5 sm:p-6"
                data-test="country-alerts-guest"
            >
                <div class="flex items-center gap-2">
                    <BellRing class="size-4 text-destructive" />
                    <h2
                        id="country-alerts-heading"
                        class="text-lg font-bold tracking-tight sm:text-xl"
                    >
                        Get email alerts for your country
                    </h2>
                </div>
                <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                    Register your country to receive email alerts for
                    significant disasters reported in that country.
                </p>
                <ul
                    class="mt-3 max-w-3xl list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                >
                    <li>
                        Alerts are based on the country an event is reported
                        in.
                    </li>
                    <li>
                        A country-level alert does not mean every city or area
                        in that country is affected.
                    </li>
                    <li>
                        Each alert includes event details and a link to the
                        original source so you can judge the information
                        yourself.
                    </li>
                    <li>Guests keep full access to public information.</li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        :href="register()"
                        data-test="country-alerts-register"
                        class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                    >
                        Create free account
                    </Link>
                    <Link
                        :href="login()"
                        data-test="country-alerts-login"
                        class="inline-flex h-9 items-center rounded-md border border-border bg-background px-4 text-sm font-medium hover:bg-muted"
                    >
                        Log in
                    </Link>
                </div>
            </div>

            <!-- Signed in -->
            <div v-else class="space-y-4" data-test="country-alerts-user">
                <div
                    v-if="unverified"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300"
                    data-test="country-alerts-unverified"
                >
                    Verify your email to receive alert emails.
                    <Link
                        :href="send()"
                        as="button"
                        class="font-medium underline underline-offset-4"
                    >
                        Resend the verification email.
                    </Link>
                    <span v-if="linkSent" class="ml-1 font-medium">
                        A new verification link has been sent.
                    </span>
                </div>

                <!-- No (supported) country yet -->
                <div
                    v-if="!countryAlerts?.country"
                    class="rounded-xl border border-dashed border-border p-5 text-sm text-muted-foreground sm:p-6"
                    data-test="country-alerts-no-country"
                >
                    <h2
                        id="country-alerts-heading"
                        class="text-base font-semibold text-foreground"
                    >
                        Recent alerts for your country
                    </h2>
                    <p class="mt-1">
                        Set your country in
                        <Link
                            :href="editProfile()"
                            class="font-medium text-foreground underline underline-offset-4"
                            >Settings</Link
                        >
                        to see recent alerts and receive alert emails.
                    </p>
                </div>

                <div v-else>
                    <div class="flex items-center gap-2">
                        <BellRing class="size-4 text-destructive" />
                        <h2
                            id="country-alerts-heading"
                            class="text-lg font-bold tracking-tight sm:text-xl"
                        >
                            Recent alerts for {{ countryAlerts.country.name }}
                        </h2>
                    </div>

                    <ul
                        v-if="countryAlerts.alerts.length"
                        class="mt-3 divide-y divide-border rounded-xl border border-border bg-card"
                        data-test="country-alerts-list"
                    >
                        <li
                            v-for="alert in countryAlerts.alerts"
                            :key="alert.id"
                            class="space-y-1 p-4 text-sm"
                            data-test="country-alert-row"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{
                                    alert.type
                                }}</span>
                                <span
                                    v-if="alert.severity"
                                    class="rounded-full border border-destructive/30 bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                                    >{{ alert.severity }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    alert.source
                                }}</span>
                            </div>
                            <p class="text-foreground">{{ alert.title }}</p>
                            <p
                                v-if="alert.location"
                                class="text-xs text-muted-foreground"
                            >
                                {{ alert.location }}
                            </p>
                            <p
                                class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted-foreground"
                            >
                                <span
                                    v-if="alert.alerted_at"
                                    data-test="alert-alerted"
                                    >Alerted:
                                    {{ formatTime(alert.alerted_at) }}</span
                                >
                                <span
                                    v-if="alert.occurred_at"
                                    data-test="alert-occurred"
                                    >Occurred:
                                    {{ formatTime(alert.occurred_at) }}</span
                                >
                            </p>
                            <div class="flex flex-wrap gap-3 pt-1 text-xs">
                                <Link
                                    :href="`/events/${encodeURIComponent(alert.id)}`"
                                    class="font-medium underline underline-offset-4"
                                    >Event details</Link
                                >
                                <a
                                    v-if="alert.source_url"
                                    :href="alert.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-medium underline underline-offset-4"
                                >
                                    Original source
                                    <ExternalLink class="size-3" />
                                </a>
                            </div>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="mt-3 rounded-xl border border-dashed border-border p-5 text-sm text-muted-foreground"
                        data-test="country-alerts-empty"
                    >
                        No alerts for {{ countryAlerts.country.name }} in the
                        last {{ countryAlerts.window_days }} days.
                    </p>

                    <p
                        class="mt-3 flex items-start gap-2 text-xs text-muted-foreground"
                        data-test="country-alerts-note"
                    >
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        Alerts are country-level. Check the event location and
                        official sources to see whether your area is affected.
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
