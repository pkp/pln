<?php

/**
 * @file PLNPlugin.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNPlugin
 *
 * @brief PLN plugin class
 */

namespace APP\plugins\generic\pln;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\pln\classes\DepositDAO;
use APP\plugins\generic\pln\classes\DepositObjectDAO;
use APP\plugins\generic\pln\classes\form\SettingsForm;
use APP\plugins\generic\pln\classes\form\StatusForm;
use APP\plugins\generic\pln\controllers\grid\StatusGridHandler;
use APP\plugins\generic\pln\pages\PageHandler;
use APP\plugins\generic\pln\classes\migration\install\PLNPluginSchemaMigration;
use DOMDocument;
use DOMElement;
use Exception;
use GuzzleHttp\Exception\RequestException;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\PKPNotification;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\userGroup\UserGroup;
use SimpleXMLElement;

define('PLN_PLUGIN_NAME', 'plnplugin');

// defined here in case an upgrade doesn't pick up the default value.
define('PLN_DEFAULT_NETWORK', 'https://pkp-pn.lib.sfu.ca');

// base IRI for the SWORD server. IRIs are constructed by appending to
// this constant.
define('PLN_PLUGIN_BASE_IRI', '/api/sword/2.0');
// used to retrieve the service document
define('PLN_PLUGIN_SD_IRI', PLN_PLUGIN_BASE_IRI . '/sd-iri');
// used to submit a deposit
define('PLN_PLUGIN_COL_IRI', PLN_PLUGIN_BASE_IRI . '/col-iri');
// used to edit and query the state of a deposit
define('PLN_PLUGIN_CONT_IRI', PLN_PLUGIN_BASE_IRI . '/cont-iri');

define('PLN_PLUGIN_ARCHIVE_FOLDER', 'pln');

// local statuses
define('PLN_PLUGIN_DEPOSIT_STATUS_NEW', 0);
define('PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED', 1);
define('PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED', 2);

// status on the processing server
define('PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED', 4);
define('PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED', 8);
define('PLN_PLUGIN_DEPOSIT_STATUS_SENT', 16);

// status in the LOCKSS PLN
define('PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED', 64);
define('PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT', 128);

define('PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION', 'Submission');
define('PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE', 'Issue');

define('PLN_PLUGIN_NOTIFICATION_TYPE_PLUGIN_BASE', PKPNotification::NOTIFICATION_TYPE_PLUGIN_BASE + 0x10000000);
define('PLN_PLUGIN_NOTIFICATION_TYPE_TERMS_UPDATED', PLN_PLUGIN_NOTIFICATION_TYPE_PLUGIN_BASE + 1);
define('PLN_PLUGIN_NOTIFICATION_TYPE_ISSN_MISSING', PLN_PLUGIN_NOTIFICATION_TYPE_PLUGIN_BASE + 2);
define('PLN_PLUGIN_NOTIFICATION_TYPE_HTTP_ERROR', PLN_PLUGIN_NOTIFICATION_TYPE_PLUGIN_BASE + 3);
define('PLN_PLUGIN_NOTIFICATION_TYPE_ZIP_MISSING', PLN_PLUGIN_NOTIFICATION_TYPE_PLUGIN_BASE + 5);

class PLNPlugin extends GenericPlugin
{
    /**
     * @copydoc LazyLoadPlugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled()) {
            $this->registerDAOs();
            Hook::add('PluginRegistry::loadCategory', [$this, 'callbackLoadCategory']);
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
            Hook::add('NotificationManager::getNotificationContents', [$this, 'callbackNotificationContents']);
            Hook::add('LoadComponentHandler', [$this, 'setupComponentHandler']);
            $this->disableRestrictions();
        }
        // The plugin might be disabled for this context, but the task can be executed to check other contexts.
        Hook::add('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
        return true;
    }

    /**
     * Permit requests to the static pages grid handler
     */
    public function setupComponentHandler(string $hookName, array $params): bool
    {
        $component = $params[0];
        if ($component !== 'plugins.generic.pln.controllers.grid.StatusGridHandler') {
            return Hook::CONTINUE;
        }

        // Allow the StatusGridHandler to get the plugin object
        StatusGridHandler::setPlugin($this);
        return Hook::ABORT;
    }

    /**
     * When the request is supposed to be handled by the plugin, this method will disable:
     * - Redirecting non-logged users (the staging server) at contexts protected by login
     * - Redirecting non-logged users (the staging server) at non-public contexts to the login page (see more at: PKPPageRouter::route())
     */
    private function disableRestrictions(): void
    {
        $request = $this->getRequest();
        // Avoid issues with the APIRouter
        if (!($request->getRouter() instanceof PageRouter)) {
            return;
        }

        $page = $request->getRequestedPage();
        $operation = $request->getRequestedOp();
        $arguments = $request->getRequestedArgs();
        if ([$page, $operation] === ['pln', 'deposits'] || [$page, $operation, $arguments[0] ?? ''] === ['gateway', 'plugin', 'PLNGatewayPlugin']) {
            define('SESSION_DISABLE_INIT', true);
            Hook::add('RestrictedSiteAccessPolicy::_getLoginExemptions', function (string $hookName, array $args): bool {
                $exemptions = & $args[0];
                array_push($exemptions, 'gateway', 'pln');
                return Hook::CONTINUE;
            });
        }
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            $actions;
        }

        $router = $request->getRouter();
        array_unshift(
            $options,
            new LinkAction(
                'settings',
                new AjaxModal(
                    $router->url(request: $request, op: 'manage', params: ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                    $this->getDisplayName()
                ),
                __('manager.plugins.settings')
            ),
            new LinkAction(
                'status',
                new AjaxModal(
                    $router->url(request: $request, op: 'manage', params: ['verb' => 'status', 'plugin' => $this->getName(), 'category' => 'generic']),
                    $this->getDisplayName()
                ),
                __('common.status')
            )
        );
        return $options;
    }

    /**
     * Register this plugin's DAOs with the application
     */
    public function registerDAOs(): void
    {
        DAORegistry::registerDAO('DepositDAO', new DepositDAO());
        DAORegistry::registerDAO('DepositObjectDAO', new DepositObjectDAO());
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.pln');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.pln.description');
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration(): SchemaMigration
    {
        return new SchemaMigration();
    }

    /**
     * @copydoc Plugin::getHandlerPath()
     */
    public function getHandlerPath(): string
    {
        return "{$this->getPluginPath()}/pages";
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return "{$this->getPluginPath()}/xml/settings.xml";
    }

    /**
     * @see Plugin::getSetting()
     *
     * @param int $journalId
     * @param string $settingName
     */
    public function getSetting($journalId, $settingName): mixed
    {
        // if there isn't a journal_uuid, make one
        switch ($settingName) {
            case 'journal_uuid':
                $uuid = parent::getSetting($journalId, $settingName);
                if (!is_null($uuid) && $uuid != '') {
                    return $uuid;
                }
                $this->updateSetting($journalId, $settingName, $this->newUUID());
                break;
            case 'object_type':
                $type = parent::getSetting($journalId, $settingName);
                if (! is_null($type)) {
                    return $type;
                }
                $this->updateSetting($journalId, $settingName, PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE);
                break;
            case 'pln_network':
                return Config::getVar('lockss', 'pln_url', PLN_DEFAULT_NETWORK);
        }
        return parent::getSetting($journalId, $settingName);
    }

    /**
     * Register as a gateway plugin.
     */
    public function callbackLoadCategory(string $hookName, array $args): bool
    {
        $category = $args[0];
        $plugins = & $args[1];
        if ($category === 'gateways') {
            $gatewayPlugin = new PLNGatewayPlugin($this->getName());
            $plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] = $gatewayPlugin;
        }

        return Hook::CONTINUE;
    }

    /**
     * @copydoc AcronPlugin::parseCronTab()
     */
    public function callbackParseCronTab(string $hookName, array $args): bool
    {
        $taskFilesPath = & $args[0];
        $taskFilesPath[] = $this->getPluginPath() . '/xml/scheduledTasks.xml';
        return Hook::CONTINUE;
    }

    /**
     * Hook registry function to provide notification messages
     */
    public function callbackNotificationContents(string $hookName, array $args): bool
    {
        /** @var Notification */
        $notification = $args[0];
        $message = & $args[1];

        $message = match ($notification->getType()) {
            PLN_PLUGIN_NOTIFICATION_TYPE_TERMS_UPDATED => __('plugins.generic.pln.notifications.terms_updated'),
            PLN_PLUGIN_NOTIFICATION_TYPE_ISSN_MISSING => __('plugins.generic.pln.notifications.issn_missing'),
            PLN_PLUGIN_NOTIFICATION_TYPE_HTTP_ERROR => __('plugins.generic.pln.notifications.http_error'),
            PLN_PLUGIN_NOTIFICATION_TYPE_ZIP_MISSING => __('plugins.generic.pln.notifications.zip_missing'),
            default => $message
        };
        return Hook::CONTINUE;
    }

    /**
     * Callback for the LoadHandler hook
     */
    public function callbackLoadHandler(string $hookName, array $args): bool
    {
        $page = $args[0];
        $op = $args[1] ?? '';
        if ($page !== 'pln' || $op !== 'deposits') {
            return Hook::CONTINUE;
        }
        define('HANDLER_CLASS', PageHandler::class);
        $handlerFile = & $args[2];
        $handlerFile = "{$this->getHandlerPath()}/PageHandler.php";
        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $verb = $request->getUserVar('verb');
        if ($verb === 'settings') {
            $context = $request->getContext();
            $form = new SettingsForm($this, $context->getId());

            if ($request->getUserVar('refresh')) {
                $this->getServiceDocument($context->getId());
            } elseif ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();

                    // Add notification: Changes saved
                    $notificationContent = __('plugins.generic.pln.settings.saved');
                    $currentUser = $request->getUser();
                    $notificationMgr = new NotificationManager();
                    $notificationMgr->createTrivialNotification($currentUser->getId(), PKPNotification::NOTIFICATION_TYPE_SUCCESS, ['contents' => $notificationContent]);

                    return new JSONMessage(true);
                }
            }

            $form->initData();

            return new JSONMessage(true, $form->fetch($request));
        }

        if ($verb === 'status') {
            $depositDao = DAORegistry::getDAO('DepositDAO');

            $context = $request->getContext();
            $form = new StatusForm($this, $context->getId());

            if ($request->getUserVar('reset')) {
                $depositIds = array_keys($request->getUserVar('reset'));
                /** @var DepositDAO */
                $depositDao = DAORegistry::getDAO('DepositDAO');
                foreach ($depositIds as $depositId) {
                    $deposit = $depositDao->getById($depositId);
                    $deposit->setNewStatus();
                    $depositDao->updateObject($deposit);
                }
            }

            return new JSONMessage(true, $form->fetch($request));
        }

        throw new Exception('Unexpected verb');
    }

    /**
     * Check to see whether the PLN's terms have been agreed to to append.
     */
    public function termsAgreed(int $journalId): bool
    {
        $terms = unserialize($this->getSetting($journalId, 'terms_of_use'));
        $termsAgreed = unserialize($this->getSetting($journalId, 'terms_of_use_agreement'));

        foreach (array_keys($terms) as $term) {
            if ((!$termsAgreed[$term] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Request service document at specified URL
     *
     * @return int The HTTP response status.
     */
    public function getServiceDocument(int $contextId): int
    {
        $application = Application::get();
        $request = $application->getRequest();
        $contextDao = Application::getContextDAO();
        $context = $contextDao->getById($contextId);

        // get the journal and determine the language.
        $locale = $context->getPrimaryLocale();
        $language = strtolower(str_replace('_', '-', $locale));
        $network = $this->getSetting($context->getId(), 'pln_network');
        $application = Application::get();
        $dispatcher = $application->getDispatcher();

        // retrieve the service document
        $result = $this->curlGet(
            $network . PLN_PLUGIN_SD_IRI,
            [
                'On-Behalf-Of' => $this->getSetting($contextId, 'journal_uuid'),
                'Journal-URL' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath()),
                'Accept-Language' => $language,
            ]
        );

        // stop here if we didn't get an OK
        if (intdiv((int) $result['status'], 100) !== 2) {
            if ($result['status']) {
                error_log(__('plugins.generic.pln.error.http.servicedocument', ['error' => $result['status'], 'message' => $result['error']]));
            } else {
                error_log(__('plugins.generic.pln.error.network.servicedocument', ['error' => $result['error']]));
            }
            return $result['status'];
        }

        $serviceDocument = new DOMDocument();
        $serviceDocument->preserveWhiteSpace = false;
        $serviceDocument->loadXML($result['result']);

        // update the max upload size
        $element = $serviceDocument->getElementsByTagName('maxUploadSize')->item(0);
        $this->updateSetting($contextId, 'max_upload_size', $element->nodeValue);

        // update the checksum type
        $element = $serviceDocument->getElementsByTagName('uploadChecksumType')->item(0);
        $this->updateSetting($contextId, 'checksum_type', $element->nodeValue);

        // update the network status
        /** @var DOMElement */
        $element = $serviceDocument->getElementsByTagName('pln_accepting')->item(0);
        $this->updateSetting($contextId, 'pln_accepting', (($element->getAttribute('is_accepting') == 'Yes') ? true : false));
        $this->updateSetting($contextId, 'pln_accepting_message', $element->nodeValue);

        // update the terms of use
        $termElements = $serviceDocument->getElementsByTagName('terms_of_use')->item(0)->childNodes;
        $terms = [];
        foreach ($termElements as $termElement) {
            if ($termElement instanceof DOMElement) {
                $terms[$termElement->tagName] = ['updated' => $termElement->getAttribute('updated'), 'term' => $termElement->nodeValue];
            }
        }

        $newTerms = serialize($terms);
        $oldTerms = $this->getSetting($contextId, 'terms_of_use');

        // if the new terms don't match the exiting ones we need to reset agreement
        if ($newTerms != $oldTerms) {
            $termAgreements = [];
            foreach ($terms as $termName => $termText) {
                $termAgreements[$termName] = null;
            }

            $this->updateSetting($contextId, 'terms_of_use', $newTerms, 'object');
            $this->updateSetting($contextId, 'terms_of_use_agreement', serialize($termAgreements), 'object');
            $this->createJournalManagerNotification($contextId, PLN_PLUGIN_NOTIFICATION_TYPE_TERMS_UPDATED);
        }

        return $result['status'];
    }

    /**
     * Create notification for all journal managers
     */
    public function createJournalManagerNotification(int $contextId, int $notificationType): void
    {
        $userGroupIds = Repo::userGroup()
            ->getByRoleIds([Role::ROLE_ID_MANAGER], $contextId)
            ->map(fn (UserGroup $userGroup) => $userGroup->getId())
            ->toArray();

        $managers = Repo::user()
            ->getCollector()
            ->filterByRoleIds($userGroupIds)
            ->getMany();
        $notificationManager = new NotificationManager();
        // TODO: This is going to notify all managers, perhaps only the technical contact should be notified?
        foreach ($managers as $manager) {
            $notificationManager->createTrivialNotification($manager->getId(), $notificationType);
        }
    }

    /**
     * Get whether zip archive support is present
     */
    public function zipInstalled(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Check if acron is enabled, or if the scheduled_tasks config var is set.
     * The plugin needs to run periodically through one of those systems.
     */
    public function cronEnabled(): bool
    {
        $application = Application::get();
        $products = $application->getEnabledProducts('plugins.generic');
        return isset($products['acron']) || Config::getVar('general', 'scheduled_tasks', false);
    }

    /**
     * Get resource
     */
    public function curlGet(string $url, array $headers = []): array
    {
        $httpClient = Application::get()->getHttpClient();
        $response = null;
        $body = null;
        $error = null;
        try {
            $response = $httpClient->request('GET', $url, ['headers' => $headers]);
            $body = (string) $response->getBody();
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $body = $response ? (string) $response->getBody() : null;
            $error = $e->getMessage();
            if (strlen($body)) {
                try {
                    $error = (new SimpleXMLElement($body))->summary ?: $error;
                } catch (Exception $e) {
                }
            }
        }
        return [
            'status' => $response ? $response->getStatusCode() : null,
            'result' => $body,
            'error' => $error
        ];
    }

    /**
     * Post a file to a resource
     */
    public function curlPostFile(string $url, string $filename): array
    {
        return $this->sendFile('POST', $url, $filename);
    }

    /**
     * Put a file to a resource
     */
    public function curlPutFile(string $url, string $filename): array
    {
        return $this->sendFile('PUT', $url, $filename);
    }

    /**
     * Create a new UUID
     */
    public function newUUID(): string
    {
        return PKPString::generateUUID();
    }

    /**
     * Transfer a file to a resource.
     */
    protected function sendFile(string $method, string $url, string $filename): array
    {
        $httpClient = Application::get()->getHttpClient();
        $response = null;
        $body = null;
        $error = null;
        try {
            $response = $httpClient->request($method, $url, [
                'headers' => [
                    'Content-Type' => mime_content_type($filename),
                    'Content-Length' => filesize($filename),
                ],
                'body' => fopen($filename, 'r'),
            ]);
            $body = (string) $response->getBody();
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $body = $response ? (string) $response->getBody() : null;
            $error = $e->getMessage();
            if (strlen($body)) {
                try {
                    $error = (new SimpleXMLElement($body))->summary ?: $error;
                } catch (Exception $e) {
                }
            }
        }
        return [
            'status' => $response ? $response->getStatusCode() : null,
            'result' => $body,
            'error' => $error
        ];
    }

    /**
     * @copydoc LazyLoadPlugin::register()
     */
    public function setEnabled(bool $enabled): void
    {
        parent::setEnabled($enabled);
        if ($enabled) {
            (new NotificationManager())->createTrivialNotification(
                Application::get()->getRequest()->getUser()->getId(),
                PKPNotification::NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.pln.onPluginEnabledNotification')]
            );
        }
    }
}
