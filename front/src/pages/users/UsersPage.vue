<script setup lang="ts">
import Spinner from '@/components/ui/spinner/Spinner.vue'
import Badge from '@/components/ui/badge/Badge.vue'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ref, onMounted } from 'vue'
import UserService from '@/services/UserService'
import type { User } from '@/types/Auth'
import { useAuthStore } from '@/stores/auth'
import { EllipsisVertical, Pencil, Trash2 } from 'lucide-vue-next'
import CreateUserDialog from './CreateUserDialog.vue'
import EditUserDialog from './EditUserDialog.vue'

const authStore = useAuthStore()
const users = ref<User[]>([])
const loading = ref(true)

const editDialog = ref<InstanceType<typeof EditUserDialog> | null>(null)

const fetchUsers = async () => {
  try {
    users.value = await UserService.getUsers()
  } catch (error) {
    console.error('Error fetching users:', error)
  } finally {
    loading.value = false
  }
}

const onUserUpdated = (updated: User) => {
  const idx = users.value.findIndex(u => u.id === updated.id)
  if (idx !== -1) {
    users.value[idx] = updated
  }
}

const deleteUser = async (user: User) => {
  if (!confirm(`Are you sure you want to delete "${user.name}"?`)) return
  try {
    await UserService.deleteUser(user.id)
    users.value = users.value.filter(u => u.id !== user.id)
  } catch (error) {
    console.error('Error deleting user:', error)
  }
}

onMounted(fetchUsers)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">Users</h1>
        <p class="text-muted-foreground">{{ users.length }} users</p>
      </div>
      <CreateUserDialog @created="fetchUsers" />
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <Spinner />
    </div>

    <div v-else-if="users.length === 0" class="text-center text-muted-foreground py-12">
      No users found.
    </div>

    <div v-else class="overflow-hidden rounded-lg border">
      <Table>
        <TableHeader class="bg-muted">
          <TableRow>
            <TableHead>Name</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Role</TableHead>
            <TableHead class="w-12"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="user in users" :key="user.id">
            <TableCell class="font-medium">{{ user.name }}</TableCell>
            <TableCell>{{ user.email }}</TableCell>
            <TableCell>
              <Badge :variant="user.isAdmin ? 'default' : 'outline'">
                {{ user.isAdmin ? 'Admin' : 'User' }}
              </Badge>
            </TableCell>
            <TableCell>
              <DropdownMenu>
                <DropdownMenuTrigger as-child>
                  <Button variant="ghost" size="icon">
                    <EllipsisVertical class="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem @click="editDialog?.show(user)">
                    <Pencil class="mr-2 h-4 w-4" />
                    Edit
                  </DropdownMenuItem>
                  <template v-if="user.id !== authStore.user?.id">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="text-destructive" @click="deleteUser(user)">
                      <Trash2 class="mr-2 h-4 w-4" />
                      Delete
                    </DropdownMenuItem>
                  </template>
                </DropdownMenuContent>
              </DropdownMenu>
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <EditUserDialog ref="editDialog" @updated="onUserUpdated" />
  </div>
</template>
