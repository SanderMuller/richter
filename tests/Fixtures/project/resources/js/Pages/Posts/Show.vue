<script setup lang="ts">
defineProps<{ post: { id: number; title: string } }>();
</script>

<template>
    <h1>{{ post.title }}</h1>
</template>
