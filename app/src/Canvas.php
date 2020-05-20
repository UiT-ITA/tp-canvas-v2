<?php declare(strict_types=1);
/**
 * Canvas
 * This is a high level Canvas client. It aims at an object-oriented view of
 * Canvas.
 */

namespace TpCanvas;

use \Psr\Log\Loggerinterface;

class Canvas
{
    private CanvasClient $canvasclient; // The canvas client to use for REST calls
    private Loggerinterface $logger; // The PSR logger interface to use for logging

    public CanvasAccountCollection $accounts; // The accounts in this Canvas instance

    /*
    * Constructor
    * @param CanvasClient $canvasclient Canvas REST client
    * @param Loggerinterface $logger Logger object
    * @return void
    */
    public function __construct(CanvasClient $canvasclient, Loggerinterface $logger)
    {
        $this->canvasclient = $canvasclient;
        $this->logger = $logger;
        $this->accounts = new CanvasAccountCollection($canvasclient, $logger);
    }
}
