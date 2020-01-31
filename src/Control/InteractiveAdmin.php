<?php

namespace Symbiote\Interactives\Control;

use Symbiote\Interactives\Model\InteractiveCampaign;
use Symbiote\Interactives\Model\InteractiveClient;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\View\Requirements;
use SilverStripe\View\ThemeResourceLoader;

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveAdmin extends ModelAdmin {
	private static $managed_models = [
		// 'Interactive',
		InteractiveCampaign::class,
		InteractiveClient::class,
	];

    private static $menu_icon_class = 'font-icon-box';
	private static $url_segment = 'interactives';
	private static $menu_title = 'Interactives';

    protected function init()
    {
        parent::init();
        Requirements::css('nyeholt/silverstripe-interactives:client/css/admin.css');
    }
}
