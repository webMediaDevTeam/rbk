# Page Design System — RBQ Frontend

This document defines the rules and conventions that **every page** must follow so the whole app looks consistent. The **Users page** (`src/pages/shared/users/`) is the reference implementation. When building a new page, copy its structure and swap the domain data.

---

## 1. Theme & Colors

Never use raw Tailwind color classes (`bg-white`, `text-gray-500`, `border-gray-200`). Always use the semantic theme tokens defined in `src/styles/theme.css`, which switch between light/dark via the `.dark` class on `<html>`.

### Surface hierarchy (light / dark)

| Token | Role | Light value | Dark value |
|-------|------|-------------|------------|
| `--background` | page background | soft gray-white `oklch(0.965)` | deep dark `oklch(0.10)` |
| `--sidebar` | sidebar surface | lighter than bg `oklch(0.975)` | above bg `oklch(0.13)` |
| `--card` | cards, tables, navbar, inputs | pure white `oklch(1)` | elevated `oklch(0.15)` |
| `--popover` | dropdowns, drawers | pure white | elevated `oklch(0.18)` |
| `--muted` | hover/fill backgrounds | soft gray `oklch(0.94)` | dark gray `oklch(0.20)` |
| `--muted-foreground` | secondary text, placeholders | gray `oklch(0.55)` | light gray `oklch(0.65)` |
| `--border` | all borders & dividers | visible gray `oklch(0.90)` | subtle dark `oklch(0.25)` |
| `--primary` | main action buttons | dark `oklch(0.208)` | light `oklch(0.90)` |
| `--primary-foreground` | text on primary | near-white | dark |

### Tailwind side-effects

- Page backgrounds: `bg-background`
- Card-like surfaces: `bg-card text-card-foreground`
- Secondary text/labels: `text-muted-foreground`
- All borders: `border-border` (rows in tables too)
- Hover fill: `hover:bg-muted`
- Active/focus ring: `focus-visible:ring-2 focus-visible:ring-ring`
- Dark mode is automatic — do NOT add `dark:` variants unless needed for a specific token.

---

## 2. Layout & Shell

The layout lives in `src/components/layout/` (`Layout.jsx`, `Sidebar.jsx`, `Breadcrumb.jsx`). It provides the header (56px sticky), the collapsible sidebar (256px / 64px collapsed), and the main content area.

### Conventions

- The page component **never** renders its own `section`/`main` wrapper — the layout owns that. Each page returns a content container only.
- Content container: `<div className="max-w-7xl mx-auto space-y-6">` (matches Users page).
- Sidebar is **auto-collapsed below 860px** and becomes a slide-in drawer with a full-screen overlay (`height: 100dvh`). Do not fight this — build responsive content instead.

---

## 3. Page Anatomy (required order)

Every page = these blocks, in this exact order:

```
<div className="max-w-7xl mx-auto space-y-6">

  1. Breadcrumb          ← "Home / Page Name"
  2. PageHeader          ← title + subtitle + primary/secondary actions
  3. Toolbar             ← search + filters + view toggle
  4. Content             ← table or card grid (assembled from data)
  5. Pagination          ← rows-per-page + page navigation

</div>
```

### 3.1 Breadcrumb

```jsx
<nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
  <a href="#" onClick={(e) => e.preventDefault()}
     className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
    <Home className="h-3.5 w-3.5" />
    Home
  </a>
  <ChevronRight className="h-3.5 w-3.5" />
  <span className="font-medium text-foreground">Users</span>
</nav>
```

### 3.2 Page Header

- Title: `text-2xl font-bold tracking-tight text-foreground`
- Subtitle: `text-sm text-muted-foreground mt-1`
- Actions: `flex items-center gap-2`, right-aligned
  - **Primary action** → `<Button variant="default">` (dark/light filled)
  - **Secondary action** → `<Button variant="secondary">` (bordered card)
- On mobile (`sm:hidden`): multiple actions collapse into a `⋮` (`MoreVertical`) dropdown menu anchored right.

### 3.3 Toolbar

- Search uses `<Input>` (width `md:w-64` on desktop, full-width on mobile).
- Filters are `<Button variant="secondary">` pills on desktop (`hidden md:flex`).
- On mobile: search + a **Filter** `<Button variant="secondary">` (with active-count badge) in the same row; the drawer (`fixed inset-y-0 right-0 z-[90] w-80 max-w-[85vw] bg-card`) holds `<Select>` controls with Apply / Clear actions and a backdrop at `z-[80]`.
- View toggle (table/card) is a bordered segmented control, active side `bg-primary text-primary-foreground`. Hidden below `md`; small screens always render the card grid (enforced via `matchMedia` in the data hook).

### 3.4 Content

- **Table view** → `<Table>` from `src/components/ui/table.jsx` wrapped in `rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden`. Rows use `border-b border-border`.
- **Card grid view** → `<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">`.
- Cards: `flex flex-col rounded-xl border bg-card text-card-foreground p-5 shadow-sm`, selected state `border-primary ring-1 ring-primary`.

### 3.5 Pagination

- Full component in `src/pages/shared/users/components/Pagination.jsx` — copy it as-is (it's generic).
- Rows-per-page `<Select>` (10/20/50) + numbered buttons + first/last icons.
- Page number buttons: `variant="default"` when active, `variant="secondary"` otherwise.

---

## 4. Reusable UI Components

Put everything UI in `src/components/ui/` (lowercase filename). All use the `cn()` helper from `src/lib/utils.js`.

### Existing components

| Component | File | Variants / Notes |
|-----------|------|------------------|
| `Button` | `ui/button.jsx` | `default`, `secondary`, `outline`, `ghost`, `destructive`, `link`; sizes `sm`, `md`, `lg`, `icon`, `icon-sm` |
| `Badge` | `ui/badge.jsx` | `default`, `secondary`, `outline`, `destructive`, `success`, `warning`, `info` |
| `Input` | `ui/input.jsx` | themed, `h-9 rounded-lg bg-card` |
| `Select` | `ui/select.jsx` | same styling as Input |
| `Avatar` + `AvatarFallback` | `ui/avatar.jsx` | initials fallback `bg-primary text-primary-foreground` |
| `Table` family | `ui/table.jsx` | `Table`, `TableHeader`, `TableBody`, `TableFooter`, `TableRow`, `TableHead`, `TableCell`, `TableCaption` |
| `Card` family | `ui/card.jsx` | `Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardContent`, `CardFooter`, `CardAction` |

### Rules for UI components

- Default export for single components; named exports for families.
- Accept `className` and merge with `cn(...)` so callers can override.
- Use semantic tokens only, never hex/gray literals.
- Icons come from `lucide-react`, size `h-4 w-4`, color `text-muted-foreground` unless inside a primary button (then `text-primary-foreground`).

---

## 5. Data Hook Pattern

All data + state + filtering lives in a `use<Domain>.js` hook **next to the page** (e.g. `useUsers.js`). The page component only destructures and renders.

### `useX.js` must own
- initial/mock data
- all `useState`: list, selection, search, filters, pagination, view mode
- `useEffect` side effects (e.g. `matchMedia` forcing card view under 767px)
- derived data (`filteredX`)
- mutation helpers (`toggleSelect`, clearing filters, etc.)

### Return shape
```js
return {
  items: filteredItems,      // already filtered
  selectedItems, setSelectedItems,
  search, setSearch,
  filterA, setFilterA,
  filterB, setFilterB,
  currentPage, setCurrentPage,
  rowsPerPage, setRowsPerPage,
  viewMode, setViewMode,
  toggleSelect,
}
```

---

## 6. Domain Components

Break the page UI into small components in `src/pages/<scope>/<page>/components/`:

| Component | Job |
|-----------|-----|
| `Header.jsx` | title/subtitle + desktop buttons + mobile `⋮` dropdown |
| `Toolbar.jsx` | search, filters (+ drawer on mobile), view toggle |
| `<List>Table.jsx` | data table using `ui/table.jsx` |
| `<List>Card.jsx` | card variant using `ui/card`/`avatar` |
| `StatusBadge.jsx` | `<Badge variant="success|destructive|info|...">` mapped from status values |
| `Pagination.jsx` | generic pagination (copy from users page) |

---

## 7. Responsive Rules (summary)

| Breakpoint | Behavior |
|------------|----------|
| `< 767px` (mobile) | sidebar drawer via hamburger; card grid only (no table toggle); search + Filter button same row; header actions in `⋮` dropdown; filter drawer from right |
| `768–859px` (tablet) | same as mobile for toolbar/cards; sidebar still auto-collapsed |
| `≥ 860px` (desktop) | sidebar toggleable (256px / 64px); full toolbar with filter pills + segmented view toggle |

- Tables always wrap in `overflow-x-auto` (safe on tablets).
- Card grids collapse to `grid-cols-1` on small screens.
- Never use fixed pixel widths for interactive controls on mobile.

---

## 8. Checklist for a new page

1. Create `src/pages/<scope>/<page>/index.jsx` → returns `<div className="max-w-7xl mx-auto space-y-6">`.
2. Add Breadcrumb (Home / Page Name).
3. Add `<Header>` with title + subtitle + actions (primary in `default`, secondary in `secondary`).
4. Add `<Toolbar>` with `<Input>` search + filter pills + view toggle (desktop), Filter-drawer (mobile).
5. Add table/card content built from the `use<Domain>` hook data.
6. Add `<Pagination>`.
7. Put all state in `use<Domain>.js`.
8. Use only `src/components/ui/*` + semantic tokens.
9. Register in `src/routes/AppRoutes.jsx` (`pages` object).
10. Verify: light & dark theme, mobile/tablet breakpoints, `npm run build` passes.