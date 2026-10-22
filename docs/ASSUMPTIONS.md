# Assumptions and decisions

- The remote repository is empty; this request authorizes creating the product described by the master specification.
- The DOCX text was extracted and read; screenshot styling is reference material, not copied personal data.
- MDRRMO is the canonical code spelling. The source also uses MDDRMO.
- Incident coordinates represent the event location, never a citizen home address.
- Store time in UTC; format operational UI in Asia/Manila.
- Jurisdiction boundaries and emergency telephone numbers require administrator configuration; do not invent them.
- Email verification is required consistently for incident submission. Local mail is captured by Mailpit.
- Google Maps, FCM, production mail, signing and production deployment require real externally provisioned configuration. No production readiness claim follows from local tests.
