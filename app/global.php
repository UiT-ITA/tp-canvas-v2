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

// Sentry
// Anything run in debug mode does not send events to sentry
if ($_SERVER['debug'] != 'on' && strlen($_SERVER['sentry_dsn'])) {
    $client = \Sentry\ClientBuilder::create(['dsn' => $_SERVER['sentry_dsn']])->getClient();
    $sentryhandler = new \Sentry\Monolog\Handler(new \Sentry\State\Hub($client), Logger::ERROR);
    $log->pushHandler($sentryhandler);
}

// Errorlog (stderr)
if ($_SERVER['debug'] == 'on') {
    // Debug mode sends EVERYTHING to stderr
    $log->pushHandler(new ErrorLogHandler());
} else {
    $log->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::INFO));
}

if ($_SERVER['debug'] == 'on') {
    var_dump($_SERVER); // Dump environment to stdout
    error_reporting(-1); // Enable all error reporting
    $log->notice('Debug mode');
}
