# certs/

This directory holds the Aiven CA certificate used by the application to verify
TLS connections to Aiven MySQL.

## How to obtain the Aiven CA certificate

1. Log in to the [Aiven Console](https://console.aiven.io/).
2. Navigate to your MySQL service.
3. On the **Overview** tab, click **Download CA cert**.
4. Save the downloaded file as `certs/aiven-ca.pem` in the project root.
5. Commit the file:

   ```bash
   git add certs/aiven-ca.pem
   git commit -m "chore: add Aiven CA certificate"
   ```

The CA certificate is **public information** (a root certificate, not a secret).
Committing it is the standard approach for TLS pinning to a known CA.

## How it is used

`Database::connection()` reads the path from the `DB_SSL_CA` environment variable
(default: `certs/aiven-ca.pem`, resolved relative to the project root).

When the cert file is present, it sets:
- `PDO::MYSQL_ATTR_SSL_CA` — points to this file
- `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true` — verifies Aiven's certificate

When the cert file is **absent** (local Docker dev without a committed cert), the
SSL options are skipped and the connection proceeds without TLS — this keeps
local development functional.

## Important: do NOT exclude certs/ from .vercelignore

`certs/` must **not** appear in `.vercelignore` so that `certs/aiven-ca.pem` is
bundled into the Vercel Lambda and available at runtime. Excluding this directory
would cause every Lambda invocation to fail with an SSL certificate error.
