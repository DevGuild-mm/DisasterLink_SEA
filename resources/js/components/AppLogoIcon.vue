<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import BrandLogo from '@/components/BrandLogo.vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <BrandLogo :class="className" v-bind="$attrs" />
</template>
