<?php
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
     * @param string semester e.g. "20v"
     * @return array courses
     */
    public function courses(string $semester)
    {
        $response = $this->get("course/", ['query' => ['id' => $this->institution, 'sem' => $semester]]);
        return (self::responseToNative($response));
    }

    /**
     * List courses changed since
     * @param string timestamp in ISO-8601 format e.g "2020-01-21T00:00:00"
     * @param string type
     * @return array courses
     */
    public function lastchangedlist(string $timestamp, string $type = 'course')
    {
        $response = $this->get("1.4/lastchanged-list.php", ['query' => ['timestamp' => $timestamp, 'type' => $type]]);
        $response = (self::responseToNative($response));
        return $response->elements;
    }
}
