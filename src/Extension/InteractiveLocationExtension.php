<?php

namespace Symbiote\Interactives\Extension;

use Symbiote\MultiValueField\Fields\MultiValueTextField;
use Symbiote\MultiValueField\Fields\MultiValueDropdownField;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\TreeMultiselectField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\DataExtension;

/**
 * Defines where in a site an interactive / campaign will appear
 *
 * @author marcus
 */
class InteractiveLocationExtension extends DataExtension
{
    private static $db = array(
        'SiteWide'          => 'Boolean',
        'IncludeUrls'       => 'MultiValueField',
        'IncludeTypes'      => 'MultiValueField',
        'IncludeCssMatch'   => 'MultiValueField',
        'ExcludeUrls'       => 'MultiValueField',
        'ExcludeTypes'      => 'MultiValueField',
        'ExcludeCssMatch'   => 'MultiValueField',
    );

    private static $many_many = array(
        'OnPages'       => SiteTree::class,
    );

    private static $defaults = [
        'SiteWide' => true,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName('SiteWide');
        $fields->removeByName('OnPages');
        $fields->removeByName('ExcludeTypes');
        $fields->removeByName('ExcludeUrls');
        $fields->removeByName('ExcludeCssMatch');

        $fields->removeByName('IncludeTypes');
        $fields->removeByName('IncludeUrls');
        $fields->removeByName('IncludeCssMatch');

        $classes = SiteTree::page_type_classes();
        $classes = array_combine($classes, $classes);
        $fields->addFieldsToTab('Root.SiteOptions', [
            CheckboxField::create('SiteWide', 'All pages in site')
                ->setRightTitle('Uncheck if using include rules; exclude rules overrule this setting'),
            TreeMultiselectField::create('OnPages', 'Display on pages', 'Page'),
            ToggleCompositeField::create('InclusionRules', 'Including', [
                MultiValueTextField::create('IncludeUrls', 'Include URLs that match'),
                MultiValueTextField::create('IncludeCssMatch', 'Include on pages matching these CSS rules'),
                MultiValueDropdownField::create('IncludeTypes', 'Include page types', $classes)
            ]),
            ToggleCompositeField::create('ExclusionRules', 'Excluding', [
                MultiValueTextField::create('ExcludeUrls', 'Exluding URLs that match'),
                MultiValueTextField::create('ExcludeCssMatch', 'Exclude on pages matching these CSS rules'),
                MultiValueDropdownField::create('ExcludeTypes', 'Excluding page types', $classes)
            ]),
        ]);
    }

    /**
     * Bundles the rules into a structure that can be bound into JSON
     * for page inclusion
     */
    public function rulesForJson()
    {
        $includeUrls = $this->owner->IncludeUrls->getValues();
        $includeCss = $this->owner->IncludeCssMatch->getValues();
        $excludeUrls = $this->owner->ExcludeUrls->getValues();
        $excludeCss = $this->owner->ExcludeCssMatch->getValues();

        return [
            'include' => [
                'urls' => $includeUrls ? $includeUrls : [],
                'css' => $includeCss ? $includeCss : [],
            ],
            'exclude' => [
                'urls' => $excludeUrls ? $excludeUrls : [],
                'css' => $excludeCss ? $excludeCss : [],
            ],
        ];
    }


    /**
     * Can this interactive be viewed on the given URL ?
     *
     * @param string $url
     */
    public function viewableOn($url, $pageType = null)
    {
        // check inclusion rules first

        $excludeUrls = $this->owner->ExcludeUrls->getValues();

        if ($excludeUrls && count($excludeUrls)) {
            foreach ($excludeUrls as $urlPattern) {
                if (preg_match("{" . $urlPattern . "}", $url)) {
                    return false;
                }
            }
        }

        $excludeTypes = $this->owner->ExcludeTypes->getValues();

        if ($pageType && $excludeTypes && count($excludeTypes) && in_array($pageType, $excludeTypes)) {
            return false;
        }

        // if we've got include rules, we need to confirm it matches those
        $includeUrls = $this->owner->IncludeUrls->getValues();

        if ($includeUrls && count($includeUrls)) {
            $match = false;
            foreach ($includeUrls as $urlPattern) {
                if (preg_match("{" . $urlPattern . "}", $url)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        }

        $includeTypes = $this->owner->IncludeTypes->getValues();

        if ($pageType && $includeTypes && count($includeTypes) && !in_array($pageType, $excludeTypes)) {
            return false;
        }

        return true;
    }
}
