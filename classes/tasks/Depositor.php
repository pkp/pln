<?php

/**
 * @file classes/tasks/Depositor.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNPluginDepositor
 * @brief Class to perform automated deposits of PLN object.
 */

namespace APP\plugins\generic\pln\classes\tasks;

use APP\journal\JournalDAO;
use APP\plugins\generic\pln\classes\DepositPackage;
use APP\plugins\generic\pln\form\Deposit;
use PKP\db\DAORegistry;
use \PKP\file\ContextFileManager;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class Depositor extends ScheduledTask {

	/** @var object */
	var $_plugin;

	/**
	 * Constructor.
	 * @param array $args task arguments
	 */
	public function __construct($args) {
		PluginRegistry::loadCategory('generic');
		$this->_plugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);
		parent::__construct($args);
	}

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName() {
		return __('plugins.generic.pln.depositorTask.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	public function executeActions() {
		if (!$this->_plugin) return false;

		$this->addExecutionLogEntry('PKP Preservation Network Processor', ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

		/** @var JournalDAO */
		$journalDao = DAORegistry::getDAO('JournalDAO');

		// Get all journals
		$journals = $journalDao->getAll(true);

		// For all journals
		while ($journal = $journals->next()) {

			// if the plugin isn't enabled for this journal, skip it
			if (!$this->_plugin->getSetting($journal->getId(), 'enabled')) {
				continue;
			}

			$this->_plugin->registerDAOs();
			$this->_plugin->import('classes.Deposit');
			$this->_plugin->import('classes.DepositObject');
			$this->_plugin->import('classes.DepositPackage');

			$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.processing_for', array('title' => $journal->getLocalizedName())), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			// check to make sure zip is installed
			if (!$this->_plugin->zipInstalled()) {
				$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.zip_missing'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
				$this->_plugin->createJournalManagerNotification($journal->getId(), PLN_PLUGIN_NOTIFICATION_TYPE_ZIP_MISSING);
				continue;
			}

			// if the pln isn't accepting deposits, skip the journal
			if (!$this->_plugin->getSetting($journal->getId(), 'pln_accepting')) {
				$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.pln_not_accepting'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
				continue;
			}

			// if the terms haven't been agreed to, skip transfer
			if (!$this->_plugin->termsAgreed($journal->getId())) {
				$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.terms_updated'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
				$this->_plugin->createJournalManagerNotification($journal->getId(), PLN_PLUGIN_NOTIFICATION_TYPE_TERMS_UPDATED);
				continue;
			}

			// it's necessary that the journal have an issn set
			if (!$journal->getSetting('onlineIssn') &&
				!$journal->getSetting('printIssn') &&
				!$journal->getSetting('issn')) {
				$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.issn_missing'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
				$this->_plugin->createJournalManagerNotification($journal->getId(), PLN_PLUGIN_NOTIFICATION_TYPE_ISSN_MISSING);
				continue;
			}

			$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.getting_servicedocument'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// get the sword service document
			$sdResult = $this->_plugin->getServiceDocument($journal->getId());
			// if for some reason we didn't get a valid response, skip this journal
			if (intdiv((int) $sdResult, 100) !== 2) {
				$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.http_error'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
				$this->_plugin->createJournalManagerNotification($journal->getId(), PLN_PLUGIN_NOTIFICATION_TYPE_HTTP_ERROR);
				continue;
			}

			// create new deposits for new deposit objects
			$this->addExecutionLogEntry(__("plugins.generic.pln.depositor.newcontent"), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			try {
				$this->_processNewDepositObjects($journal);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry($e, SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			}

			// flag any deposits that have been updated and need to be rebuilt
			$this->addExecutionLogEntry(__("plugins.generic.pln.depositor.updatedcontent"), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			try {
				$this->_processHavingUpdatedContent($journal);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry($e, SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			}

			// package any deposits that need packaging
			$this->addExecutionLogEntry(__("plugins.generic.pln.depositor.packagingdeposits"), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			try {
				$this->_processNeedPackaging($journal);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry($e, SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			}

			// update the statuses of existing deposits
			$this->addExecutionLogEntry(__("plugins.generic.pln.depositor.statusupdates"), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			try {
				$this->_processStatusUpdates($journal);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry($e, SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			}

			// transfer the deposit atom documents
			$this->addExecutionLogEntry(__("plugins.generic.pln.depositor.transferringdeposits"), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			try {
				$this->_processNeedTransferring($journal);
			} catch (Throwable $e) {
				$this->addExecutionLogEntry($e, SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			}
		}

		$this->pruneOrphaned();

		return true;
	}

	/**
	 * Go through existing deposits and fetch their status from the PLN
	 * @param Journal $journal
	 */
	protected function _processStatusUpdates($journal) {
		// get deposits that need status updates
		$depositDao = DAORegistry::getDAO('DepositDAO'); /** @var DepositDAO $depositDao */
		$depositQueue = $depositDao->getNeedStagingStatusUpdate($journal->getId());

		while ($deposit = $depositQueue->next()) {
			$this->addExecutionLogEntry(__('plugins.generic.pln.depositor.statusupdates.processing',
				array('depositId' => $deposit->getId(),
					'statusLocal' => $deposit->getLocalStatus(),
					'statusProcessing' => $deposit->getProcessingStatus(),
					'statusLockss' => $deposit->getLockssStatus(),
					'objectId' => $deposit->getObjectId(),
					'objectType' => $deposit->getObjectType())),
				ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			$depositPackage = new DepositPackage($deposit, $this);
			$depositPackage->updateDepositStatus();
		}
	}

	/**
	 * @param Journal $journal Object
	 *
	 * Go through the deposits and mark them as updated if they have been
	 */
	protected function _processHavingUpdatedContent(&$journal) {
		// get deposits that have updated content
		$depositObjectDao = DAORegistry::getDAO('DepositObjectDAO'); /** @var DepositObjectDAO $depositObjectDao */
		$depositObjectDao->markHavingUpdatedContent($journal->getId(), $this->_plugin->getSetting($journal->getId(), 'object_type'));
	}

	/**
	 * If a deposit hasn't been transferred, transfer it
	 * @param Journal $journal Object
	 */
	protected function _processNeedTransferring($journal) {
		// fetch the deposits we need to send to the pln
		$depositDao = DAORegistry::getDAO('DepositDAO'); /** @var DepositDAO $depositDao */
		$depositQueue = $depositDao->getNeedTransferring($journal->getId());

		while ($deposit = $depositQueue->next()) {
			$this->addExecutionLogEntry(__('plugins.generic.pln.depositor.transferringdeposits.processing',
				array('depositId' => $deposit->getId(),
					'statusLocal' => $deposit->getLocalStatus(),
					'statusProcessing' => $deposit->getProcessingStatus(),
					'statusLockss' => $deposit->getLockssStatus(),
					'objectId' => $deposit->getObjectId(),
					'objectType' => $deposit->getObjectType())),
				ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			$depositPackage = new DepositPackage($deposit, $this);
			$depositPackage->transferDeposit();
			unset($deposit);
		}
	}

	/**
	 * Create packages for any deposits that don't have any or have been updated
	 * @param Journal $journal Object
	 */
	protected function _processNeedPackaging($journal) {
		$depositDao = DAORegistry::getDAO('DepositDAO'); /** @var DepositDAO $depositDao */
		$depositQueue = $depositDao->getNeedPackaging($journal->getId());
		$fileManager = new ContextFileManager($journal->getId());
		$plnDir = $fileManager->getBasePath() . PLN_PLUGIN_ARCHIVE_FOLDER;

		// make sure the pln work directory exists
		$fileManager->mkdirtree($plnDir);

		// loop though all of the deposits that need packaging
		while ($deposit = $depositQueue->next()) {
			$this->addExecutionLogEntry(__('plugins.generic.pln.depositor.packagingdeposits.processing',
				array('depositId' => $deposit->getId(),
					'statusLocal' => $deposit->getLocalStatus(),
					'statusProcessing' => $deposit->getProcessingStatus(),
					'statusLockss' => $deposit->getLockssStatus(),
					'objectId' => $deposit->getObjectId(),
					'objectType' => $deposit->getObjectType())),
				ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			$depositPackage = new DepositPackage($deposit, $this);
			$depositPackage->packageDeposit();
		}
	}

	/**
	 * Create new deposits for deposit objects
	 * @param Journal $journal Object
	 */
	protected function _processNewDepositObjects($journal) {
		// get the object type we'll be dealing with
		$objectType = $this->_plugin->getSetting($journal->getId(), 'object_type');

		// create new deposit objects for any new OJS content
		$depositDao = DAORegistry::getDAO('DepositDAO'); /** @var DepositDAO $depositDao */
		$depositObjectDao = DAORegistry::getDAO('DepositObjectDAO'); /** @var DepositObjectDAO $depositObjectDao */
		$depositObjectDao->createNew($journal->getId(), $objectType);

		// retrieve all deposit objects that don't belong to a deposit
		$newObjects = $depositObjectDao->getNew($journal->getId());

		switch ($objectType) {
			case 'PublishedArticle': // Legacy (OJS pre-3.2)
			case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:

				// get the new object threshold per deposit and split the objects into arrays of that size
				$objectThreshold = $this->_plugin->getSetting($journal->getId(), 'object_threshold');
				foreach (array_chunk($newObjects->toArray(),$objectThreshold) as $newObject_array) {

					// only create a deposit for the complete threshold, we'll worry about the remainder another day
					if (count($newObject_array) == $objectThreshold) {

						//create a new deposit
						$newDeposit = new Deposit($this->_plugin->newUUID());
						$newDeposit->setJournalId($journal->getId());
						$depositDao->insertObject($newDeposit);

						// add each object to the deposit
						foreach ($newObject_array as $newObject) {
							$newObject->setDepositId($newDeposit->getId());
							$depositObjectDao->updateObject($newObject);
						}
					}
				}
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				// create a new deposit for each deposit object
				while ($newObject = $newObjects->next()) {
					$newDeposit = new Deposit($this->_plugin->newUUID());
					$newDeposit->setJournalId($journal->getId());
					$depositDao->insertObject($newDeposit);
					$newObject->setDepositId($newDeposit->getId());
					$depositObjectDao->updateObject($newObject);
					unset($newObject);
				}
				break;
			default:
				throw new Exception("Invalid object type \"{$objectType}\"");
		}
	}

	/**
	 * Removes orphaned deposits
	 * This should be called at the end of the process to avoid dropping "deposit objects", which still don't have an assigned deposit
	 */
	public function pruneOrphaned() {
		$this->addExecutionLogEntry(__('plugins.generic.pln.notifications.pruningOrphanedDeposits'), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

		/** @var DepositDAO */
		$depositDao = DAORegistry::getDAO('DepositDAO');
		if (count($failedDepositIds = $depositDao->pruneOrphaned())) {
			$this->addExecutionLogEntry(__('plugins.generic.pln.depositor.pruningDeposits.error', ['depositIds' => implode(', ', $failedDepositIds)]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
		}

		/** @var DepositObjectDAO */
		$depositObjectDao = DAORegistry::getDAO('DepositObjectDAO');
		$depositObjectDao->pruneOrphaned();
	}
}
