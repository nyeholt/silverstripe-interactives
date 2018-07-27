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

/**
 * Controller extension that binds details of the configured interactives
 * into the current page view
 *
 * @author marcus
 */
class InteractiveControllerExtension extends Extension
{
    public function onAfterInit() {
        Requirements::javascript('nyeholt/silverstripe-interactives:client/javascript/interactives.js');

        $fragment = $this->generateInteractivesFragment();

        Requirements::customScript($fragment, 'ads');
    }

    public function generateInteractivesFragment() {
        $url = $this->owner->getRequest()->getURL();

        $siteWide = InteractiveCampaign::get()->filter(['SiteWide' => 1]);
        $pageCampaigns = ArrayList::create();

        $page = $this->owner->data();
        if ($page instanceof Page) {
            $pageCampaigns = InteractiveCampaign::get()->filterAny(['OnPages.ID' => $page->ID]);
        }

        $campaigns = array_merge($siteWide->toArray(), $pageCampaigns->toArray());

        $items = [];
        foreach ($campaigns as $campaign) {
            // collect its interactives.
            $class = $page ? $page->class : null;
            $anyViewable = $campaign->invokeWithExtensions('viewableOn', $url, $class);
            $canView = array_reduce($anyViewable, function ($carry, $item) {
                return $carry && $item;
            }, true);

            if (!$canView) {
                continue;
            }

            $interactives = $campaign->relevantInteractives($url, $page);
            $items[] = array(
                'interactives' => $interactives,
                'display'       => $campaign->DisplayType,
                'id'            => $campaign->ID,
                'trackIn'       => $campaign->TrackIn,
            );
        }

        $item = '';
        $page = Director::get_current_page();
        if ($page) {
            $table = DataObject::getSchema()->tableName($page->ClassName);
            $item = $table . "," . $page->ID;
        }

        $data = array(
            'endpoint'  => '',
            'trackviews'    => false,
            'trackclicks'   => true,
            'remember'      => false,
            'campaigns'     => $items,
            'tracker'       => Config::inst()->get(Interactive::class, 'tracker_type'),
            'item' => $item,
        );

        $data = json_encode($data);
        return 'window.SSInteractives = {config: ' . $data . '};';
    }
}
