<?php

/**
 * @file classes/form/StatusForm.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class StatusForm
 *
 * @brief Form for journal managers to check PLN plugin status
 */

namespace APP\plugins\generic\pln\classes\form;

use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\PlnPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\handler\PKPHandler;

class StatusForm extends Form
{
    /**
     * Constructor
     */
    public function __construct(private PlnPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('status.tpl'));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $context = $request->getContext();
        $networkStatus = $this->plugin->getSetting($context->getId(), 'pln_accepting');
        $networkStatusMessage = $this->plugin->getSetting($context->getId(), 'pln_accepting_message')
            ?: __($networkStatus ? 'plugins.generic.pln.notifications.pln_accepting' : 'plugins.generic.pln.notifications.pln_not_accepting');

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'networkStatus' => $networkStatus,
            'networkStatusMessage' => $networkStatusMessage
        ]);

        return parent::fetch($request, $template, $display);
    }
}
