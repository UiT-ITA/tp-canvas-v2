<?php
/**
 * A file for global operations. Included by all scripts meant to run directly.
 */

declare(strict_types = 1);

use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;

/**
 * Include the composer autoloader - necessary to use composer packages.
 */
require __DIR__ . '/vendor/autoload.php';

/**
 * Set up logging
 */

$log = new Logger('tpcanvas');
$log->pushHandler(new ErrorLogHandler());

if ($_SERVER['debug'] == 'on') {
    phpinfo(); // Dump all PHP internals to stdout
    error_reporting(-1); // Enable all error reporting
    $log->info('Debug mode');
}
