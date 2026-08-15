#!/usr/bin/env php
<?php

declare(strict_types = 1);

use ComposerRequireChecker\Cli\Application;

require dirname(__DIR__, 2) . '/_vendor/autoload.php';

exit((new Application())->run());
