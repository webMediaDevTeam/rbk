import { existsSync, readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(import.meta.dirname, '..')
const src = path.join(root, 'src')

const requiredFiles = [
  'index.html',
  'package.json',
  'vite.config.js',
  'src/App.jsx',
  'src/main.jsx',
  'src/index.css',
  'src/assets/images/.gitkeep',
  'src/assets/styles/global.css',
  'src/styles/index.css',
  'src/styles/theme.css',
  'src/assets/logo.jsx',
  'src/assets/custom/icon-layout-default.jsx',
  'src/assets/rbq-branding/RbqAppIcon.jsx',
  'src/assets/rbq-branding/RbqFavicon.jsx',
  'src/assets/rbq-branding/RbqHorizontalLogo.jsx',
  'src/assets/rbq-branding/index.js',
  'src/components/ui/Button.jsx',
  'src/components/ui/Badge.jsx',
  'src/components/ui/Card.jsx',
  'src/components/ui/Table.jsx',
  'src/components/layouts/Header.jsx',
  'src/components/layouts/Sidebar.jsx',
  'src/components/layouts/MainLayout.jsx',
  'src/components/layout/app-sidebar.jsx',
  'src/components/layout/app-title.jsx',
  'src/components/layout/authenticated-layout.jsx',
  'src/components/layout/footer.jsx',
  'src/components/layout/header.jsx',
  'src/components/layout/main.jsx',
  'src/components/layout/nav-group.jsx',
  'src/components/layout/nav-user.jsx',
  'src/components/layout/team-switcher.jsx',
  'src/components/layout/top-nav.jsx',
  'src/components/layout/data/sidebar-data.js',
  'src/components/layout/types.js',
  'src/components/ui/alert-dialog.jsx',
  'src/components/ui/alert.jsx',
  'src/components/ui/avatar.jsx',
  'src/components/ui/badge.jsx',
  'src/components/ui/button.jsx',
  'src/components/ui/calendar.jsx',
  'src/components/ui/card.jsx',
  'src/components/ui/checkbox.jsx',
  'src/components/ui/collapsible.jsx',
  'src/components/ui/command.jsx',
  'src/components/ui/dialog.jsx',
  'src/components/ui/dropdown-menu.jsx',
  'src/components/ui/form.jsx',
  'src/components/ui/input-otp.jsx',
  'src/components/ui/input.jsx',
  'src/components/ui/label.jsx',
  'src/components/ui/popover.jsx',
  'src/components/ui/radio-group.jsx',
  'src/components/ui/scroll-area.jsx',
  'src/components/ui/select.jsx',
  'src/components/ui/separator.jsx',
  'src/components/ui/sheet.jsx',
  'src/components/ui/sidebar.jsx',
  'src/components/ui/skeleton.jsx',
  'src/components/ui/sonner.jsx',
  'src/components/ui/switch.jsx',
  'src/components/ui/table.jsx',
  'src/components/ui/tabs.jsx',
  'src/components/ui/textarea.jsx',
  'src/components/ui/tooltip.jsx',
  'src/context/AuthContext.js',
  'src/stores/useAuthStore.js',
  'src/stores/useClientStore.js',
  'src/routes/AppRoutes.jsx',
  'src/routes/ProtectedRoute.jsx',
  'src/routes/RoleBasedRoute.jsx',
  'src/services/api.js',
  'src/services/endpoints/auth.js',
  'src/services/endpoints/clients.js',
  'src/services/endpoints/enterprises.js',
  'src/utils/constants.js',
  'src/utils/helpers.js',
  'src/pages/shared/connexion/index.jsx',
  'src/pages/shared/connexion/useConnexion.js',
  'src/pages/shared/users/index.jsx',
  'src/pages/shared/users/useUsers.js',
  'src/pages/shared/users/components/UserSummary.jsx',
  'src/pages/dashboard/index.jsx',
  'src/pages/shared/profil/index.jsx',
  'src/pages/shared/profil/useProfil.js',
  'src/pages/shared/profil/components/AvatarUploader.jsx',
  'src/pages/comercial/index.jsx',
  'src/pages/entreprise/index.jsx',
  'src/pages/admin/index.jsx',
  'src/pages/superAdmin/index.jsx',
]

const forbiddenPaths = [
  'components.json',
  'tsconfig.json',
  'tsconfig.app.json',
  'tsconfig.node.json',
  'vite.config.ts',
  'src/main.tsx',
  'src/routeTree.gen.ts',
  'src/features',
]

const allowedDependencies = new Set([
  '@radix-ui/react-alert-dialog',
  '@radix-ui/react-avatar',
  '@radix-ui/react-checkbox',
  '@radix-ui/react-collapsible',
  '@radix-ui/react-dialog',
  '@radix-ui/react-direction',
  '@radix-ui/react-dropdown-menu',
  '@radix-ui/react-label',
  '@radix-ui/react-popover',
  '@radix-ui/react-radio-group',
  '@radix-ui/react-scroll-area',
  '@radix-ui/react-select',
  '@radix-ui/react-separator',
  '@radix-ui/react-slot',
  '@radix-ui/react-switch',
  '@radix-ui/react-tabs',
  '@radix-ui/react-tooltip',
  'class-variance-authority',
  'clsx',
  'cmdk',
  'date-fns',
  'esbuild',
  'input-otp',
  'lucide-react',
  'react',
  'react-day-picker',
  'react-dom',
  'react-hook-form',
  'sonner',
  'tailwind-merge',
  'vaul',
])
const allowedDevDependencies = new Set(['@vitejs/plugin-react', 'vite'])

const failures = []

for (const file of requiredFiles) {
  if (!existsSync(path.join(root, file))) {
    failures.push(`Missing required file: ${file}`)
  }
}

for (const file of forbiddenPaths) {
  if (existsSync(path.join(root, file))) {
    failures.push(`Unexpected leftover file or folder: ${file}`)
  }
}

if (existsSync(path.join(root, 'package.json'))) {
  const packageJson = JSON.parse(readFileSync(path.join(root, 'package.json'), 'utf8'))

  for (const dependency of Object.keys(packageJson.dependencies ?? {})) {
    if (!allowedDependencies.has(dependency)) {
      failures.push(`Unexpected dependency: ${dependency}`)
    }
  }

  for (const dependency of Object.keys(packageJson.devDependencies ?? {})) {
    if (!allowedDevDependencies.has(dependency)) {
      failures.push(`Unexpected devDependency: ${dependency}`)
    }
  }

  if (packageJson.scripts?.build !== 'vite build') {
    failures.push('Build script must be "vite build"')
  }
}

if (existsSync(path.join(root, 'index.html'))) {
  const html = readFileSync(path.join(root, 'index.html'), 'utf8')
  if (!html.includes('/src/main.jsx')) {
    failures.push('index.html must load /src/main.jsx')
  }
  if (html.includes('/src/main.tsx')) {
    failures.push('index.html still loads /src/main.tsx')
  }
}

if (existsSync(path.join(root, 'src/App.jsx'))) {
  const app = readFileSync(path.join(root, 'src/App.jsx'), 'utf8')
  for (const label of [
    'Dashboard',
    'Tasks',
    'Apps',
    'Chats',
    'Users',
    'Secured by Clerk',
    'Auth',
    'Errors',
    'Settings',
    'Help Center',
  ]) {
    if (!app.includes(label)) {
      failures.push(`Sidebar missing label: ${label}`)
    }
  }
}

const contentChecks = [
  ['src/pages/dashboard/index.jsx', 'Total Revenue'],
  ['src/pages/dashboard/index.jsx', 'Recent Sales'],
  ['src/pages/dashboard/index.jsx', 'Overview'],
  ['src/pages/dashboard/index.jsx', 'dashboard-chart'],
  ['src/pages/dashboard/index.jsx', 'olivia.martin@email.com'],
  ['src/pages/shared/users/index.jsx', 'User List'],
  ['src/pages/shared/users/index.jsx', 'Manage your users and their roles here.'],
  ['src/components/layout/authenticated-layout.jsx', 'layout-top-nav'],
  ['src/components/layout/authenticated-layout.jsx', 'Header'],
  ['src/components/layout/authenticated-layout.jsx', 'RbqHorizontalLogo'],
  ['src/components/layout/team-switcher.jsx', 'RbqAppIcon'],
  ['src/components/layouts/Header.jsx', 'Search...'],
  ['src/components/ui/Card.jsx', 'card'],
  ['src/components/ui/Table.jsx', 'table'],
  ['src/App.jsx', "components/layout/authenticated-layout.jsx"],
  ['src/components/layout/app-sidebar.jsx', 'function AppSidebar'],
  ['src/components/ui/sidebar.jsx', 'SidebarProvider'],
  ['src/index.css', '[data-slot="sidebar"][data-state="collapsed"] [data-slot="sidebar-gap"]'],
  ['src/index.css', 'transform: translateX(-256px);'],
  ['src/assets/rbq-branding/RbqAppIcon.jsx', '<svg'],
  ['src/assets/rbq-branding/RbqHorizontalLogo.jsx', '<svg'],
]

const sourceFiles = listFiles(src).filter((file) => /\.(js|jsx)$/.test(file))
for (const file of sourceFiles) {
  const content = readFileSync(file, 'utf8')
  if (content.includes('"@/') || content.includes("'@/")) {
    failures.push(`${path.relative(root, file)} still uses @ alias imports`)
  }
  if (/screen\.png|\.png['")]/.test(content)) {
    failures.push(`${path.relative(root, file)} still references PNG branding`)
  }
}

for (const [file, expected] of contentChecks) {
  const fullPath = path.join(root, file)
  if (existsSync(fullPath)) {
    const content = readFileSync(fullPath, 'utf8')
    if (!content.includes(expected)) {
      failures.push(`${file} missing content: ${expected}`)
    }
  }
}

if (failures.length) {
  console.error(failures.join('\n'))
  process.exit(1)
}

console.log('Static React JS frontend structure verified.')

function listFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const fullPath = path.join(directory, entry.name)
    return entry.isDirectory() ? listFiles(fullPath) : [fullPath]
  })
}
