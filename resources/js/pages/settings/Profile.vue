<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { send } from '@/routes/verification';

type CountryOption = { id: number; iso2: string; name: string };

const props = defineProps<{
    countries: CountryOption[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const currentCountryId = computed<number | null>(() => {
    const id = user.value.country_id;

    return typeof id === 'number' ? id : null;
});

// A person whose country was deactivated keeps it (the server allows keeping it);
// without this option the select would show blank and saving would clear it.
const currentCountryUnavailable = computed(
    () =>
        currentCountryId.value !== null &&
        !props.countries.some((c) => c.id === currentCountryId.value),
);

const alertsEnabled = ref(user.value.alerts_enabled !== false);
const emailUnverified = computed(
    () => !!page.props.mustVerifyEmail && !user.value.email_verified_at,
);
</script>

<template>
    <Head title="Profile settings" />

    <Form
        v-bind="ProfileController.update.form()"
        class="space-y-6"
        v-slot="{ errors, processing, recentlySuccessful }"
    >
        <section
            class="space-y-6 rounded-xl border border-border bg-card p-5 sm:p-6"
            aria-labelledby="personal-information-heading"
            data-test="section-personal-information"
        >
            <header class="space-y-0.5">
                <h2
                    id="personal-information-heading"
                    class="text-base font-semibold"
                >
                    Personal Information
                </h2>
                <p class="text-sm text-muted-foreground">
                    Your name, email address and country.
                </p>
            </header>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <p class="text-xs text-muted-foreground">
                    If you change your email address you will need to verify
                    the new one. Alerts are not sent until it is verified.
                </p>
                <InputError class="mt-2" :message="errors.email" />

                <div v-if="emailUnverified" class="text-sm">
                    <p class="text-muted-foreground">
                        Your email address is unverified.
                        <Link
                            :href="send()"
                            as="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                        >
                            Click here to re-send the verification email.
                        </Link>
                    </p>

                    <div
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 text-sm font-medium text-green-600"
                    >
                        A new verification link has been sent to your email
                        address.
                    </div>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="country_id">Country</Label>
                <select
                    id="country_id"
                    name="country_id"
                    data-test="country-select"
                    :aria-invalid="!!errors.country_id"
                    class="mt-1 h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                >
                    <option
                        value=""
                        :selected="currentCountryId === null"
                        class="bg-background text-foreground"
                    >
                        No country selected
                    </option>
                    <option
                        v-if="currentCountryUnavailable"
                        :value="currentCountryId ?? ''"
                        selected
                        class="bg-background text-foreground"
                    >
                        Your current country (no longer available)
                    </option>
                    <option
                        v-for="country in countries"
                        :key="country.id"
                        :value="country.id"
                        :selected="country.id === currentCountryId"
                        class="bg-background text-foreground"
                    >
                        {{ country.name }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    Optional. Alerts are sent for events reported in this
                    country.
                </p>
                <InputError :message="errors.country_id" />
            </div>
        </section>

        <section
            class="space-y-4 rounded-xl border border-border bg-card p-5 sm:p-6"
            aria-labelledby="alert-preferences-heading"
            data-test="section-alert-preferences"
        >
            <header class="space-y-0.5">
                <h2
                    id="alert-preferences-heading"
                    class="text-base font-semibold"
                >
                    Disaster Alert Preferences
                </h2>
                <p class="text-sm text-muted-foreground">
                    Choose whether DisasterLink SEA emails you about disasters
                    in your country.
                </p>
            </header>

            <div class="grid gap-1.5">
                <div class="flex items-start gap-3">
                    <Checkbox
                        id="alerts_checkbox"
                        v-model="alertsEnabled"
                        class="mt-0.5"
                        data-test="alerts-checkbox"
                    />
                    <input
                        type="hidden"
                        name="alerts_enabled"
                        :value="alertsEnabled ? 1 : 0"
                        data-test="alerts-enabled-value"
                    />
                    <div class="grid gap-1">
                        <Label for="alerts_checkbox" class="cursor-pointer">
                            Email me disaster alerts
                            <span
                                class="ml-1 font-normal text-muted-foreground"
                                data-test="alerts-status"
                                >({{ alertsEnabled ? 'On' : 'Off' }})</span
                            >
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Country-level alerts go to your verified email
                            address. They do not mean your city or area is
                            directly affected.
                        </p>
                    </div>
                </div>
                <p
                    v-if="emailUnverified"
                    class="text-xs text-amber-700 dark:text-amber-400"
                    data-test="alerts-unverified-note"
                >
                    Alerts are unavailable until your email address is
                    verified.
                </p>
                <InputError :message="errors.alerts_enabled" />
            </div>
        </section>

        <div class="flex items-center gap-4">
            <Button :disabled="processing" data-test="update-profile-button"
                >Save changes</Button
            >
            <Transition
                enter-active-class="transition ease-in-out"
                enter-from-class="opacity-0"
                leave-active-class="transition ease-in-out"
                leave-to-class="opacity-0"
            >
                <p
                    v-show="recentlySuccessful"
                    class="text-sm text-green-600"
                    role="status"
                    data-test="profile-saved"
                >
                    Saved.
                </p>
            </Transition>
        </div>
    </Form>

    <section
        class="rounded-xl border border-border bg-card p-5 sm:p-6"
        data-test="section-delete-account"
    >
        <DeleteUser />
    </section>
</template>
