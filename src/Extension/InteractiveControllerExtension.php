<?php

namespace Symbiote\Interactives\Extension;

use Page;

use SilverStripe\View\Requirements;
use SilverStripe\Core\Config\Config;
use Symbiote\Interactives\Model\Interactive;
use SilverStripe\Core\Extension;
use Symbiote\Interactives\Model\InteractiveCampaign;
use SilverStripe\Control\Director;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ArrayList;
use Symbiote\Interactives\Model\InteractiveClient;

/**
 * Controller extension that binds details of the configured interactives
 * into the current page view
 *
 * @author marcus
 */
class InteractiveControllerExtension extends Extension
{
    private static $include_interactives_fragment = true;

    public function onAfterInit()
    {
        if (Config::inst()->get(self::class, 'include_interactives_fragment')) {
            Requirements::javascript('nyeholt/silverstripe-interactives:client/javascript/interactives.js');
            $fragment = $this->generateInteractivesFragment();
            Requirements::customScript($fragment, 'ads');
        }
    }

    /**
     * Generates js fragment for including interactives detail in
     * the page
     *
     * @param $url string
     *          The URL to use as context for the interactives to generate
     * @param $client Symbiote\Interactives\Model\InteractiveClient
     *          The client to retrieve interactives for
     * @param $class string
     *          The page type associated with the requested URL
     */
    public function generateInteractivesFragment($url = null, InteractiveClient $client = null, $class = null, $for = null)
    {
        if (!$url) {
            $url = $this->owner->getRequest()->getURL();
        }

        $campaigns = $client ? $client->Campaigns() : InteractiveCampaign::get();

        $items = [];
        foreach ($campaigns as $campaign) {
            // collect its interactives.
            //
            // NOTE(Marcus) 2019-01-30
            //
            // Note that this has moved to being performed client side
            //
            // $anyViewable = $campaign->invokeWithExtensions('viewableOn', $url, $class);
            // $canView = array_reduce($anyViewable, function ($carry, $item) {
            //     return $carry && $item;
            // }, true);

            // if (!$canView) {
            //     continue;
            // }

            $items[] = $campaign->forJson();
        }

        $item = $for ? $for : null;

        if (!$item) {
            $page = Director::get_current_page();
            if ($page && $page->ID > 0) {
                $table = DataObject::getSchema()->tableName($page->ClassName);
                $item = $table . "," . $page->ID;
            }
        }

        $data = array(
            'endpoint' => Director::absoluteBaseURL().'int-act/trk',
            'trackviews' => false,
            'trackclicks' => true,
            'remember' => false,
            'campaigns' => $items,
            'tracker' => Config::inst()->get(Interactive::class, 'tracker_type'),
            'item' => $item,
        );

        $data = json_encode($data);
        return 'window.SSInteractives = {config: ' . $data . '};';
    }
}
