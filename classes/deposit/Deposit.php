<?php

/**
 * @file classes/deposit/Deposit.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Deposit
 *
 * @brief Container for deposit objects that are submitted to a PLN
 */

namespace APP\plugins\generic\pln\classes\deposit;

use APP\plugins\generic\pln\classes\depositObject\Repository;
use APP\plugins\generic\pln\PlnPlugin;
use Illuminate\Support\LazyCollection;
use PKP\core\DataObject;
use PKP\core\PKPString;

class Deposit extends DataObject
{
    /**
     * Constructor
     */
    public function __construct(?string $uuid = null)
    {
        parent::__construct();

        //Set up new deposits with a UUID
        $this->setUUID($uuid ?: PKPString::generateUUID());
    }

    /**
     * Get the type of deposit objects in this deposit.
     */
    public function getObjectType(): ?string
    {
        $depositObjects = $this->getDepositObjects();
        return $depositObjects->first()?->getObjectType();
    }

    /**
     * Get the id of deposit objects in this deposit.
     */
    public function getObjectId(): ?int
    {
        $depositObjects = $this->getDepositObjects();
        return $depositObjects->first()?->getObjectId();
    }

    /**
     * Get all deposit objects of this deposit.
     *
     * @return LazyCollection<Deposit> List of DepositObject
     */
    public function getDepositObjects(): LazyCollection
    {
        return Repository::instance()
            ->getCollector()
            ->filterByContextIds([$this->getJournalId()])
            ->filterByDepositIds([$this->getId()])
            ->getMany();
    }

    /**
     * Get deposit UUID
     */
    public function getUUID(): ?string
    {
        return $this->getData('uuid');
    }

    /**
     * Set deposit UUID
     */
    public function setUUID(?string $uuid): void
    {
        $this->setData('uuid', $uuid);
    }

    /**
     * Get journal ID
     */
    public function getJournalId(): ?int
    {
        return $this->getData('journalId');
    }

    /**
     * Set journal ID
     */
    public function setJournalId(?int $journalId): void
    {
        $this->setData('journalId', $journalId);
    }

    /**
     * Get deposit status
     */
    public function getStatus(): ?int
    {
        return $this->getData('status');
    }

    /**
     * Set deposit status
     */
    public function setStatus(?int $status): void
    {
        $this->setData('status', $status);
    }

    /**
     * Return a string representation of the local status.
     */
    public function getLocalStatus(): string
    {
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
     */
    public function getProcessingStatus(): string
    {
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
     */
    public function getLockssStatus(): string
    {
        if ($this->getLockssAgreementStatus()) {
            return __('plugins.generic.pln.status.agreement');
        }
        if ($this->getLockssReceivedStatus()) {
            return __('plugins.generic.pln.status.received');
        }
        return __('plugins.generic.pln.status.unknown');
    }

    /**
     * Get new deposit status
     */
    public function getNewStatus(): int
    {
        return $this->getStatus() == PlnPlugin::DEPOSIT_STATUS_NEW;
    }

    /**
     * Set new deposit status
     */
    public function setNewStatus(): void
    {
        $this->setStatus(PlnPlugin::DEPOSIT_STATUS_NEW);
        $this->setLastStatusDate(null);
        $this->setExportDepositError(null);
        $this->setStagingState(null);
        $this->setLockssState(null);
    }

    /**
     * Get a status from the bit field.
     *
     * @param int $field one of the PlnPlugin::STATUS_* constants.
     */
    protected function getStatusField(int $field): int
    {
        return $this->getStatus() & $field;
    }

    /**
     * Set a status value.
     *
     * @param boolean $value
     * @param int $field one of the PlnPlugin::STATUS_* constants.
     */
    protected function setStatusField(bool $value, int $field): void
    {
        $this->setStatus($value ? $this->getStatus() | $field : $this->getStatus() & ~$field);
    }

    /**
     * Get whether the deposit has been packaged for the PN
     */
    public function getPackagedStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_PACKAGED);
    }

    /**
     * Set whether the deposit has been packaged for the PN
     */
    public function setPackagedStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_PACKAGED);
    }

    /**
     * Get whether the PN has been notified of the available deposit
     */
    public function getTransferredStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_TRANSFERRED);
    }

    /**
     * Set whether the PN has been notified of the available deposit
     */
    public function setTransferredStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_TRANSFERRED);
    }

    /**
     * Get whether the PN has retrieved the deposit from the journal
     */
    public function getReceivedStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_RECEIVED);
    }

    /**
     * Set whether the PN has retrieved the deposit from the journal
     */
    public function setReceivedStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_RECEIVED);
    }

    /**
     * Get whether the PN has validated the deposit
     */
    public function getValidatedStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_VALIDATED);
    }

    /**
     * Set whether the PN has validated the deposit
     */
    public function setValidatedStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_VALIDATED);
    }

    /**
     * Get whether the deposit has been sent to LOCKSS
     */
    public function getSentStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_SENT);
    }

    /**
     * Set whether the deposit has been sent to LOCKSS
     */
    public function setSentStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_SENT);
    }

    /**
     * Get whether LOCKSS received the deposit
     */
    public function getLockssReceivedStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_LOCKSS_RECEIVED);
    }

    /**
     * Set whether LOCKSS received the deposit
     */
    public function setLockssReceivedStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_LOCKSS_RECEIVED);
    }

    /**
     * Get whether LOCKSS considered the deposit as preserved
     */
    public function getLockssAgreementStatus(): int
    {
        return $this->getStatusField(PlnPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT);
    }

    /**
     * Set whether LOCKSS considered the deposit as preserved
     */
    public function setLockssAgreementStatus(bool $status = true): void
    {
        $this->setStatusField($status, PlnPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT);
    }

    /**
     * Get the date of the last status change
     */
    public function getLastStatusDate(): ?string
    {
        return $this->getData('dateStatus');
    }

    /**
     * Set set the date of the last status change
     */
    public function setLastStatusDate(?string $dateLastStatus): void
    {
        $this->setData('dateStatus', $dateLastStatus);
    }

    /**
     * Get the date of deposit creation
     */
    public function getDateCreated(): ?string
    {
        return $this->getData('dateCreated');
    }

    /**
     * Set the date of deposit creation
     */
    public function setDateCreated(?string $dateCreated): void
    {
        $this->setData('dateCreated', $dateCreated);
    }

    /**
     * Get the modification date of the deposit
     */
    public function getDateModified(): ?string
    {
        return $this->getData('dateModified');
    }

    /**
     * Set the modification date of the deposit
     */
    public function setDateModified(?string $dateModified): void
    {
        $this->setData('dateModified', $dateModified);
    }

    /**
     * Set the export deposit error message.
     */
    public function setExportDepositError(?string $exportDepositError): void
    {
        $this->setData('exportDepositError', $exportDepositError);
    }

    /**
     * Get the export deposit error message.
     */
    public function getExportDepositError(): ?string
    {
        return $this->getData('exportDepositError');
    }

    /**
     * Get Displayed status locale string
     */
    public function getDisplayedStatus(): string
    {
        if (strlen((string) $this->getExportDepositError())) {
            $displayedStatus = __('plugins.generic.pln.displayedstatus.error');
        } elseif ($this->getLockssAgreementStatus()) {
            $displayedStatus = __('plugins.generic.pln.displayedstatus.completed');
        } elseif ($this->getNewStatus()) {
            $displayedStatus = __('plugins.generic.pln.displayedstatus.pending');
        } else {
            $displayedStatus = __('plugins.generic.pln.displayedstatus.inprogress');
        }

        return $displayedStatus;
    }

    /**
     * Retrieves when the deposit was preserved
     */
    public function getPreservedDate(): ?string
    {
        return $this->getData('datePreserved');
    }

    /**
     * Set the preserved date of the deposit
     */
    public function setPreservedDate(?string $date): void
    {
        $this->setData('datePreserved', $date);
    }

    /**
     * Retrieves the staging server state
     */
    public function getStagingState(): ?string
    {
        return $this->getData('stagingState');
    }

    /**
     * Sets the staging server state
     */
    public function setStagingState(?string $state): void
    {
        $this->setData('stagingState', $state);
    }

    /**
     * Retrieves the LOCKSS server state
     */
    public function getLockssState(): ?string
    {
        return $this->getData('lockssState');
    }

    /**
     * Sets the LOCKSS server state
     */
    public function setLockssState(?string $state): void
    {
        $this->setData('lockssState', $state);
    }
}
