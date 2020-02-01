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

$canvasHandlerStack = GuzzleHttp\HandlerStack::create();
$canvasHandlerStack->push(GuzzleHttp\Middleware::retry(retryDecider(), retryDelay()));
$canvasclient = new GuzzleHttp\Client([
    'debug' => ($_SERVER['debug'] == "on" ? true : false),
    'base_uri' => "{$_SERVER['canvas_url']}api/v1/",
    'headers' => [
        'Authorization' => "Bearer {$_SERVER['canvas_key']}"
    ],
    'handler' => $canvasHandlerStack,
    /** @todo fix exception support */
    'http_errors' => false // We are not exception compliant :-/
]);


// Send a request to https://foo.com/root
$response = $canvasclient->get("accounts/1/courses", ['query' => ['search_term' => 'INF-1110', 'per_page' => 10]]);
//var_dump($response);
$out = json_decode((string) $response->getBody(), true);
$linkheader= $response->getHeader('Link')[0];
var_dump($linkheader);
$nextpage = array();
preg_match_all('/\<(.+)\>; rel=\"(\w+)\"/iU', $linkheader, $nextpage, PREG_SET_ORDER);
var_dump($nextpage);
$nextpage = array_filter($nextpage, function (array $entry) {
    return ($entry[2] == "next");
});
var_dump($nextpage);
while (count($nextpage)>0) {
    echo "loop\n";
    $nextpage = reset($nextpage)[1];
    $response = $canvasclient->get($nextpage);
    //var_dump($response);
    array_merge($out,json_decode((string) $response->getBody(), true));
    $linkheader= $response->getHeader('Link')[0];
    var_dump($linkheader);
    $nextpage = array();
    preg_match_all('/\<(.+)\>; rel=\"(\w+)\"/iU', $linkheader, $nextpage, PREG_SET_ORDER);
    var_dump($nextpage);
    $nextpage = array_filter($nextpage, function (array $entry) {
        return ($entry[2] == "next");
    });
    var_dump($nextpage);
}

function retryDecider()
{
    return function (
        $retries,
        GuzzleHttp\Psr7\Request $request,
        GuzzleHttp\Psr7\Response $response = null,
        GuzzleHttp\Exception\ConnectException $exception = null
    ) {
       // Limit the number of retries to 5
        if ($retries >= 5) {
            return false;
        }

        // Retry connection exceptions
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($response) {
            // Retry on server errors
            if ($response->getStatusCode() >= 500) {
                return true;
            }
        }
        return false;
    };
}
function retryDelay()
{
    return function ($numberOfRetries) {
        return 1000 * $numberOfRetries;
    };
}
