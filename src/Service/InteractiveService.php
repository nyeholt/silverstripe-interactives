<?php

namespace Symbiote\Interactives\Service;

use Symbiote\Interactives\Model\InteractiveImpression;
use SilverStripe\Security\Member;
use Symbiote\Interactives\Model\InteractiveClient;
use Symbiote\Interactives\Model\Interactive;
use Symbiote\Interactives\Model\InteractiveCampaign;


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

    public function webEnabledMethods()
    {
        return [
            'mostViewed' => [
                'type' => 'GET',
                'perm' => 'SYMBIOTIC_FRONTEND_USER',
            ],
            'interactiveStats' => [
                'type' => 'GET',
                'perm' => 'SYMBIOTIC_FRONTEND_USER',
            ]
        ];
    }


    protected function requestedItem($item)
    {
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

    public function mostViewed($number = 10, $filter = [], $age = '-10 days')
    {
        $number = max($number, 10);
        $member = Member::currentUser();
        if (!$member) {
            return [];
        }

        if (!isset($filter['InteractiveID'])) {
            $interactives = $this->memberInteractives($member);
            $filter['InteractiveID'] = $interactives->column();
        }

        $list = InteractiveImpression::get()->filter($filter);

        $dataQuery = $list->dataQuery();
        $query = $dataQuery->getFinalisedQuery();

        $out = $query
            ->aggregate('COUNT("ID")', 'NumViews')
            ->addSelect("Item")
                // ->addWhere('"Item" NOT LIKE \'Home%\'')
            ->setOrderBy('"NumViews" DESC')
            ->addGroupBy(['Item'])
                // need to do twice the number here, because the limit
                // gets applied before the group because SilverStripe or something. Sigh
            ->setLimit($number * 2)
            ->execute();

        $objects = [];
        foreach ($out as $row) {
            if (!$row['Item'] || !strpos($row['Item'], ',')) {
                continue;
            }
            list($table, $id) = explode(',', $row['Item']);
            $object = new \stdClass;
            $object->Item = $row['Item'];
            $object->NumViews = $row['NumViews'];
            $objects[] = $object;
        }
        return $objects;

    }

    protected function memberInteractives($member)
    {
        // get the clients this member has access to.
        $clients = InteractiveClient::get()->filter([
            'Members.ID' => $member->ID,
        ]);

        $interactives = Interactive::get()->filter([
            'Campaign.ClientID' => $clients->column()
        ]);

        return $interactives;
    }

    /**
     * Retrieve statistics for a given url
     */
    public function interactiveStats($item, $filterSet = [])
    {
        $member = Member::currentUser();
        if (!$member) {
            return [];
        }

        $interactives = $this->memberInteractives($member);
        $interactiveIds = $interactives->column();

        $results = [];
        $itemInfo = InteractiveImpression::get()->filter([
            'InteractiveID' => $interactiveIds,
            'Item' => $item,
        ]);

        foreach ($filterSet as $name => $filter) {
            $results[$name] = $itemInfo->filter($filter)->count();
        }

        return $results;
    }
}
