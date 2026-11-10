<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthDisasterLayout.vue';

const {
    title = '',
    description = '',
    toggle = null,
} = defineProps<{
    title?: string;
    description?: string;
    toggle?: 'login' | 'register' | null;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description" :toggle="toggle">
        <slot />
    </AuthLayout>
</template>
