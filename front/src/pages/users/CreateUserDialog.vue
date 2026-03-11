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
  DialogTrigger,
} from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { ref } from 'vue'
import UserService from '@/services/UserService'
import { ValidationException } from '@/exceptions/ValidationException'
import { Plus } from 'lucide-vue-next'

const emit = defineEmits<{ created: [] }>()

const dialogOpen = ref(false)
const loading = ref(false)
const errors = ref<Record<string, string[]>>({})

const form = ref({
  name: '',
  email: '',
  password: '',
  is_admin: false,
})

const handleCreate = async () => {
  errors.value = {}
  loading.value = true

  try {
    await UserService.createUser(form.value)
    form.value = { name: '', email: '', password: '', is_admin: false }
    dialogOpen.value = false
    emit('created')
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="dialogOpen">
    <DialogTrigger as-child>
      <Button>
        <Plus class="h-4 w-4 mr-2" />
        Add User
      </Button>
    </DialogTrigger>
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Add User</DialogTitle>
        <DialogDescription>Create a new user account.</DialogDescription>
      </DialogHeader>
      <form @submit.prevent="handleCreate" class="grid gap-4">
        <div class="grid gap-2">
          <Label for="user_name">Name</Label>
          <Input id="user_name" v-model="form.name" placeholder="John Doe" required />
          <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="user_email">Email</Label>
          <Input id="user_email" v-model="form.email" type="email" placeholder="john@example.com" required />
          <p v-if="errors.email" class="text-sm text-destructive">{{ errors.email[0] }}</p>
        </div>
        <div class="grid gap-2">
          <Label for="user_password">Password</Label>
          <Input id="user_password" v-model="form.password" type="password" placeholder="Min. 8 characters" required />
          <p v-if="errors.password" class="text-sm text-destructive">{{ errors.password[0] }}</p>
        </div>
        <div class="flex items-center justify-between">
          <Label for="user_admin">Administrator</Label>
          <Switch id="user_admin" :checked="form.is_admin" @update:checked="form.is_admin = $event" />
        </div>
        <DialogFooter>
          <Button type="submit" :disabled="loading">
            {{ loading ? 'Creating...' : 'Create User' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
