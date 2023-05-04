<?php

namespace Symbiote\Interactives\Control;

use Symbiote\Interactives\Model\Interactive;
use Symbiote\Interactives\Model\InteractiveImpression;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPRequest;

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD http://silverstripe.org/BSD-license
 */
class InteractiveController extends Controller
{

    private static $record_impressions = true;

    private static $allowed_events = array(
        'clk' => 'Click',
        'imp' => 'View',
        'int' => 'Interact',
        'cpl' => 'Complete',
    );


    private static $allowed_actions = array(
        'trk',
        'imp',
        'go',
        'clk',
    );

    private static $cors = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, x-requested-with',
        'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE',
    ];

    /**
     * @var  \Symbiote\Interactives\Service\InteractiveService
     */
    public $interactiveService;

    public function handleRequest(HTTPRequest $request)
    {
        if (strtolower($request->httpMethod()) === 'options') {
            $response = new HTTPResponse('');
            return $this->addCorsHeaders($response);
        }
        return $this->addCorsHeaders(parent::handleRequest($request));
    }

    protected function addCorsHeaders(HTTPResponse $response)
    {
        if (count($this->config()->cors)) {
            foreach ($this->config()->cors as $header => $val) {
                $response->addHeader($header, $val);
            }
        }
        return $response;
    }

    protected function requestedItem()
    {
        $item = $this->request->requestVar('itm');
        if (preg_match('/[a-zA-Z0-9_-]+,\d+/i', $item)) {
            return $item;
        }
    }

    public function trk()
    {
        return $this->interactiveService->track(
            $this->request->requestVar('evt'),
            $this->request->requestVar('ids'),
            $this->request->requestVar('sig'),
            $this->request->requestVar('itm'),
            $this->request->requestVar('lbl')
        );
    }

    public function imp()
    {
        if (!self::config()->record_impressions) {
            return;
        }
        return $this->interactiveService->track(
            'imp',
            $this->request->requestVar('ids'),
            $this->request->requestVar('sig'),
            $this->request->requestVar('itm')
        );
    }

    public function clk()
    {
        if ($this->request->requestVar('id')) {
            return $this->interactiveService->track(
                'clk',
                $this->request->requestVar('ids'),
                $this->request->requestVar('sig'),
                $this->request->requestVar('itm'),
                $this->request->requestVar('lbl')
            );
        }
    }

    public function go()
    {
        $id = (int)$this->request->param('ID');

        if ($id) {
            $ad = DataObject::get_by_id(Interactive::class, $id);
            if ($ad && $ad->exists()) {
                $this->interactiveService->track(
                    'clk',
                    $id,
                    $this->request->requestVar('sig'),
                    $this->request->requestVar('itm')
                );

                $this->redirect($ad->getTarget());
                return;
            }
        }
    }
}
