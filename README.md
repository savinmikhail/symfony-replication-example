# Демонстрация синхронной и асинхронной репликации PostgreSQL + Symfony

Этот репозиторий — “живой” пример того, как в связке **Symfony 7.3 + PostgreSQL 16** показать:

- **синхронную и асинхронную репликацию** (1 primary + 2 read‑only replica);
- **балансировку чтения** через внешний балансер (HAProxy) поверх пулеров (pgbouncer);
- эффект **eventual consistency** на async‑реплике;
- **read‑your‑writes** (sticky‑read) на уровне приложения.

Symfony‑приложение пишет только в primary, а читает либо через балансер, либо принудительно через sync‑replica.

---

## Быстрый запуск

Требования: Docker + Docker Compose, `make` (опционально).

```bash
make up ci dmm
```

Демо‑команды:

```bash
php bin/console app:replication:demo
php bin/console app:replication:demo --read-your-writes
php bin/console app:replication:demo --show-lsn
php bin/console app:replication:demo --await-async
```

---

## Архитектура и стек

Все сервисы описаны в `docker-compose.yml`:

- `php` — PHP‑FPM 8.4 + Symfony 7.3 (API)
- `nginx` — HTTP фронт
- **PostgreSQL репликация**:
  - `db-primary` — primary (read/write)
  - `db-replica-sync` — синхронная read‑only реплика
  - `db-replica-async` — асинхронная read‑only реплика (с искусственной задержкой применения WAL)
- **Пулеры и балансировка**:
  - `pgbouncer-primary` — пул соединений для primary
  - `pgbouncer-replica-sync` — пул для sync‑replica
  - `pgbouncer-replica-async` — пул для async‑replica
  - `read-balancer` — HAProxy, балансирует чтения между sync/async
- Observability: Prometheus, Grafana, Loki, exporters

---

## Как организована репликация

### Конфиги Postgres

- `docker/postgres/postgresql.conf` (primary)
  - `wal_level = replica`
  - `max_wal_senders`, `max_replication_slots`
  - `synchronous_standby_names = 'FIRST 1 (replica_sync)'`
- `docker/postgres/postgresql.replica.conf` (sync replica)
  - `hot_standby = on`
  - `default_transaction_read_only = on`
- `docker/postgres/postgresql.replica-async.conf` (async replica)
  - `hot_standby = on`
  - `default_transaction_read_only = on`
  - `recovery_min_apply_delay = '3s'` (чтобы consistently видеть лаг)
- `docker/postgres/pg_hba.conf` — доступы для репликации
- `docker/postgres/replica-init.sh` — инициализация реплик (`pg_basebackup`, slots, standby)

### DSN в приложении (`app/.env`)

```dotenv
DATABASE_URL="postgresql://app:app@pgbouncer-primary:6432/app?serverVersion=16&charset=utf8"
DATABASE_URL_READ_BALANCER="postgresql://app:app@read-balancer:6432/app?serverVersion=16&charset=utf8"
DATABASE_URL_READ_SYNC="postgresql://app:app@pgbouncer-replica-sync:6432/app?serverVersion=16&charset=utf8"
DATABASE_URL_READ_ASYNC="postgresql://app:app@pgbouncer-replica-async:6432/app?serverVersion=16&charset=utf8"
```

---

## Демонстрация репликации

Основная команда — `app:replication:demo` (`app/src/Command/ReplicationDemoCommand.php`).

Она:

1. пишет продукт в primary;
2. читает продукт из read‑балансера (sync/async);
3. показывает, увиделась ли запись и на какой реплике;
4. (опционально) показывает LSN‑lag;
5. (опционально) ждёт, пока async‑реплика догонит запись.

### Основные команды

```bash
php bin/console app:replication:demo
php bin/console app:replication:demo --read-your-writes
php bin/console app:replication:demo --show-lsn
php bin/console app:replication:demo --await-async
```

### Полезные флаги

- `--read-your-writes` — читает только с **sync‑replica** (sticky‑read)
- `--await-async` — после первых чтений ждёт, пока async‑реплика увидит запись
- `--await-async-seconds=10` — максимальное время ожидания
- `--show-lsn` — показывает LSN‑lag (байты)
- `--reads=6` / `--delay=500` — число чтений и пауза между ними

### Ожидаемый результат

Без sticky‑read чтения идут через балансер, поэтому часть чтений попадает на async‑реплику и **иногда не видит свежую запись**:

```text
Read (balancer: sync + async)
-----------------------------

 --------- ------------------ --------- ------- ---------------
  attempt   node               replica   found   lsn_lag_bytes
 --------- ------------------ --------- ------- ---------------
  1/4       db-replica-sync    yes       yes     0
  2/4       db-replica-async   yes       no      40
  3/4       db-replica-sync    yes       yes     0
  4/4       db-replica-async   yes       no      40
 --------- ------------------ --------- ------- ---------------
```

С `--read-your-writes` чтение **всегда** уходит на sync‑реплику и запись видна сразу:

```text
Read (read-your-writes -> sync replica)
---------------------------------------

 --------- ----------------- --------- -------
  attempt   node              replica   found
 --------- ----------------- --------- -------
  1/4       db-replica-sync   yes       yes
  2/4       db-replica-sync   yes       yes
  3/4       db-replica-sync   yes       yes
  4/4       db-replica-sync   yes       yes
 --------- ----------------- --------- -------
```

С `--await-async` видно, что async‑реплика **догоняет** запись через несколько секунд:

```text
Async follow-up (eventual consistency)
--------------------------------------

 --------- --------- ------------------ ------- ---------------
  attempt   elapsed   node               found   lsn_lag_bytes
 --------- --------- ------------------ ------- ---------------
  1         0ms       db-replica-async   no      40
  2         400ms     db-replica-async   no      40
  3         800ms     db-replica-async   no      40
  3         2000ms    db-replica-async   yes     0
 --------- --------- ------------------ ------- ---------------
```

### Объяснение результатов

- **sync‑replica** подтверждает commit → запись видна сразу, но commit может быть медленнее.
- **async‑replica** получает WAL без ожидания → commit быстрый, но чтение может быть stale.
- `--read-your-writes` имитирует sticky‑read: после записи читаем только sync‑replica.
- `--await-async` показывает eventual consistency: async‑реплика догоняет через несколько секунд.

---

## Как устроено приложение

- Сущность `Product` — простая таблица `product` (миграция `app/migrations/Version20250101000000.php`).
- Запись — через Doctrine ORM в primary.
- Чтение — через отдельные соединения, создаваемые в команде.

Ключевые файлы:

- `docker-compose.yml`
- `docker/postgres/postgresql.conf`
- `docker/postgres/postgresql.replica.conf`
- `docker/postgres/postgresql.replica-async.conf`
- `docker/postgres/replica-init.sh`
- `docker/haproxy/haproxy.cfg`
- `docker/pgbouncer/*.ini`
- `app/src/Command/ReplicationDemoCommand.php`

---
