<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
    /** The active countries, from the server. */
    countries: Array<{ id: number; iso2: string; name: string }>;
}>();

defineOptions({
    layout: {
        title: 'Create your account',
        description: 'Register to get email alerts for your country.',
        toggle: 'register',
    },
});

// Checked by default, which matches the database default. The form always sends an explicit 1 or 0 (see the
// hidden input below), because an unchecked checkbox sends nothing and the server would read that as "on".
const alertsEnabled = ref(true);
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Full name"
                    :aria-invalid="!!errors.name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                    :aria-invalid="!!errors.email"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="country_id">Country</Label>
                <select
                    id="country_id"
                    name="country_id"
                    required
                    :tabindex="3"
                    :disabled="countries.length === 0"
                    :aria-invalid="!!errors.country_id"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                >
                    <option value="" disabled selected class="bg-background text-foreground">
                        Choose your country
                    </option>
                    <option
                        v-for="country in countries"
                        :key="country.id"
                        :value="country.id"
                        class="bg-background text-foreground"
                    >
                        {{ country.name }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    Alerts are sent for events reported in this country.
                </p>
                <InputError :message="errors.country_id" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="5"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="grid gap-1.5">
                <div class="flex items-start gap-3">
                    <Checkbox
                        id="alerts_checkbox"
                        v-model="alertsEnabled"
                        :tabindex="6"
                        class="mt-0.5"
                        data-test="alerts-checkbox"
                    />
                    <Label for="alerts_checkbox" class="flex-col items-start gap-0.5 font-normal">
                        <span class="font-medium">Email me disaster alerts</span>
                        <span class="text-xs text-muted-foreground">
                            Country-level alerts go to your verified email. You can
                            change this at any time in Settings.
                        </span>
                    </Label>
                </div>
                <input
                    type="hidden"
                    name="alerts_enabled"
                    :value="alertsEnabled ? 1 : 0"
                    data-test="alerts-enabled-value"
                />
                <InputError :message="errors.alerts_enabled" />
            </div>

            <Button
                type="submit"
                class="mt-1 w-full"
                :tabindex="7"
                :disabled="processing || countries.length === 0"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="8"
                >Log in</TextLink
            >
        </div>
    </Form>
</template>
