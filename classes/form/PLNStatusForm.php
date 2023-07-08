<?php

/**
 * @file classes/form/PLNStatusForm.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNStatusForm
 *
 * @brief Form for journal managers to check PLN plugin status
 */

namespace APP\plugins\generic\pln\classes\form;

use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\form\Form;
use PKP\handler\PKPHandler;

class PLNStatusForm extends Form
{
    /** @var int */
    public $_contextId;

    /** @var PLNPlugin Plugin */
    public $_plugin;

    /**
     * Constructor
     *
     * @param PLNPlugin $plugin
     * @param int $contextId
     */
    public function __construct($plugin, $contextId)
    {
        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('status.tpl'));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $context = $request->getContext();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        $networkStatus = $this->_plugin->getSetting($context->getId(), 'pln_accepting');
        $networkStatusMessage = $this->_plugin->getSetting($context->getId(), 'pln_accepting_message');
        $rangeInfo = PKPHandler::getRangeInfo($request, 'deposits');

        if (!$networkStatusMessage) {
            if ($networkStatus === true) {
                $networkStatusMessage = __('plugins.generic.pln.notifications.pln_accepting');
            } else {
                $networkStatusMessage = __('plugins.generic.pln.notifications.pln_not_accepting');
            }
        }
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'networkStatus' => $networkStatus,
            'networkStatusMessage' => $networkStatusMessage
        ]);

        return parent::fetch($request, $template, $display);
    }
}
