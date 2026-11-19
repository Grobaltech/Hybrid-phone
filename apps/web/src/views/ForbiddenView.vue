<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="page-wrap">
    <div class="panel" style="max-width: 560px; margin: 12vh auto; text-align: center">
      <h1>Access denied</h1>
      <p class="muted">
        Your account does not have permission to open this page. Contact an administrator if you need access.
      </p>
      <RouterLink
        to="/dashboard"
        class="btn primary"
        style="display: inline-block; margin-top: 18px"
      >
        Return to dashboard
      </RouterLink>
    </div>
  </section>
</template>
