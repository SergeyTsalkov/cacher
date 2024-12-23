CREATE TABLE items (
    `key` TEXT NOT NULL,
    `version` TEXT NOT NULL,
    `path` TEXT NOT NULL DEFAULT '',
    `files` TEXT NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `touched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`, `version`)
);

CREATE TABLE installed (
    `username` TEXT NOT NULL DEFAULT '',
    `key` TEXT NOT NULL,
    `version` TEXT NOT NULL,
    `path` TEXT NOT NULL DEFAULT '',
    `files` TEXT NOT NULL DEFAULT '',
    `is_symlink` INTEGER NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`username`, `key`)
);