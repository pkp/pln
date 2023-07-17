<?php

/**
 * @file classes/form/SettingsForm.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SettingsForm
 *
 * @brief Form for journal managers to modify PLN plugin settings
 */

namespace APP\plugins\generic\pln\classes\form;

use APP\plugins\generic\pln\PLNPlugin;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\form\Form;
use PKP\plugins\PluginSettingsDAO;

class SettingsForm extends Form
{
    /**
     * Constructor
     */
    public function __construct(private PLNPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
    }

    /**
     * @copydoc Form::initData
     */
    public function initData(): void
    {
        $contextId = $this->contextId;
        if (!$this->plugin->getSetting($contextId, 'terms_of_use')) {
            $this->plugin->getServiceDocument($contextId);
        }
        $this->setData('terms_of_use', $this->plugin->getSetting($contextId, 'terms_of_use'));
        $this->setData('terms_of_use_agreement', $this->plugin->getSetting($contextId, 'terms_of_use_agreement'));
    }

    /**
     * @copydoc Form::readInputData
     */
    public function readInputData(): void
    {
        $this->readUserVars(['terms_agreed']);

        $termsAgreed = $this->getData('terms_of_use_agreement');
        if (!$this->getData('terms_agreed')) {
            return;
        }

        foreach (array_keys($this->getData('terms_agreed')) as $termAgreed) {
            $termsAgreed[$termAgreed] = gmdate('c');
        }
        $this->setData('terms_of_use_agreement', $termsAgreed);
    }

    /**
     * Check for the prerequisites for the plugin, and return a translated message for each missing requirement.
     *
     * @return string[]
     */
    private function checkPrerequisites(): array
    {
        $messages = [];

        if (!$this->plugin->hasZipArchive()) {
            $messages[] = __('plugins.generic.pln.notifications.zip_missing');
        }
        if (!$this->plugin->hasScheduledTasks()) {
            $messages[] = __('plugins.generic.pln.settings.acron_required');
        }
        return $messages;
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $context = $request->getContext();
        $issn = $context->getSetting('onlineIssn') ?: $context->getSetting('printIssn');
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'hasIssn' => strlen($issn),
            'prerequisitesMissing' => $this->checkPrerequisites(),
            'journal_uuid' => $this->plugin->getSetting($this->contextId, 'journal_uuid'),
            'terms_of_use' => $this->plugin->getSetting($this->contextId, 'terms_of_use'),
            'terms_of_use_agreement' => $this->getData('terms_of_use_agreement'),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        parent::execute(...$functionArgs);
        $this->plugin->updateSetting($this->contextId, 'terms_of_use_agreement', $this->getData('terms_of_use_agreement'), 'object');

        /** @var PluginSettingsDAO */
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
        $pluginSettingsDao->installSettings($this->contextId, $this->plugin->getName(), $this->plugin->getContextSpecificPluginSettingsFile());
    }
}
