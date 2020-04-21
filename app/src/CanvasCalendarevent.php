<?php declare(strict_types=1);
/**
 * Canvas Course
 * An object-oriented representation of a Canvas course
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;
use \GuzzleHttp\Exception\ClientException;

class CanvasCalendarevent extends CanvasObject
{
    #region magic methods
    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Canvas API key
    * @param object $sourceobject The source object to base this object on
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger, object $sourceobject = null)
    {
        parent::__construct($canvasclient, $logger, $sourceobject);
    }

    /**
     * Magical __toString method
     *
     * @return string A string representation of this object
     */
    public function __toString(): string
    {
        $simpledate = $this->getShorttime();
        return "{$simpledate} {$this->title}";
    }

    #endregion magic methods

    #region CanvasObject methods

    public function save(): void
    {
        throw new ErrorException("Not implemented");
    }

    #endregion CanvasObject methods

    public function delete(): void
    {
        try {
            $this->canvasclient->calendar_events_delete($this->id);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                // Not found in Canvas, let's just take credit.
                $this->logger->warning(static::class . " missing in Canvas, assume deletion", ['id' => $this->id]);
                return;
            }
            if ($e->getResponse()->getStatusCode() == 401) {
                // Unauthorized, let's see if it is because of a ghost object.
                // Not doing a try{} - if this fails, let it bubble up.
                $response2 = $this->canvasclient->calendar_events_get($this->id);
                if ($response2->workflow_state == 'deleted') {
                    // Marked as deleted in Canvas, let's just take credit.
                    $this->logger->notice(static::class . " marked as deleted in Canvas, assume deleted", ['id' => $this->id]);
                    return;
                }
                $this->logger->info("Unhandled 401");
            }
            throw $e;
        }
        $this->logger->info(static::class . " deleted", ['id' => $this->id]);
    }
    /**
     * Return a short time descriptor for this event. Assumes start and end is
     * at the same date. Format is date, start time and end time.
     *
     * @return string The short date string.
     */
    public function getShorttime(): string
    {
        $out =  date("j.n.y G:i", \strtotime($this->start_at));
        $out .= date("-G:i", \strtotime($this->end_at));
        return $out;
    }
}
