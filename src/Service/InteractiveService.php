<?php

namespace Symbiote\Interactives\Service;

use Symbiote\Interactives\Model\InteractiveImpression;


/**
 * Abstracts the interaction of tracking certain interactions
 *
 */
class InteractiveService
{

    /**
     * The events we're configured to track
     */
    public $allowedEvents = array(
        'clk' => 'Click',
        'imp' => 'View',
        'int' => 'Interact',
        'cpl' => 'Complete',
    );

    protected function requestedItem($item) {
        if (preg_match('/[a-zA-Z0-9_-]+,\d+/i', $item)) {
            return $item;
        }
    }

    /**
     * Track an event
     *
     * @param string $event
     *      The name of the event in short form
     * @param string $ids
     *      Comma separated list of ids relating to this event
     * @param string $sig
     *      unique identifier of the source of this event
     * @param string $item
     *      A String,Int mapping to an object that was the source of the event
     */
    public function track($event, $ids, $sig = null, $item = null)
    {
        $trackAs = isset($this->allowedEvents[$event]) ? $this->allowedEvents[$event] : null;

        if ($trackAs && $ids) {
            $ids = explode(',', $ids);
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id) {
                    $imp = InteractiveImpression::create([
                        'Interaction' => $trackAs,
                        'Signature' => $sig,
                        'Item' => $item,
                    ]);
                    $imp->InteractiveID = $id;
                    $imp->write();
                }
            }
            return true;
        }

        return false;
    }
}
