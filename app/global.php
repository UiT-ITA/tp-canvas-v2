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
if ($_SERVER['debug'] == 'on') {
    $log->pushHandler(new ErrorLogHandler());
} else {
    $log->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::INFO));
}

if ($_SERVER['debug'] == 'on') {
    var_dump($server); // Dump environment to stdout
    error_reporting(-1); // Enable all error reporting
    $log->notice('Debug mode');
}
