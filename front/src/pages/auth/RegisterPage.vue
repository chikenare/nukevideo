<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import AuthService from '@/services/AuthService'
import SettingsService from '@/services/SettingsService'
import { ValidationException } from '@/exceptions/ValidationException'

const router = useRouter()
const registrationEnabled = ref(true)
const checkingSettings = ref(true)

onMounted(async () => {
  try {
    const pub = await SettingsService.getPublic()
    registrationEnabled.value = pub.registrationEnabled
  } catch {
    // allow registration attempt if settings fail to load
  } finally {
    checkingSettings.value = false
  }
})

const form = ref({
  name: '',
  email: '',
  password: '',
})

const errors = ref<Record<string, string[]>>({})
const loading = ref(false)

async function handleRegister() {
  errors.value = {}
  loading.value = true

  try {
    await AuthService.register(form.value)
    router.push('/')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
    console.error(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center px-4">
    <Card v-if="!checkingSettings && !registrationEnabled" class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Registro deshabilitado</CardTitle>
        <CardDescription>El registro de nuevos usuarios no esta disponible en este momento.</CardDescription>
      </CardHeader>
      <CardContent class="text-center">
        <RouterLink to="/login" class="text-primary underline-offset-4 hover:underline text-sm">
          Volver al inicio de sesion
        </RouterLink>
      </CardContent>
    </Card>

    <Card v-else-if="!checkingSettings" class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Crear cuenta</CardTitle>
        <CardDescription>Completa los campos para registrarte</CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="handleRegister" class="grid gap-4">
          <div class="grid gap-2">
            <Label for="name">Nombre</Label>
            <Input id="name" v-model="form.name" type="text" placeholder="Name" required />
            <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input id="email" v-model="form.email" type="email" placeholder="mail@example.com" required />
            <p v-if="errors.email" class="text-sm text-destructive">{{ errors.email[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="password">Password</Label>
            <Input id="password" v-model="form.password" type="password" placeholder="••••••••" required />
            <p v-if="errors.password" class="text-sm text-destructive">{{ errors.password[0] }}</p>
          </div>

          <Button type="submit" class="w-full" :disabled="loading">
            {{ loading ? 'Loading...' : 'Register' }}
          </Button>

          <p class="text-center text-sm text-muted-foreground">
            ¿Ya tienes cuenta?
            <RouterLink to="/login" class="text-primary underline-offset-4 hover:underline">
              Inicia sesión
            </RouterLink>
          </p>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
