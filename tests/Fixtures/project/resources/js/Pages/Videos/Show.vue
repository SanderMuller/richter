<script setup lang="ts">
defineProps<{ video: { id: number; title: string } }>();
</script>

<template>
    <h1>{{ video.title }}</h1>
</template>
