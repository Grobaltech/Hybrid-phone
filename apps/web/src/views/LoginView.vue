<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '@/stores/auth'
const username=ref(''),password=ref(''),error=ref(''),busy=ref(false),auth=useAuth(),router=useRouter()
async function submit(){busy.value=true;error.value='';try{await auth.login(username.value,password.value);await router.replace('/dashboard')}catch(e:any){error.value=e.message??'Sign in failed'}finally{busy.value=false}}
</script>
<template><main class="login-screen"><form class="login-card" @submit.prevent="submit"><img src="/logo.png" alt="Shop logo" class="login-logo"><h1>Hybrid Shop</h1><p>Sign in to manage your business</p><label>Username<input v-model.trim="username" class="control" autocomplete="username" required></label><label>Password<input v-model="password" class="control" type="password" autocomplete="current-password" required></label><p v-if="error" class="alert">{{error}}</p><button class="btn primary full" :disabled="busy">{{busy?'Signing in…':'Sign in'}}</button><small>Use the account created in Supabase Auth and its matching shop profile.</small></form></main></template>
