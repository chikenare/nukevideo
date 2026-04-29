import HomePage from '@/pages/home/HomePage.vue'
import ProjectsPage from '@/pages/projects/ProjectsPage.vue'
import TemplatesPage from '@/pages/templates/TemplatesPage.vue'
import EditTemplatePage from '@/pages/templates/EditTemplatePage.vue'
import VideoPage from '@/pages/videos/VideoPage.vue'
import VideosPage from '@/pages/videos/VideosPage.vue'
import NodesPage from '@/pages/nodes/NodesPage.vue'
import UsersPage from '@/pages/users/UsersPage.vue'
import AccountPage from '@/pages/settings/AccountPage.vue'
import ActivityLogPage from '@/pages/settings/ActivityLogPage.vue'
import ApiKeysPage from '@/pages/settings/ApiKeysPage.vue'
import SshKeysPage from '@/pages/settings/SshKeysPage.vue'
import AppSettingsPage from '@/pages/settings/AppSettingsPage.vue'
import LoginPage from '@/pages/auth/LoginPage.vue'
import RegisterPage from '@/pages/auth/RegisterPage.vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { name: 'Login', path: '/login', component: LoginPage, meta: { guest: true } },
    { name: 'Register', path: '/register', component: RegisterPage, meta: { guest: true } },
    { name: 'Home', path: '/', component: HomePage },
    { name: 'Videos', path: '/videos', component: VideosPage },
    { name: 'Video', path: '/videos/:id', component: VideoPage },
    { name: 'Templates', path: '/templates', component: TemplatesPage },
    { name: 'CreateTemplate', path: '/templates/create', component: EditTemplatePage },
    { name: 'EditTemplate', path: '/templates/:id', component: EditTemplatePage },
    { name: 'Projects', path: '/projects', component: ProjectsPage },
    { name: 'Nodes', path: '/nodes', component: NodesPage, meta: { admin: true } },
    { name: 'Users', path: '/users', component: UsersPage, meta: { admin: true } },
    { name: 'Account', path: '/settings/account', component: AccountPage },
    { name: 'ActivityLog', path: '/settings/activity-log', component: ActivityLogPage },
    { name: 'ApiKeys', path: '/settings/api-keys', component: ApiKeysPage },
    { name: 'SshKeys', path: '/settings/ssh-keys', component: SshKeysPage, meta: { admin: true } },
    { name: 'AppSettings', path: '/settings/app', component: AppSettingsPage, meta: { admin: true } },
  ],
})

router.beforeEach(async (to) => {
  const authStore = useAuthStore()
  if (!authStore.isAuthenticated && !authStore.loaded) {
    await authStore.fetchUser()
  }

  const isGuest = to.meta.guest === true

  if (isGuest && authStore.isAuthenticated) {
    return { name: 'Home' }
  }

  if (!isGuest && !authStore.isAuthenticated) {
    return { name: 'Login' }
  }

  if (to.meta.admin && !authStore.isAdmin) {
    return { name: 'Home' }
  }
})

export default router
