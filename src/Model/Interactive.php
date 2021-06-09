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
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\Connect\PDOQuery;
use Symbiote\Interactives\Control\InteractiveAdmin;

/**
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class Interactive extends DataObject
{

    private static $use_js_tracking = false;

    private static $table_name = 'Interactive';

    private static $db = [
        'Title'                => 'Varchar',
        'TargetURL'            => 'Varchar(255)',
        'NewWindow'         => 'Boolean',
        'HTMLContent'       => 'HTMLText',
        'Label'             => 'Varchar(64)',
        'PostInteractionContent' => 'HTMLText',
        'SubsequentContent' => 'HTMLText',

        'Preset'            => 'Varchar(255)',             // 'friendly' way to map element class / position options
        // to configured sets

        'Element'           => 'Varchar(255)',           // within which containing element will it display?
        'Location'          => 'Varchar(64)',           // where in its container element?
        'Frequency'         => 'Int',                   // how often? 1 in X number of users see this
        'Delay'             => 'Int',                   // how long until it displays?
        'Transition'        => 'Varchar(64)',           // how does it appear?
        'HideAfterInteraction'  => 'Boolean',           // should the item not appear if someone has interacted with it?
        'TrackViews'        => 'Varchar(16)',

        'CompletionElement'   => 'Varchar(255)',         // what element needs clicking to be considered a 'complete' event
    ];

    private static $has_one = [
        'InternalPage'        => 'Page',
        'Campaign'            => InteractiveCampaign::class,
        'Image'                => Image::class,
    ];

    private static $owns = [
        'Image',
    ];

    private static $extensions = [
        InteractiveLocationExtension::class,
        Versioned::class
    ];

    private static $defaults = [
        'Frequency' => 1,
        'Delay' => 0,
    ];

    private static $interactive_presets = [];

    private static $tracker_type = '';

    private static $summary_fields = ['Title', 'Clicks', 'Impressions', 'Completes'];

    public function getTypeLabel()
    {
        return "Viewed item";
    }

    public function canView($member = null)
    {
        $p = $this->Campaign();
        if ($p && $p->ID) {
            return $p->canView($member);
        }
        return $this->ID === 0 ? true : parent::canView($member);
    }

    public function canCreate($member = null, $context = array())
    {
        if ($context && isset($context['Parent']) && $context['Parent']->ID) {
            return $context['Parent']->canEdit($member);
        }

        $createNew = $this->ID == 0 && Permission::check('CMS_ACCESS_' . InteractiveAdmin::class);
        return $createNew || parent::canCreate($member);
    }

    public function canDelete($member = null)
    {
        $p = $this->Campaign();
        if ($p && $p->ID) {
            return $p->canEdit($member);
        }
        return parent::canDelete($member);
    }

    public function canEdit($member = null)
    {
        $p = $this->Campaign();
        if ($p && $p->ID) {
            return $p->canEdit($member);
        }
        return $this->ID == 0 && Permission::check('CMS_ACCESS_' . InteractiveAdmin::class) ? true : parent::canEdit($member);
    }

    public function getCMSFields()
    {
        $fields = new FieldList();

        // main tab
        $main =  new Tab('Main');
        $fields->push(new TabSet('Root', $main));

        // campaign id
        $main->push(DropdownField::create('CampaignID', 'Campaign', InteractiveCampaign::get())
            ->setEmptyString('--none--'));
        // metrics
        if ($this->ID) {
            $impressions = $this->getImpressions();
            $clicks = $this->getClicks();

            $fields->addFieldToTab('Root.Main', new ReadonlyField('Impressions', 'Impressions', $impressions), 'Title');
            $fields->addFieldToTab('Root.Main', new ReadonlyField('Clicks', 'Clicks', $clicks), 'Title');

            $contentHelp = 'Any link in this content will trigger a tracking event. Select "Existing content" as the Location field to ' .
                'bind to items contained in the named element instead of entering content here';

            $fields->addFieldsToTab('Root.Content', array(
                LiteralField::create('ContentHelp', _t('Interactives.CONTENT_HELP', $contentHelp)),
                new UploadField('Image'),
                new TextareaField('HTMLContent'),
                new TextareaField('PostInteractionContent', 'Content shown immediately post interaction'),
                new TextareaField('SubsequentContent', 'Content shown ongoing if user has interacted')
            ));
        }
        // title
        $main->push(new TextField('Title', 'Title'));

        $presets = array_keys(self::config()->interactive_presets);

        if (count($presets)) {
            $presets = array_combine($presets, $presets);
            $main->push(DropdownField::create('Preset', 'Preset configuration', $presets)->setEmptyString(""));
        }

        // hide after
        $main->push(CheckboxField::create('HideAfterInteraction'));
        // click label
        $main->push(TextField::create('Label', 'Label')
            ->setRightTitle('A label to give the click event, if relevant'));


        // advanced dropdown
        $advanced = new ToggleCompositeField('Advanced', 'Advanced', []);
        $advanced->setStartClosed(true);
        $fields->addFieldToTab('Root.Main', $advanced);

        // css target
        $advanced->push(TextField::create('Element', 'Relative Element')
            ->setRightTitle('CSS selector for element to appear with'));
        // positioning
        $locations = ['prepend' => 'Top', 'append' => 'Bottom', 'before' => 'Before', 'after' => 'After', 'html' => 'Replace content', 'existing' => 'Existing content'];
        $advanced->push(DropdownField::create('Location', 'Location in / near element', $locations)
            ->setRightTitle('"Existing content" to bind to existing content'));

        // type
        $classes = ClassInfo::subclassesFor(self::class);
        $types = [];
        foreach ($classes as $cls) {
            $types[$cls] = singleton($cls)->getTypeLabel();
        }
        $advanced->push(DropdownField::create('ClassName', 'Type', $types)->setRightTitle('Type of interactive'));
        // effect
        $transitions = ['show' => 'Immediate', 'fadeIn' => 'Fade In', 'slideDown' => 'Slide Down'];
        $advanced->push(DropdownField::create('Transition', 'What display effect should be used?', $transitions));
        // frequency
        $advanced->push(NumericField::create('Frequency', 'Display frequency')->setRightTitle('1 in N number of people will see this'));
        // delay
        $advanced->push(NumericField::create('Delay', 'Delay display (milliseconds)'));
        // target url
        $advanced->push(
            TextField::create('TargetURL', 'Target URL')
                ->setRightTitle('Or select a page below. NOTE: This will replace any links in the interactive\'s content! Leave both blank to use source links')
        );
        // target page
        $advanced->push(new Treedropdownfield('InternalPageID', 'Internal Page Link', 'Page'));
        // new window
        $advanced->push(CheckboxField::create('NewWindow', 'Open generated links in a new window'));
        // completion
        $advanced->push(TextField::create('CompletionElement', 'Completion Element(s)')->setRightTitle('CSS selector for element(s) that are considered the "completion" clicks'));
        // tracking
        if (Permission::check('ADMIN')) {
            $view_tracking = self::config()->view_tracking;
            $track_conf = ($view_tracking && strlen($view_tracking) > 0) ? $view_tracking : 'no';
            $advanced->push(
                DropdownField::create('TrackViews', 'Should views be tracked?', ['yes' => 'Yes', 'no' => 'No'])
                    ->setEmptyString('Use config value (currently "' . $track_conf . '")')
            );
        }

        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if ($this->isChanged('Preset', self::CHANGE_VALUE)) {
            $presets = self::config()->interactive_presets;
            if (isset($presets[$this->Preset])) {
                $this->update($presets[$this->Preset]);
            }
        }
    }

    public function getImpressions()
    {
        $stats = $this->getCollatedStatistics();
        return isset($stats['View']) ? $stats['View'] : 0;
    }

    public function getClicks()
    {
        $stats = $this->getCollatedStatistics();
        return isset($stats['Click']) ? $stats['Click'] : 0;
    }

    public function getCompletes()
    {
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
    protected function getCollatedStatistics($timeframe = null)
    {
        if ($this->stats) {
            return $this->stats;
        }

        $stats = array();

        $statsQuery = InteractiveImpression::get()->dataQuery()->getFinalisedQuery(["ID", "Interaction"]);
        $statsQuery->setWhere('"InteractiveID" = ' . (int) $this->ID);
        $statsQuery->setSelect(array(
            'Interaction' => '"Interaction"'
        ));
        $statsQuery->addGroupBy("Interaction");
        $statsQuery->selectField("count(*) as Number");

        /**
         * @var PDOQuery
         */
        $result = $statsQuery->execute();

        if ($result) {
            foreach ($result as $row) {
                $stats[$row['Interaction']] = $row['Number'];
            }
            $this->stats = $stats;
        }

        return $stats;
    }

    public function forTemplate($width = null, $height = null)
    {
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

        $tag = '<a ' . $class . $target . ' href="' . $this->Link() . '" data-intid="' . $this->ID . '">' . $inner . '</a>';

        return $tag;
    }

    /**
     * Convert this interactive to a format for display
     *
     * @return array
     */
    public function forDisplay()
    {
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

    public function SetRatioSize($width, $height)
    {
        return $this->forTemplate($width, $height);
    }

    public function Link()
    {
        $link = Convert::raw2att($this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL);
        return $link;

        if (self::config()->use_js_tracking) {
            Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
            Requirements::javascript('advertisements/javascript/interactives.js');

            $link = Convert::raw2att($this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL);
        } else {
            $link = Controller::join_links(Director::baseURL(), 'int-act/go/' . $this->ID);
        }
        return $link;
    }

    public function getTarget()
    {
        return $this->InternalPageID ? $this->InternalPage()->AbsoluteLink() : $this->TargetURL;
    }
}
