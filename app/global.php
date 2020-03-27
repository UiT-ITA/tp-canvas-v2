<?php
/**
 * A file for global operations. Included by all scripts meant to run directly.
 */

declare(strict_types = 1);

use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;
use Monolog\ErrorHandler;

/**
 * Include the composer autoloader - necessary to use composer packages.
 */
require __DIR__ . '/vendor/autoload.php';

/**
 * Set up logging
 */

$log = new Logger('tpcanvas');
ErrorHandler::register($log);

// Sentry
// Add sentry for anything not running in debug mode
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
