<?php

/**
 * @file PlnPlugin.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlnPlugin
 *
 * @brief PLN plugin class
 */

namespace APP\plugins\generic\pln;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\classes\deposit\Schema as DepositSchema;
use APP\plugins\generic\pln\classes\depositObject\Schema as DepositObjectSchema;
use APP\plugins\generic\pln\classes\form\SettingsForm;
use APP\plugins\generic\pln\classes\form\StatusForm;
use APP\plugins\generic\pln\classes\PLNGatewayPlugin;
use APP\plugins\generic\pln\classes\tasks\Depositor;
use APP\plugins\generic\pln\pages\PageHandler;
use APP\plugins\generic\pln\classes\migration\install\SchemaMigration;
use Carbon\Carbon;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPString;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\PKPNotification;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\session\SessionManager;
use PKP\userGroup\UserGroup;
use SimpleXMLElement;

class PlnPlugin extends GenericPlugin
{
    public const DEFAULT_NETWORK_URL = 'https://pkp-pn.lib.sfu.ca';

    public const DEPOSIT_FOLDER = 'pln';

    // Notification types
    public const NOTIFICATION_BASE = PKPNotification::NOTIFICATION_TYPE_PLUGIN_BASE + 0x10000000;
    public const NOTIFICATION_TERMS_UPDATED = self::NOTIFICATION_BASE + 1;
    public const NOTIFICATION_ISSN_MISSING = self::NOTIFICATION_BASE + 2;
    public const NOTIFICATION_HTTP_ERROR = self::NOTIFICATION_BASE + 3;
    public const NOTIFICATION_ZIP_MISSING = self::NOTIFICATION_BASE + 5;

    // Deposit types
    public const DEPOSIT_TYPE_SUBMISSION = 'Submission';
    public const DEPOSIT_TYPE_ISSUE = 'Issue';

    // Local status
    public const DEPOSIT_STATUS_NEW = 0;
    public const DEPOSIT_STATUS_PACKAGED = 1;
    public const DEPOSIT_STATUS_TRANSFERRED = 2;

    // Staging server status
    public const DEPOSIT_STATUS_RECEIVED = 4;
    public const DEPOSIT_STATUS_VALIDATED = 8;
    public const DEPOSIT_STATUS_SENT = 16;

    // LOCKSS server status
    public const DEPOSIT_STATUS_LOCKSS_RECEIVED = 64;
    public const DEPOSIT_STATUS_LOCKSS_AGREEMENT = 128;

    protected static $instance;

    /**
     * @copydoc LazyLoadPlugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        static::$instance = $this;
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        $this->registerSchemas();

        if ($this->getEnabled()) {
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
            SessionManager::disable();
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
            $actions,
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
        return $actions;
    }

    /**
     * Register the plugin's schemas within the application
     */
    public function registerSchemas(): void
    {
        DepositSchema::register();
        DepositObjectSchema::register();
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
                $this->updateSetting($journalId, $settingName, static::DEPOSIT_TYPE_ISSUE);
                break;
            case 'pln_network':
                return Config::getVar('lockss', 'pln_url', static::DEFAULT_NETWORK_URL);
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
            static::NOTIFICATION_TERMS_UPDATED => __('plugins.generic.pln.notifications.terms_updated'),
            static::NOTIFICATION_ISSN_MISSING => __('plugins.generic.pln.notifications.issn_missing'),
            static::NOTIFICATION_HTTP_ERROR => __('plugins.generic.pln.notifications.http_error'),
            static::NOTIFICATION_ZIP_MISSING => __('plugins.generic.pln.notifications.zip_missing'),
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
                $result = $this->getServiceDocument($context->getId());
                if (intdiv((int) $result['status'], 100) !== 2) {
                    $message = $result['status']
                        ? __('plugins.generic.pln.error.http.servicedocument', ['error' => $result['status'], 'message' => $result['error']])
                        : __('plugins.generic.pln.error.network.servicedocument', ['error' => $result['error']]);
                    return new JSONMessage(false, $message);
                }
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
            $context = $request->getContext();
            $form = new StatusForm($this, $context->getId());

            if ($request->getUserVar('reset')) {
                $depositIds = array_keys($request->getUserVar('reset'));
                $repo = Repository::instance();
                $deposits = $repo
                    ->getCollector()
                    ->filterByIds($depositIds)
                    ->filterByContextIds([$context->getId()])
                    ->getMany();
                foreach ($deposits as $deposit) {
                    $deposit->setNewStatus();
                    $repo->edit($deposit);
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
        $terms = $this->getSetting($journalId, 'terms_of_use');
        $termsAgreed = $this->getSetting($journalId, 'terms_of_use_agreement');

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
     * @return array{status:?int,result:?string,error:?string}
     */
    public function getServiceDocument(int $contextId): array
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
            "{$network}/api/sword/2.0/sd-iri",
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
            return $result;
        }

        $serviceDocument = new DOMDocument('1.0', 'utf-8');
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
        $newTerms = [];
        foreach ($termElements as $termElement) {
            if ($termElement instanceof DOMElement) {
                $newTerms[$termElement->tagName] = ['updated' => $termElement->getAttribute('updated'), 'term' => $termElement->nodeValue];
            }
        }

        $oldTerms = $this->getSetting($contextId, 'terms_of_use');

        // if the new terms don't match the exiting ones we need to reset agreement
        if ($newTerms != $oldTerms) {
            $termAgreements = [];
            foreach ($newTerms as $termName => $termText) {
                $termAgreements[$termName] = null;
            }

            $this->updateSetting($contextId, 'terms_of_use', $newTerms, 'object');
            $this->updateSetting($contextId, 'terms_of_use_agreement', $termAgreements, 'object');
            $this->createJournalManagerNotification($contextId, static::NOTIFICATION_TERMS_UPDATED);
        }

        return $result;
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
    public function hasZipArchive(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Check if the Acron plugin is enabled, or if the scheduled task has been running lately.
     */
    public function hasScheduledTasks(): bool
    {
        $application = Application::get();
        $products = $application->getEnabledProducts('plugins.generic');
        return isset($products['acron']) || (($lastRun = $this->getLastExecutionDate()) && Carbon::now()->diffInWeeks($lastRun) < 1);
    }

    /**
     * Retrieves the last time the depositor task was executed
     */
    public function getLastExecutionDate(): ?DateTimeImmutable
    {
        $lastRun = DB::table('scheduled_tasks')
            ->where('class_name', Depositor::class)
            ->soleValue('last_run');
        return $lastRun ? new DateTimeImmutable($lastRun) : null;
    }

    /**
     * Get resource
     *
     * @return array{status:?int,result:?string,error:?string}
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
        } catch (Exception $e) {
            $error = $e->getMessage();
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
        } catch (Exception $e) {
            $error = $e->getMessage();
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
    public function setEnabled($enabled): void
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

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return substr(static::class, strlen(__NAMESPACE__) + 1);
    }

    /**
     * Self loads and registers the plugin
     */
    public static function loadPlugin(): static
    {
        /** @var static */
        static::$instance ??= PluginRegistry::loadPlugin('generic', 'PLN');
        return static::$instance;
    }
}
