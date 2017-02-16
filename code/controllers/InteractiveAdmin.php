<?php

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveAdmin extends VersionedModelAdmin {
	private static $managed_models = array(
//		'Interactive',
		'InteractiveCampaign',
		'InteractiveClient',
	);

	private static $url_segment = 'interactives';
	private static $menu_title = 'Interactives';
}