<script setup lang="ts">
/**
 * The DisasterLink SEA logo. `public/DisasterLink_SEA_logo.png` is transparent and mostly navy, so on a dark surface
 * `public/DisasterLink_SEA_logo-dark.png` (navy recoloured light, red unchanged) is shown instead. The page theme
 * picks the file with the `dark:` variant; `forceDark` is for surfaces that are always dark (the login brand panel).
 * Pass the size and spacing as classes. Leave `alt` empty when the name is written next to the logo, so a screen
 * reader does not read it twice.
 */
defineProps<{
    alt?: string;
    forceDark?: boolean;
}>();
</script>

<template>
    <span class="inline-flex aspect-square shrink-0">
        <img
            v-if="!forceDark"
            src="/DisasterLink_SEA_logo.png"
            :alt="alt ?? ''"
            width="1024"
            height="1024"
            decoding="async"
            class="size-full object-contain dark:hidden"
        />
        <img
            src="/DisasterLink_SEA_logo-dark.png"
            :alt="alt ?? ''"
            width="1024"
            height="1024"
            decoding="async"
            :loading="forceDark ? 'eager' : 'lazy'"
            :class="[
                'size-full object-contain',
                forceDark ? '' : 'hidden dark:block',
            ]"
        />
    </span>
</template>
