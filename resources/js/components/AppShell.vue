<script setup lang="ts">
defineProps<{
    class?: string;
}>();
</script>

<template>
    <div class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
</template>
