<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import Spinner from '@/components/ui/spinner/Spinner.vue'
import ApiTokenService from '@/services/ApiTokenService'
import type { ApiToken } from '@/types/ApiToken'
import { ValidationException } from '@/exceptions/ValidationException'
import { Copy, Trash2 } from 'lucide-vue-next'

const tokens = ref<ApiToken[]>([])
const loading = ref(true)
const createLoading = ref(false)
const newTokenName = ref('')
const newPlainToken = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})

async function fetchTokens() {
  try {
    loading.value = true
    tokens.value = await ApiTokenService.getTokens()
  } catch (error) {
    console.error('Error fetching tokens:', error)
  } finally {
    loading.value = false
  }
}

async function handleCreate() {
  errors.value = {}
  createLoading.value = true

  try {
    const result = await ApiTokenService.createToken(newTokenName.value)
    newPlainToken.value = result.token
    newTokenName.value = ''
    await fetchTokens()
  } catch (error) {
    if (error instanceof ValidationException) {
      errors.value = error.errors
    }
  } finally {
    createLoading.value = false
  }
}

async function handleDelete(id: number) {
  try {
    await ApiTokenService.deleteToken(id)
    tokens.value = tokens.value.filter(t => t.id !== id)
  } catch (error) {
    console.error('Error deleting token:', error)
  }
}

function copyToken() {
  if (newPlainToken.value) {
    navigator.clipboard.writeText(newPlainToken.value)
  }
}

function formatDate(dateString: string) {
  return new Date(dateString).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

onMounted(() => {
  fetchTokens()
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4 max-w-4xl">
    <div>
      <h1 class="text-2xl font-bold">API Keys</h1>
      <p class="text-muted-foreground">Manage your API tokens for external access.</p>
    </div>

    <!-- New Token Alert -->
    <div v-if="newPlainToken"
      class="rounded-lg border border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950 p-4">
      <p class="text-sm font-medium mb-2">Your new API token has been created. Copy it now — you won't be able to see it
        again.</p>
      <div class="flex items-center gap-2">
        <code class="flex-1 rounded bg-muted px-3 py-2 text-sm font-mono break-all">{{ newPlainToken }}</code>
        <Button variant="outline" size="icon" @click="copyToken">
          <Copy class="h-4 w-4" />
        </Button>
      </div>
      <Button variant="ghost" size="sm" class="mt-2" @click="newPlainToken = null">Dismiss</Button>
    </div>

    <!-- Create Token -->
    <Card>
      <CardHeader>
        <CardTitle>Create Token</CardTitle>
        <CardDescription>Generate a new API token for accessing the API.</CardDescription>
      </CardHeader>
      <CardContent>
        <form @submit.prevent="handleCreate" class="flex items-end gap-3">
          <div class="grid gap-2 flex-1">
            <Label for="token_name">Token Name</Label>
            <Input id="token_name" v-model="newTokenName" type="text" placeholder="e.g. My App" required />
            <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name[0] }}</p>
          </div>
          <Button type="submit" :disabled="createLoading">
            {{ createLoading ? 'Creating...' : 'Create' }}
          </Button>
        </form>
      </CardContent>
    </Card>

    <!-- Token List -->
    <Card>
      <CardHeader>
        <CardTitle>Active Tokens</CardTitle>
        <CardDescription>Tokens that can be used to authenticate API requests.</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="overflow-hidden rounded-lg border">
          <Table>
            <TableHeader class="bg-muted">
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Created</TableHead>
                <TableHead>Last Used</TableHead>
                <TableHead class="w-20"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="loading">
                <TableCell colspan="4" class="text-center">
                  <Spinner />
                  Loading tokens...
                </TableCell>
              </TableRow>
              <TableRow v-else-if="tokens.length === 0">
                <TableCell colspan="4" class="text-center text-muted-foreground">
                  No API tokens yet.
                </TableCell>
              </TableRow>
              <TableRow v-else v-for="token in tokens" :key="token.id">
                <TableCell class="font-medium">{{ token.name }}</TableCell>
                <TableCell>{{ formatDate(token.createdAt) }}</TableCell>
                <TableCell>{{ token.lastUsedAt ? formatDate(token.lastUsedAt) : 'Never' }}</TableCell>
                <TableCell>
                  <AlertDialog>
                    <AlertDialogTrigger as-child>
                      <Button variant="ghost" size="icon" class="text-destructive hover:text-destructive">
                        <Trash2 class="h-4 w-4" />
                      </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                      <AlertDialogHeader>
                        <AlertDialogTitle>Revoke token</AlertDialogTitle>
                        <AlertDialogDescription>
                          Are you sure you want to revoke the token "{{ token.name }}"? Any applications using this
                          token will no longer be able to access the API.
                        </AlertDialogDescription>
                      </AlertDialogHeader>
                      <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction @click="handleDelete(token.id)">Revoke</AlertDialogAction>
                      </AlertDialogFooter>
                    </AlertDialogContent>
                  </AlertDialog>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
