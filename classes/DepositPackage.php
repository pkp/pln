<?php

/**
 * @file classes/DepositPackage.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositPackage
 *
 * @brief Represent a PLN deposit package.
 */

namespace APP\plugins\generic\pln\classes;

use APP\core\Application;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\importexport\native\NativeImportExportPlugin;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\file\ContextFileManager;
use PKP\file\FileManager;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTaskHelper;
use PKP\submission\PKPSubmission;

class DepositPackage
{
    public const PKP_NAMESPACE = 'http://pkp.sfu.ca/SWORD';

    /** @var Deposit */
    public $_deposit;

    /**
     * If the DepositPackage object was created as part of a scheduled task
     * run, then save the task so error messages can be logged there.
     *
     * @var ScheduledTask;
     */
    public $_task;

    /**
     * Constructor.
     *
     * @param Deposit $deposit
     * @param ScheduledTask $task
     */
    public function __construct($deposit, $task = null)
    {
        $this->_deposit = $deposit;
        $this->_task = $task;
    }

    /**
     * Send a message to a log. If the deposit package is aware of a
     * a scheduled task, the message will be sent to the task's
     * log. Otherwise it will be sent to error_log().
     *
     * @param string $message Locale-specific message to be logged
     */
    protected function _logMessage($message)
    {
        if ($this->_task) {
            $this->_task->addExecutionLogEntry($message, ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
        } else {
            error_log($message);
        }
    }

    /**
     * Get the directory used to store deposit data.
     *
     * @return string
     */
    public function getDepositDir()
    {
        $fileManager = new ContextFileManager($this->_deposit->getJournalId());
        return $fileManager->getBasePath() . PLN_PLUGIN_ARCHIVE_FOLDER . DIRECTORY_SEPARATOR . $this->_deposit->getUUID();
    }

    /**
     * Get the filename used to store the deposit's atom document.
     *
     * @return string
     */
    public function getAtomDocumentPath()
    {
        return $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID() . '.xml';
    }

    /**
     * Get the filename used to store the deposit's bag.
     *
     * @return string
     */
    public function getPackageFilePath()
    {
        return $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID() . '.zip';
    }

    /**
     * Create a DOMElement in the $dom, and set the element name, namespace, and
     * content. Any invalid UTF-8 characters will be dropped. The
     * content will be placed inside a CDATA section.
     *
     * @param DOMDocument $dom
     * @param string $elementName
     * @param string $content
     * @param string $namespace
     *
     * @return DOMElement
     */
    protected function _generateElement($dom, $elementName, $content, $namespace = null)
    {
        // remove any invalid UTF-8.
        $original = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $filtered = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        mb_substitute_character($original);

        // put the filtered content in a CDATA, as it may contain markup that
        // isn't valid XML.
        $node = $dom->createCDATASection($filtered);
        $element = $dom->createElementNS($namespace, $elementName);
        $element->appendChild($node);
        return $element;
    }

    /**
     * Create an atom document for this deposit.
     *
     * @return string
     */
    public function generateAtomDocument()
    {
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);
        /** @var JournalDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        /** @var Journal */
        $journal = $journalDao->getById($this->_deposit->getJournalId());
        $fileManager = new ContextFileManager($this->_deposit->getJournalId());

        // set up folder and file locations
        $atomFile = $this->getAtomDocumentPath();
        $packageFile = $this->getPackageFilePath();

        // make sure our bag is present
        if (!$fileManager->fileExists($packageFile)) {
            $this->_logMessage(__('plugins.generic.pln.error.depositor.missingpackage', ['file' => $packageFile]));
            return false;
        }

        $atom = new DOMDocument('1.0', 'utf-8');
        $entry = $atom->createElementNS('http://www.w3.org/2005/Atom', 'entry');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', 'http://purl.org/dc/terms/');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pkp', static::PKP_NAMESPACE);

        $entry->appendChild($this->_generateElement($atom, 'email', $journal->getData('contactEmail')));
        $entry->appendChild($this->_generateElement($atom, 'title', $journal->getLocalizedName()));

        $request = Application::get()->getRequest();
        $dispatcher = Application::get()->getDispatcher();

        $entry->appendChild($this->_generateElement($atom, 'pkp:journal_url', $dispatcher->url($request, Application::ROUTE_PAGE, $journal->getPath()), static::PKP_NAMESPACE));

        $entry->appendChild($this->_generateElement($atom, 'pkp:publisherName', $journal->getData('publisherInstitution'), static::PKP_NAMESPACE));

        $entry->appendChild($this->_generateElement($atom, 'pkp:publisherUrl', $journal->getData('publisherUrl'), static::PKP_NAMESPACE));

        $issn = '';
        if ($journal->getData('onlineIssn')) {
            $issn = $journal->getData('onlineIssn');
        } elseif ($journal->getData('printIssn')) {
            $issn = $journal->getData('printIssn');
        }

        $entry->appendChild($this->_generateElement($atom, 'pkp:issn', $issn, static::PKP_NAMESPACE));

        $entry->appendChild($this->_generateElement($atom, 'id', 'urn:uuid:' . $this->_deposit->getUUID()));

        $entry->appendChild($this->_generateElement($atom, 'updated', date('Y-m-d H:i:s', strtotime($this->_deposit->getDateModified()))));

        $url = $dispatcher->url($request, Application::ROUTE_PAGE, $journal->getPath()) . '/' . PLN_PLUGIN_ARCHIVE_FOLDER . '/deposits/' . $this->_deposit->getUUID();
        $pkpDetails = $this->_generateElement($atom, 'pkp:content', $url, static::PKP_NAMESPACE);
        $pkpDetails->setAttribute('size', ceil(filesize($packageFile) / 1000));

        $objectVolume = '';
        $objectIssue = '';
        $objectPublicationDate = 0;

        switch ($this->_deposit->getObjectType()) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
                $depositObjects = $this->_deposit->getDepositObjects();
                /** @var SubmissionDAO */
                $submissionDao = DAORegistry::getDAO('SubmissionDAO');
                while ($depositObject = $depositObjects->next()) {
                    $submission = $submissionDao->getById($depositObject->getObjectId());
                    $publication = $submission->getCurrentPublication();
                    $publicationDate = $publication ? $publication->getData('publicationDate') : null;
                    if ($publicationDate && strtotime($publicationDate) > $objectPublicationDate) {
                        $objectPublicationDate = strtotime($publicationDate);
                    }
                }
                break;
            case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
                $depositObjects = $this->_deposit->getDepositObjects();
                while ($depositObject = $depositObjects->next()) {
                    /** @var IssueDAO */
                    $issueDao = DAORegistry::getDAO('IssueDAO');
                    $issue = $issueDao->getById($depositObject->getObjectId());
                    $objectVolume = $issue->getVolume();
                    $objectIssue = $issue->getNumber();
                    if ($issue->getDatePublished() > $objectPublicationDate) {
                        $objectPublicationDate = $issue->getDatePublished();
                    }
                }
                break;
        }

        $pkpDetails->setAttribute('volume', $objectVolume);
        $pkpDetails->setAttribute('issue', $objectIssue);
        $pkpDetails->setAttribute('pubdate', date('Y-m-d', strtotime($objectPublicationDate)));

        // Add OJS Version
        /** @var VersionDAO */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion();
        $pkpDetails->setAttribute('ojsVersion', $currentVersion->getVersionString());

        switch ($plnPlugin->getSetting($journal->getId(), 'checksum_type')) {
            case 'SHA-1':
                $pkpDetails->setAttribute('checksumType', 'SHA-1');
                $pkpDetails->setAttribute('checksumValue', sha1_file($packageFile));
                break;
            case 'MD5':
                $pkpDetails->setAttribute('checksumType', 'MD5');
                $pkpDetails->setAttribute('checksumValue', md5_file($packageFile));
                break;
        }

        $entry->appendChild($pkpDetails);
        $atom->appendChild($entry);

        $locale = $journal->getPrimaryLocale();
        $license = $atom->createElementNS(static::PKP_NAMESPACE, 'license');
        $license->appendChild($this->_generateElement($atom, 'openAccessPolicy', $journal->getLocalizedSetting('openAccessPolicy', $locale), static::PKP_NAMESPACE));
        $license->appendChild($this->_generateElement($atom, 'licenseURL', $journal->getLocalizedSetting('licenseURL', $locale), static::PKP_NAMESPACE));

        $mode = $atom->createElementNS(static::PKP_NAMESPACE, 'publishingMode');
        switch($journal->getData('publishingMode')) {
            case Journal::PUBLISHING_MODE_OPEN:
                $mode->nodeValue = 'Open';
                break;
            case Journal::PUBLISHING_MODE_SUBSCRIPTION:
                $mode->nodeValue = 'Subscription';
                break;
            case Journal::PUBLISHING_MODE_NONE:
                $mode->nodeValue = 'None';
                break;
        }
        $license->appendChild($mode);
        $license->appendChild($this->_generateElement($atom, 'copyrightNotice', $journal->getLocalizedSetting('copyrightNotice', $locale), static::PKP_NAMESPACE));
        $license->appendChild($this->_generateElement($atom, 'copyrightBasis', $journal->getLocalizedSetting('copyrightBasis'), static::PKP_NAMESPACE));
        $license->appendChild($this->_generateElement($atom, 'copyrightHolder', $journal->getLocalizedSetting('copyrightHolder'), static::PKP_NAMESPACE));

        $entry->appendChild($license);
        $atom->save($atomFile);

        return $atomFile;
    }

    /**
     * Create a package containing the serialized deposit objects. If the
     * bagit library fails to load, null will be returned.
     *
     * @return string The full path of the created zip archive
     */
    public function generatePackage()
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        // get DAOs, plugins and settings
        /** @var JournalDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        /** @var IssueDAO */
        $issueDao = DAORegistry::getDAO('IssueDAO');
        /** @var SubmissionDAO */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        /** @var NativeImportExportPlugin */
        $exportPlugin = PluginRegistry::loadPlugin('importexport', 'native');
        $supportsOptions = method_exists($exportPlugin, 'parseOpts');
        @ini_set('memory_limit', -1);
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);

        $journal = $journalDao->getById($this->_deposit->getJournalId());
        $depositObjects = $this->_deposit->getDepositObjects();

        // set up folder and file locations
        $bagDir = $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID();
        $packageFile = $this->getPackageFilePath();
        $exportFile = tempnam(sys_get_temp_dir(), 'ojs-pln-export-');
        $termsFile = tempnam(sys_get_temp_dir(), 'ojs-pln-terms-');

        $bag = \whikloj\BagItTools\Bag::create($bagDir);

        $fileList = [];
        $fileManager = new FileManager();

        switch ($this->_deposit->getObjectType()) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
                $submissionIds = [];

                // we need to add all of the relevant submissions to an array to export as a batch
                while ($depositObject = $depositObjects->next()) {
                    $submission = $submissionDao->getById($this->_deposit->getObjectId());
                    $currentPublication = $submission->getCurrentPublication();
                    if ($submission->getContextId() != $journal->getId()) {
                        continue;
                    }
                    if (!$currentPublication || $currentPublication->getData('status') != PKPSubmission::STATUS_PUBLISHED) {
                        continue;
                    }

                    $submissionIds[] = $submission->getId();
                }

                // export all of the submissions together
                $exportXml = $exportPlugin->exportSubmissions($submissionIds, $journal, null, ['no-embed' => 1]);
                if (!$exportXml) {
                    throw new Exception(__('plugins.generic.pln.error.depositor.export.articles.error'));
                }
                if ($supportsOptions) {
                    $exportXml = $this->_cleanFileList($exportXml, $fileList);
                }
                $fileManager->writeFile($exportFile, $exportXml);
                break;
            case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
                // we only ever do one issue at a time, so get that issue
                $request = Application::get()->getRequest();
                $depositObject = $depositObjects->next();
                $issue = $issueDao->getByBestId($depositObject->getObjectId(), $journal->getId());

                $exportXml = $exportPlugin->exportIssues(
                    (array) $issue->getId(),
                    $journal,
                    $request->getUser(),
                    ['no-embed' => 1]
                );

                if (!$exportXml) {
                    throw new $exception($this->_logMessage(__('plugins.generic.pln.error.depositor.export.issue.error')));
                }

                if ($supportsOptions) {
                    $exportXml = $this->_cleanFileList($exportXml, $fileList);
                }
                $fileManager->writeFile($exportFile, $exportXml);
                break;
            default:
                throw new Exception('Unknown deposit type!');
        }

        // add the current terms to the bag
        $termsXml = new DOMDocument('1.0', 'utf-8');
        $entry = $termsXml->createElementNS('http://www.w3.org/2005/Atom', 'entry');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', 'http://purl.org/dc/terms/');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pkp', PLN_PLUGIN_NAME);

        $terms = unserialize($plnPlugin->getSetting($this->_deposit->getJournalId(), 'terms_of_use'));
        $agreement = unserialize($plnPlugin->getSetting($this->_deposit->getJournalId(), 'terms_of_use_agreement'));

        $pkpTermsOfUse = $termsXml->createElementNS(PLN_PLUGIN_NAME, 'pkp:terms_of_use');
        foreach ($terms as $termName => $termData) {
            $element = $termsXml->createElementNS(PLN_PLUGIN_NAME, $termName, $termData['term']);
            $element->setAttribute('updated', $termData['updated']);
            $element->setAttribute('agreed', $agreement[$termName]);
            $pkpTermsOfUse->appendChild($element);
        }

        $entry->appendChild($pkpTermsOfUse);
        $termsXml->appendChild($entry);
        $termsXml->save($termsFile);

        // add the exported content to the bag
        $bag->addFile($exportFile, $this->_deposit->getObjectType() . $this->_deposit->getUUID() . '.xml');
        foreach ($fileList as $sourcePath => $targetPath) {
            // $sourcePath is a relative path to the files directory; add the files directory to the front
            $sourcePath = rtrim(Config::getVar('files', 'files_dir'), '/') . '/' . $sourcePath;
            $bag->addFile($sourcePath, $targetPath);
        }

        // Add the schema files to the bag (adjusting the XSD references to flatten them)
        $bag->createFile(
            preg_replace(
                '/schemaLocation="[^"]+pkp-native.xsd"/',
                'schemaLocation="pkp-native.xsd"',
                file_get_contents('plugins/importexport/native/native.xsd')
            ),
            'native.xsd'
        );
        $bag->createFile(
            preg_replace(
                '/schemaLocation="[^"]+importexport.xsd"/',
                'schemaLocation="importexport.xsd"',
                file_get_contents('lib/pkp/plugins/importexport/native/pkp-native.xsd')
            ),
            'pkp-native.xsd'
        );
        $bag->createFile(file_get_contents('lib/pkp/xml/importexport.xsd'), 'importexport.xsd');

        // add the exported content to the bag
        $bag->addFile($termsFile, 'terms' . $this->_deposit->getUUID() . '.xml');

        // Add OJS Version
        /** @var VersionDAO */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion();
        $bag->setExtended(true);
        $bag->addBagInfoTag('PKP-PLN-OJS-Version', $currentVersion->getVersionString());

        $bag->update();

        // create the bag
        $bag->package($packageFile);

        // remove the temporary bag directory and temp files
        $fileManager->rmtree($bagDir);
        $fileManager->deleteByPath($exportFile);
        $fileManager->deleteByPath($termsFile);
        return $packageFile;
    }

    /**
     * Read a list of file paths from the specified native XML string and clean up the XML's pathnames.
     *
     * @param string $xml
     * @param array $fileList Reference to array to receive file list
     *
     * @return string
     */
    public function _cleanFileList($xml, &$fileList)
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNameSpace('pkp', 'http://pkp.sfu.ca');
        foreach ($xpath->query('//pkp:submission_file//pkp:href') as $hrefNode) {
            $filePath = $hrefNode->getAttribute('src');
            $targetPath = 'files/' . basename($filePath);
            $fileList[$filePath] = $targetPath;
            $hrefNode->setAttribute('src', $targetPath);
        }
        return $doc->saveXML();
    }

    /**
     * Transfer the atom document to the PLN.
     */
    public function transferDeposit()
    {
        $journalId = $this->_deposit->getJournalId();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        /** @var PLNPlugin */
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);

        // post the atom document
        $baseUrl = $plnPlugin->getSetting($journalId, 'pln_network');
        $atomPath = $this->getAtomDocumentPath();

        // Reset deposit if the package doesn't exist
        if (!file_exists($atomPath)) {
            $this->_deposit->setNewStatus();
            $depositDao->updateObject($this->_deposit);
            return;
        }

        $journalUuid = $plnPlugin->getSetting($journalId, 'journal_uuid');
        $baseContUrl = $baseUrl . PLN_PLUGIN_CONT_IRI . "/{$journalUuid}/{$this->_deposit->getUUID()}";

        $result = $plnPlugin->curlGet("{$baseContUrl}/state");
        $status = intdiv((int) $result['status'], 100);
        // Abort if status not 2XX or 4XX
        if ($status !== 2 && $status !== 4) {
            $this->_task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultFailed',
                    ['depositId' => $this->_deposit->getId(),
                        'error' => $result['status'],
                        'result' => $result['error']]
                ),
                SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );
            $this->_logMessage(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            $this->_deposit->setExportDepositError(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            $this->_deposit->setLastStatusDate(Core::getCurrentDate());
            $depositDao->updateObject($this->_deposit);
            return;
        }
        // Status 2XX at this URL means the content has been deposited before
        $isNewDeposit = $status !== 2;
        $url = $isNewDeposit ? $baseUrl . PLN_PLUGIN_COL_IRI . "/{$journalUuid}" : "{$baseContUrl}/edit";

        $this->_task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.transferringdeposits.processing.postAtom',
                [
                    'depositId' => $this->_deposit->getId(),
                    'statusLocal' => $this->_deposit->getLocalStatus(),
                    'statusProcessing' => $this->_deposit->getProcessingStatus(),
                    'statusLockss' => $this->_deposit->getLockssStatus(),
                    'atomPath' => $atomPath,
                    'url' => $url,
                    'method' => $isNewDeposit ? 'PostFile' : 'PutFile'
                ]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        $result = $isNewDeposit
            ? $plnPlugin->curlPostFile($url, $atomPath)
            : $plnPlugin->curlPutFile($url, $atomPath);

        // If we get a 2XX, set the status as transferred
        if (intdiv((int) $result['status'], 100) === 2) {
            $this->_task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultSucceeded',
                    ['depositId' => $this->_deposit->getId()]
                ),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );

            $this->_deposit->setTransferredStatus();
            $this->_deposit->setExportDepositError(null);
        } else {
            $this->_task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultFailed',
                    ['depositId' => $this->_deposit->getId(), 'error' => $result['status'], 'result' => $result['error']]
                ),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );
            if ($result['status']) {
                $this->_logMessage(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
                $this->_deposit->setExportDepositError(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            } else {
                $this->_logMessage(__('plugins.generic.pln.error.network.deposit', ['error' => $result['error']]));
                $this->_deposit->setExportDepositError(__('plugins.generic.pln.error.network.deposit', ['error' => $result['error']]));
            }
        }

        $this->_deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->_deposit);
    }

    /**
     * Package a deposit for transfer to and retrieval by the PLN.
     */
    public function packageDeposit()
    {
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        $fileManager = new ContextFileManager($this->_deposit->getJournalId());
        $plnDir = $fileManager->getBasePath() . PLN_PLUGIN_ARCHIVE_FOLDER;

        // make sure the pln work directory exists
        if (!$fileManager->fileExists($plnDir, 'dir')) {
            $fileManager->mkdir($plnDir);
        }

        // make a location for our work and clear it out if it already exists
        $this->remove();
        $fileManager->mkdir($this->getDepositDir());

        try {
            $packagePath = $this->generatePackage();
            if (!$fileManager->fileExists($packagePath)) {
                throw new Exception(__(
                    'plugins.generic.pln.depositor.packagingdeposits.processing.packageFailed',
                    ['depositId' => $this->_deposit->getId()]
                ));
            }

            if (!$fileManager->fileExists($this->generateAtomDocument())) {
                throw new Exception(__(
                    'plugins.generic.pln.depositor.packagingdeposits.processing.packageFailed',
                    ['depositId' => $this->_deposit->getId()]
                ));
            }
        } catch (Throwable $exception) {
            $this->_logMessage(__('plugins.generic.pln.error.depositor.export.issue.error') . $exception->getMessage());
            $this->_deposit->setExportDepositError($exception->getMessage());
            $this->_deposit->setLastStatusDate(Core::getCurrentDate());
            $depositDao->updateObject($this->_deposit);
            return;
        }

        $this->_task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.packagingdeposits.processing.packageSucceeded',
                ['depositId' => $this->_deposit->getId()]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        // update the deposit's status
        $this->_deposit->setPackagedStatus();
        $this->_deposit->setExportDepositError(null);
        $this->_deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->_deposit);
    }

    /**
     * Update the deposit's status by checking with the PLN.
     */
    public function updateDepositStatus()
    {
        $journalId = $this->_deposit->getJournalId();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        /** @var PLNPlugin */
        $plnPlugin = PluginRegistry::getPlugin('generic', 'plnplugin');

        $url = $plnPlugin->getSetting($journalId, 'pln_network') . PLN_PLUGIN_CONT_IRI;
        $url .= '/' . $plnPlugin->getSetting($journalId, 'journal_uuid');
        $url .= '/' . $this->_deposit->getUUID() . '/state';

        // retrieve the content document
        $result = $plnPlugin->curlGet($url);
        if (intdiv((int) $result['status'], 100) !== 2) {
            if ($result['status']) {
                error_log(__('plugins.generic.pln.error.http.swordstatement', ['error' => $result['status'], 'message' => $result['error']]));

                // Status 4XX means the deposit doesn't exist or isn't related to the given journal, so we restart the deposit
                if (intdiv($result['status'], 100) === 4) {
                    $this->_deposit->setNewStatus();
                    $depositDao->updateObject($this->_deposit);
                }

                return;
            }

            error_log(__('plugins.generic.pln.error.network.swordstatement', ['error' => $result['error'] ?: 'Unexpected error']));
            return;
        }

        $contentDOM = new DOMDocument();
        $contentDOM->preserveWhiteSpace = false;
        $contentDOM->loadXML($result['result']);

        // get the remote deposit state
        $processingState = $contentDOM->getElementsByTagName('category')->item(0)->getAttribute('term');
        $this->_task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.statusupdates.processing.processingState',
                ['depositId' => $this->_deposit->getId(),
                    'processingState' => $processingState]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        // Clear previous error messages
        $this->_deposit->setExportDepositError(null);
        $this->_deposit->setStagingState($processingState ?: null);
        // Handle the local state
        switch ($processingState) {
            case 'depositedByJournal':
            case 'harvest-error':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED);
                break;
            case 'harvested':
            case 'payload-validated':
            case 'bag-validated':
            case 'xml-validated':
            case 'virus-checked':
            case 'payload-error':
            case 'bag-error':
            case 'xml-error':
            case 'virus-error':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED);
                break;
            case 'reserialized':
            case 'hold':
            case 'reserialize-error':
            case 'deposit-error':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED);
                break;
            case 'deposited':
            case 'status-error':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT);
                break;
            default:
                $this->_deposit->setExportDepositError('Unknown processing state ' . $processingState);
                $this->_logMessage('Deposit ' . $this->_deposit->getId() . ' has unknown processing state ' . $processingState);
                break;
        }

        // The deposit file can be dropped once it's received by the PKP PN
        if ($this->_deposit->getReceivedStatus()) {
            $this->remove();
        } elseif (!file_exists($this->getAtomDocumentPath())) {
            // Otherwise the package must still exist at this point, if it doesn't, we restart the deposit
            $this->_deposit->setNewStatus();
            $depositDao->updateObject($this->_deposit);
            return;
        }

        // Handle error messages
        if (in_array($processingState, ['hold', 'harvest-error', 'deposit-error', 'reserialize-error', 'virus-error', 'xml-error', 'payload-error', 'bag-error', 'status-error'])) {
            $this->_deposit->setExportDepositError(__('plugins.generic.pln.status.error.' . $processingState));
        }

        $lockssState = $contentDOM->getElementsByTagName('category')->item(1)->getAttribute('term');
        $this->_deposit->setLockssState($lockssState ?: null);
        switch ($lockssState) {
            case '':
                // do nothing.
                break;
            case 'inProgress':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED);
                break;
            case 'agreement':
                $this->_deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT);
                $this->_deposit->setPreservedDate(Core::getCurrentDate());
                break;
            default:
                $this->_deposit->setExportDepositError('Unknown LOCKSS state ' . $lockssState);
                $this->_logMessage('Deposit ' . $this->_deposit->getId() . ' has unknown LOCKSS state ' . $lockssState);
                break;
        }

        $this->_deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->_deposit);
    }

    /**
     * Delete a deposit package from the disk
     *
     * @return bool True on success
     */
    public function remove()
    {
        return (new ContextFileManager($this->_deposit->getJournalId()))
            ->rmtree($this->getDepositDir());
    }
}
