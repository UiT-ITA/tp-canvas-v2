<?php
/**
 * A file for global operations. Included by all scripts meant to run directly.
 */

declare(strict_types = 1);

use Monolog\Logger;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FilterHandler;
use Monolog\ErrorHandler;
use TpCanvas\ChangeList;

/**
 * Include the composer autoloader - necessary to use composer packages.
 */
require __DIR__ . '/vendor/autoload.php';

/**
 * Set up logging
 */

$log = new Logger('tpcanvas');

if ($_SERVER['debug'] == 'on') {
    // Debug mode sends EVERYTHING to stderr
    $log->pushHandler(new ErrorLogHandler());
} else {
    // Add sentry for anything not running in debug mode
    if (strlen($_SERVER['sentry_dsn'])) {
        $client = \Sentry\ClientBuilder::create(['dsn' => $_SERVER['sentry_dsn']])->getClient();
        $sentryhandler = new \Sentry\Monolog\Handler(new \Sentry\State\Hub($client), Logger::ERROR);
        $log->pushHandler($sentryhandler);
    }
    // Levels info through warning are sent to stdout, error through emergency goes to stderr
    $log->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::ERROR));
    $stdh = new StreamHandler("php://stdout", Logger::INFO);
    $log->pushHandler(new FilterHandler($stdh, Logger::INFO, Logger::WARNING));
}
ErrorHandler::register($log);

if ($_SERVER['debug'] == 'on') {
    var_dump($_SERVER); // Dump environment to stdout
    error_reporting(-1); // Enable all error reporting
    $log->notice('Debug mode');
}

$changelist = new ChangeList($log);
