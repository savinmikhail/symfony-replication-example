# Symfony 7.3 Docker Template (API‑only)

This repository is a **template** for Symfony 7.3 API services running in Docker with a full set of infrastructure:

- PHP‑FPM 8.4 (Alpine) with Symfony 7.3 (API‑only skeleton)
- Nginx as HTTP entrypoint
- PostgreSQL 16
- Redis
- RabbitMQ
- Doctrine ORM + migrations
- Symfony Messenger (Doctrine transports + failure transport)
- Observability stack: Prometheus, Grafana, Loki, Promtail, exporters
- k6 for HTTP load testing

The goal is to provide a **production‑like environment** for local development and experiments with metrics, logging and messaging.

---

## Project structure

- `app/` – Symfony application (Symfony 7.3 skeleton)
  - `src/`
    - `Entity/Product.php` – simple `Product` entity
    - `Repository/ProductRepository.php`
    - `Controller/ProductController.php` – sample API endpoints
  - `config/` – Symfony configuration (Doctrine, Messenger, Framework, Monolog, etc.)
  - `migrations/` – Doctrine migrations (organized by year & month)
  - `bin/` – console tools (`bin/console`, `bin/phpunit`)
  - `tools/` – isolated Composer tools (via `bamarni/composer-bin-plugin`)
    - `rector`, `php-cs-fixer`, `phpstan`
- `docker/`
  - `php/` – PHP‑FPM Dockerfile and configs (`php.ini`, `php-fpm.conf`, `www.conf`, `xdebug.ini`)
  - `nginx/` – Nginx config + vhost
  - `postgres/` – tuned `postgresql.conf`, queries for exporter, `init.sql` (pg_stat_statements)
  - `prometheus/` – Prometheus configuration (scraping app exporters)
  - `grafana/` – provisioned datasources and dashboards (HTTP, Redis, RabbitMQ)
  - `loki/` – Loki configuration
  - `promtail/` – Promtail configuration for Docker log scraping
  - `k6/load.js` – k6 load script for `/products` API
- `docker-compose.yml` – all services (application + infra)
- `Makefile` – helper commands for running the stack and tools
- `.env` – root env vars (ports, resource sizes, etc.)
- `app/.env` – Symfony app env (DB & Messenger DSNs)

---

## Docker stack

Defined in `docker-compose.yml`:

- `php` – PHP 8.4 FPM (Alpine)
  - Built from `docker/php/Dockerfile`
  - Uses `install-php-extensions` (intl, opcache, pdo_pgsql, zip, xdebug in dev)
  - Runs as `www-data`, working dir `/var/www/app`
  - Mounts `./app` as project root
- `nginx` – Nginx 1.27 (Alpine)
  - Configured via `docker/nginx/nginx.conf` and `docker/nginx/conf.d/app.conf`
  - Listens on port `8080` in the container
  - Exposed as `APP_HTTP_PORT` (default `8080`) on the host
  - Access log in JSON to stdout (used by Loki)
- `db` – PostgreSQL 16 (Alpine)
  - Data volume: `db-data`
  - Tuned with `docker/postgres/postgresql.conf`
  - `pg_stat_statements` enabled via `init.sql`
  - Healthcheck via `pg_isready`
- `redis` – Redis 7 (Alpine)
  - `maxmemory` taken from `APP_REDIS_MEMORY_LIMIT`
- `rabbitmq` – RabbitMQ 3 management
  - Default user/pass: `app/app`
  - Ports:
    - AMQP: `APP_RABBITMQ_PORT` (default `5672`)
    - Management UI: `APP_RABBITMQ_MGMT_PORT` (default `15672`)
- Exporters & observability
  - `postgres-exporter` – PostgreSQL metrics (Prometheus)
  - `redis-exporter` – Redis metrics (`oliver006/redis_exporter`)
  - `rabbitmq-exporter` – RabbitMQ metrics (`kbudde/rabbitmq-exporter`)
  - `prometheus` – metrics storage (`docker/prometheus/prometheus.yml`)
  - `grafana` – dashboards (Redis, RabbitMQ, HTTP, etc.)
  - `loki` – log storage
  - `promtail` – collects Docker logs → Loki
- `k6` – Grafana k6 image for load testing

---

## Symfony application

### Database & Doctrine

- DB URL in `app/.env`:

```dotenv
DATABASE_URL="postgresql://app:app@db:5432/app?serverVersion=16&charset=utf8"
```

- Doctrine ORM mapping:
  - Attributes in `src/Entity`
  - Config in `app/config/packages/doctrine.yaml`
- Migrations:
  - Config: `app/config/packages/doctrine_migrations.yaml`
    - `organize_migrations: BY_YEAR_AND_MONTH`
  - Example migration: `app/migrations/Version20250101000000.php` (creates `product` table)

### Product entity & API

Entity: `App\Entity\Product`

Fields:
- `id` – integer, PK
- `name` – string
- `price` – decimal(10, 2) (stored as string)
- `createdAt` – `DateTimeImmutable`
- `updatedAt` – nullable `DateTimeImmutable`

Controller: `App\Controller\ProductController`

Routes:

- `GET /products`
  - Returns last 50 products ordered by `id DESC`.
  - Response example:
    ```json
    [
      {
        "id": 1,
        "name": "Product-ABC",
        "price": "19.99",
        "createdAt": "2025-01-01T12:00:00+00:00",
        "updatedAt": null
      }
    ]
    ```

- `POST /products`
  - Request body:
    ```json
    { "name": "Product name", "price": 9.99 }
    ```
  - On success: `201 Created` with created product.
  - On invalid payload: `400 Bad Request`.

### Messenger

Package: `symfony/messenger`  
Config: `app/config/packages/messenger.yaml`

- Bus:
  - `messenger.bus.default`
  - Custom middleware stack:
    - `reject_redelivered_message_middleware`
    - `validation`
    - `doctrine_ping_connection`
    - `add_bus_name_stamp_middleware: ['messenger.bus.default']`
    - `dispatch_after_current_bus`
    - `send_message`
    - `failed_message_processing_middleware`
    - `handle_message`
    - `doctrine_close_connection`

- Failure transport:
  - `failure_transport: failed`
  - `failed` transport uses `doctrine://default?queue_name=failed`

- Transports (all customizable via env):
  - `async`, `notifications`, `search_index`, `modules_bell_async`, `scheduler_default`

- Env (`app/.env`):

```dotenv
MESSENGER_TRANSPORT_DSN=doctrine://default
MESSENGER_MODULES_BELL_ASYNC_TRANSPORT_DSN=doctrine://default
```

- Test override (`when@test`):
  - `async` and `notifications` use `test://` DSNs.

---

## Tooling

### Composer bin tools (in `app/tools`)

Isolated via `bamarni/composer-bin-plugin` with target directory `tools`:

- Rector:
  - `app/tools/rector/vendor/bin/rector`
  - Config: `app/rector.php`
- PHP CS Fixer:
  - `app/tools/php-cs-fixer/vendor/bin/php-cs-fixer`
  - Config: `app/.php-cs-fixer.dist.php`
- PHPStan:
  - `app/tools/phpstan/vendor/bin/phpstan`
  - Config: `app/phpstan.neon.dist`

### PHPUnit

- Installed as dev dependency in `app/composer.json`.
- Config: `app/phpunit.dist.xml`.
- Run inside PHP container:
  - `make php` → `php bin/phpunit`

---

## Observability

### Metrics (Prometheus)

Scrape configs in `docker/prometheus/prometheus.yml`:

- `prometheus` itself
- PostgreSQL exporter (`postgres-exporter:9187`)
- Redis exporter (`redis-exporter:9121`)
- RabbitMQ exporter (`rabbitmq-exporter:9419`)
- Promtail metrics (nginx request time histogram) (`promtail:9080`)

### Dashboards (Grafana)

Provisioned via:

- Datasources:
  - `docker/grafana/provisioning/datasources/prometheus.yml`
  - `docker/grafana/provisioning/datasources/loki.yml`
- Dashboards:
  - Redis – `docker/grafana/dashboards/redis.json`
  - RabbitMQ – `docker/grafana/dashboards/rabbitmq.json`
  - HTTP RPS & latency (via Loki) – `docker/grafana/dashboards/http.json`

Grafana runs on `APP_GRAFANA_PORT` (default `3000`), default admin credentials: `admin/admin`.

### Logs (Loki + Promtail)

- Loki:
  - Config: `docker/loki/config.yml`
  - Exposed on `APP_LOKI_PORT` (default `3100`)
- Promtail:
  - Config: `docker/promtail/config.yml`
  - Scrapes Docker logs via `/var/run/docker.sock`
  - Adds labels `service`, `container`, `compose_project`, `stream`.

PHP & Nginx are configured to write logs to `stdout`/`stderr`:

- Monolog:
  - Dev/test: `php://stdout`
  - Prod: `php://stderr` (JSON)
- Nginx:
  - JSON access logs to `stdout` with `request_time` and `upstream_response_time`

In Grafana → Explore → Loki you can query:

- `{service="nginx"}` – HTTP logs
- `{service="php"}` – Symfony/PHP logs

---

## Load testing with k6

Script: `docker/k6/load.js`

- Scenario:
  - `vus: 10`, `duration: 30s`
  - For each VU:
    1. `POST /products` (create product)
    2. `GET /products` (list products)
    3. `sleep(1)`

Base URL:

- Inside Docker network: `http://nginx:8080`
- Configurable via env var `BASE_URL`.

Service `k6` in `docker-compose.yml` uses the official `grafana/k6` image and mounts `docker/k6` as `/scripts`.

---

## Makefile commands

From the repository root:

- Start stack:
  - `make up`
  - App URL is printed (uses `APP_HTTP_PORT` from `.env`)
- Rebuild only PHP container:
  - `make php-rebuild`
- Shell inside PHP container:
  - `make php`

Static analysis / code style (run inside PHP container via Docker):

- `make phpstan` – runs PHPStan with `phpstan.neon.dist`
- `make cs-fix` – runs PHP CS Fixer with `.php-cs-fixer.dist.php`
- `make rector` – runs Rector with `rector.php`

Load testing:

- `make k6` – runs k6 with `docker/k6/load.js` against the running stack.

---

## Getting started

Prerequisites:

- Docker + Docker Compose
- Make (optional but recommended)

Steps:

1. Clone the repo:

   ```bash
   git clone <this-repo-url>
   cd symfony-template-docker
   ```

2. Start the stack:

   ```bash
   make up
   ```

3. Apply database migrations:

   ```bash
   make php
   php bin/console doctrine:migrations:migrate
   ```

4. Test the API:

   ```bash
   curl http://localhost:8080/products
   curl -X POST http://localhost:8080/products \
     -H 'Content-Type: application/json' \
     -d '{"name":"Test","price":9.99}'
   ```

5. Run a basic load test:

   ```bash
   make k6
   ```

6. Explore metrics and logs:

   - Prometheus: `http://localhost:9090`
   - Grafana: `http://localhost:3000`
   - Loki via Grafana → Explore (logs from `nginx`, `php`, etc.)

---

## Notes & next steps

This template is intentionally minimal on **domain code** and heavy on **infrastructure**.  
You are expected to:

- Add your own entities, message handlers and routing.
- Switch Messenger transports to RabbitMQ (AMQP) if needed.
- Extend Grafana dashboards and alerting rules for your use‑cases.

Use this as a starting point for new Symfony API projects with Docker‑first, observability‑ready setup.
