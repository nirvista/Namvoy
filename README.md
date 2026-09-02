# NamVoy

Vietnam travel booking platform — Phase 1 catalog marketplace (Da Nang + Hoi An).

Stack: core PHP 8.2+, Slim 4 (routing/middleware only), mysqli prepared statements, MySQL 8, native PHP sessions, `vlucas/phpdotenv`.

## Setup

```bash
composer install
cp .env.example .env            # fill in DB_* values
mysql -u root -e "CREATE DATABASE namvoy; CREATE USER 'namvoy'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON namvoy.* TO 'namvoy'@'localhost';"
composer migrate                # applies database/migrations/*.sql
composer start                  # http://localhost:8080
```

Create an admin (never possible via the web app):

```bash
php bin/create-admin.php admin@example.com "Full Name"
```

## Hard rules (see docs/ for the full build-out spec)

1. **All SQL touching user input goes through `queryPrepared()`** (`App\Database::queryPrepared`, or the `fetchOne` / `fetchAll` / `execute` helpers built on it). Never concatenate a variable into SQL. The only `multi_query()` in the codebase is the migration runner, which executes static files.
2. **Every protected route handler calls `Auth::requireRole([...])` as its first line.** No inline role checks, ever. MySQL has no RLS — this function is the entire authorization layer.
3. **Business-owned resources are checked in the SQL `WHERE` clause** (`WHERE business_id = ? AND id = ?`), never by trusting an ID from the request alone.
4. **Every mutating route is CSRF-protected** by `CsrfMiddleware` (applied to the main route group in `config/routes.php`). Token from the `_csrf` form field or the `X-CSRF-Token` header; fetch it from `GET /api/auth/csrf`. PSP webhook routes live in a separate group without CSRF and verify provider signatures instead.
5. `trip_requests` and `bids` exist in the schema but must not be referenced by application code (Phase 2, dormant).

## Layout

```
public/index.php          front controller (Slim app + global middleware)
config/bootstrap.php      autoload + .env + error reporting (shared by web and CLI)
config/routes.php         all routes; CSRF group vs webhook group
src/Database.php          mysqli wrapper: queryPrepared(), fetchOne(), fetchAll(), execute(), transaction()
src/Auth.php              sessions, requireRole(), login/logout/register/attempt
src/Csrf.php              token()/verify()/field()
src/Middleware/           SessionMiddleware, CsrfMiddleware, JsonErrorMiddleware
src/Controllers/          AuthController, HealthController
src/Exceptions/           HttpException (401/403/422 subclasses) -> JSON errors
src/helpers.php           env(), queryPrepared(), uuid4(), e()
database/migrations/      versioned .sql files, tracked in schema_migrations
bin/migrate.php           migration runner (--status to list)
bin/create-admin.php      admin user CLI
```

## Routes (Step 1)

| Method | Route | Auth |
|---|---|---|
| GET | `/api/health` | public |
| GET | `/api/auth/csrf` | public |
| POST | `/api/auth/register` | public (creates `traveler`) |
| POST | `/api/auth/login` | public |
| POST | `/api/auth/logout` | public |
| GET | `/api/auth/me` | `requireRole(['traveler','business','admin'])` |

## Routes (Step 2 — partner onboarding + admin approval)

| Method | Route | Auth |
|---|---|---|
| POST | `/api/partner/onboarding` | public → creates `business` user + `pending` business (multipart: `email`, `password`, `full_name`, `business_name`, `contact_email`, `contact_phone`, `location` ∈ `da_nang\|hoi_an`, `verification_docs[]` 1–5 PDF/JPEG/PNG ≤5 MB) |
| GET | `/api/partner/me` | `requireRole(['business'])` — own business profile |
| GET | `/api/admin/businesses?status=pending` | `requireRole(['admin'])` — approval queue |
| GET | `/api/admin/businesses/{id}` | `requireRole(['admin'])` |
| PATCH | `/api/admin/businesses/{id}` `{"verification_status":"approved"\|"rejected"}` | `requireRole(['admin'])` |
| GET | `/api/admin/businesses/{id}/docs/{docId}` | `requireRole(['admin'])` — streams a verification document |

Verification documents are stored via `App\Storage\StorageInterface` (`LocalStorage` → `storage/uploads/`, outside the web root; override with `STORAGE_PATH`). They are never URL-addressable; admins fetch them through the authenticated route above. Experience creation (Step 3) must check `verification_status = 'approved'` in the query.

Errors are JSON: `{"error": "...", "errors": {field: msg}}` with 401/403/422/404/500 status codes.

## Adding a migration

Create `database/migrations/NNNN_description.sql` (next number in sequence) and run `composer migrate`. DDL auto-commits in MySQL, so a failed migration must be repaired by hand before re-running.
