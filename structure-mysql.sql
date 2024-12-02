CREATE TABLE `items` (
    `key` VARCHAR(255) NOT NULL,
    `version` VARCHAR(64) NOT NULL,
    `path` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`, `version`)
);
