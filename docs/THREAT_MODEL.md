# Threat model and remaining review

Assets: reporter identity, precise incident location, emergency descriptions, evidence images, staff privileges, account credentials and public content integrity.

| Threat | Implemented boundary | Remaining assessment |
|---|---|---|
| Cross-agency or cross-citizen access | Policy + scoped queries, including analytics/CSV | Expand adversarial tests for every management route |
| Duplicate/uncertain delivery | Unique per-user key, request hash, transaction, encrypted mobile retry | Device/network-loss test against a real server |
| False urgency or malicious reports | Verified account, rate limits, lifecycle verification | Operational abuse controls and human triage policy |
| Stored XSS / malicious uploads | HTMLPurifier allowlist, escaped UI, image MIME/size/dimension limits; no SVG | Antivirus/quarantine service and image reencoding |
| Credential exposure | Hashing, token expiry/revocation, Keystore, ignored config | Production TLS/cookies, key rotation, signing, session invalidation review |
| Precise-location leakage | Staff-only analytics, aggregate cells with threshold, no raw identity | Retention/deletion policy and formal privacy/legal review |
| Notification failure | Durable reports, database queue, retrying jobs, dashboard polling | Queue monitoring, alert delivery and real-device FCM validation |
| Export injection | CSV quoting plus formula-leading-character prefix | Large dataset and spreadsheet interoperability tests |

No real agencies are contacted by local tests. Synthetic descriptions are conspicuously labeled. The system is not certified dispatch infrastructure and must not promise delivery to an agency merely because a record exists in the database. Retention automation, administrator retention controls, production backups/restores, malware scanning, field-level audit coverage, and formal Philippine privacy compliance approval remain pending.
