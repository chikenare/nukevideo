import HomePage from '@/pages/home/HomePage.vue'
import TemplatesPage from '@/pages/templates/TemplatesPage.vue'
import EditTemplatePage from '@/pages/templates/EditTemplatePage.vue'
import VideoPage from '@/pages/videos/VideoPage.vue'
import VideosPage from '@/pages/videos/VideosPage.vue'
import NodesPage from '@/pages/nodes/NodesPage.vue'
import AccountPage from '@/pages/settings/AccountPage.vue'
import ApiKeysPage from '@/pages/settings/ApiKeysPage.vue'
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
    { name: 'Nodes', path: '/nodes', component: NodesPage },
    { name: 'Account', path: '/settings/account', component: AccountPage },
    { name: 'ApiKeys', path: '/settings/api-keys', component: ApiKeysPage },
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
})

export default router
