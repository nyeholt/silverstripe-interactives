<?php

namespace Symbiote\Interactives\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\RandomGenerator;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveClient extends DataObject
{
    private static $table_name = 'InteractiveClient';

    private static $db = array(
        'Title' => 'Varchar(128)',
        'ContactEmail' => 'Varchar(128)',
        'ClientUuid' => 'Varchar(64)',
        'Salt' => 'Varchar(64)',
        'PublicKey' => 'Varchar(64)',
        'ApiKey' => 'Varchar(64)',
        'RegenerateKeys' => 'Boolean',
    );


    private static $indexes = [
        'ClientUuid' => 'true',
    ];

    private static $has_many = [
        'Campaigns' => InteractiveCampaign::class,
    ];

    private static $extensions = [
        Versioned::class
    ];


    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->ClientUuid) {
            $this->ClientUuid = $this->generateUuid();
        }

        if (!$this->ApiKey || $this->RegenerateKeys) {
            $this->RegenerateKeys = false;
            $this->PublicKey = bin2hex(random_bytes(64));
            $details = Security::encrypt_password($this->PublicKey);
            $this->ApiKey = $details['password'];
            $this->Salt = $details['salt'];
        }
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->makeFieldReadonly('ClientUuid');
        $fields->makeFieldReadonly('ApiKey');
        $fields->makeFieldReadonly('PublicKey');
        $fields->removeByName('Salt');
        return $fields;
    }

    public function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        // 32 bits for "time_low"
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),

        // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

        // 48 bits for "node"
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
