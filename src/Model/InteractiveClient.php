<?php

namespace Symbiote\Interactives\Model;

use SilverStripe\ORM\DataObject;

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveClient extends DataObject {
	private static $db = array(
		'Title'				=> 'Varchar(128)',
		'ContactEmail'		=> 'Text',
	);
}
