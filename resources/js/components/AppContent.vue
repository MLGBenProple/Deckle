<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    class?: string;
}>();
const className = computed(() => props.class);
</script>

<template>
    <main
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
