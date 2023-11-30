<?php

/**
 * @file classes/Deposit.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Deposit
 * @brief Container for deposit objects that are submitted to a PLN
 */

class Deposit extends DataObject {

	/**
	 * Constructor
	 * @param ?string $uuid
	 * @return Deposit
	 */
	public function __construct($uuid) {
		parent::__construct();

		//Set up new deposits with a UUID
		$this->setUUID($uuid);
	}

	/**
	 * Get the type of deposit objects in this deposit.
	 * @return string One of PLN_PLUGIN_DEPOSIT_SUPPORTED_OBJECTS
	 */
	public function getObjectType() {
		$depositObjects = $this->getDepositObjects();
		$depositObject = $depositObjects->next();
		return ($depositObject?$depositObject->getObjectType():null);
	}

	/**
	 * Get the id of deposit objects in this deposit.
	 * @return int
	 */
	public function getObjectId() {
		$depositObjects = $this->getDepositObjects();
		$depositObject = $depositObjects->next();
		return ($depositObject?$depositObject->getObjectId():null);
	}

	/**
	 * Get all deposit objects of this deposit.
	 * @return DAOResultFactory List of DepositObject
	 */
	public function getDepositObjects() {
		/** @var DepositObjectDAO */
		$depositObjectDao = DAORegistry::getDAO('DepositObjectDAO');
		return $depositObjectDao->getByDepositId($this->getJournalId(), $this->getId());
	}

	/**
	 * Get deposit uuid
	 * @return string
	 */
	public function getUUID() {
		return $this->getData('uuid');
	}

	/**
	 * Set deposit uuid
	 * @param string $uuid
	 */
	public function setUUID($uuid) {
		$this->setData('uuid', $uuid);
	}

	/**
	 * Get journal id
	 * @return int
	 */
	public function getJournalId() {
		return $this->getData('journal_id');
	}

	/**
	 * Set journal id
	 * @param int $journalId
	 */
	public function setJournalId($journalId) {
		$this->setData('journal_id', $journalId);
	}

	/**
	 * Get deposit status - this is the raw bit field, the other status
	 * functions are more immediately useful.
	 * @return int
	 */
	public function getStatus() {
		return $this->getData('status');
	}

	/**
	 * Set deposit status - this is the raw bit field, the other status
	 * functions are more immediately useful.
	 * @param int $status
	 */
	public function setStatus($status) {
		$this->setData('status', $status);
	}

	/**
	 * Return a string representation of the local status.
	 * @return string
	 */
	public function getLocalStatus() {
		if (!$this->getPackagedStatus() && $this->getExportDepositError()) {
			return __('plugins.generic.pln.status.packagingFailed');
		}
		if ($this->getTransferredStatus()) {
			return __('plugins.generic.pln.status.transferred');
		}
		if ($this->getPackagedStatus()) {
			return __('plugins.generic.pln.status.packaged');
		}
		if ($this->getNewStatus()) {
			return __('plugins.generic.pln.status.new');
		}
		return __('plugins.generic.pln.status.unknown');
	}

	/**
	 * Return a string representation of the processing status.
	 * @return string
	 */
	public function getProcessingStatus() {
		if ($this->getSentStatus()) {
			return __('plugins.generic.pln.status.sent');
		}
		if ($this->getValidatedStatus()) {
			return __('plugins.generic.pln.status.validated');
		}
		if ($this->getReceivedStatus()) {
			return __('plugins.generic.pln.status.received');
		}
		return __('plugins.generic.pln.status.unknown');
	}

	/**
	 * Return a string representation of the LOCKSS status.
	 * @return string
	 */
	public function getLockssStatus() {
		if ($this->getLockssAgreementStatus()) {
			return __('plugins.generic.pln.status.agreement');
		}
		if ($this->getLockssReceivedStatus()) {
			return __('plugins.generic.pln.status.received');
		}
		return __('plugins.generic.pln.status.unknown');
	}

	/**
	 * Get new (blank) deposit status
	 * @return int
	 */
	public function getNewStatus() {
		return $this->getStatus() == PLN_PLUGIN_DEPOSIT_STATUS_NEW;
	}

	/**
	 * Set new (blank) deposit status
	 */
	public function setNewStatus() {
		$this->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_NEW);
		$this->setLastStatusDate(null);
		$this->setExportDepositError(null);
		$this->setStagingState(null);
		$this->setLockssState(null);

	}

	/**
	 * Get a status from the bit field.
	 * @param int $field one of the PLN_PLUGIN_DEPOSIT_STATUS_* constants.
	 * @return int
	 */
	protected function _getStatusField($field) {
		return $this->getStatus() & $field;
	}

	/**
	 * Set a status value.
	 * @param boolean $value
	 * @param int $field one of the PLN_PLUGIN_DEPOSIT_STATUS_* constants.
	 */
	protected function _setStatusField($value, $field) {
		if($value) {
			$this->setStatus($this->getStatus() | $field);
		} else {
			$this->setStatus($this->getStatus() & ~$field);
		}
	}

	/**
	 * Get whether the deposit has been packaged for the PLN
	 * @return int
	 */
	public function getPackagedStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED);
	}

	/**
	 * Set whether the deposit has been packaged for the PLN
	 * @param boolean $status
	 */
	public function setPackagedStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED);
	}

	/**
	 * Get whether the PLN has been notified of the available deposit
	 * @return int
	 */
	public function getTransferredStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED);
	}

	/**
	 * Set whether the PLN has been notified of the available deposit
	 * @param boolean $status
	 */
	public function setTransferredStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED);
	}

	/**
	 * Get whether the PLN has retrieved the deposit from the journal
	 * @return int
	 */
	public function getReceivedStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED);
	}

	/**
	 * Set whether the PLN has retrieved the deposit from the journal
	 * @param boolean $status
	 */
	public function setReceivedStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED);
	}

	/**
	 * Get whether the PLN has validated the deposit
	 * @return int
	 */
	public function getValidatedStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED);
	}

	/**
	 * Set whether the PLN has validated the deposit
	 * @param boolean $status
	 */
	public function setValidatedStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED);
	}

	/**
	 * Get whether the deposit has been sent to LOCKSS
	 * @return int
	 */
	public function getSentStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_SENT);
	}

	/**
	 * Set whether the deposit has been sent to LOCKSS
	 * @param boolean $status
	 */
	public function setSentStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_SENT);
	}

	/**
	 * Get whether LOCKSS received the deposit
	 * @return int
	 */
	public function getLockssReceivedStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED);
	}

	/**
	 * Set whether LOCKSS received the deposit
	 * @param boolean $status
	 */
	public function setLockssReceivedStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED);
	}

	/**
	 * Get whether LOCKSS considered the deposit as preserved
	 * @return int
	 */
	public function getLockssAgreementStatus() {
		return $this->_getStatusField(PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT);
	}

	/**
	 * Set whether LOCKSS considered the deposit as preserved
	 * @param boolean $status
	 */
	public function setLockssAgreementStatus($status = true) {
		$this->_setStatusField($status, PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT);
	}

	/**
	 * Get the date of the last status change
	 * @return ?string
	 */
	public function getLastStatusDate() {
		return $this->getData('dateStatus');
	}

	/**
	 * Set set the date of the last status change
	 * @param ?string $dateLastStatus
	 */
	public function setLastStatusDate($dateLastStatus) {

		$this->setData('dateStatus', $dateLastStatus);
	}

	/**
	 * Get the date of deposit creation
	 * @return string
	 */
	public function getDateCreated() {
		return $this->getData('dateCreated');
	}

	/**
	 * Set the date of deposit creation
	 * @param string $dateCreated
	 */
	public function setDateCreated($dateCreated) {
		$this->setData('dateCreated', $dateCreated);
	}

	/**
	 * Get the modification date of the deposit
	 * @return string
	 */
	public function getDateModified() {
		return $this->getData('dateModified');
	}

	/**
	 * Set the modification date of the deposit
	 * @param string $dateModified
	 */
	public function setDateModified($dateModified) {
		$this->setData('dateModified', $dateModified);
	}

	/**
	 * Set the export deposit error message.
	 * @param ?string $exportDepositError
	 */
	public function setExportDepositError($exportDepositError) {
		$this->setData('exportDepositError', $exportDepositError);
	}

	/**
	 * Get the export deposit error message.
	 * @return string|null
	 */
	public function getExportDepositError() {
		return $this->getData('exportDepositError');
	}

	/**
	 * Get Displayed status locale string
	 * @return string
	 */
	public function getDisplayedStatus() {
		if (!empty($this->getExportDepositError())) {
			$displayedStatus = __('plugins.generic.pln.displayedstatus.error');
		} else if ($this->getLockssAgreementStatus()) {
			$displayedStatus = __('plugins.generic.pln.displayedstatus.completed');
		} else if ($this->getNewStatus()) {
			$displayedStatus = __('plugins.generic.pln.displayedstatus.pending');
		} else {
			$displayedStatus = __('plugins.generic.pln.displayedstatus.inprogress');
		}

		return $displayedStatus;
	}

	/**
	 * Retrieves when the deposit was preserved
	 * @return ?string
	 */
	public function getPreservedDate() {
		return $this->getData('datePreserved');
	}

	/**
	 * Set the preserved date of the deposit
	 * @param ?string $date
	 */
	public function setPreservedDate($date) {
		$this->setData('datePreserved', $date);
	}

	/**
	 * Retrieves the staging server state
	 * @return ?string
	 */
	public function getStagingState() {
		return $this->getData('stagingState');
	}

	/**
	 * Sets the staging server state
	 * @param ?string $date
	 */
	public function setStagingState($date) {
		$this->setData('stagingState', $date);
	}

	/**
	 * Retrieves the LOCKSS server state
	 * @return ?string
	 */
	public function getLockssState() {
		return $this->getData('lockssState');
	}

	/**
	 * Sets the LOCKSS server state
	 * @param ?string $state
	 */
	public function setLockssState($state) {
		$this->setData('lockssState', $state);
	}
}
