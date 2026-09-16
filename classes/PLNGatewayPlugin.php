<?php

/**
 * @file classes/PLNGatewayPlugin.php
 *
 * Copyright (c) 2016 Simon Fraser University
 * Copyright (c) 2016 John Willinsky
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
     * Override the builtin to get the correct plugin path.
     */
    public function getPluginPath(): string
    {
        return PlnPlugin::loadPlugin()->getPluginPath();
    }

    /**
     * Override the builtin to get the correct template path.
     */
    public function getTemplatePath($inCore = false): string
    {
        return PlnPlugin::loadPlugin()->getTemplatePath($inCore);
    }

    /**
     * @copydoc Plugin::getEnabled()
     */
    public function getEnabled()
    {
        return PlnPlugin::loadPlugin()->getEnabled();
    }

    /**
     * @copydoc GatewayPlugin::fetch()
     */
    public function fetch($args, $request): bool
    {
        $plugin = PlnPlugin::loadPlugin();
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
            ->all();
        $templateMgr->assign([
            'termsAccepted' => $termsAccepted ? 'yes' : 'no',
            'phpVersion' => PHP_VERSION,
            'hasZipArchive' => $plugin->hasZipArchive() ? 'yes' : 'no',
            'termsDisplay' => new ArrayItemIterator($termsDisplay),
            'ojsVersion' => Application::get()->getCurrentVersion()->getVersionString(),
            'publications' => $publications,
            'networkUrl' => PlnPlugin::getNetworkUrl()
        ]);

        header('content-type: text/xml; charset=utf-8');
        $templateMgr->display($plugin->getTemplateResource('handshake.tpl'));
        return true;
    }
}
