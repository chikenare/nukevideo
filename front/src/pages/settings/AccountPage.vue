<script setup lang="ts">
import { ref } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { useAuthStore } from '@/stores/auth'
import ProfileService from '@/services/ProfileService'
import { ValidationException } from '@/exceptions/ValidationException'

const authStore = useAuthStore()

const profileForm = ref({
  name: authStore.user?.name ?? '',
  email: authStore.user?.email ?? '',
})

const passwordForm = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const profileErrors = ref<Record<string, string[]>>({})
const passwordErrors = ref<Record<string, string[]>>({})
const profileLoading = ref(false)
const passwordLoading = ref(false)
const profileSuccess = ref(false)
const passwordSuccess = ref(false)

async function handleUpdateProfile() {
  profileErrors.value = {}
  profileSuccess.value = false
  profileLoading.value = true

  try {
    const updatedUser = await ProfileService.updateProfile(profileForm.value)
    authStore.setUser(updatedUser)
    profileSuccess.value = true
  } catch (error) {
    if (error instanceof ValidationException) {
      profileErrors.value = error.errors
    }
  } finally {
    profileLoading.value = false
  }
}

async function handleUpdatePassword() {
  passwordErrors.value = {}
  passwordSuccess.value = false
  passwordLoading.value = true

  try {
    await ProfileService.updatePassword(passwordForm.value)
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
    passwordSuccess.value = true
  } catch (error) {
    if (error instanceof ValidationException) {
      passwordErrors.value = error.errors
    }
  } finally {
    passwordLoading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Account</h1>
      <p class="text-muted-foreground">Manage your account settings.</p>
    </div>

    <!-- Profile Information -->
    <Card>
      <CardHeader>
        <CardTitle>Profile Information</CardTitle>
        <CardDescription>Update your name and email address.</CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="handleUpdateProfile" class="grid gap-4">
          <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="profileForm.name" type="text" required />
            <p v-if="profileErrors.name" class="text-sm text-destructive">{{ profileErrors.name[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input id="email" v-model="profileForm.email" type="email" required />
            <p v-if="profileErrors.email" class="text-sm text-destructive">{{ profileErrors.email[0] }}</p>
          </div>

          <div class="flex items-center gap-3">
            <Button type="submit" :disabled="profileLoading">
              {{ profileLoading ? 'Saving...' : 'Save' }}
            </Button>
            <p v-if="profileSuccess" class="text-sm text-green-600">Saved.</p>
          </div>
        </form>
      </CardContent>
    </Card>

    <!-- Change Password -->
    <Card>
      <CardHeader>
        <CardTitle>Change Password</CardTitle>
        <CardDescription>Ensure your account uses a strong password.</CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="handleUpdatePassword" class="grid gap-4">
          <div class="grid gap-2">
            <Label for="current_password">Current Password</Label>
            <Input id="current_password" v-model="passwordForm.current_password" type="password" required />
            <p v-if="passwordErrors.current_password" class="text-sm text-destructive">{{ passwordErrors.current_password[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="password">New Password</Label>
            <Input id="password" v-model="passwordForm.password" type="password" required />
            <p v-if="passwordErrors.password" class="text-sm text-destructive">{{ passwordErrors.password[0] }}</p>
          </div>

          <div class="grid gap-2">
            <Label for="password_confirmation">Confirm Password</Label>
            <Input id="password_confirmation" v-model="passwordForm.password_confirmation" type="password" required />
          </div>

          <div class="flex items-center gap-3">
            <Button type="submit" :disabled="passwordLoading">
              {{ passwordLoading ? 'Updating...' : 'Update Password' }}
            </Button>
            <p v-if="passwordSuccess" class="text-sm text-green-600">Password updated.</p>
          </div>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
