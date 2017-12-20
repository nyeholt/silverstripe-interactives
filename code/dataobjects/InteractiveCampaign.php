<?php

/**
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveCampaign extends DataObject {
	private static $db = array(
		'Title'				=> 'Varchar',
        'Begins'            => 'Date',
		'Expires'			=> 'Date',
        'ResetStats'        => 'Boolean',
        'DisplayType'       => 'Varchar(64)',
        'TrackIn'           => 'Varchar(64)',
	);

	private static $has_many = array(
		'Interactives'		=> 'Interactive',
	);

	private static $has_one = array(
		'Client'			=> 'InteractiveClient',
	);

    private static $extensions = array(
        'InteractiveLocationExtension',
        'Heyday\\VersionedDataObjects\\VersionedDataObject',
    );

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $reset = $fields->dataFieldByName('ResetStats');
        $fields->addFieldToTab('Root.Interactives', $reset);

        $options = array(
            'random' => 'Always Random',
            'stickyrandom'  => 'Sticky Random',
            'all' => 'All',
        );

        $fields->replaceField('DisplayType', $df = DropdownField::create('DisplayType', 'Use items as', $options));
        $df->setRightTitle("Should one random item of this list be displayed, or all of them at once? A 'Sticky' item is randomly chosen, but then always shown to the same user");

        $grid = $fields->dataFieldByName('Interactives');
        if ($grid) {
            $config = $grid->getConfig();
            $config->removeComponentsByType('GridFieldDetailForm');
            $config->addComponent(new Heyday\VersionedDataObjects\VersionedDataObjectDetailsForm());
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
                $table = ClassInfo::baseDataClass('InteractiveImpression');
                $query = new SQLDelete($table, ['InteractiveID' => $interactive->ID]);
                $query->execute();
            }
        }

        $this->ResetStats = false;
    }

    /**
     * Collect a list of interactives that are relevant for the passed in URL
     * and viewed page
     *
     * @param string $url
     * @param SiteTree $page
     */
    public function relevantInteractives($url, $page = null) {
        $items = [];
        foreach ($this->Interactives() as $ad) {
            if (!$ad->viewableOn($url, $page ? $page->class : null)) {
                continue;
            }

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
    public function viewableOn($url, $pageType = null) {
        $start = 0; $end = strtotime('2038-01-01');
        if ($this->Begins) {
            $start = strtotime(date('Y-m-d 00:00:00', strtotime($this->Begins)));
        }
        if ($this->Expires) {
            $end =  strtotime(date('Y-m-d 23:59:59', strtotime($this->Expires)));
        }

        return $start < time() && $end > time();
    }

	public function getRandomAd() {
		$number = $this->Interactives()->count();
		if ($number) {
			--$number;
			$rand = mt_rand(0, $number);
			$items = $this->Interactives()->toArray();
			return $items[$rand];
		}
	}
}
