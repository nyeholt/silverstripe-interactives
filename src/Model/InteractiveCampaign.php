<?php

namespace Symbiote\Interactives\Model;

use Symbiote\Interactives\Extension\InteractiveLocationExtension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\View\Requirements;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Symbiote\Interactives\Control\InteractiveAdmin;

/**
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveCampaign extends DataObject
{
    private static $table_name = 'InteractiveCampaign';

    private static $db = [
        'Title' => 'Varchar',
        'Begins' => 'DBDatetime',
        'Expires' => 'DBDatetime',
        'ResetStats' => 'Boolean',
        'DisplayType' => 'Varchar(64)',
        'TrackIn' => 'Varchar(64)',
        'AllowedHosts' => 'MultiValueField',
        'IsPublic'     => 'Boolean',
    ];

    private static $has_many = [
        'Interactives' => Interactive::class,
    ];

    private static $many_many = [
        'Editors' => Group::class,
    ];

    private static $has_one = [
        'Client' => InteractiveClient::class,
    ];

    private static $extensions = [
        InteractiveLocationExtension::class,
        Versioned::class
    ];

    private static $defaults = [
        'IsPublic' => 1,
    ];

    private static $datetimeFormat = 'Y-m-d H:i:00';

    public function populateDefaults()
    {
        // begins
        if ($begins = self::config()->Begins) {
            $this->Begins = date(static::$datetimeFormat, strtotime($begins));
        } else {
            $this->Begins = date(static::$datetimeFormat, strtotime('now'));
        }
        // expires
        if ($expires = self::config()->Expires) {
            $this->Expires = date(static::$datetimeFormat, strtotime($expires));
        } else {
            // end of 30 days from now
            $this->Expires = date(static::$datetimeFormat, strtotime('midnight + 31 days - 1 minute'));
        }
        // allowed hosts
        if ($allowed_hosts = self::config()->AllowedHosts) {
            $vals = $this->AllowedHosts ? $this->AllowedHosts->getValue() : [];
            $vals = $vals ? $vals : [];
            if (is_array($allowed_hosts)) {
                foreach ($allowed_hosts as $host) {
                    if (!array_search($host, $vals)) {
                        $vals[] = $host;
                    }
                }
            } elseif (is_string($allowed_hosts) && !array_search($allowed_hosts, $vals)) {
                $vals[] = $allowed_hosts;
            }
            $this->AllowedHosts->setValue($vals);
        }
        parent::populateDefaults();
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $reset = $fields->dataFieldByName('ResetStats');
        $fields->addFieldToTab('Root.Interactives', $reset);

        // advanced dropdown
        $advanced = new ToggleCompositeField('Advanced', 'Advanced', []);
        $advanced->setStartClosed(true);

        $fields->addFieldToTab('Root.Main', $advanced);

        // display type
        $fields->removeByName('DisplayType');
        $options = ['all' => 'All', 'random' => 'Always Random', 'stickyrandom' => 'Sticky Random'];
        $displayType = DropdownField::create('DisplayType', 'Use items as', $options);
        $displayType->setRightTitle("Should an item of this list be displayed, or all of them at once?" .
            "A 'Sticky'item is randomly chosen, but then always shown to the same user");
        $advanced->push($displayType);

        // track in
        $fields->removeByName('TrackIn');
        $options = ['' => 'None', 'Local' => "Locally", 'Google' => 'Google events', 'Gtm' => 'Tag Manager'];
        $trackIn = DropdownField::create('TrackIn', 'Track interactions in', $options);
        $advanced->push($trackIn);

        // client
        $fields->removeByName('ClientID');
        $client = DropdownField::create('ClientID', 'Client', InteractiveClient::get()->map('ID', 'Title'));
        $advanced->push($client);

        $f = $fields->dataFieldByName('IsPublic');
        $fields->removeByName('IsPublic');
        $advanced->push($f);

        /** @var GridField */
        $grid = $fields->dataFieldByName('Editors');
        if ($grid) {
            $grid->getConfig()->removeComponentsByType(GridFieldAddNewButton::class);
        }


        return $fields;
    }

    public function canView($member = null)
    {
        return $this->IsPublic || parent::canView($member);
    }

    public function canEdit($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        if (!$member->ID) {
            return false;
        }

        $editors = $this->Editors()->filter('Members.ID', $member->ID);
        $editor = Permission::check('CMS_ACCESS_' . InteractiveAdmin::class) && $editors->count() > 0;
        return $editor || parent::canEdit($member);
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

        $inclusionRules = $this->rulesForJson();

        $me = array(
            'interactives' => $interactives,
            'display' => $this->DisplayType,
            'id' => $this->ID,
            'trackIn' => $this->TrackIn,
            'siteWide' => $this->SiteWide,
            'include' => $inclusionRules['include'],
            'exclude' => $inclusionRules['exclude'],
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
        if (!$this->viewableNow()) {
            return [];
        }

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
     * Is this campaign active now? Checks start / expires dates
     */
    public function viewableNow()
    {
        $start = 0;
        $end = PHP_INT_MAX;
        $now = time();

        if ($this->Begins) {
            $start = strtotime($this->Begins);
        }
        if ($this->Expires) {
            $end = strtotime($this->Expires);
        }

        return $start <= $now && $end >= $now;
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
