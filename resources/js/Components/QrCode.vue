<script setup lang="ts">
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    value: string;
    size?: number;
    margin?: number;
    errorCorrectionLevel?: 'L' | 'M' | 'Q' | 'H';
}>(), {
    size: 160,
    margin: 1,
    errorCorrectionLevel: 'M',
});

const canvasRef = ref<HTMLCanvasElement | null>(null);

const generateQr = async () => {
    if (!props.value || !canvasRef.value) return;

    try {
        await QRCode.toCanvas(canvasRef.value, props.value, {
            width: props.size,
            margin: props.margin,
            errorCorrectionLevel: props.errorCorrectionLevel,
            color: {
                dark: '#0f172a', // slate-900
                light: '#ffffff',
            },
        });
    } catch (err) {
        console.error('Failed to generate offline QR code:', err);
    }
};

onMounted(() => {
    generateQr();
});

watch(() => props.value, () => {
    generateQr();
});

watch(() => props.size, () => {
    generateQr();
});
</script>

<template>
    <div class="inline-flex items-center justify-center p-2.5 bg-white rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
        <canvas ref="canvasRef" :width="size" :height="size" class="rounded-xl"></canvas>
    </div>
</template>
