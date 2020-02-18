<?php
/**
 * A REST Client wrapper for Guzzle
 */

namespace TpCanvas;

use GuzzleHttp;

class RESTClient extends GuzzleHTTP\Client
{
    private $handlerStack;

    /**
     * Constructor
     * @param array? $config Config array passed to Guzzle client
     * @return void
     */
    public function __construct(array $config = [])
    {
        $this->handlerStack = GuzzleHttp\HandlerStack::create();
        $retrymiddleware = GuzzleHttp\Middleware::retry(
            '\\TpCanvas\RESTClient::retryDecider',
            '\\TpCanvas\RESTClient::retryDelay'
        );
        $this->handlerStack->push($retrymiddleware);
        $this->handlerStack->push(GuzzleHttp\Middleware::httpErrors());
        $defaultopts = [
            'debug' => ($_SERVER['curldebug'] == "on" ? true : false),
            'handler' => $this->handlerStack,
            'synchronous' => true
        ];

        parent::__construct($config + $defaultopts);
    }

    /**
     * Decide if we retry the request
     *
     * @param int $retries
     * @param GuzzleHttp\Psr7\Request $request
     * @param GuzzleHttp\Psr7\Response $response
     * @param GuzzleHttp\Exception\ConnectException $exception
     * @return bool Should we wait or not
     */
    public static function retryDecider(
        int $retries,
        GuzzleHttp\Psr7\Request $request,
        GuzzleHttp\Psr7\Response $response = null,
        \RuntimeException $exception = null
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
            // Retry on 403 Forbidden because Canvas uses it for throttling
            if ($response->getStatusCode() == 403) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decide delay to use when retrying
     *
     * @param int $numberOfRetries
     * @return int milliseconds to wait
     */
    public static function retryDelay(int $numberOfRetries)
    {
        return 3000 * $numberOfRetries;
    }

    /**
     * Paginated get
     * @param string $path path to get
     * @param array $opts options to pass along
     * @return mixed native data - preferably an array
     */
    public function paginatedGet(string $path, array $opts)
    {
        $response = $this->get($path, $opts);
        $return = self::responseToNative($response);
        if (!is_array($return)) {
            return $return;
        }
        $nextpage = self::getPSR7NextLinkPage($response);
        while ($nextpage) {
            $response = $this->get($nextpage);
            $return = array_merge($return, self::responseToNative($response));
            $nextpage = self::getPSR7NextLinkPage($response);
        }
        return $return;
    }

    /**
     * Convert a PSR 7 response to a native data type
     * For now, it assumes everything is json. Should probably look at the
     * content header of the response to determine format.
     *
     * @param GuzzleHTTP\Psr7\Response $response
     * @return mixed decoded response
     */
    public static function responseToNative(GuzzleHTTP\Psr7\Response $response)
    {
        /** @todo Throw exceptions */
        return (json_decode($response->getBody()->getContents()));
    }

    /**
     * Find next page of a paginated response using link headers
     *
     * @param GuzzleHttp\Psr7\Response $response Response from Canvas API
     * @return string The uri for next page, empty string otherwise.
     * @todo Needs better error handling
     */
    public static function getPSR7NextLinkPage(GuzzleHttp\Psr7\Response $response): string
    {
        $linkheader= $response->getHeader('Link')[0]; // Get link header - needs error handling
        $nextpage = array();
        // Parse out all links
        preg_match_all('/\<(.+)\>; rel=\"(\w+)\"/iU', $linkheader, $nextpage, PREG_SET_ORDER);
        // Reduce to only next link
        $nextpage = array_filter($nextpage, function (array $entry) {
            return ($entry[2] == "next");
        });
        if (count($nextpage)) {
            return reset($nextpage)[1];
        }
        return '';
    }

}

