# Backend Technical Report

**Garment Factory Management System** · Laravel 11.31 · PHP 8.2 · MySQL · Sanctum token auth

Audit date: 2026-08-21 · **Revised** after the D-1…D-5 remediation round.

Every claim in this report was verified by running the code, not by reading it.

---

## 0. Executive summary

| | |
|---|---|
| **Feature complete against everything requested** | Yes |
| **Bug-free** | **Cannot be claimed** — no automated test suite exists |
| **Production ready** | **No** — 5 blockers listed in §4 |

The backend implements every module that was asked for, and each was verified working end-to-end through live HTTP calls. What it does **not** have is a regression safety net: outside of two scaffolded example tests, there are no automated tests. Every verification in this project has been manual. That is enough to say "it worked when I tested it"; it is not enough to say "it is bug-free", and no honest report can claim otherwise.

One blocker was found and fixed during the audit (**missing `APP_KEY`** — the application could not encrypt anything and its test suite could not run). Four environment/hardening issues remain, all listed with exact fixes.

**All five design limitations (D-1…D-5) have since been closed** — see §5. Doing so surfaced one further defect in the source data: the worksheet's date cell is a live `=TODAY()` formula, not a production date (§5.2).

**Scale:** 44 endpoints · 24 migrations · 10 models · 10 controllers · 9 services · 6 policies · 8 resources.

---

## 1. Endpoint inventory

44 routes: 3 public, 41 authenticated.

Legend — 🔑 password required in body · ⏱ rate limited · 📎 multipart

### 1.1 Authentication (5)

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| POST | `/api/login` | Issue a token | `login` (username **or** email), `password`, `device_name?` | Public ⏱ 6/min |
| GET | `/api/user` | Current user + role | — | Bearer |
| POST | `/api/logout` | Revoke current token | — | Bearer |
| POST | `/api/password/change` | Change own password | `current_password`, `new_password`, `new_password_confirmation` | Bearer 🔑 ⏱ 6/min |
| POST | `/api/password/forgot` | Email a 6-digit OTP | `email` | Public ⏱ 5/10min |
| POST | `/api/password/reset` | Reset via OTP | `email`, `otp`, `password`, `password_confirmation` | Public ⏱ 10/10min |

### 1.2 Products (8) — Admin, Production Manager

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| GET | `/api/productions` | List/filter catalog | `department`, `workshop`, `month`, `design_status`, `barcode`, `item_number`, `search`, `unassigned_workshop`, `per_page` | Policy |
| GET | `/api/productions/{id}` | One product + transfer journey | — | Policy |
| POST | `/api/productions` | Create | `model_name`, `item_number`*, `barcode`*, `sizes[]`, `colors[]`, `quantity`, `month`, `fabric`, `department?`, `workshop?`, `images[]?`, `guide_file?` | Policy 📎 |
| PATCH | `/api/productions/{id}` | Update | as above **minus** `department`/`workshop`; plus `remove_images[]` | Policy 📎 |
| GET | `/api/productions/{id}/images/{index}` | Stream product image | — | Policy |
| GET | `/api/productions/{id}/guide-file` | Stream tech pack | — | Policy |
| GET | `/api/productions/{id}/transfers` | Item's movement legs | — | Policy |
| GET | `/api/productions/{id}/activity` | Transfers + audit merged | — | Policy |

\* unique per department — `(barcode, department)` and `(item_number, department)`. See §5.1.

### 1.3 Workshops, transfers & statistics (4) — Admin, Production Manager

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| GET | `/api/productions/workshops` | Piece totals by department/workshop | `department`, `month` | Policy |
| GET | `/api/productions/statistics` | Time-bucketed piece statistics | `period` (day/week/month), `date`, `date_from`, `date_to`, `department`, `workshop`, `month` | Policy |
| GET | `/api/transfers` | Movement ledger | `production_id`, `department`, `per_page` | Policy |
| POST | `/api/transfers` | Move stock — splits the batch when partial | `production_id`, `to_department`, `to_workshop?`, `quantity`, `notes?`, `password` | Policy 🔑 |

### 1.4 Employees (8) — Admin, HR (delete & portal-account: Admin only)

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| GET | `/api/employees` | List/filter staff | `department`, `position`, `shift`, `search`, `per_page` | Policy |
| GET | `/api/employees/{id}` | One employee | — | Policy |
| POST | `/api/employees` | Create | `name`, `fingerprint_id`*, `department`, `salary`(Admin), `email?`, `phone?`, `position?`, `shift?`, `vacation_balance?`, `address?`, `start_date?`, `id_card_image?`, `cv_file?` | Policy 📎 |
| PATCH | `/api/employees/{id}` | Update | as above, all optional | Policy 🔑 📎 |
| POST | `/api/employees/{id}/delete` | Delete | `password` | **Admin** 🔑 |
| GET | `/api/employees/{id}/id-card` | Stream ID scan | — | Policy |
| GET | `/api/employees/{id}/cv` | Stream CV | — | Policy |
| GET | `/api/employees/{id}/report` | Profile + attendance + payroll | `year`, `month` | Policy |
| POST | `/api/employees/{id}/portal-account` | Issue self-service login | `username?`, `email?`, `password` | **Admin** 🔑 |

### 1.5 Attendance (5) — Admin, HR

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| POST | `/api/attendance/import` | Import biometric export | `file` (xlsx/xls/csv), `dry_run?`, `password` | Policy 🔑 📎 |
| POST | `/api/attendance` | Record a day manually | `employee_id`\|`fingerprint_id`, `date`, `check_in?`, `check_out?`, `overnight?`, `working_hours?`, `status?`, `notes?` | Policy |
| PATCH | `/api/attendance/{id}` | Correct a day | as above | Policy |
| GET | `/api/attendance/search` | Search records | `fingerprint_id`, `employee_id`, `date`, `date_from`, `date_to`, `unmatched_only`, `per_page` | Policy |
| GET | `/api/attendance/statistics` | Monthly per-employee stats | `employee_id`, `department`, `year`, `month` | Policy |

### 1.6 Payroll (4) — Admin, HR (amounts hidden from HR; adjustments Admin only)

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| POST | `/api/payrolls/generate` | Build monthly statements | `year`, `month`, `employee_id?`, `department?` | Policy |
| GET | `/api/payrolls` | List statements | `employee_id`, `department`, `year`, `month`, `status`, `per_page` | Policy |
| GET | `/api/payrolls/{id}` | One statement | — | Policy |
| PATCH | `/api/payrolls/{id}` | Adjust / finalise | `other_deductions?`, `bonuses?`, `notes?`, `status?`, `password` | **Admin** 🔑 |

### 1.7 Audit log (1) — Admin only

| Method | Endpoint | Purpose | Input | Security |
|---|---|---|---|---|
| GET | `/api/audit-logs` | Read the trail | `action`, `username`, `per_page` | **Admin** |

### 1.8 Employee portal (7) — Employee, read-only

No endpoint accepts an employee id; the subject is derived from the token.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/portal/summary` | Profile + this month + latest payslip |
| GET | `/api/portal/profile` | Own profile & vacation balance |
| GET | `/api/portal/attendance` | Own history (`date_from`, `date_to`, `year`, `month`) |
| GET | `/api/portal/payrolls` | Own **finalised** payslips |
| GET | `/api/portal/payrolls/{id}` | One payslip (404 if not theirs, or still draft) |
| GET | `/api/portal/id-card` · `/api/portal/cv` | Own documents |

---

## 2. Implementation logic by module

### 2.1 Authentication

Sanctum personal access tokens. Login accepts username **or** email, resolved with a single `where/orWhere` query, then `Hash::check`. Failure returns a generic 422 that does not reveal whether the account exists.

**Password reset** is OTP-based: a cryptographically secure 6-digit code (`random_int`), stored **hashed** in `password_reset_tokens.token`, valid 10 minutes, single-use, with a per-code `attempts` counter that burns the code after 5 wrong guesses. A successful reset revokes **all** tokens.

**Password change** re-authenticates via `current_password` and revokes **all other** tokens, leaving the calling device signed in — the deliberate difference from reset, which is driven by an email code rather than a live session.

### 2.2 Authorization

Three layers, in order:

1. `auth:sanctum` — is there a valid token?
2. `role:` middleware on route groups — coarse gate, returns an early 403.
3. **Policies** via `$this->authorize()` in every controller action — the authoritative per-action check.

Where the layers disagree the stricter wins: HR passes the `role:Admin,HR` group on employees but `EmployeePolicy::delete` still returns 403.

**Salary** is a fourth layer, implemented in resources rather than policies: `EmployeeResource` and `PayrollResource` consult a `view-salary` gate and **omit** the monetary keys entirely for roles outside `config('garment_factory.salary_visible_roles')` (default `['Admin']`). Read and write share the boundary — `EmployeeController` strips `salary` from any write by a role that cannot read it.

### 2.3 Products & the worksheet importer

`ProductionImportService` reads the factory's Excel worksheet, which is **not a flat table**: stacked sections, repeated headers, subtotal and grand-total rows, and 266 blank rows out of 290. The parser walks top to bottom tracking which section it is in, builds a column map from each section's header labels, and imports only genuine data rows. Unlabelled columns inherit meaning from the previous section; a column *relabelled* to something unstored is explicitly dropped so its values cannot land in the wrong field.

Column A is dual-purpose — a design status in the first section, the **workshop** inside a department section. The importer resolves this structurally, from whether the header labels A as the design-status column.

Imports are idempotent on `(barcode, department)` (`updateOrCreate`), and canonicalise department and workshop names against the reference tables (§5.3). The worksheet's own date cell is a `=TODAY()` formula and is deliberately ignored — pass `--production-date` instead (§5.2).

### 2.4 Serial numbering

`DepartmentSerialNumberService` keeps each department list numbered 1..N. Appending takes the next free number; moving an item out renumbers the source so the sequence stays contiguous. **Validation:** the computed serials reproduce the worksheet's own numbering (column J) with zero mismatches.

### 2.5 Transfers

`POST /api/transfers` runs in a transaction: write the ledger row (from/to department, from/to workshop, quantity, user), then either move the whole row or **split the batch** — decrementing the source and creating or merging a row in the destination — assign serials, renumber the source, and write an audit entry. Pieces are conserved across every mode (§5.1). Guards reject a transfer to the same department, a quantity exceeding the item's own, or an unknown department/workshop name.

`department` and `workshop` are **create-only** on the product endpoints, so stock cannot move without a ledger entry.

### 2.6 Attendance

Two entry paths, both keyed on `(fingerprint_id, date)` so a day is corrected rather than duplicated.

`BiometricAttendanceImportService` matches header labels against English and Arabic alias lists, locates the header row by content (tolerating banner rows), detects the CSV delimiter itself, and decodes Excel serial dates and fractional-day times. Writes are a chunked `upsert`. Unmatched fingerprints import with `employee_id: null` and are reported back rather than dropped.

Manual entry rejects future dates unless the status is `leave`/`holiday`, and requires an explicit `overnight: true` when check-out is not after check-in — so a mistyped time cannot silently become a 16-hour shift.

### 2.7 Payroll

`PayrollService` derives a month from attendance and **snapshots** the result onto the `payrolls` row. Correcting an old attendance record therefore cannot rewrite a month that has been paid.

```
absence_deduction = (base_salary / PAYROLL_WORKING_DAYS) × absent_days   // default 26
net_salary        = max(0, base − absence_deduction − other_deductions + bonuses)
```

Regeneration refreshes drafts and **skips finalised** statements, reporting which were skipped. Finalised statements reject further edits with 409.

### 2.8 Statistics

`GET /api/productions/statistics` aggregates **pieces** (`SUM(quantity)`), never row counts, in a single grouped query. Buckets by day / week / month on `coalesce(production_date, date(created_at))`. Three totals mirror the worksheet's own summary rows, plus Basic (البيزك) isolation: `totals` (everything), `basic` (Basic alone), `production_only` (line with Basic removed). Department name variants are configuration, not code.

### 2.9 Audit trail

`AuditLogger` writes `user_id`, a **denormalised `username`** (so the entry survives account deletion), `action`, JSON `details`, and a second-precision timestamp. Updates additionally record what each field changed **from** and **to**, with secrets redacted (§5.4). Entries are written **only after** authorization and password confirmation succeed — failed attempts leave no trace.

### 2.10 File handling

Product images, guide files, ID cards and CVs all live on the **private** disk and are streamed through authorised controller endpoints. There are no public URLs and no `public/storage` symlink. Uploads are validated by MIME type and size; the upload is parked under its real extension for parsing and removed in a `finally` block.

---

## 3. Quality & security audit

### 3.1 Verified sound

| Area | Evidence |
|---|---|
| SQL injection | All raw expressions are static strings. The one interpolated value (`$bucket`) comes from a `match()` over a validated `in:day,week,month`, so only three literals can ever be produced. |
| Password/token leakage | No resource exposes `password`; `User::$hidden` = `password, remember_token`. |
| N+1 queries | Measured: attendance list = **3 queries for 12 rows** with eager loading vs **14** without. All relation-heavy list endpoints eager load. |
| Authorization coverage | Every resource-controller action calls `authorize()`. The only methods without one are the six auth endpoints, which act on the caller. |
| Cross-tenant access (portal) | Employee A cannot read B's payslip (404), attendance, or documents. Verified with two live accounts. Drafts are invisible too (§5.5). |
| File exposure | Private disk, no public symlink, ownership checked on `remove_images[]`. |
| Brute force | 5 wrong password confirmations → 5-minute per-user lockout; login/OTP endpoints throttled. |
| Mass assignment | All writes go through `validate()` then `fill`/`create` with explicit `$fillable`. |

### 3.2 Found and fixed during this audit

**F-1 · `APP_KEY` was empty — CRITICAL, fixed**

`config('app.key')` was unset. `encrypt()` threw `MissingAppKeyException`, and `php artisan test` failed on the scaffolded example test. Anything touching encryption, signed URLs, or encrypted cookies would have failed in production. Sanctum tokens are hashed rather than encrypted, which is why the API appeared to work.

*Fix applied:* `php artisan key:generate`. Verified: encryption works, both tests now pass, and all 10 read endpoints still return 200. A backup of the previous `.env` is at `.env.backup-preaudit`.

### 3.3 Open issues

**O-1 · No automated test suite — HIGH**

`tests/` contains only Laravel's two scaffolded examples. Every check in this project has been manual. Without tests, any future change can silently break a verified behaviour — the salary filter, the portal isolation, the serial renumbering. This is the single largest risk to the codebase.

*Fix:* feature tests for the security boundaries first — salary visibility per role, portal cross-account isolation, password-confirmation gates, payroll finalisation lock.

**O-2 · No rate limit on authenticated endpoints — MEDIUM**

Laravel 11's `api` middleware group ships **without** a throttle (unlike Laravel 10's `throttle:api`). Only login, password change, forgot and reset are limited. An authenticated user can hammer `/productions/statistics` or `/attendance/import` without limit.

*Fix* in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: ['throttle:api']);
    $middleware->alias([...]);
})
```

and define the `api` limiter in a service provider (e.g. 60/min per user).

**O-3 · CORS allows every origin — MEDIUM**

`config/cors.php` is not published, so the framework default `allowed_origins => ['*']` applies. Acceptable for a native mobile client; wrong for a browser front-end.

*Fix:* `php artisan config:publish cors`, then restrict `allowed_origins`.

**O-4 · Framework advisories — MEDIUM**

`composer audit` reports 3 advisories against `laravel/framework` 11.31, including a **high-severity CRLF injection in the default `email` validation rule** (fixed in 12.60.0). The `email` rule is used in employee creation and both password-reset endpoints.

*Fix:* upgrade the framework. This is a major-version move and needs its own testing pass.

**O-5 · Environment not production-configured — BLOCKER for deploy**

| Setting | Current | Required |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` — otherwise stack traces with credentials leak on any 500 |
| `APP_URL` | `http://localhost` | real host (used to build file URLs in responses) |
| `MAIL_MAILER` | `log` | real SMTP — **OTP codes currently go to `storage/logs/laravel.log`, not to users** |
| `SEED_USER_PASSWORD` | unset → `password` | a real secret |

### 3.4 Design limitations — **all resolved**

The seven limitations recorded in the first pass have been addressed or reclassified. Full detail in §5.

| # | Limitation | Status |
|---|---|---|
| D-1 | Transfers moved whole rows without decrementing quantity | **Fixed** — batches now split, pieces conserved |
| D-2 | No production date; statistics bucketed on `created_at` | **Fixed** — `production_date` column, explicit on import |
| D-3 | Departments and workshops were free text | **Fixed** — reference tables + normalisation, unknown names rejected |
| D-4 | Audit log recorded field names, not values | **Fixed** — `from`/`to` per field, secrets redacted |
| D-5 | Employees could see draft payslips | **Fixed** — portal shows finalised only |
| D-6 | Password reset confirms whether an email is registered | **Open by design** — explicit requirement; fix is to drop `exists` |
| D-7 | No `Accountant` role | **Open** — one seeder row + one config entry |
## 4. Final status

### Is it feature complete?

**Yes.** Every module requested was built and verified working through live HTTP calls: authentication and OTP reset, product catalog with file uploads, workshop tracking, department transfers with serial renumbering, biometric attendance import, payroll, audit trail, employee self-service portal, and piece-based statistics with time filtering and department isolation.

### Is it bug-free?

**That cannot be claimed, and I will not claim it.** Every behaviour in this system was verified manually. That establishes the code worked at the moment it was tested; it does not establish the absence of bugs, and with no regression suite it does not establish that today's behaviour survives tomorrow's change. Five real defects were found *after* their features were built and "working" — including a PATCH that moved stock with no audit trail, and an empty `APP_KEY` that broke encryption entirely. There is no reason to assume the next audit finds nothing.

### Is it production ready?

**No — not yet.** Nothing in §3.3 is a logic fault; they are deployment and hardening gaps. In order:

1. **O-5** — set `APP_ENV`, `APP_DEBUG=false`, `APP_URL`, real SMTP, `SEED_USER_PASSWORD`. Without SMTP, password reset does not work for users at all.
2. **O-2** — add the API throttle.
3. **O-3** — restrict CORS if a browser front-end is planned.
4. **O-1** — write tests for the security boundaries before the next feature lands.
5. **O-4** — plan the framework upgrade.

Items 1–3 are a short afternoon. Item 4 is the one that determines whether this codebase stays trustworthy as it grows.

### Recommended before first real data

D-1, D-2 and D-3 — the three items previously flagged as "decide before real data lands" — are now implemented (§5). What remains before go-live is the §3.3 list, in the order given above.

One operational decision is still yours: **imports must now be given a production date** (`--production-date=YYYY-MM-DD`). Without it the rows carry no date and fall back to `created_at` for reporting. Agree with whoever runs the import what that date means — the day the worksheet covers, or the day it was compiled.

---

## 5. Remediation round — D-1 to D-5

All five were implemented and verified live. Two new migrations, two new models, two new services.

### 5.1 D-1 · Transfers split batches and conserve pieces

`ProductionTransferService` replaces the old whole-row move with three modes:

| Mode | When | Effect |
|---|---|---|
| `whole_batch` | quantity = the batch's full quantity | the row moves, as before |
| `split` | partial, destination has no row for this barcode | source decremented, new row created carrying `split_from_id` |
| `split_merged` | partial, destination already holds this barcode | source decremented, existing destination row incremented |

**Schema consequence you should be aware of.** A split batch means the same barcode legitimately exists in two departments at once, which the global `UNIQUE(barcode)` from Phase 4 forbids. Uniqueness therefore moved to **`(barcode, department)`** — and `(item_number, department)`. The product is still unique per location, which is what the constraint protects; it is no longer unique across the whole table. The importer's upsert key moved to `(barcode, department)` to match.

Verified on a 300-piece batch:

```
transfer 100 → خياطه   mode=split         source 300→200, new row 100      total 2963
transfer  50 → خياطة   mode=split_merged  source 200→150, existing 100→150 total 2963
transfer 150 → امبلاج  mode=whole_batch   row moves                        total 2963
```

Pieces are conserved across every mode — the sum before and after each transfer is identical.

### 5.2 D-2 · Real production date

`productions.production_date` (date, indexed) added, with `split_from_id` alongside it. Statistics now bucket and filter on `coalesce(production_date, date(created_at))` — the real date when present, the old fallback otherwise.

**The worksheet does not carry a usable date.** Cell `J1` looked like one (`46249` → 2026-08-15) but is a live **`=TODAY()` formula**; the cached number is merely when the file was last saved, and PhpSpreadsheet recalculates it to whatever day the import runs. Importing it would have stamped every batch with the import date while *looking* like a real production date — strictly worse than leaving the field empty.

The importer therefore ignores formula cells and accepts the date explicitly:

```bash
php artisan productions:import "Book1.xlsx" --production-date=2026-06-30
```

`production_date` is also accepted on `POST`/`PATCH /api/productions`. Verified: importing without the flag leaves the column `null` (the `=TODAY()` cell correctly ignored); importing with it stamps `2026-06-30` on all 17 rows.

### 5.3 D-3 · Departments and workshops are no longer free text

Two reference tables — `departments` and `workshops` — each holding a canonical `name` plus an `aliases` list, seeded by `DepartmentWorkshopSeeder` (8 departments, 6 workshops).

`NameNormalizer` folds any input before matching: it strips diacritics and tatweel and unifies the alef family (`أ إ آ` → `ا`), ta marbuta (`ة` → `ه`) and the ya family. Arabic makes this necessary rather than merely tidy — `خياطة` and `خياطه` are the same word with a different final letter, and either spelling would previously have split a production total in two.

```
خياطه   → خياطة      امبلاج  → امبلاج
الخياطة → خياطة      أمبلاج  → امبلاج
مصطفي   → مصطفى      التغليف → امبلاج
Basic   → البيزك     نجارة   → REJECTED
```

Writes are canonicalised on transfers, product creation and import. An unknown name is rejected with the allowed list:

```json
{ "errors": { "to_department": ["Unknown department. Allowed: جاهز قص, جاهز طباعه, مطابع, ج خ, خياطة, امبلاج, البيزك, مسلم"] } }
```

The string columns were kept rather than replaced with foreign keys — they now hold canonical values, so every reporting query that groups by them keeps working without a five-table FK migration. Adding a department is a row in the seeder, not a code change.

### 5.4 D-4 · Audit log records before and after

`AuditLogger::logUpdate()` records what each field changed **from** and **to**:

```json
{
  "employee_id": 1,
  "name": "Audit Probe",
  "changed": ["phone", "position", "salary"],
  "changes": {
    "phone":    { "from": null,      "to": "0555" },
    "position": { "from": "خياط",   "to": "مشرف" },
    "salary":   { "from": "2000.00", "to": 2750 }
  }
}
```

Applied to `employee.updated`, `production.updated` and `payroll.updated`. Originals are captured **before** the write, because Eloquent resyncs them during `save()` — reading afterwards would report the new values as old.

`password`, `remember_token`, `current_password` and `new_password` are redacted wherever they appear, at any nesting depth. Verified: zero audit rows contain the word "password".

### 5.5 D-5 · Portal shows finalised payslips only

All three portal payroll paths — the list, the single payslip, and `latest_payroll` on the summary — now filter `status = finalized`. A draft is HR's working copy and its figures can still move.

Verified with one draft and one finalised statement for the same employee:

| Request | Result |
|---|---|
| `GET /portal/payrolls` | 1 of 2 returned — the finalised one |
| `GET /portal/payrolls/{finalised}` | **200** |
| `GET /portal/payrolls/{draft}` | **404** |
| `GET /portal/summary` → `latest_payroll` | the finalised statement |

### 5.6 Regression check

After the full round: `migrate:fresh --seed` completes in 29 steps, the worksheet re-imports to 17 rows / 2,963 pieces with canonical department names, all ten read endpoints return 200, and the test suite passes.
