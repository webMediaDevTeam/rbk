src/
├── assets/                           # Global static files
│   ├── images/                       # Brand logos, icons, placeholders
│   └── styles/                       # Global Tailwind / CSS imports
│
├── components/                       # Shared components across the app
│   ├── ui/                           # Base UI elements (Buttons, Inputs, Modals, Badges)
│   └── layouts/                      # Layout wrappers (Sidebar, Header, MainLayout)
│
├── context/                          # Global React Contexts
│   └── AuthContext.js                # Auth session state provider
│
├── stores/                           # State management (Zustand / Redux / Jotai)
│   ├── useAuthStore.js               # User auth & role state
│   └── useClientStore.js             # Shared client selection state
│
├── routes/                           # Routing and Route Guards
│   ├── AppRoutes.jsx                 # Main React Router setup
│   ├── ProtectedRoute.jsx            # Authentication guard
│   └── RoleBasedRoute.jsx           # Role access guard (SUPER_ADMIN, ADMIN, etc.)
│
├── services/                         # API services & Axios instances
│   ├── api.js                        # Axios instance with JWT interceptors
│   └── endpoints/                    # Endpoint modules (auth, clients, enterprises)
│
├── utils/                            # Helper functions & constants
│   ├── constants.js                  # App-wide constants (Roles, ClientStatuses)
│   └── helpers.js                    # Date formatters, string parsers
│
└── pages/                            # Role-based modular page architecture
    ├── shared/                       # Public or multi-role accessible pages
    │   ├── connexion/
    │   │   ├── index.jsx             # Public login view
    │   │   └── useConnexion.js      # Login logic & API calls
    │   │   └── users
    │   │       ├── index.jsx             # User list base on the connected user role (exmp : if super admin he can handerl all if entres he can handel only his comercielle users )
    │   │       ├── useUsers.js           # Profile update hooks
    │   │       └── components/
    │   │       
    │   ├── profil/
    │   │   ├── index.jsx             # User profile view
    │   │   ├── useProfil.js          # Profile update hooks
    │   │   └── components/
    │   │       └── AvatarUploader.jsx
    │
    ├── comercial/                    # COMERCIAL role exclusive pages
    │   └── if he have sefic oages for hem only 
    │
    ├── entreprise/                   # ENTREPRISE role pages (+ Admin / SuperAdmin)
    │   └── if he have sefic oages for hem only 
    │
    ├── admin/                        # ADMIN role pages (+ SuperAdmin)
    │   └── if he have sefic oages for hem only 
    │
    └── superAdmin/                   # SUPER_ADMIN exclusive pages
    │   └── if he have sefic oages for hem only 