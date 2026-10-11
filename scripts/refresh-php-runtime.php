<?php
/** Rebuild php-runtime.json + httpd-php-versions.conf from php* folders (CLI). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/htdocs/panel/includes/bootstrap.php';
fwrite(STDOUT, 'php-runtime: ' . count(PHP_VERSIONS) . " version(s)\n");
exit(0);
