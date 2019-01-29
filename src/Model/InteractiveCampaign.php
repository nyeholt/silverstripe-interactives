<?php

namespace Symbiote\Interactives\Model;

use Symbiote\Interactives\Extension\InteractiveLocationExtension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\View\Requirements;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveCampaign extends DataObject
{
    private static $table_name = 'InteractiveCampaign';

    private static $db = array(
        'Title' => 'Varchar',
        'Begins' => 'Date',
        'Expires' => 'Date',
        'ResetStats' => 'Boolean',
        'DisplayType' => 'Varchar(64)',
        'TrackIn' => 'Varchar(64)',
        'AllowedHosts' => 'MultiValueField',
    );

    private static $has_many = array(
        'Interactives' => Interactive::class,
    );

    private static $has_one = array(
        'Client' => InteractiveClient::class,
    );

    private static $extensions = array(
        InteractiveLocationExtension::class,
        Versioned::class
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $reset = $fields->dataFieldByName('ResetStats');
        $fields->addFieldToTab('Root.Interactives', $reset);

        $options = array(
            'random' => 'Always Random',
            'stickyrandom' => 'Sticky Random',
            'all' => 'All',
        );

        $fields->replaceField('DisplayType', $df = DropdownField::create('DisplayType', 'Use items as', $options));
        $df->setRightTitle("Should one random item of this list be displayed, or all of them at once? A 'Sticky' item is randomly chosen, but then always shown to the same user");

        $grid = $fields->dataFieldByName('Interactives');
        if ($grid) {
            $config = $grid->getConfig();

        }

        $options = ['' => 'Default', 'Local' => "Locally", 'Google' => 'Google events'];
        $fields->replaceField('TrackIn', $df = DropdownField::create('TrackIn', 'Track interactions in', $options));

        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if ($this->ResetStats) {
            foreach ($this->Interactives() as $interactive) {
                $table = ClassInfo::baseDataClass(InteractiveImpression::class);
                $query = new SQLDelete($table, ['InteractiveID' => $interactive->ID]);
                $query->execute();
            }
        }

        $this->ResetStats = false;
    }

    /**
     * Convert for inclusion in output JSON
     */
    public function forJson()
    {
        $interactives = $this->relevantInteractives();
        $includeUrls = $this->IncludeUrls->getValues();
        $includeCss = $this->IncludeCssMatch->getValues();
        $excludeUrls = $this->ExcludeUrls->getValues();
        $excludeCss = $this->ExcludeCssMatch->getValues();

        $me = array(
            'interactives' => $interactives,
            'display' => $this->DisplayType,
            'id' => $this->ID,
            'trackIn' => $this->TrackIn,
            'siteWide' => $this->SiteWide,
            'include' => [
                'urls' => $includeUrls ? $includeUrls : [],
                'css' => $includeCss ? $includeCss : [],
            ],
            'exclude' => [
                'urls' => $excludeUrls ? $excludeUrls : [],
                'css' => $excludeCss ? $excludeCss : [],
            ],
        );

        return $me;
    }

    /**
     * Collect a list of interactives that are relevant for the passed in URL
     * and viewed page
     *
     * @param string $url
     *      @deprecated
     * @param SiteTree $page
     *      @deprecated
     */
    public function relevantInteractives($url = null, $page = null)
    {
        $items = [];
        foreach ($this->Interactives() as $ad) {
            // NOTE(Marcus) 2019-01-30
            // Disabled interactive-level hiding for now; it's not
            // currently exposed at all
            //
            // if (!$ad->viewableOn($url, $page ? $page->class : null)) {
            //     continue;
            // }

            $items[] = $ad->forDisplay();
            if ($ad->ExternalCssID) {
                Requirements::css($ad->getUrl());
            }
        }
        return $items;
    }

    /**
     * Is this campaign viewable? Checks start / expires dates
     *
     * @param type $url
     * @param type $pageType
     */
    public function viewableOn($url, $pageType = null)
    {
        $start = 0;
        $end = strtotime('2038-01-01');
        if ($this->Begins) {
            $start = strtotime(date('Y-m-d 00:00:00', strtotime($this->Begins)));
        }
        if ($this->Expires) {
            $end = strtotime(date('Y-m-d 23:59:59', strtotime($this->Expires)));
        }

        return $start < time() && $end > time();
    }

    public function getRandomAd()
    {
        $number = $this->Interactives()->count();
        if ($number) {
            --$number;
            $rand = mt_rand(0, $number);
            $items = $this->Interactives()->toArray();
            return $items[$rand];
        }
    }
}
