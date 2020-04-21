<?php declare(strict_types=1);
/**
 * Canvas Account Collection
 * An iterator of canvas accounts
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class CanvasAccountCollection extends CanvasCollection
{
    /**
     * Get list of accounts
     *
     * @return array An array of stdClass objects
     */
    public function getList(): array
    {
        return $this->canvasclient->accounts();
    }

    /**
     * Get a single account object from the REST api
     *
     * @param int $accountid
     * @return object A single stdClass object or null
     */
    public function getSingle(int $accountid): ?object
    {
        return $this->canvasclient->account($accountid);
    }

    /**
     * Create a CanvasAccount instance from a stdClass object
     *
     * @param object $element
     * @return CanvasAccount
     */
    public function createElementInstance(object $element): CanvasAccount
    {
        return new CanvasAccount($this->canvasclient, $this->logger, $element);
    }
}
