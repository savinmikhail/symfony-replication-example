#!/bin/sh
set -e

PRIMARY_HOST=${PRIMARY_HOST:-db-primary}
PRIMARY_PORT=${PRIMARY_PORT:-5432}
REPLICATION_USER=${REPLICATION_USER:-replicator}
REPLICATION_PASSWORD=${REPLICATION_PASSWORD:-replicator}
REPLICA_NAME=${REPLICA_NAME:-db-replica}
REPLICATION_SLOT=${REPLICATION_SLOT:-replica_slot}

mkdir -p "$PGDATA"
chown -R postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"

if [ ! -s "$PGDATA/PG_VERSION" ]; then
  echo "Waiting for primary at ${PRIMARY_HOST}:${PRIMARY_PORT}..."
  until PGPASSWORD="$REPLICATION_PASSWORD" pg_isready -h "$PRIMARY_HOST" -p "$PRIMARY_PORT" -U "$REPLICATION_USER" -d postgres >/dev/null 2>&1; do
    sleep 1
  done

  echo "Running base backup for ${REPLICA_NAME}..."

  SLOT_EXISTS=$(PGPASSWORD="$REPLICATION_PASSWORD" su-exec postgres psql \
    -h "$PRIMARY_HOST" \
    -p "$PRIMARY_PORT" \
    -U "$REPLICATION_USER" \
    -d postgres \
    -Atqc "SELECT 1 FROM pg_replication_slots WHERE slot_name = '${REPLICATION_SLOT}'" || true)

  if [ "$SLOT_EXISTS" = "1" ]; then
    SLOT_ARGS="-S $REPLICATION_SLOT"
  else
    SLOT_ARGS="-C -S $REPLICATION_SLOT"
  fi

  PGPASSWORD="$REPLICATION_PASSWORD" su-exec postgres pg_basebackup \
    -h "$PRIMARY_HOST" \
    -p "$PRIMARY_PORT" \
    -D "$PGDATA" \
    -U "$REPLICATION_USER" \
    -Fp -Xs -P \
    $SLOT_ARGS

  cat > "$PGDATA/postgresql.auto.conf" <<CONF
primary_conninfo = 'host=${PRIMARY_HOST} port=${PRIMARY_PORT} user=${REPLICATION_USER} password=${REPLICATION_PASSWORD} application_name=${REPLICA_NAME}'
primary_slot_name = '${REPLICATION_SLOT}'
CONF

  touch "$PGDATA/standby.signal"
  echo "Replica ${REPLICA_NAME} initialized."
fi

exec su-exec postgres postgres -c config_file=/etc/postgresql/postgresql.conf -c hba_file=/etc/postgresql/pg_hba.conf
