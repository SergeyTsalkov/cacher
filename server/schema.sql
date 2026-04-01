CREATE TABLE IF NOT EXISTS items (
    world       TEXT    NOT NULL,
    key         TEXT    NOT NULL,
    version     TEXT    NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    PRIMARY KEY (world, key, version)
);

CREATE TABLE IF NOT EXISTS users (
    name        TEXT    NOT NULL PRIMARY KEY,
    api_key     TEXT    NOT NULL UNIQUE,
    level       INTEGER NOT NULL CHECK (level BETWEEN 1 AND 3),
    world       TEXT    NOT NULL,
    created_by  TEXT    NOT NULL,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE INDEX IF NOT EXISTS idx_items_world_key ON items(world, key);
