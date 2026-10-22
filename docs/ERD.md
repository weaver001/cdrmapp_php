# Entity relationship diagram

```mermaid
erDiagram
 users }o--o| agencies : assigned_to
 incident_categories }o--o| agencies : routes_to
 users ||--o{ incidents : reports
 agencies |o--o{ incidents : receives
 incident_categories ||--o{ incidents : classifies
 incidents ||--o{ incident_status_histories : tracks
 incidents ||--o{ incident_responses : records
 incidents ||--o{ incident_verifications : validates
 incidents ||--o{ incident_attachments : secures
 users ||--o{ personal_access_tokens : authenticates
 users ||--o{ device_tokens : receives_push
 users ||--o{ audit_logs : performs
 users ||--o{ notifications : receives
```

CMS uses a typed `contents` table for announcements, infographics, services, team members, contact details and FAQs, with sanitized body, publication flag, optional image and validated metadata. This intentionally consolidates identical publishing lifecycles. `contact_messages` has soft-delete trash/restore. `jobs`, `failed_jobs`, `job_batches`, `cache`, `sessions`, and `password_reset_tokens` use Laravel migrations. Incident evidence has restrictive foreign keys rather than cascading deletion. Users are deactivated rather than physically deleted by management endpoints.
