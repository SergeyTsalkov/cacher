CREATE TABLE items (
    `key` TEXT NOT NULL,
    `version` TEXT NOT NULL,
    `path` TEXT NOT NULL DEFAULT '',
    `files` TEXT NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`, `version`)
);
