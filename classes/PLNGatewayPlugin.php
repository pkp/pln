<?php

/**
 * @file classes/PLNGatewayPlugin.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNGatewayPlugin
 *
 * @brief PLNGatewayPlugin component of web PLN plugin
 */

namespace APP\plugins\generic\pln\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\pln\PlnPlugin;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\core\ArrayItemIterator;
use PKP\plugins\GatewayPlugin;
use PKP\plugins\PluginRegistry;
use PKP\site\VersionCheck;

class PLNGatewayPlugin extends GatewayPlugin
{
    private const PING_ARTICLE_COUNT = 10;

    /**
     * Constructor.
     */
    public function __construct(private string $parentPluginName)
    {
        parent::__construct();
    }

    /**
     * @copydoc Plugin::getHideManagement()
     */
    public function getHideManagement(): bool
    {
        return true;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return substr(static::class, strlen(__NAMESPACE__) + 1);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.plngateway.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.plngateway.description');
    }

    /**
     * Get the plugin
     */
    public function getPlugin(): PlnPlugin
    {
        /** @var PlnPlugin */
        $plugin = PluginRegistry::getPlugin('generic', $this->parentPluginName);
        return $plugin;
    }

    /**
     * Override the builtin to get the correct plugin path.
     */
    public function getPluginPath(): string
    {
        $plugin = $this->getPlugin();
        return $plugin->getPluginPath();
    }

    /**
     * Override the builtin to get the correct template path.
     */
    public function getTemplatePath($inCore = false): string
    {
        $plugin = $this->getPlugin();
        return $plugin->getTemplatePath($inCore);
    }

    /**
     * @copydoc Plugin::getEnabled()
     */
    public function getEnabled()
    {
        return $this->getPlugin()->getEnabled(); // Should always be true anyway if this is loaded
    }

    /**
     * @copydoc GatewayPlugin::fetch()
     */
    public function fetch($args, $request): bool
    {
        $plugin = $this->getPlugin();
        $templateMgr = TemplateManager::getManager($request);
        $journal = $request->getJournal();

        $pluginVersionFile = $this->getPluginPath() . '/version.xml';
        $pluginVersion = VersionCheck::parseVersionXml($pluginVersionFile);
        $templateMgr->assign('pluginVersion', $pluginVersion);

        $terms = [];
        $termsAccepted = $plugin->termsAgreed($journal->getId());
        if ($termsAccepted) {
            $terms = $plugin->getSetting($journal->getId(), 'terms_of_use');
            $termsAgreement = $plugin->getSetting($journal->getId(), 'terms_of_use_agreement');
        }

        $termKeys = array_keys($terms);
        $termsDisplay = [];
        foreach ($termKeys as $key) {
            $termsDisplay[] = [
                'key' => $key,
                'term' => $terms[$key]['term'],
                'updated' => $terms[$key]['updated'],
                'accepted' => $termsAgreement[$key]
            ];
        }

        $publications = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$journal->getId()])
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->limit(static::PING_ARTICLE_COUNT)
            ->getMany()
            ->map(fn (Submission $submission) => $submission->getCurrentPublication())
            ->toArray();

        $templateMgr->assign([
            'termsAccepted' => $termsAccepted ? 'yes' : 'no',
            'phpVersion' => PHP_VERSION,
            'hasZipArchive' => $plugin->hasZipArchive() ? 'Yes' : 'No',
            'hasTasks' => $plugin->hasScheduledTasks() ? 'Yes' : 'No',
            'termsDisplay' => new ArrayItemIterator($termsDisplay),
            'ojsVersion' => Application::get()->getCurrentVersion()->getVersionString(),
            'publications' => $publications,
            'pln_network' => $plugin->getSetting($journal->getId(), 'pln_network')
        ]);

        header('content-type: text/xml; charset=utf-8');
        $templateMgr->display($plugin->getTemplateResource('handshake.tpl'));
        return true;
    }
}
