#!/usr/bin/env php
<?php

declare(strict_types=1);

use PHPUnit\TextUI\Application;

$composer_install = __DIR__ . '/autoload.php';
define('PHPUNIT_COMPOSER_INSTALL', $composer_install);

require PHPUNIT_COMPOSER_INSTALL;

exit((new Application())->run($_SERVER['argv']));
