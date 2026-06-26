<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { ref } from 'vue'
import UserService from '@/services/UserService'
import type { User } from '@/types/Auth'
import { ValidationException } from '@/exceptions/ValidationException'

const emit = defineEmits<{ updated: [user: User] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const userId = ref(0)
const form = ref({
  name: '',
  email: '',
  password: '',
  isAdmin: false,
})

const show = (user: User) => {
  userId.value = user.id
  form.value = {
    name: user.name,
    email: user.email,
    password: '',
    isAdmin: user.isAdmin,
  }
  errors.value = {}
  dialogOpen.value = true
}

const handleUpdate = async () => {
  errors.value = {}
  loading.value = true

  try {
    const payload: Record<string, unknown> = {
      name: form.value.name,
      email: form.value.email,
      isAdmin: form.value.isAdmin,
    }
    if (form.value.password) {
      payload.password = form.value.password
    }

    const updated = await UserService.updateUser(userId.value, payload)
    dialogOpen.value = false
    emit('updated', updated)
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    loading.value = false
  }
}

defineExpose({ show })
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit User</DialogTitle>
        <DialogDescription>Update user information.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleUpdate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="edit_user_name">Name</Label>
          <Input id="edit_user_name" v-model="form.name" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="edit_user_email">Email</Label>
          <Input id="edit_user_email" v-model="form.email" type="email" required />
          <p v-if="errors.email" class="text-sm text-destructive">{{ errors.email[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="edit_user_password">Password</Label>
          <Input id="edit_user_password" v-model="form.password" type="password" placeholder="Leave blank to keep current" />
          <p v-if="errors.password" class="text-sm text-destructive">{{ errors.password[0] }}</p>
        </div>
        <div class="flex items-center justify-between">
          <Label for="edit_user_admin">Administrator</Label>
          <Switch id="edit_user_admin" :checked="form.isAdmin" @update:checked="form.isAdmin = $event" />
        </div>
        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Saving...' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
