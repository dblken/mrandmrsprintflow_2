# Database configuration

`includes/db.php` is the canonical database bootstrap for HTTP requests, CLI
migrations, cron jobs, APIs, and webhooks. It resolves the application root
from `__DIR__` and loads the project-root `.env`; it does not use the process
working directory.

Use these canonical variables in `public_html/.env`:

```dotenv
DB_HOST=
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASSWORD=
```

Existing deployments may continue using `PRINTFLOW_DB_HOST`,
`PRINTFLOW_DB_PORT`, `PRINTFLOW_DB_NAME`, `PRINTFLOW_DB_USER`, and either
`PRINTFLOW_DB_PASSWORD` or the older `PRINTFLOW_DB_PASS`. The canonical `DB_*`
name wins when both forms are present.

No database variable has a root-user, blank-password, or database-name
fallback. Missing values stop the request or migration with a safe error.
Never commit the production `.env`.
