# CODEX MASTER PROMPT — CDRMAPP

## YOUR ROLE AND MISSION
Act as a senior full-stack software architect, senior PHP/Laravel developer, Android engineer, database architect, GIS developer, security engineer, QA lead, and DevOps engineer. Build a working, tested, maintainable **Citizen Disaster Response, Crime and Emergency Incident Reporting and Heat Mapping System (CDRMAPP)** following the attached 51-page source document (`for codex.docx`) as the functional reference. Implement all modules below; do not stop at wireframes, scaffolds, or pseudocode. The screenshots are functional/UI references, not a requirement to clone obsolete styling. Treat the attached document as the authority on original functions and these instructions as the technical implementation specification.

If the original document is not available within your Codex workspace, immediately record that limitation in `docs/ASSUMPTIONS.md` and use the complete requirements below. Do not claim to have read an absent attachment.

## NON-NEGOTIABLE ARCHITECTURE
- Web: PHP 8.3+ using a currently maintained, mutually compatible Laravel release; Blade + Tailwind CSS + accessible JavaScript. Implement both public citizen-facing web pages and role-protected staff dashboards.
- Android: Native Kotlin, Jetpack Compose, MVVM/Clean Architecture as appropriate, Retrofit/OkHttp, Coroutines/Flow, encrypted local credential storage. The Android app is citizen-facing.
- Database: ONE shared MySQL 8+ database. Laravel owns the database schema, business logic, access control, transactions, and migrations.
- Integration: Android communicates ONLY through versioned, HTTPS Laravel JSON REST APIs. NEVER connect Android directly to MySQL or embed DB credentials in APKs. Web and Android must immediately share all server-side entities and updates through the API/database.
- Auth: Laravel Sanctum or another documented Laravel-supported token strategy for mobile; secure server-side sessions for web; role/permission enforcement on every sensitive endpoint and query.
- Maps: Google Maps JavaScript API for the web base map; Google Maps SDK for Android for maps. Google Maps is mandatory. **Do not use `google.maps.visualization.HeatmapLayer`, which became unavailable in May 2026.** Use a supported `deck.gl` HeatmapLayer integrated over the Google Map for WEB, or a verified equivalent supported visualization overlay if compatibility requires it. For Android heatmaps, use Google's Android Maps SDK Utility Library HeatmapTileProvider if a mobile heatmap screen is implemented. All points come from the same authorized backend heatmap dataset.
- Notifications: Firebase Cloud Messaging for Android; Laravel queues and server-driven notifications for staff dashboards. Do not rely on push notifications as the only means of receiving emergency reports.
- Local setup: Docker Compose for web, database, queue worker, and supporting services; Android Studio/Gradle for Android. Production-ready environment-variable configuration and deployment docs.
- Keep code organized in a monorepo: `/backend`, `/android`, `/docs`, `/infra` with root README.
- Use currently supported dependency versions, pin resolved versions in lockfiles, and document versions. Do not fabricate completed integrations or test results.

## USER ROLES AND ACCESS
1. System Administrator: full oversight of user and agency management, assignment, reports, status history, maps, CMS, notifications, reporting, and audit logs.
2. PNP Officer: authorized police/crime reports and response workflow only.
3. Fire Department Officer: authorized fire reports and response workflow only.
4. MDRRMO Officer (normalize source document's MDDRMO/MDRRMO spelling in code and explain in docs): authorized disaster/emergency management reports and response workflow only.
5. Registered Citizen: own account, incident submission, GPS/location capture, own incident history/status, and public content.
6. Anonymous Public Visitor: public content and contact form only; do not allow viewing private reports or reporter identity.
Enforce least privilege server-side, including agency-scoped dashboards, API query filters, record-detail endpoints, exports, maps, and notifications. Admin can explicitly reroute incidents with an audit trail. Disable or soft-delete accounts without losing incident evidence.

## SOURCE-DERIVED FUNCTIONAL REQUIREMENTS
### A. Account and Registration Management
- Admin adds/edits/deactivates staff with full name, email, phone (optional), agency, role, account activation state, and profile photo; admin can edit/remove privileges and initiate safe password resets.
- Citizen Android: landing screen with Login/Register; registration: full name, email, date of birth, password and confirmation; login using email/username as supported, logout, forgot-password flow, email verification, edit profile, change profile image, change email with reauthentication and reverification, and change password.
- Admin and officers: login with email/username and password, remember-me option for web where safe, password reset, edit profile and change password.
- Source documents an unusual 'first login allowed, later logins require verified email' pattern; replace this with a consistent modern secure email-verification policy, configurable and documented as a deliberate security improvement. Emergency access requirements must not be blocked by accidental client-side validation failures.

### B. Citizen Incident Reporting (Android)
- Logged-in citizen can report **Police/Crime, Fire, or MDRRMO/Disaster Emergency** incidents.
- Reporting screen: straightforward category/agency selection, concise incident description, GPS coordinates, mapped location preview, optional manual map-pin correction, optional address and attachment(s), clear timestamp, and confirmation.
- On permissions granted, request foreground location and retrieve fresh location with accuracy metadata. If GPS denied, unavailable, or stale, allow a clearly labeled manually chosen map location; never pretend an approximate location is precise.
- Large, accessible emergency send control. The document depicts PRESS AND HOLD to send; implement an accessible hold-to-confirm or equally safe explicit confirmation to prevent accidental submissions, **without adding dangerous delay or obscuring urgent action**. Provide alternative tap confirmation for accessibility.
- The backend validates, persists, timestamps, assigns a unique public reference, routes the report to the appropriate agency, and returns acknowledgement. Do not show 'delivered to authorities' until the server confirms durable receipt.
- Include clear guidance that the application is not a substitute for official emergency hotlines when urgent response is required; emergency numbers must be administrator-configurable and not hard-coded without confirmation.
- Citizen can view own submission list, incident details, and status progress; protect other citizens' data. Include no-connection handling and retry with idempotency keys so repeated taps/retries do not create duplicates. Do not claim an offline submission was transmitted until synchronized.

### C. Incident, Agency, Queue, and Response Management
- Agency module: authorized PNP, Fire, MDRRMO staff receive department-routed reports with message, reporter (authorized staff only), accurate incident coordinates, readable address when available, submitted timestamp, and reference ID.
- Administrator sees department totals and latest incident cards; staff see only assigned departments.
- Watchlist: searchable/filterable/paginated rows, department, incident type, severity (if configured), received date, reporter, message excerpt, location, assigned officer, status, latest update; filters for date range, department, status; export allowed by role.
- Lifecycle: `submitted -> received -> verified -> in_progress -> resolved -> closed` with `rejected`/`duplicate` outcomes and clear transition authorization. Preserve source UI language with user-facing mapping, e.g. `in_progress = In Progress`, `resolved/closed = Done` where appropriate. Record status transition time, actor, notes, and optional response outcome. Administrators/officers can log responses and outcomes. Do not allow the reporter to mark their own incident as verified.
- Routing should default from category and jurisdiction with a configurable dispatch mapping and safe 'needs triage' fallback; avoid silently routing to the wrong unit. Notify authorized officers about new incidents and state changes.
- Staff see mapped incident pins and may open report details from pins. Administrative map includes all authorized incidents; department maps show only their agency's incidents. Distinct category iconography: police/crime, fire, MDRRMO (purple as in reference).
- Avoid showing personally identifying reporter information in public maps or public heatmap datasets.
- Admin dashboard auto-refreshes summary/new-report information about every **15 seconds** (per source) using targeted polling or event broadcasting; pause unnecessary refresh when hidden, handle network errors, avoid full-page reloads.

### D. Geographic Decision Support and Heat Mapping
- Use **Google Maps** for web incident map and **Google Maps SDK** for Android location selection. Use valid geographical coordinates with a clear location source, observed accuracy, and event timestamp.
- Build a decision-support heatmap screen for authorized management showing **validated** incident density by configurable date range, agency/type, status, and geographic extent. Unverified, rejected, test, duplicate, or inaccurate-location incidents must not be counted by default. Give an explicit toggle for broader exploratory views with strong labeling.
- Backend provides authorized aggregated and/or weighted latitude-longitude points via `/api/v1/analytics/heatmap`, with stable response schema and filters. Never publish raw personal data in heatmap responses. Support sufficient-data/threshold rules where appropriate to reduce privacy leakage.
- Web map: Google Maps JS base map + supported deck.gl heatmap overlay (not deprecated Google Maps visualization HeatmapLayer); map legend, scale, clear filter indicators, cluster/density interpretation, togglable incident pins and heatmap, and empty-state handling.
- Provide descriptive stats: count by agency, category, time range, validated vs submitted, trend over time, top spatial hotspots, and a plain-language disclaimer that density is descriptive rather than a predictive guarantee. Define hotspot calculations transparently, e.g. spatial grid/geohash aggregation with sample thresholds and configurable weights; avoid arbitrary crime risk scoring.
- Include performance controls: bounding-box queries, indexed spatial/date fields, pagination where relevant, caching of aggregated queries with invalidation on verification/update, and sensible upper limits.
- Maps configuration: Google Maps API keys supplied via environment/build config, with separate HTTP-referrer restrictions for web and application-ID/SHA restrictions for Android; restricted APIs, quotas, billing alerts, and no committed credentials.

### E. Reports, Queue, and Analytics
- Generate customizable printable/exportable incident reports by date range, department, status, category, and agency, with accurate totals and status counts.
- Support on-screen summary, CSV export, and print-friendly/PDF output, including applied filters, creation timestamp, and authorizing user. Prevent formula injection in CSV.
- Display current queue, completed counts, and trends over time. Ensure filtered summary totals match filtered incident detail queries and enforce permissions on exports.

### F. Content Management System (Web)
Implement admin CRUD (create/list/view/edit/soft-delete as appropriate), image upload and public publishing for:
1. Infographics (title, body/description, image; homepage carousel).
2. Announcements (title, rich description, image, publish state).
3. Services (title, description, image).
4. Team Members (name, email/contact where appropriate, role/position, bio, photo).
5. Contact Details (location title, description, public contact channels, map position/embed).
6. FAQs (question/title, answer/rich description, optional image).
7. Visitor Messages/Contact Us (full name, contact details, message, date; admin searchable inbox; trash, restore, permanently delete with confirmation/authorization).
- Public web pages: landing/home with infographic carousel, Services, Announcements, Our Team, Contact Us with embedded map + message form, FAQs.
- Rich text MUST be sanitized to prevent stored XSS; validate and limit uploads; store media securely, publish public media intentionally, and avoid exposing contact-message personal data.

### G. Web UI/UX
- Responsive dashboard with left navigation, role-specific cards, incident counts, watchlist, report filters, Google Map, heatmap/DSS, CMS, user accounts and profile.
- Preserve original green-led branding as inspiration, while modernizing layouts and accessibility, contrast, keyboard navigation, loading/empty/error states, form feedback, and mobile responsiveness.
- Staff notification badges for genuinely unread new incidents; update incrementally without creating a noisy alarm loop.

## SUGGESTED RELATIONAL DATA MODEL
Create normalized migrations, foreign keys, indexes, seeders, Eloquent models, factories, and ERD for at least:
- `users` (id, name, email unique, password hash, DOB nullable as policy allows, phone nullable, verified_at, status, profile_photo_path, timestamps)
- `agencies` (id, name, type [PNP/FIRE/MDRRMO], jurisdiction, contact config, active)
- `roles` / `permissions` / pivot assignments or a tested Laravel permissions package
- `user_agencies` (if multiple assignments needed)
- `incident_categories` (agency mapping)
- `incidents` (id, public_reference unique, reporter_id, agency_id nullable pending triage, category_id, description, incident_at nullable, received_at, latitude/longitude decimal, accuracy_m nullable, location_source, address nullable, verification_status, workflow_status, priority nullable, assigned_officer_id nullable, idempotency_key unique scoped per user, timestamps)
- `incident_attachments` (incidents, approved file metadata, secure storage path)
- `incident_status_histories` (actor, old/new state, note, timestamp)
- `incident_responses` (officer, incident, action/note/outcome, occurred_at)
- `incident_verifications` (verified_by, outcome, timestamps, rationale)
- `notifications` / device push tokens (ownership and token revocation)
- `announcements`, `infographics`, `services`, `team_members`, `contact_details`, `faqs`, `contact_messages` (trashed_at), with publication flags where needed
- `audit_logs` (actor, action, record type/id, essential before/after metadata excluding secrets)
- optional `dispatch_rules`, `map_settings`, `analytics_cache`, `report_exports` if needed.
Document whether incident and reporter location are identical or differ; source document uses both ambiguously. Default to incident location as the mapped operational location and never conflate it with the citizen's personal home location. Respect timezone `Asia/Manila` in UI, and store timestamps consistently in UTC.

## API CONTRACT (VERSIONED; USE API RESOURCES)
Implement, document, authorize and test at minimum:
- `POST /api/v1/auth/register`, `/login`, `/logout`, `/forgot-password`, `/reset-password`, `/verify-email` flow
- `GET/PATCH /api/v1/me`, `POST /api/v1/me/change-password`, `POST /api/v1/me/change-email`
- `GET /api/v1/agencies`, `GET /api/v1/incident-categories`
- `POST /api/v1/incidents`, `GET /api/v1/incidents` (own or agency-scoped), `GET /api/v1/incidents/{id}`
- `PATCH /api/v1/incidents/{id}/status`, `POST /api/v1/incidents/{id}/verify`, `POST /api/v1/incidents/{id}/responses`, `POST /api/v1/incidents/{id}/assign` (permission-gated)
- `GET /api/v1/dashboard/summary`, `GET /api/v1/analytics/heatmap`, `GET /api/v1/analytics/trends`, `GET /api/v1/reports`, `GET /api/v1/reports/export`
- `GET /api/v1/public/{announcements,infographics,services,team-members,contact-details,faqs}`; `POST /api/v1/public/contact-messages`
- Authenticated management endpoints for users, agencies, and CMS; device token registration/revocation.
JSON must use a documented consistent success/error envelope, pagination meta, canonical time representation, authorization errors, validation fields, idempotent submission behavior, and status transition errors. Generate OpenAPI 3 specification and runnable request examples. Prefer server-side filtering, validated sort fields and indexed search.

## SECURITY, PRIVACY, AND RESILIENCE
- HTTPS only in production; CSRF protection for session forms; secure SameSite/HttpOnly cookies; hash passwords using modern Laravel-approved hashing; short-lived/revocable mobile credentials; email verification/password reset token hygiene; rate limiting and brute-force protection; RBAC and policy-based object authorization.
- Use parameterized queries/Eloquent, output encoding, XSS-safe rich text, secure file validation/scanning as feasible, storage access policies, size limits, upload type allowlists, strict CORS, secure headers, structured logging, audit trails, and secrets management.
- Treat incident descriptions, personal identifiers, and precise geolocation as sensitive. Minimize public exposure, retain only necessary data, add retention/deletion controls and notices consistent with applicable Philippine privacy requirements. Do not expose named reporters in exported/public analytics unless explicitly authorized.
- Protect emergency reporting: database transaction on submission, idempotency key, safe server acknowledgement, queuing side effects after commit, retry strategies, failure logging, and no silent loss. Properly convey degraded service; do not claim this is certified emergency dispatch software or guarantee response times.
- Request only needed Android permissions, especially foreground location. No background tracking by default. Explain permissions and allow manual map pin. Design accessible error, retry, and offline states.
- Create demo data only in local/test environments, conspicuously labeled synthetic; never load fake emergencies into production or forward tests to actual agencies.

## REPOSITORY AND DELIVERABLES
Create a runnable monorepo with:
```
/backend        Laravel app, migrations, seeders, tests, OpenAPI
/android        Android Studio Kotlin/Compose application and tests
/infra          Docker compose, deployment examples, reverse proxy config
/docs           requirements traceability matrix, ERD, architecture,
                API spec, permissions matrix, state diagram,
                threat model, setup, testing, deployment, assumptions
README.md       exact setup instructions and demo credentials only for local dev
.env.example    placeholders only; no live credentials
```
Create reproducible install/configuration steps for Windows development and Linux deployment; include queue worker setup, mail transport, Google Maps API key provisioning, Google Cloud billing/quotas, Android SHA/app restriction, FCM setup, build commands, migration/seed commands, test commands, and health checks.

## IMPLEMENTATION SEQUENCE — EXECUTE, DON'T JUST PROPOSE
1. Inspect repository and attached source; create `docs/REQUIREMENTS_TRACEABILITY.md`, mark every source feature, choose compatibility-safe dependency versions, record unclear assumptions.
2. Create monorepo, Laravel project, Docker/MySQL, migrations, models, seeders, role policies, secure authentication, profile management, and tests.
3. Implement REST API, citizen incident submission, safe routing, validation, response lifecycle, history/auditing, APIs/tests.
4. Implement web admin and agency dashboards, 15-second refresh, lists, details, status actions, exports, and maps.
5. Implement Google Maps + deck.gl web heatmap, backend validated-point aggregation, filters, legends, analytics and security/performance tests.
6. Implement full CMS and public website; verify publishing and visitor messages.
7. Implement Android app end to end: registration/login/email verification/profile, incident category/location/report submission, retries, history, and notifications.
8. Integrate Android with SAME real backend; run end-to-end workflows, permission tests, unit/integration/UI tests, lint/static analysis, security checks, and deploy/test instructions.
9. Fix failing tests and incomplete flows. Provide final completion report with implementation status by module, environment requirements, screenshots where possible, build/test evidence, remaining limitations and explicit commands to run.

## VERIFIABLE ACCEPTANCE TESTS
- Citizen registers on Android; same user exists in MySQL and can authenticate through supported API; email verification behavior is consistent.
- Citizen submits a FIRE report with map-selected or permitted GPS coordinates; one and only one incident record appears after retries; admin sees it on dashboard within ~15 seconds without refreshing entire page; assigned fire agency can read it, while unrelated PNP officer cannot.
- PNP/MDRRMO reports route only to their authorized agency, with an unassigned/triage path for configuration gaps.
- Authorized officer verifies and advances incident through correct lifecycle; citizen sees status changes; history logs actor and timestamp.
- An unverified/fake/test/rejected incident does NOT affect default decision-support heatmap; verified incident appears on filtered Google base map with deck.gl overlay and valid coordinates.
- Date-range and department filters produce consistent dashboard counts, watchlist rows, exports, and heatmap results, accounting for respective inclusion rules.
- Admin creates an announcement/infographic/FAQ; published content appears on public website, draft content does not; contact messages support trash/restore.
- Cross-agency access attempts, unrelated citizen incident lookups, unauthorized status changes, invalid uploads, and invalid map coordinates are rejected by API regardless of UI.
- Android network interruption does not lead to a false success message or duplicate report submission; user receives an explicit retry path.
- System runs from documented clean checkout; DB migrations/seeders succeed; critical automated test suite passes. Report exact commands and measured outcomes—never fabricate pass status.

## WORKING RULES
- Make focused, incremental edits with sound naming, typed APIs where possible, clean architectural separation, and no hardcoded secrets.
- Execute repository changes directly; do not produce only advice, mockups, or code snippets.
- Use screenshots in attachment as design inspiration; do not reproduce personal names/contact data embedded in screenshots as real seed data.
- Keep source requirements distinct from improvements. Record design decisions and gaps, rather than inventing local government operational policies, emergency numbers, jurisdiction boundaries, or legal approvals.
- When credentials, billed API access, external mail, Android signing, or production infrastructure are unavailable, provide functional adapters and clearly identified configuration placeholders; implement and test all feasible local paths.
- After each phase, give a short status summary, affected files, tests performed and next steps; continue implementing through phases without repeatedly requesting approval unless blocked by a truly necessary external decision.
- Completion means an integrated, functioning web-plus-Android product with shared backend, not separately mocked applications.

**Start now:** inspect the workspace, check for the source file, establish the architecture/traceability documents, and begin implementing Phase 1 and Phase 2 immediately. Continue through all phases as execution context permits. If interrupted, leave a precise `docs/IMPLEMENTATION_STATUS.md` checkpoint showing completed work, test evidence, blockers, and next concrete commands.
