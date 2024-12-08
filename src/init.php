<?php
namespace Cacher2;

const DEV_FILE = __DIR__ . '/../.dev';
if (file_exists(DEV_FILE)) {
  require_once DEV_FILE;
}
