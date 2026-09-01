# NamVoy — Technical Build-Out (v2, PHP stack)

**Build context:** Solo founder, AI-assisted (Claude Code / Cursor), strong core PHP knowledge, no framework familiarity. Stack chosen so you can read, debug, and extend every line yourself — that's the actual security control on a solo build, not a nice-to-have.

**Unchanged from v1:** product scope, phasing (catalog first, bidding deferred), schema shape, PSP routing logic, payout ledger design, Phase 2 activation trigger (30 verified operators, ≥3 bookings each). Only the implementation layer changes.

---

## 1. PDR — unchanged from v1

See prior doc for full detail. Summary: Phase 1 = catalog marketplace, Da Nang + Hoi An, 15% commission. Phase 2 = reverse-bidding, schema-ready but not built, gated behind the 30-operator threshold.

---

## 2. Architecture (revised)

| Layer | Choice | Why |
|---|---|---|
| Language | Core PHP 8.2+ | Your existing fluency — you can read and debug everything Claude Code generates |
| Routing | Slim Framework 4 (micro-framework, not full-stack) | Hand-rolled routers are a common source of auth-bypass bugs; Slim adds routing + middleware only, nothing else |
| DB access | **mysqli, prepared statements only** | `bind_param()` on every query touching user input — non-negotiable, not a style choice |
| Database | MySQL 8 | Matches mysqli |
| Auth/sessions | Core PHP sessions + a single shared `requireRole($allowedRoles)` function, called at the top of every protected file | Centralizes the check — an AI-generated new page can't silently skip it if it's one function call, not a hand-written check each time |
| CSRF | Single reusable token generate/verify pair, included on every mutating form | ~20 lines, applied everywhere without exception |
| Env/secrets | `vlucas/phpdotenv` | PSP keys, DB credentials out of source control |
| Migrations | Versioned `.sql` files, tracked in a `schema_migrations` table, run manually | No migration DSL needed — just discipline and a log |
| Payments | PSP abstraction — one PHP interface, one adapter class per provider (Stripe PHP SDK, Razorpay PHP SDK, PayU PHP SDK) | Same reasoning as v1 — business logic must never touch a provider-specific API directly |
| Hosting | VPS (DigitalOcean/Hetzner) via a simple deploy script, or a managed PHP host | Cheaper than managed Node+Postgres stack at this volume; you already know how to run a PHP server |
| File storage | Local disk + backup script initially, or S3-compatible object storage (e.g. Backblaze B2) once volume justifies it | Avoid Supabase Storage dependency — no reason to add a Node-ecosystem service to a PHP stack |
| Background jobs | Cron jobs calling PHP CLI scripts | Booking reminders now; bid-expiry logic later (dormant) |

**Payout approach: unchanged from v1** — manual/ledger-based, not automated, for the same FEMA/RBI/SBV reasons. This was never framework-dependent.

---

## 3. Database schema (MySQL / mysqli)

Same entities and relationships as v1, translated to MySQL syntax (UUIDs as CHAR(36), generated in PHP with a UUID library or `random_bytes`-based generator since MySQL has no native `gen_random_uuid()`).

```sql
-- ===== CORE ENTITIES =====

CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(255),
  role ENUM('traveler','business','admin') NOT NULL,
  country VARCHAR(100),
  preferred_currency VARCHAR(10),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE businesses (
  id CHAR(36) PRIMARY KEY,
  owner_user_id CHAR(36) NOT NULL,
  business_name VARCHAR(255) NOT NULL,
  contact_email VARCHAR(255) NOT NULL,
  contact_phone VARCHAR(50),
  location ENUM('da_nang','hoi_an') NOT NULL,
  verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verification_docs JSON,
  payout_bank_details JSON,          -- encrypt at application layer before insert
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE destinations (
  id CHAR(36) PRIMARY KEY,
  slug VARCHAR(100) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  hero_image_url VARCHAR(500)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id CHAR(36) PRIMARY KEY,
  slug VARCHAR(100) UNIQUE NOT NULL,
  name VARCHAR(255) NOT NULL,
  icon VARCHAR(100)
) ENGINE=InnoDB;

CREATE TABLE experiences (
  id CHAR(36) PRIMARY KEY,
  business_id CHAR(36) NOT NULL,
  destination_id CHAR(36) NOT NULL,
  category_id CHAR(36) NOT NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) UNIQUE NOT NULL,
  description TEXT NOT NULL,
  duration_minutes INT NOT NULL,
  max_group_size INT NOT NULL,
  price_amount DECIMAL(10,2) NOT NULL,
  price_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  languages JSON,
  included_items JSON,
  cancellation_policy TEXT,
  status ENUM('draft','pending_review','published','suspended') NOT NULL DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id),
  FOREIGN KEY (destination_id) REFERENCES destinations(id),
  FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_dest_status (destination_id, status),
  INDEX idx_cat_status (category_id, status)
) ENGINE=InnoDB;

CREATE TABLE experience_images (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  display_order INT DEFAULT 0,
  FOREIGN KEY (experience_id) REFERENCES experiences(id)
) ENGINE=InnoDB;

CREATE TABLE experience_availability (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  date DATE NOT NULL,
  slots_total INT NOT NULL,
  slots_booked INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_exp_date (experience_id, date),
  FOREIGN KEY (experience_id) REFERENCES experiences(id)
) ENGINE=InnoDB;

-- ===== BOOKING & PAYMENT =====

CREATE TABLE bookings (
  id CHAR(36) PRIMARY KEY,
  experience_id CHAR(36) NOT NULL,
  traveler_id CHAR(36) NOT NULL,
  booking_date DATE NOT NULL,
  guest_count INT NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  commission_amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','confirmed','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (experience_id) REFERENCES experiences(id),
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  INDEX idx_traveler (traveler_id),
  INDEX idx_experience (experience_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id CHAR(36) PRIMARY KEY,
  booking_id CHAR(36) NOT NULL,
  provider ENUM('stripe','razorpay','payu','vnpay') NOT NULL,
  provider_payment_id VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  status ENUM('pending','succeeded','failed','refunded') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

CREATE TABLE payouts (
  id CHAR(36) PRIMARY KEY,
  business_id CHAR(36) NOT NULL,
  booking_id CHAR(36) NOT NULL,
  amount_owed DECIMAL(10,2) NOT NULL,
  status ENUM('accrued','paid','disputed') NOT NULL DEFAULT 'accrued',
  paid_at DATETIME NULL,
  payout_batch_ref VARCHAR(255),
  FOREIGN KEY (business_id) REFERENCES businesses(id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  INDEX idx_business_status (business_id, status)
) ENGINE=InnoDB;

CREATE TABLE reviews (
  id CHAR(36) PRIMARY KEY,
  booking_id CHAR(36) NOT NULL,
  experience_id CHAR(36) NOT NULL,
  traveler_id CHAR(36) NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (experience_id) REFERENCES experiences(id),
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ===== PHASE 2 — SCHEMA-READY, DORMANT =====

CREATE TABLE trip_requests (
  id CHAR(36) PRIMARY KEY,
  traveler_id CHAR(36) NOT NULL,
  destination_id CHAR(36),
  budget_amount DECIMAL(10,2),
  budget_currency VARCHAR(10),
  duration_days INT,
  interests JSON,
  travel_start DATE,
  travel_end DATE,
  status ENUM('open','matched','expired','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (traveler_id) REFERENCES users(id),
  FOREIGN KEY (destination_id) REFERENCES destinations(id)
) ENGINE=InnoDB;

CREATE TABLE bids (
  id CHAR(36) PRIMARY KEY,
  trip_request_id CHAR(36) NOT NULL,
  business_id CHAR(36) NOT NULL,
  proposed_price DECIMAL(10,2) NOT NULL,
  proposal_details TEXT,
  status ENUM('submitted','accepted','rejected','expired') NOT NULL DEFAULT 'submitted',
  expires_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (trip_request_id) REFERENCES trip_requests(id),
  FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB;

-- ===== MIGRATION TRACKING =====
CREATE TABLE schema_migrations (
  filename VARCHAR(255) PRIMARY KEY,
  applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

**No RLS equivalent in MySQL** — authorization must be enforced entirely in application code via `requireRole()`. This is the single biggest risk-shift versus the Supabase version: there is no database-level backstop if an app-layer check is missed. That's exactly why `requireRole()` must be called at the top of every protected file, with no exceptions, ever.

---

## 4. API specification — same routes as v1, PHP/Slim implementation

Auth: session-based (PHP native sessions), not JWT — simpler and correct for a server-rendered PHP app. `requireRole(['traveler'])` etc. called first line of every protected route handler.

| Method | Route | Auth | Purpose |
|---|---|---|---|
| GET | `/api/destinations` | Public | List destinations |
| GET | `/api/experiences` | Public | Browse/filter published experiences |
| GET | `/api/experiences/{slug}` | Public | Experience detail + availability |
| POST | `/api/bookings` | `requireRole(['traveler'])` | Create booking (pending) |
| POST | `/api/bookings/{id}/checkout` | `requireRole(['traveler'])` | Create PSP checkout session |
| POST | `/api/webhooks/stripe` | PSP signature verify | Payment confirmation |
| POST | `/api/webhooks/razorpay` | PSP signature verify | Same, Razorpay |
| POST | `/api/webhooks/payu` | PSP signature verify | Same, PayU |
| GET | `/api/account/bookings` | `requireRole(['traveler'])` | My bookings |
| POST | `/api/partner/onboarding` | Public → creates `business` role | Business signup |
| POST | `/api/partner/experiences` | `requireRole(['business'])` + ownership check | Create experience |
| PATCH | `/api/partner/experiences/{id}` | `requireRole(['business'])` + ownership check | Edit own experience |
| GET | `/api/partner/bookings` | `requireRole(['business'])` + ownership check | Bookings for own experiences |
| GET | `/api/partner/payouts` | `requireRole(['business'])` + ownership check | Own payout ledger |
| GET | `/api/admin/experiences/pending` | `requireRole(['admin'])` | Approval queue |
| PATCH | `/api/admin/experiences/{id}/approve` | `requireRole(['admin'])` | Publish/reject |
| POST | `/api/admin/payouts/batch` | `requireRole(['admin'])` | Mark payout batch paid |

**Ownership check pattern (every business-role route):** `requireRole` confirms the session role; a *second*, separate check confirms the authenticated business owns the resource being mutated (`WHERE business_id = ? AND id = ?` in the query itself, never trust an ID from the request body alone). This is the check that's easy to forget and the one most worth being paranoid about.

---

## 5. Build order — same sequence as v1

1. Project init, Slim routing skeleton, mysqli connection wrapper (prepared-statement helper functions), schema + migrations runner, session-based auth + `requireRole()`.
2. Business onboarding + admin approval queue.
3. Experience CRUD (business portal) — validate with 2-3 real operators before proceeding.
4. Public catalog (destinations, browse, detail pages).
5. Booking + Stripe checkout + webhook (first PSP only — prove the loop before adding Razorpay/PayU).
6. Payout ledger + admin reconciliation view.
7. Reviews.
8. Razorpay + PayU integration.
9. Operator recruitment to 30 verified/≥3-bookings-each.
10. Phase 2 — only once step 9 is genuinely true.

---

## 6. AI-coding prompts (PHP stack)

### 6.1 Master prompt

```
You are building NamVoy, a Vietnam travel booking platform, Phase 1 only.

STACK: Core PHP 8.2+, Slim Framework 4 for routing, mysqli for all database
access, MySQL 8 database, session-based auth (no JWT).

SCOPE: Catalog marketplace for bookable experiences in Da Nang and Hoi An only.
Travelers browse and book fixed-price experiences from verified local operators.
15% commission on completed bookings.

HARD CONSTRAINTS — these are non-negotiable, not style preferences:
1. Every database query touching any user-supplied value MUST use
   mysqli::prepare() and bind_param(). Never concatenate a variable into a
   SQL string, under any circumstance, including admin-only routes.
2. Every route handler that requires authentication MUST call
   requireRole($allowedRoles) as its first line. Never write an inline,
   route-specific auth check — always call the shared function.
3. Every route that mutates a resource owned by a business (experiences,
   bookings-for-my-experiences, payouts) MUST verify ownership in the SQL
   WHERE clause itself (business_id = ? AND id = ?), never by trusting an
   ID from the request body or URL alone.
4. Every form that mutates data MUST include and verify a CSRF token using
   the shared csrf_token()/csrf_verify() pair.
5. Do NOT build any bidding, trip-request, or "custom trip" UI or matching
   logic. The trip_requests and bids tables exist in the schema but must
   remain unused by application code until explicitly instructed otherwise.
6. Payments: implement a PaymentProviderInterface with one adapter class per
   provider. Implement the Stripe adapter first and fully; stub Razorpay/PayU
   adapters with the same interface but do not wire them into checkout
   routing yet.
7. Payouts to operators are NOT automated. Build the payouts ledger table and
   an admin view to mark payouts as manually paid. Do not integrate any
   payout/transfer API.
8. Store all secrets (DB credentials, PSP API keys) in .env via
   vlucas/phpdotenv. Never hardcode a credential in source.

Use the schema in [paste §3], the API spec in [paste §4], and follow the
build order in [paste §5] exactly — do not build ahead of the current step.

Before writing any code, confirm you understand constraints 1-4 specifically,
since those are the ones most likely to be silently skipped under time
pressure.
```

### 6.2 Phase-specific prompts

**Step 1 — Foundation:**
```
Set up the Slim Framework project. Create a mysqli connection wrapper with
a helper function `queryPrepared($conn, $sql, $types, $params)` that all
future database code must use — no raw mysqli_query() calls with variables
in the SQL string anywhere in the codebase. Run the schema migrations
(including trip_requests and bids, unused). Build session-based auth with
requireRole($allowedRoles) as a single shared function in a file every
protected route includes.
```

**Step 2 — Business onboarding:**
```
Build the /become-a-host signup flow: business_name, contact info, location
(da_nang | hoi_an only, enforced server-side), verification doc upload to
local/S3-compatible storage. Build /admin/businesses approval queue using
requireRole(['admin']). No experience creation permitted until a business's
verification_status = 'approved'.
```

**Step 3 — Experience CRUD:**
```
Build /partner/experiences (list, create, edit) restricted to requireRole(
['business']) AND an ownership check on every query. New experiences save
with status='pending_review'. Build the availability calendar input. Build
/admin/experiences/pending approval queue for requireRole(['admin']).
```

**Step 4 — Public catalog:**
```
Build /destinations, /destinations/{slug}, /experiences (filterable), and
/experiences/{slug} detail pages. Published experiences only (status =
'published' enforced in the query itself, not just hidden in the UI).
No auth required.
```

**Step 5 — Booking + payment:**
```
Build booking creation with an availability check inside a transaction
(SELECT ... FOR UPDATE on experience_availability to prevent double-booking
race conditions), Stripe checkout session creation via the
PaymentProviderInterface, Stripe webhook handler with signature verification
updating booking status on success, /booking/success page. Commission (15%)
calculated and stored on the booking row at creation time, not recalculated
later.
```

**Step 6 — Payout ledger:**
```
On booking status transition to 'completed', insert a payouts row
(amount_owed = total_amount - commission_amount) inside the same transaction
that sets the booking status. Build /admin/payouts (requireRole(['admin']))
listing accrued payouts grouped by business, with an action to mark a batch
'paid' and store a reference note. No transfer API integration.
```

---

## What changed from the Next.js version, and what didn't

**Changed:** language, routing library, DB driver, hosting model, auth mechanism (session vs JWT), file storage default.

**Did not change:** product scope, phasing decision, Phase 2 activation trigger, schema shape and relationships, PSP abstraction pattern, payout-as-ledger-not-automation decision, build order, and the core discipline requirement (auth check + ownership check on every mutating route) — that requirement exists regardless of stack, and MySQL's lack of RLS makes it *more* load-bearing here than in the Supabase version, not less.
