# phatbrewclub-wp

Self-hosted WordPress acting as the **headless CMS** for a second copy of
`pbc.christianbrown.uk`, so the brewery can compare editing in WordPress against
editing in Payload before committing to either.

| | Payload version | This version |
|---|---|---|
| Site | pbc.christianbrown.uk | pbc-wp.christianbrown.uk |
| Admin | cms.pbc.christianbrown.uk | cms.pbc-wp.christianbrown.uk |
| Database | Cloud SQL Postgres 17 | Cloud SQL MySQL 8.4 |
| Front end | `apps/web` in `phatbrewclub.com` | **the same image**, `CMS_KIND=wordpress` |

The public site is rendered by the same Next.js container in both cases,
deployed twice against different data sources. That is the whole point: if the
two front ends ever differ by so much as a rebuild, every number the comparison
produces is about the image rather than the CMS.

## Why WordPress needs its own database

Payload has no MySQL adapter and WordPress has no Postgres one — the constraint
runs both ways, so a second instance was unavoidable. It is local
(`asia-southeast1`) rather than the shared MySQL in `shared-data-services`
(`europe-west2`) because WordPress issues tens of queries per page render, and
routing those from Singapore to London would have measured the distance rather
than the platform.

## Layout

    public/index.php            front controller
    public/wp-config.php        entirely env-driven; no secrets, no installer writes
    public/wp/                  WordPress core — Composer owns this, never edit it
    public/wp-content/
      mu-plugins/phat.php       loader; the code is in src/ under PSR-4
      themes/phat-headless/     admin only; the front end is a separate service
    src/                        ChristianBrown\PhatWp\ — the content model and API
    docker/                     Apache, opcache and upload limits
    Dockerfile                  php:8.4-apache, core baked in, immutable

Core lives in `public/wp` and content beside it in `public/wp-content`, because
Composer replaces the core directory wholesale on every update and anything of
ours inside it would go with it.

## The things that will bite

Recorded here because each cost someone an afternoon somewhere:

- **Uploads are a bucket mounted with FUSE, and Cloud Run mounts it as root
  while Apache runs as `www-data` (uid 33).** Without `uid=33,gid=33` in the
  mount options the admin can read every existing upload and write none of
  them: a 500 on the first media upload, with nothing in the logs naming the
  cause. The mount also needs `EXECUTION_ENVIRONMENT_GEN2`.
- **`chmod` is not supported on FUSE**, and WordPress calls it on every upload
  without suppressing errors. Expect a PHP warning per upload. It is not fatal
  and it is not worth chasing.
- **Cloud Run terminates TLS and forwards plain HTTP.** `wp-config.php` sets
  `$_SERVER['HTTPS']` from `X-Forwarded-Proto`; without it every admin URL is
  generated as `http`, `FORCE_SSL_ADMIN` fights the proxy and `/wp-admin`
  redirect-loops.
- **The database user must be created with `--host='%'`.** Connections arrive
  through the Cloud SQL connector, not from `localhost`, and a host-scoped user
  produces an access-denied that reads exactly like a wrong password.
- **`db-f1-micro` MySQL allows roughly 25 connections, and mod_php holds one per
  in-flight request.** Request concurrency is therefore set far lower than the
  Next.js services'. That is a real property of the stack and a finding worth
  reporting, not a misconfiguration to tune away.
- **`Listen ${PORT}` does not work.** Apache expands `Define` directives and the
  `envvars` file, not the process environment, so the port is hardcoded to 8080
  on both sides.

## Local development

    composer install
    docker compose up -d          # MySQL 8.4
    php -S localhost:8090 -t public

with `WP_DB_PASSWORD`, `WP_HOME` and the rest exported. `.local.env` is
gitignored.

## Keeping the comparison honest

Both stacks scale to zero, share a database tier, run the same front-end image
and hold the same content. Where they genuinely differ — image size, cold start,
request concurrency, page caching — the difference is a result to report rather
than something to configure away. The full register lives in
`docs/comparison.md`.
