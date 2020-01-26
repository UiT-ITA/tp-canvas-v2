<?php
/**
 * Test file. Nothing important happens here.
 */

require_once "global.php";

use GuzzleHttp\Client;

$client = new Client([
    // Base URI is used with relative requests
    'base_uri' => 'http://httpbin.org',
    // You can set any number of default request options.
    'timeout'  => 2.0,
]);

// Send a request to https://foo.com/root
$response = $client->request('GET', '/root');

var_dump($response);
