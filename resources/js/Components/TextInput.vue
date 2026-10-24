<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string>({ required: true });

defineProps<{
    hasError?: boolean;
}>();

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="form-input"
        :class="{ 'has-error': hasError }"
        v-model="model"
        ref="input"
    />
</template>

