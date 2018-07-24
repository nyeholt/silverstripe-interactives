<?php

namespace Symbiote\Interactives\Control;

use Symbiote\Interactives\Model\InteractiveCampaign;
use Symbiote\Interactives\Model\InteractiveClient;
use SilverStripe\Admin\ModelAdmin;

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveAdmin extends ModelAdmin {
	private static $managed_models = array(
//		'Interactive',
		InteractiveCampaign::class,
		InteractiveClient::class,
    );

    private static $menu_icon_class = 'font-icon-box';

	private static $url_segment = 'interactives';
	private static $menu_title = 'Interactives';
}
