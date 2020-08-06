<?php

require_once 'Credentials.php';
require_once 'DB.php';
require_once 'Migrator.php';

$m = new Migrator();
$m->migrate($credentials);
$m->listAll(php_sapi_name() === 'cli');