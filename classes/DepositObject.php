<?php

/**
 * @file classes/DepositObject.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositObject
 *
 * @brief Basic class describing a deposit stored in the PLN
 */

namespace APP\plugins\generic\pln\classes;

use APP\facades\Repo;
use APP\plugins\generic\pln\PLNPlugin;
use Exception;
use Issue;
use PKP\core\DataObject;
use Submission;

class DepositObject extends DataObject
{
    /**
     * Get the content object that's referenced by this deposit object
     */
    public function getContent(): Issue|Submission
    {
        return match ($this->getObjectType()) {
            PLNPlugin::DEPOSIT_TYPE_ISSUE => Repo::issue()->get($this->getObjectId(), $this->getJournalId()),
            'PublishedArticle', // Legacy (OJS pre-3.2)
            PLNPlugin::DEPOSIT_TYPE_SUBMISSION => Repo::submission()->get($this->getObjectId(), $this->getJournalId()),
            default => throw new Exception('Unknown object type')
        };
    }

    /**
     * Set the content object that's referenced by this deposit object
     */
    public function setContent(object $content): void
    {
        if (!($content instanceof Issue) && !($content instanceof Submission)) {
            throw new Exception('Unknown content type');
        }
        $this->setObjectId($content->getId());
        $this->setObjectType(get_class($content));
    }

    /**
     * Get type of the object being referenced by this deposit object
     */
    public function getObjectType(): string
    {
        return $this->getData('objectType');
    }

    /**
     * Set type of the object being referenced by this deposit object
     */
    public function setObjectType(string $objectType): void
    {
        $this->setData('objectType', $objectType);
    }

    /**
     * Get the id of the object being referenced by this deposit object
     */
    public function getObjectId(): int
    {
        return $this->getData('objectId');
    }

    /**
     * Set the id of the object being referenced by this deposit object
     */
    public function setObjectId(int $objectId): void
    {
        $this->setData('objectId', $objectId);
    }

    /**
     * Get the journal id of this deposit object
     */
    public function getJournalId(): int
    {
        return $this->getData('journalId');
    }

    /**
     * Set the journal id of this deposit object
     */
    public function setJournalId(int $journalId): void
    {
        $this->setData('journalId', $journalId);
    }

    /**
     * Get the id of the deposit which includes this deposit object
     */
    public function getDepositId(): int
    {
        return $this->getData('depositId');
    }

    /**
     * Set the id of the deposit which includes this deposit object
     */
    public function setDepositId(int $depositId): void
    {
        $this->setData('depositId', $depositId);
    }

    /**
     * Get the date of deposit object creation
     */
    public function getDateCreated(): string
    {
        return $this->getData('dateCreated');
    }

    /**
     * Set the date of deposit object creation
     */
    public function setDateCreated(string $dateCreated): void
    {
        $this->setData('dateCreated', $dateCreated);
    }

    /**
     * Get the modification date of the deposit object
     */
    public function getDateModified(): string
    {
        return $this->getData('dateModified');
    }

    /**
     * Set the modification date of the deposit object
     */
    public function setDateModified(string $dateModified): void
    {
        $this->setData('dateModified', $dateModified);
    }
}
