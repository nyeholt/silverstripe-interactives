<?php

namespace Symbiote\Interactives\Extension;

use DNADesign\Elemental\Models\BaseElement;
use SilverShop\HasOneField\HasOneButtonField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;

class InteractiveElementalExtension extends DataExtension
{
    private static $has_one = [
        'ContentElement' => BaseElement::class
    ];

    private static $owns = [
        'ContentElement',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        if ($this->owner->ID) {
            $hasone = HasOneButtonField::create($this->owner, 'ContentElement');
            $fields->addFieldToTab('Root.Content', $hasone);

            $fields->addFieldToTab("Root.Content", LiteralField::create('ElementInstruction', "If an element is chosen, you <b>must</b> add the \$Element keyword above to HTMLContent for it to be displayed." .
                "When adding a new element, ensure you select the type on the 'Settings' tab"));

            $content = $fields->dataFieldByName('HTMLContent');
            if ($content) {
                $content->setRightTitle("If an element is chosen below, you must add the \$Element keyword above for it to be displayed");
            }
        }
    }

    public function updateInteractiveData($data)
    {
        /** @var \Symbiote\Interactives\Model\Interactive $owner  */
        $owner = $this->owner;

        if ($owner->ContentElementID && isset($data['Content']) && strpos($data['Content'], '$Element') !== false) {
            $element = $owner->ContentElement();
            $controller = $element->getController();
            if ($controller) {
                $content = $controller->forTemplate();

                $data['Content'] = str_replace('$Element', $content, $data['Content']);
            }
        }
    }
}
