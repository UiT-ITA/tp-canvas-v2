<?php declare(strict_types=1);
/**
 * TP Client
 */

namespace TpCanvas;

use GuzzleHttp;

class TPClient extends RESTClient
{
    /** @var int TP institution id number */
    public int $institution;

    /**
     * Constructor
     * @param string $url Tp installation
     * @param string $key Tp API key
     * @param int $institution Tp institution id number
     * @return void
     */
    public function __construct(string $url, string $key, int $institution)
    {
        $this->institution = $institution;

        $defaultopts = [
            'base_uri' => "{$url}ws/",
            'headers' => [
                'X-Gravitee-Api-Key' => $key
            ],
        ];
        parent::__construct($defaultopts);
    }

    /**
     * List courses
     * @param string $semester semester e.g. "20v"
     * @param int|null $times ???
     * @return array courses
     */
    public function courses(string $semester, ?int $times = null): array
    {
        $query = ['id' => $this->institution, 'sem' => $semester];
        if (!is_null($times)) {
            $query['times'] = $times;
        }
        $response = $this->get("course/", ['query' => $query]);
        return (self::responseToNative($response));
    }



    /**
     * List courses changed since
     * @param string timestamp in ISO-8601 format e.g "2020-01-21T00:00:00"
     * @param string type
     * @return array courses
     */
    public function lastchangedlist(string $timestamp, string $type = 'course'): array
    {
        $response = $this->get("1.4/lastchanged-list.php", ['query' => ['timestamp' => $timestamp, 'type' => $type]]);
        $response = (self::responseToNative($response));
        return $response->elements;
    }
}
