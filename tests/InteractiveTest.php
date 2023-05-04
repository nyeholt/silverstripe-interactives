<?php

namespace Symbiote\Interactives\Tests;

use SilverStripe\Dev\SapphireTest;
use Symbiote\Interactives\Model\Interactive;
use Symbiote\Interactives\Model\InteractiveCampaign;


class InteractiveTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testCreateInteractiveCampaign()
    {
        $this->logInWithPermission();

        $ic = new InteractiveCampaign();
        $ic->Title = 'Campaign 1';
        $ic->Begins = date('Y-m-d H:i:00', strtotime('2021-07-19 15:16:00'));
        $ic->Expires = date('Y-m-d H:i:00', strtotime('2021-07-19 16:16:00'));
        $ic->Content = 'Campaign 1';

        $ic->write();

        $out = InteractiveCampaign::get()->first();

        $this->assertEquals($out->Title, 'Campaign 1');
    }

    public function testCreateInteractive()
    {
        $this->logInWithPermission();

        $i = new Interactive();
        $i->Title = 'Interactive 1';
        $i->HTMLContent = 'Test interactive item';
        $i->write();
        $out = Interactive::get()->first();
        $this->assertEquals($out->HTMLContent, 'Test interactive item');
    }
}
