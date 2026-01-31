CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Allow the application user used by postgres-exporter to read stats
DO
$$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app') THEN
        CREATE ROLE app LOGIN PASSWORD 'app';
    END IF;
END;
$$;

GRANT pg_read_all_stats TO app;
