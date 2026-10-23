<script setup lang="ts">
import UserLayout from '@/Layouts/UserLayout.vue';
</script>

<template>
    <UserLayout>
        <template #header>
            <slot name="header" />
        </template>
        <slot />
    </UserLayout>
</template>
