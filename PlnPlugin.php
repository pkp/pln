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
use APP\core\Request;
use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\classes\deposit\Schema as DepositSchema;
use APP\plugins\generic\pln\classes\depositObject\Schema as DepositObjectSchema;
use APP\plugins\generic\pln\classes\form\SettingsForm;
use APP\plugins\generic\pln\classes\form\StatusForm;
use APP\plugins\generic\pln\classes\migration\install\SchemaMigration;
use APP\plugins\generic\pln\classes\PLNGatewayPlugin;
use APP\plugins\generic\pln\classes\tasks\Depositor;
use APP\plugins\generic\pln\pages\PageHandler;
use DOMDocument;
use DOMElement;
use Exception;
use GuzzleHttp\Exception\RequestException;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\core\PKPString;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\PKPScheduler;
use PKP\security\Role;
use PKP\userGroup\UserGroup;
use SimpleXMLElement;

class PlnPlugin extends GenericPlugin implements HasTaskScheduler
{
    public const DEFAULT_NETWORK_URL = 'https://pkp-pn.lib.sfu.ca';

    public const DEPOSIT_FOLDER = 'pln';

    // Notification types
    public const NOTIFICATION_BASE = Notification::NOTIFICATION_TYPE_PLUGIN_BASE + 0x10000000;
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

        if (!$this->getEnabled()) {
            return true;
        }

        $this->registerSchemas();
        Hook::add('PluginRegistry::loadCategory', $this->onLoadCategory(...));
        Hook::add('LoadHandler', $this->onLoadHandler(...));
        Hook::add('LoadComponentHandler', $this->onLoadComponentHandler(...));
        $this->disableRestrictions();
        return true;
    }

    /**
     * Permit requests to the status grid handler
     */
    public function onLoadComponentHandler(string $hookName, array $params): bool
    {
        [$component] = $params;
        return $component !== 'plugins.generic.pln.controllers.grid.StatusGridHandler' ? Hook::CONTINUE : Hook::ABORT;
    }

    /**
     * When the request is supposed to be handled by the plugin, this method will disable:
     * - Redirecting non-logged users (the staging server) at contexts protected by login
     * - Redirecting non-logged users (the staging server) at non-public contexts to the login page (see more at: PKPPageRouter::route())
     */
    private function disableRestrictions(): void
    {
        $request = $this->getRequest();
        // If we're under an API request (APIRouter), a couple of method calls will fail, and this workaround is needed just on a place that uses the PageRouter
        if (!($request->getRouter() instanceof PageRouter)) {
            return;
        }

        $page = $request->getRequestedPage();
        $operation = $request->getRequestedOp();
        $arguments = $request->getRequestedArgs();
        if ([$page, $operation] !== ['pln', 'deposits'] && [$page, $operation, $arguments[0] ?? ''] !== ['gateway', 'plugin', 'PLNGatewayPlugin']) {
            return;
        }

        Hook::add('RestrictedSiteAccessPolicy::_getLoginExemptions', $this->onGetLoginExemptions(...));
    }

    /**
     * Included an extra route which won't require the user to be logged in (to cover the case of non-public journals)
     */
    public function onGetLoginExemptions(string $hookName, array $args): bool
    {
        [&$exemptions] = $args;
        array_push($exemptions, 'gateway', 'pln');
        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $buildLinkAction = fn (string $verb, string $title) => new LinkAction(
            $verb,
            new AjaxModal(
                $router->url(request: $request, op: 'manage', params: ['verb' => $verb, 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __($title)
        );
        array_unshift(
            $actions,
            $buildLinkAction('settings', 'manager.plugins.settings'),
            $buildLinkAction('status', 'common.status')
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
        $value = parent::getSetting($journalId, $settingName);
        $getSetting = function (callable $getValue) use ($journalId, $settingName, $value) {
            if (!strlen((string) $value)) {
                $this->updateSetting($journalId, $settingName, $value = $getValue());
            }

            return $value;
        };
        return match ($settingName) {
            'journal_uuid' => $getSetting(fn () => $this->newUUID()),
            'object_type' => $getSetting(fn () => static::DEPOSIT_TYPE_ISSUE),
            default => $value
        };
    }

    /**
     * Register as a gateway plugin.
     */
    public function onLoadCategory(string $hookName, array $args): bool
    {
        [$category, &$plugins] = $args;
        if ($category !== 'gateways') {
            return Hook::CONTINUE;
        }

        $gatewayPlugin = new PLNGatewayPlugin($this->getName());
        $plugins[$gatewayPlugin->getSeq()][$gatewayPlugin->getPluginPath()] = $gatewayPlugin;
        return Hook::CONTINUE;
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $this->registerSchemas();
        $scheduler
            ->addSchedule(new Depositor())
            ->daily()
            ->name(Depositor::class)
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Callback for the LoadHandler hook
     */
    public function onLoadHandler(string $hookName, array $args): bool
    {
        [$page, $op] = $args + [1 => ''];
        if ($page !== 'pln' || $op !== 'deposits') {
            return Hook::CONTINUE;
        }

        $args[3] = new PageHandler();
        return Hook::ABORT;
    }

    /**
     * Manage settings
     */
    private function manageSettings(Request $request): JSONMessage
    {
        $context = $request->getContext();
        $form = new SettingsForm($this, $context->getId());

        if ($request->getUserVar('refresh')) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false);
            }
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
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification(
                    $request->getUser()->getId(),
                    Notification::NOTIFICATION_TYPE_SUCCESS,
                    ['contents' => __('plugins.generic.pln.settings.saved')]
                );
                return new JSONMessage(true);
            }
        }

        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * Manage status
     */
    private function manageStatus(Request $request): JSONMessage
    {
        $context = $request->getContext();
        $form = new StatusForm($this, $context->getId());
        if (!$request->getUserVar('reset')) {
            return new JSONMessage(true, $form->fetch($request));
        }

        if (!$request->checkCSRF()) {
            return new JSONMessage(false);
        }

        $depositIds = array_keys((array) $request->getUserVar('reset'));
        $repo = Repository::instance();
        $deposits = $repo->getCollector()
            ->filterByIds($depositIds)
            ->filterByContextIds([$context->getId()])
            ->getMany();
        foreach ($deposits as $deposit) {
            $deposit->setNewStatus();
            $repo->edit($deposit);
        }

        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $verb = $request->getUserVar('verb');
        return match ($verb) {
            'settings' => $this->manageSettings($request),
            'status' => $this->manageStatus($request)
        };
    }

    /**
     * Check to see whether the PLN's terms have been agreed to to append.
     */
    public function termsAgreed(int $journalId): bool
    {
        //HASH of all text to detect changes, for existing setup hash automatically
        $terms = $this->getSetting($journalId, 'terms_of_use') ?: [];
        $termsAgreed = $this->getSetting($journalId, 'terms_of_use_agreement') ?: [];

        foreach (array_keys($terms) as $term) {
            if (!($termsAgreed[$term] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Request service document at specified URL
     *
     * @return array{status: ?int, result: ?string, error:? string}
     */
    public function getServiceDocument(int $contextId): array
    {
        $application = Application::get();
        $request = $application->getRequest();
        $contextDao = Application::getContextDAO();
        $context = $contextDao->getById($contextId);
        // get the journal and determine the language
        $locale = $context->getPrimaryLocale();
        $language = strtolower(str_replace('_', '-', $locale));
        $networkUrl = static::getNetworkUrl();
        $application = Application::get();
        $dispatcher = $application->getDispatcher();
        // retrieve the service document
        $result = $this->httpGet(
            "{$networkUrl}/api/sword/2.0/sd-iri",
            [
                'On-Behalf-Of' => $this->getSetting($contextId, 'journal_uuid'),
                'Journal-URL' => $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath()),
                'Accept-Language' => $language,
            ]
        );

        // stop here if we didn't get an OK (2XX status)
        if (intdiv((int) $result['status'], 100) !== 2) {
            $message = $result['status']
                ? __('plugins.generic.pln.error.http.servicedocument', ['error' => $result['status'], 'message' => $result['error']])
                : __('plugins.generic.pln.error.network.servicedocument', ['error' => $result['error']]);
            error_log($message);
            return $result;
        }

        $serviceDocument = new DOMDocument('1.0', 'utf-8');
        $serviceDocument->preserveWhiteSpace = false;
        $serviceDocument->loadXML($result['result']);
        $findFirst = fn (string $tagName) => $serviceDocument->getElementsByTagName($tagName)->item(0);
        // update the max upload size
        $this->updateSetting($contextId, 'max_upload_size', (int) $findFirst('maxUploadSize')->textContent);
        // update the checksum type
        $this->updateSetting($contextId, 'checksum_type', $findFirst('uploadChecksumType')->textContent);
        // update the network status
        $acceptingNode = $findFirst('pln_accepting');
        $this->updateSetting($contextId, 'pln_accepting', mb_strtolower($acceptingNode->getAttribute('is_accepting')) === 'yes');
        $this->updateSetting($contextId, 'pln_accepting_message', $acceptingNode->textContent);
        // update the terms of use
        $termNodes = $findFirst('terms_of_use')->getElementsByTagName('*');
        $terms = [];
        /** @var DOMElement $termNode */
        foreach ($termNodes as $termNode) {
            $terms[$termNode->tagName] = ['updated' => $termNode->getAttribute('updated'), 'term' => $termNode->textContent];
        }

        $currentTerms = $this->getSetting($contextId, 'terms_of_use');
        // if the new terms don't match the exiting ones the agreement must be reset
        if ($terms != $currentTerms) {
            $termAgreements = [];
            foreach (array_keys($terms) as $term) {
                $termAgreements[$term] = null;
            }

            $this->updateSetting($contextId, 'terms_of_use', $terms, 'object');
            $this->updateSetting($contextId, 'terms_of_use_agreement', $termAgreements, 'object');
            $this->createJournalManagerNotification($contextId, static::NOTIFICATION_TERMS_UPDATED);
        }

        return $result;
    }

    /**
     * Send a notification to the journal managers
     */
    public function createJournalManagerNotification(int $contextId, int $notificationType, ?array $params = null): void
    {
        $managers = Repo::user()
            ->getCollector()
            ->filterByRoleIds([Role::ROLE_ID_MANAGER])
            ->filterByContextIds([$contextId])
            ->getMany();
        $notificationManager = new NotificationManager();
        [$type, $message] = match ($notificationType) {
            static::NOTIFICATION_TERMS_UPDATED => [Notification::NOTIFICATION_TYPE_ERROR, __('plugins.generic.pln.notifications.terms_updated')],
            static::NOTIFICATION_ISSN_MISSING => [Notification::NOTIFICATION_TYPE_ERROR, __('plugins.generic.pln.notifications.issn_missing')],
            static::NOTIFICATION_HTTP_ERROR => [Notification::NOTIFICATION_TYPE_ERROR, __('plugins.generic.pln.notifications.http_error')],
            static::NOTIFICATION_ZIP_MISSING => [Notification::NOTIFICATION_TYPE_ERROR, __('plugins.generic.pln.notifications.zip_missing')]
        };
        foreach ($managers as $manager) {
            $notificationManager->createTrivialNotification(
                $manager->getId(),
                $type,
                ['contents' => $message]
            );
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
     * Get resource
     *
     * @return array{status: ?int, result: ?string, error: ?string}
     */
    public function httpGet(string $url, array $headers = []): array
    {
        $httpClient = Application::get()->getHttpClient();
        $response = $body = $error = null;
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
                    error_log("Failed to parse PKP PN error:\n$body");
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'status' => $response?->getStatusCode(),
            'result' => $body,
            'error' => $error
        ];
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
    public function httpUpload(string $url, string $filename, bool $usePut = false): array
    {
        $httpClient = Application::get()->getHttpClient();
        $response = $body = $error = null;
        try {
            $response = $httpClient->request($usePut ? 'PUT' : 'POST', $url, [
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
                    error_log("Failed to parse PKP PN error:\n$body");
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        return [
            'status' => $response?->getStatusCode(),
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
        if (!$enabled) {
            return;
        }

        (new NotificationManager())->createTrivialNotification(
            Application::get()->getRequest()->getUser()->getId(),
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('plugins.generic.pln.onPluginEnabledNotification')]
        );
    }

    /**
     * Retrieves the PKP Preservation Network URL
     */
    public static function getNetworkUrl(): string
    {
        return Config::getVar('lockss', 'pln_url', static::DEFAULT_NETWORK_URL);
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
        static::$instance ??= PluginRegistry::loadPlugin('generic', 'pln');
        return static::$instance;
    }
}
