# Security Policy

## Supported versions

The latest minor of the current major receives security fixes. Older majors are supported for
six months after their successor's first stable release.

## Reporting a vulnerability

Please report privately, through
[GitHub's private vulnerability reporting](https://github.com/th3mouk/audit-trail-bundle/security/advisories/new).
Do not open a public issue for a vulnerability.

Include the affected version, what an attacker gains, and a reproduction if you have one.
Expect an acknowledgement within a few days and, for a confirmed report, a fix and an advisory
before any public disclosure.

## What this bundle does and does not guarantee

### The trail is soft append-only

Nothing in the bundle ever updates or deletes an audit row. `AuditLog` is mapped
`readOnly: true`, and the only write path is an insert. Entries land inside the transaction
that produced the change they describe, so a rolled-back change cannot leave a phantom entry
behind, and a committed change cannot lose its entry.

That is a guarantee about *this code*, not about your database. Anything else holding the same
credentials — a migration, a console command, a psql session, a compromised application
process — can still `UPDATE` or `DELETE` those rows. The bundle cannot prevent that, because
it runs with exactly the privileges you gave it.

### Making it hard append-only

If your threat model includes tampering with the trail itself, revoke the privileges at the
database. This is an application and infrastructure decision, not something a library can make
for you: it changes how migrations, fixtures and test teardown behave, and only you know
whether that is acceptable.

For PostgreSQL, with `app_user` as the role your application connects as:

```sql
-- The application may write history and read it. It may not rewrite it.
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE audit_logs FROM app_user;
GRANT  INSERT, SELECT                ON TABLE audit_logs TO   app_user;

-- Keep it that way for tables created later under the same schema.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT INSERT, SELECT ON TABLES TO app_user;
```

Two things to know before you run it:

- **Table ownership defeats it.** A table's owner keeps full rights on it regardless of
  `REVOKE`, and superusers bypass privilege checks entirely. The application role must
  therefore not own `audit_logs` and must not be a superuser — otherwise the statements above
  are decoration. Have a separate migration role own the table.
- **Migrations and tests need the other role.** Schema changes to `audit_logs`, and any test
  teardown that truncates it, must run as the owner. Keep two DSNs: one for the application,
  one for migrations.

Retention, if you need it, then becomes a scheduled job running as the owning role — an
explicit, auditable deletion policy rather than an ambient capability of the application.

### Data ends up in the trail

Audit entries hold before/after values, and that is the point. Two consequences worth stating:

- **Mask what should not be stored.** `#[AuditMasked]` records that a property changed
  without ever reading its value, and is the correct tool for credentials, tokens and
  secrets. `#[NotAuditable]` goes further: the property is neither stored nor able to trigger
  an entry.
- **The trail inherits the sensitivity of what it audits.** Personal data captured in
  `changes` is subject to the same obligations as the source row, including erasure requests.
  A `SELECT`-only read model is not exempt from GDPR.

### Reading the trail is your call

The API Platform bridge emits no security attribute unless you configure
`audit_trail.bridges.api_platform.security` with an expression of your own. An exposed audit
feed is a map of who does what, and when — treat access to it as privileged. If you do not
supply an expression, the route is as open as any other route in your application, which is
almost certainly not what you want in production.
