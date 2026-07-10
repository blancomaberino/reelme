# T-008 — Filament admin panel scaffold + Users resource

- **Phase:** M0 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-003
- **Target paths:** `apps/api/app/Filament/`
- **Spec refs:** [01-architecture.md#tech-stack](../01-architecture.md#tech-stack)

## Context
Per ADR-012 all admin/ops tooling lives in a Filament panel inside `apps/api` (app repo, not this plans folder) — there is deliberately **no** `/api/v1/admin/*` REST surface. T-003 provided `users` with the `is_admin` flag this panel gates on. This task ships the panel shell plus the first resource (Users) so ops can manage roles and ban accounts; later tasks add moderation queues (T-035, T-049) and dashboards (T-051).

## Implementation steps
1. `composer require filament/filament` (resolve latest stable at install time), then `php artisan filament:install --panels`. This creates `app/Providers/Filament/AdminPanelProvider.php` with panel id `admin`, path `/admin`.
2. Restrict access to admins: implement `FilamentUser` on the `User` model:
   ```php
   public function canAccessPanel(Panel $panel): bool
   {
       return $this->is_admin;
   }
   ```
   Filament enforces this in ALL environments (unlike the local-only default some guides suggest). Panel uses the standard web guard + session auth (server-rendered Livewire; separate from the API's Sanctum tokens — per FR-51 admin auth is protected separately from user auth).
3. Create an admin seeder for local/dev: `database/seeders/AdminUserSeeder.php` creating `admin@reelmap.test` with `is_admin = true` (documented in README; never run in production — production admins are flipped via artisan tinker or a dedicated `php artisan app:make-admin {email}` command — create that small command too).
4. Users resource: `php artisan make:filament-resource User --generate`. Then customize `app/Filament/Resources/UserResource.php`:
   - **Table**: columns `name`, `username`, `email`, role badges (`is_admin`, `is_influencer`, `is_restaurant_owner` as boolean icon columns), `created_at`; searchable on `name`, `username`, `email`; filters: role flags (ternary filters), `TrashedFilter` (banned/active).
   - **Form**: `name`, `username`, `email`, `bio`, toggles for `is_admin`, `is_influencer`, `is_restaurant_owner`, `is_public`. Do NOT expose `password`, stripe columns, or `preferred_analysis_model` editing at M0 (read-only display is fine).
   - **Ban action**: the data model has no `banned_at` column — ban = soft delete (02-data-model `users.deleted_at`) plus token revocation. Add a table/record action `ban`: `$record->tokens()->delete(); $record->delete();` with confirmation modal; and an `unban` action (`restore()`) visible on trashed records. Guard: an admin cannot ban themselves (disable the action when `$record->is($currentUser)`).
   - Eloquent query must include trashed so banned users remain visible: `getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])` per Filament soft-delete recipe.
5. Navigation group "Users & Access"; panel branding name "Reelmap Admin".
6. Pest feature tests (Livewire + Filament testing helpers, `composer require --dev livewire/livewire` already transitive):
   - Guest hitting `/admin` → redirected to `/admin/login`; non-admin user logging in → 403.
   - Admin can access `/admin` and the Users list; search by username returns the row.
   - Edit form toggles `is_admin` and persists.
   - Ban action soft-deletes the user, deletes their Sanctum tokens, and login via `POST /api/v1/auth/login` now fails for that user; unban restores.

## Acceptance criteria
- [ ] Filament (latest stable) installed with a panel at `/admin`; login screen renders.
- [ ] `canAccessPanel` restricts the panel to `is_admin` users in every environment; non-admins get 403, guests get the login redirect.
- [ ] Users resource lists all users with search over name/username/email and role-flag filters.
- [ ] Admin can edit role flags (`is_admin`, `is_influencer`, `is_restaurant_owner`) from the edit form.
- [ ] Ban action soft-deletes the user AND revokes all Sanctum tokens; banned users cannot log in via the API; unban (restore) works; self-ban is prevented.
- [ ] No `/api/v1/admin/*` routes exist (`php artisan route:list` clean).
- [ ] Pest tests cover panel access control, search, role editing, and ban/unban.

## Verification
```bash
cd apps/api
composer test -- --filter=Filament
php artisan db:seed --class=AdminUserSeeder
# browser: http://localhost/admin → login admin@reelmap.test → Users list, search, edit, ban
php artisan route:list --path=api/v1/admin   # no results
```

## Gotchas
- Filament uses the `web` guard + sessions — ensure `SESSION_DRIVER` works (database/redis/cookie) and CSRF applies to `/admin`; the API remains stateless Sanctum. Don't try to authenticate the panel with bearer tokens.
- `canAccessPanel` is the gate that matters; forgetting it leaves `/admin` open to ANY authenticated user in production.
- Soft-delete-as-ban interacts with unique citext indexes: a banned user's email/username stays reserved (unique index has no `deleted_at` carve-out in the data model) — that's intended; document it in the resource.
- Banning must revoke `personal_access_tokens` — soft delete alone leaves issued bearer tokens valid (Sanctum doesn't check `deleted_at` on the tokenable automatically in all versions; the T-003 login test covers new logins, add a `/me` check with the old token in the ban test).
- Filament + Vite: `php artisan filament:install` publishes its own compiled assets — no Node build needed in `apps/api`; don't add one.
- Check the Filament version's Laravel 13 compatibility at install time; if the latest stable lags the framework major, pin the compatible release and note it in composer.json.

## Log
- **2026-07-09** — Done. **PR #4** (`feat/t008-filament` → `feat/t007-horizon`, stacked). **Filament ^5.6** (supports Laravel 13). All gates green: `composer test` 43 passing / 104 assertions (12 Filament tests), Pint (66 files), PHPStan L6. Panel verified over HTTP (`/admin/login` 200, `/admin` 302); `AdminUserSeeder` seeds local admin.
- **Filament 5 structure**: resource split into `UserResource` + `Tables/UsersTable` + `Schemas/UserForm` + `Pages/`. Table actions tested via `Filament\Actions\Testing\TestAction::make('ban')->table($record)` (not `callTableAction`).
- **Key decisions**:
  - Role flags are guarded (non-mass-assignable) per T-003; the edit/create pages `forceFill($data)` so the admin panel can set them. **/security-review traced Filament v5 internals and confirmed this is safe** — form state is pruned to the declared fields, so no mass-assignment hole.
  - Ban = `tokens()->delete()` + soft `delete()`; unban = `restore()`; self-ban blocked via server-side `disabled()`. `getEloquentQuery()` drops the SoftDeletingScope so banned users stay listable.
  - `canAccessPanel` returns `(bool) $this->is_admin` (coerces null on freshly-built factory models). Enforced in **all** environments (unlike Horizon's local bypass).
  - Filament published assets (`public/{css,js,fonts}/filament`) are **gitignored** — republished by `filament:upgrade` in `post-autoload-dump`.
  - Removed the now-deprecated `horizon:publish` composer hook (Horizon 5 dropped asset publishing) — small cross-task cleanup carried on this stacked branch.
  - **/frontend-design not used**: Filament is a batteries-included admin framework with its own design system; standard resource scaffolding isn't custom UI design.
  - **/simplify** clean; **/security-review** no Critical/High — applied both Low notes (seeder production guard, direct non-admin resource test).
