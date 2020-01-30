<?php

namespace Symbiote\Interactives\Model;

use ArrayObject;
use SilverStripe\Forms\TreeDropdownField;

use Symbiote\Interactives\Model\InteractiveCampaign;
use SilverStripe\Assets\Image;
use Symbiote\Interactives\Extension\InteractiveLocationExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Security\Permission;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Convert;
use SilverStripe\View\Requirements;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;

/**
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class Interactive extends DataObject {

	private static $use_js_tracking = false;

    private static $table_name = 'Interactive';

	private static $db = array(
		'Title'				=> 'Varchar',
		'TargetURL'			=> 'Varchar(255)',
        'NewWindow'         => 'Boolean',
        'HTMLContent'       => 'HTMLText',
        'Label'             => 'Varchar(64)',
        'PostInteractionContent' => 'HTMLText',
        'SubsequentContent' => 'HTMLText',

        'Element'           => 'Varchar(64)',           // within which containing element will it display?
        'Location'          => 'Varchar(64)',           // where in its container element?
        'Frequency'         => 'Int',                   // how often? 1 in X number of users see this
        'Delay'             => 'Int',                   // how long until it displays?
        'Transition'        => 'Varchar(64)',           // how does it appear?
        'HideAfterInteraction'  => 'Boolean',           // should the item not appear if someone has interacted with it?
        'TrackViews'        => 'Varchar(16)',

        'CompletionElement'   => 'Varchar(64)',         // what element needs clicking to be considered a 'complete' event

	);

	private static $has_one = array(
		'InternalPage'		=> 'Page',
		'Campaign'			=> InteractiveCampaign::class,
		'Image'				=> Image::class,
    );

    private static $owns = [
        'Image',
    ];

    private static $extensions = array(
        InteractiveLocationExtension::class,
        Versioned::class
    );

    private static $tracker_type = 'Local';

    private static $summary_fields = array('Title', 'Clicks', 'Impressions', 'Completes');

    public function getTypeLabel() {
        return "Viewed item";
    }

	public function getCMSFields() {
        $fields = new FieldList();

        $classes = ClassInfo::subclassesFor(self::class);
        $types = [];
        foreach ($classes as $cls) {
            $types[$cls] = singleton($cls)->getTypeLabel();
        }

        $locations = ['prepend' => 'Top', 'append' => 'Bottom', 'before' => 'Before', 'after' => 'After', 'html' => 'Replace content', 'existing' => 'Existing content'];
        $transitions = ['show' => 'Immediate', 'fadeIn' => 'Fade In', 'slideDown' => 'Slide Down'];

		$fields->push(new TabSet('Root', new Tab('Main',
            new TextField('Title', 'Title'),
            DropdownField::create('ClassName', 'Type', $types)->setRightTitle('Type of interactive'),
			TextField::create('TargetURL', 'Target URL')->setRightTitle('Or select a page below. NOTE: This will replace any links in the interactive\'s content! Leave both blank to use source links'),
            CheckboxField::create('NewWindow', 'Open generated links in a new window'),
            new Treedropdownfield('InternalPageID', 'Internal Page Link', 'Page'),
            TextField::create('Element', 'Relative Element')->setRightTitle('CSS selector for element to appear with'),
            DropdownField::create('Location', 'Location in / near element', $locations)->setRightTitle('"Use existing" to bind to existing content'),
            TextField::create('Label', 'Label')->setRightTitle('A label to give the click event, if relevant'),
            NumericField::create('Frequency', 'Display frequency')->setRightTitle('1 in N number of people will see this'),
            NumericField::create('Delay', 'Delay display (milliseconds)'),
            DropdownField::create('Transition', 'What display effect should be used?', $transitions),
            TextField::create('CompletionElement', 'Completion Element(s)')
                ->setRightTitle('CSS selector for element(s) that are considered the "completion" clicks'),

            CheckboxField::create('HideAfterInteraction'),
            DropdownField::create('CampaignID', 'Campaign', InteractiveCampaign::get())->setEmptyString('--none--')
		)));

        if (Permission::check('ADMIN')) {
            $fields->addFieldToTab(
                'Root.Main',
                DropdownField::create('TrackViews', 'Should views be tracked?', array('yes' => 'Yes', 'no' => 'No'))->setEmptyString('Default'),
                'CampaignID'
            );
        }

		if ($this->ID) {
			$impressions = $this->getImpressions();
			$clicks = $this->getClicks();

			$fields->addFieldToTab('Root.Main', new ReadonlyField('Impressions', 'Impressions', $impressions), 'Title');
			$fields->addFieldToTab('Root.Main', new ReadonlyField('Clicks', 'Clicks', $clicks), 'Title');

            $contentHelp = 'Any link in this content will trigger a tracking event. Select "Existing content" as the Location field to '.
                'bind to items contained in the named element instead of entering content here';

            $fields->addFieldsToTab('Root.Content', array(
                LiteralField::create('ContentHelp', _t('Interactives.CONTENT_HELP', $contentHelp)),
                new UploadField('Image'),
                new TextareaField('HTMLContent'),
                new TextareaField('PostInteractionContent', 'Content shown immediately post interaction'),
                new TextareaField('SubsequentContent', 'Content shown ongoing if user has interacted')
            ));
		}

        $this->extend('updateCMSFields', $fields);

		return $fields;
	}

	public function getImpressions() {
		$stats = $this->getCollatedStatistics();
        return isset($stats['View']) ? $stats['View'] : 0;
	}

	public function getClicks() {
		$stats = $this->getCollatedStatistics();
        return isset($stats['Click']) ? $stats['Click'] : 0;
	}

    public function getCompletes() {
        $stats = $this->getCollatedStatistics();
        return isset($stats['Complete']) ? $stats['Complete'] : 0;
    }

    protected $stats;

    /**
     * Get a list of statistics about how this interactive has been viewed and interacted with
     *
     * @param mixed $timeframe
     * @return array
     */
    protected function getCollatedStatistics($timeframe = null) {
        if ($this->stats) {
            return $this->stats;
        }

        $stats = array(

        );

        $mappedStats = InteractiveImpression::get()->filter(array(
            'InteractiveID' => $this->ID
        ))->map('ID', 'Interaction');

        foreach ($mappedStats as $id => $type) {
            $current = isset($stats[$type]) ? $stats[$type] : 0;
            $current += 1;
            $stats[$type] = $current;
        }

        $this->stats = $stats;
        return $stats;
    }

	public function forTemplate($width = null, $height = null) {
		$inner = Convert::raw2xml($this->Title);
		if ($this->ImageID && $this->Image()->ID) {
			if ($width) {
                $converted = $this->Image()->SetRatioSize($width, $height);
                if ($converted) {
                    $inner = $converted->forTemplate();
                }

			} else {
                $inner = $this->Image()->forTemplate();
			}
		}

		$class = '';
		if (self::config()->use_js_tracking) {
			$class = 'class="intlink" ';
		}

        $target = $this->NewWindow ? ' target="_blank"' : '';

		$tag = '<a ' . $class . $target .' href="'.$this->Link().'" data-intid="'.$this->ID.'">'.$inner.'</a>';

		return $tag;
	}

    /**
     * Convert this interactive to a format for display
     *
     * @return array
     */
    public function forDisplay() {
        $content = strlen($this->HTMLContent) ? $this->HTMLContent : $this->forTemplate();

        $inclusionRules = $this->rulesForJson();

        $data = array(
            'ID'    => $this->ID,
            'Content'   => $content,
            'Element' => $this->Element,
            'Label' => $this->Label,
            'Location'  => $this->Location,
            'Transition'    => $this->Transition,
            'Frequency' => $this->Frequency,
            'Delay'   => $this->Delay,
            'HideAfterInteraction'  => $this->HideAfterInteraction,
            'PostInteractionContent' => $this->PostInteractionContent,
            'SubsequentContent' => $this->SubsequentContent,
            'CompletionElement'       => $this->CompletionElement,
            'TrackViews'    => strlen($this->TrackViews) ? $this->TrackViews == 'yes' : self::config()->view_tracking,
            'siteWide' => $this->SiteWide,
            'include' => $inclusionRules['include'],
            'exclude' => $inclusionRules['exclude'],
        );

        $target = $this->Link();
        if (strlen($target)) {
            $data['TargetLink'] = $target;
        }

        $update = new ArrayObject($data);
        $this->extend("updateInteractiveData", $update);
        return $update->getArrayCopy();
    }

	public function SetRatioSize($width, $height) {
		return $this->forTemplate($width, $height);
	}

	public function Link() {
        $link = Convert::raw2att($this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL);
        return $link;

		if (self::config()->use_js_tracking) {
			Requirements::javascript(THIRDPARTY_DIR.'/jquery/jquery.js');
			Requirements::javascript('advertisements/javascript/interactives.js');

			$link = Convert::raw2att($this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL);

		} else {
			$link = Controller::join_links(Director::baseURL(), 'int-act/go/'.$this->ID);
		}
		return $link;
	}

	public function getTarget() {
		return $this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL;
	}
}
