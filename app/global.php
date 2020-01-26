<?php
/**
 * A file for global operations. Included by all scripts meant to run directly.
 */

/**
 * Include the composer autoloader - necessary to use composer packages.
 */
require __DIR__ . '/vendor/autoload.php';

if ($_SERVER['debug'] == 'on') {
    phpinfo();
}
