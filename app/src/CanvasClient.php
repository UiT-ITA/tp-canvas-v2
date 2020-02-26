<?php declare(strict_types=1);
/**
 * Canvas Client
 * This is a low level Canvas client. It aims a 1:1 implementation of the Canvas
 * REST API endpoints. It handles pagination, authentication, versioning and
 * json decoding to anonymous PHP data types.
 */

namespace TpCanvas;

use GuzzleHttp;

class CanvasClient extends RESTClient
{
    /**
     * Constructor
     * @param string $url Canvas installation
     * @param string $key Canvas API key
     * @return void
     */
    public function __construct(string $url, string $key)
    {
        $defaultopts = [
            'base_uri' => "{$url}api/v1/",
            'headers' => [
                'Authorization' => "Bearer {$key}"
            ]
        ];

        parent::__construct($defaultopts);
    }

    /**
     * List accounts
     * @return array accounts
     */
    public function accounts()
    {
        $response = $this->get("accounts");
        return (self::responseToNative($response));
    }

    /**
     * List courses in account
     * @param int $accountid account id
     * @param array $params parameters to search on
     * @return array courses
     * @see https://canvas.instructure.com/doc/api/accounts.html#method.accounts.courses_api
     */
    public function accounts_courses(int $accountid, array $params = array())
    {
        $params = ['per_page' => 999] + $params;
        $response = $this->paginatedGet(
            "accounts/{$accountid}/courses",
            ['query' => $params]
        );
        return $response;
    }

    /**
     * Get calendar events
     * @param array $params parameters to filter on
     * @return array calendar events
     * @see https://canvas.instructure.com/doc/api/calendar_events.html#method.calendar_events_api.index
     */
    public function calendar_events(array $params = array())
    {
        $params = ['per_page' => 999, 'all_events' => true] + $params;
        $response = $this->paginatedGet(
            "calendar_events",
            ['query' => $params]
        );
        return $response;
    }

    /**
     * Compare to events. Returns true if equal (for some definition of equal)
     * @param object $event1
     * @param object $event2
     * @return bool isEqual
     */
    public static function eventsEqual(object $event1, object $event2, bool $strict = false)
    {
        $comparefields = [
            'title',
            'start_at',
            'end_at',
            'description',
            'location_name',
            'workflow_state'
        ];
        if ($strict) {
            // Should created_at and updated_at be considere here?
            $comparefields += [
                'id',
                'context_code',
                'effective_context_code'
            ];
        }

        foreach ($comparefields as $cmp) {
            if ($event1->$cmp != $event2->$cmp) {
                return false;
            }
        }

        return true;
    }
}
