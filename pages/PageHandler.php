<?php

/**
 * @file pages/PageHandler.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PageHandler
 *
 * @brief Handle PLN requests
 */

namespace APP\plugins\generic\pln\pages;

use APP\core\Request;
use APP\handler\Handler;
use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\classes\DepositPackage;
use APP\plugins\generic\pln\PlnPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPString;
use PKP\file\FileManager;
use PKP\security\authorization\ContextRequiredPolicy;

class PageHandler extends Handler
{
    /**
     * @copydoc PKPHandler::index()
     */
    public function index($args, $request): void
    {
        $request->redirect(null, 'index');
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new ContextRequiredPolicy($request));

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Provide an endpoint for the PLN staging server to retrieve a deposit
     */
    public function deposits(array $args, Request $request): bool
    {
        $journal = $request->getJournal();
        $fileManager = new FileManager();
        $dispatcher = $request->getDispatcher();

        $depositUuid = $args[0] ?? '';

        // sanitize the input
        if (!preg_match('/^[[:xdigit:]]{8}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{4}-[[:xdigit:]]{12}$/', $depositUuid)) {
            error_log(__('plugins.generic.pln.error.handler.uuid.invalid'));
            $dispatcher->handle404();
        }

        $deposit = Repository::instance()
            ->getCollector()
            ->filterByContextIds([$journal->getId()])
            ->filterByUUIDs([$depositUuid])
            ->getMany()
            ->first();

        if (!$deposit) {
            error_log(__('plugins.generic.pln.error.handler.uuid.notfound'));
            $dispatcher->handle404();
        }

        $depositPackage = new DepositPackage($deposit, null);
        $depositBag = $depositPackage->getPackageFilePath();

        if (!$fileManager->fileExists($depositBag)) {
            error_log('plugins.generic.pln.error.handler.file.notfound');
            $dispatcher->handle404();
        }

        return $fileManager->downloadByPath($depositBag, PKPString::mime_content_type($depositBag), true);
    }

    /**
     * Display status of deposit(s)
     */
    public function status(array $args, Request $request): void
    {
        $router = $request->getRouter();
        $plugin = PlnPlugin::loadPlugin();
        $templateMgr = TemplateManager::getManager();
        $templateMgr->assign('pageHierarchy', [[$router->url($request, null, 'about'), 'about.aboutTheJournal']]);
        $templateMgr->display("{$plugin->getTemplatePath()}/status.tpl");
    }
}
