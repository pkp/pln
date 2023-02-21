<?php

/**
 * @file classes/DepositObjectDAO.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositObjectDAO
 * @brief Operations for adding a PLN deposit object
 */

use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\plugins\Hook;
use \PKP\submission\PKPSubmission;

class DepositObjectDAO extends DAO {
	/**
	 * Retrieve a deposit object by deposit object id.
	 * @param int $journalId
	 * @param int $depositObjectId
	 * @return DepositObject
	 */
	public function getById($journalId, $depositObjectId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? and deposit_object_id = ?',
			[(int) $journalId, (int) $depositObjectId]
		);

		$row = $result->current();
		if ($row) return $this->_fromRow((array) $row);

		return $row;
	}

	/**
	 * Retrieve all deposit objects by deposit id.
	 * @param int $journalId
	 * @param int $depositId
	 * @return DAOResultFactory
	 */
	public function getByDepositId($journalId, $depositId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? AND deposit_id = ?',
			[(int) $journalId, (int) $depositId]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposit objects with no deposit id.
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getNew($journalId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? AND deposit_id = 0',
			[(int) $journalId]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposit objects with no deposit id.
	 * @param int $journalId
	 * @param string $objectType
	 */
	public function markHavingUpdatedContent($journalId, $objectType) {
		/** @var DepositDAO */
		$depositDao = DAORegistry::getDAO('DepositDAO');

		switch ($objectType) {
			case 'PublishedArticle': // Legacy (OJS pre-3.2)
			case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
				$result = $this->retrieve(
					'SELECT pdo.deposit_object_id, s.last_modified FROM pln_deposit_objects pdo
					JOIN submissions s ON pdo.object_id = s.submission_id
					JOIN publications p ON p.publication_id = s.current_publication_id
					WHERE s.context_id = ? AND pdo.journal_id = ? AND pdo.date_modified < p.last_modified',
					[(int) $journalId, (int) $journalId]
				);
				foreach ($result as $row) {
					$depositObject = $this->getById($journalId, $row->deposit_object_id);
					$deposit = $depositDao->getById($depositObject->getDepositId());
					$depositObject->setDateModified($row->last_modified);
					$this->updateObject($depositObject);
					$deposit->setNewStatus();
					$depositDao->updateObject($deposit);
				}
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				$result = $this->retrieve(
					'SELECT pdo.deposit_object_id, MAX(i.last_modified) as issue_modified, MAX(p.last_modified) as article_modified
					FROM issues i
					JOIN pln_deposit_objects pdo ON pdo.object_id = i.issue_id
					JOIN publication_settings ps ON (CAST(i.issue_id AS CHAR) = ps.setting_value AND ps.setting_name = ?)
					JOIN publications p ON (p.publication_id = ps.publication_id AND p.status = ?)
					JOIN submissions s ON s.current_publication_id = p.publication_id
					WHERE (pdo.date_modified < p.last_modified OR pdo.date_modified < i.last_modified)
					AND (pdo.journal_id = ?)
					GROUP BY pdo.deposit_object_id',
					['issueId', PKPSubmission::STATUS_PUBLISHED, (int) $journalId]
				);
				foreach ($result as $row) {
					$depositObject = $this->getById($journalId, $row->deposit_object_id);
					$deposit = $depositDao->getById($depositObject->getDepositId());
					$depositObject->setDateModified(max($row->issue_modified, $row->article_modified));
					$this->updateObject($depositObject);
					$deposit->setNewStatus();
					$depositDao->updateObject($deposit);
				}
				break;
			default:
				throw new Exception("Invalid object type \"{$objectType}\"");
		}
	}

	/**
	 * Create a new deposit object for OJS content that doesn't yet have one
	 *
	 * @param int $journalId
	 * @param string $objectType
	 * @return array DepositObject ordered by sequence
	 */
	public function createNew($journalId, $objectType) {
		$objects = array();

		switch ($objectType) {
			case 'PublishedArticle': // Legacy (OJS pre-3.2)
			case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
				/** @var SubmissionDAO */
				$submissionDao = DAORegistry::getDAO('SubmissionDAO'); // Constants
				$result = $this->retrieve(
					'SELECT p.submission_id FROM publications p
					JOIN submissions s ON s.current_publication_id = p.publication_id
					LEFT JOIN pln_deposit_objects pdo ON s.submission_id = pdo.object_id
					WHERE s.journal_id = ? AND pdo.object_id is null AND p.status = ?',
					[(int) $journalId, PKPSubmission::STATUS_PUBLISHED]
				);
				foreach ($result as $row) {
					$objects[] = $submissionDao->getById($row->submission_id);
				}
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				/** @var IssueDAO */
				$issueDao = DAORegistry::getDAO('IssueDAO');
				$result = $this->retrieve(
					'SELECT i.issue_id
					FROM issues i
					LEFT JOIN pln_deposit_objects pdo ON pdo.object_id = i.issue_id
					WHERE i.journal_id = ?
					AND i.published = 1
					AND pdo.object_id is null',
					[(int) $journalId]
				);
				foreach ($result as $row) {
					$objects[] = $issueDao->getById($row->issue_id);
				}
				break;
			default:
				throw new Exception("Invalid object type \"{$objectType}\"");
		}

		$depositObjects = array();
		foreach($objects as $object) {
			$depositObject = $this->newDataObject();
			$depositObject->setContent($object);
			$depositObject->setJournalId($journalId);
			$this->insertObject($depositObject);
			$depositObjects[] = $depositObject;
		}

		return $depositObjects;
	}

	/**
	 * Insert deposit object
	 * @param DepositObject $depositObject
	 * @return int inserted DepositObject id
	 */
	public function insertObject($depositObject) {
		$this->update(
			sprintf('
				INSERT INTO pln_deposit_objects
					(journal_id,
					object_id,
					object_type,
					deposit_id,
					date_created,
					date_modified)
				VALUES
					(?, ?, ?, ?, NOW(), %s)',
				$this->datetimeToDB($depositObject->getDateModified())
			),
			[
				(int) $depositObject->getJournalId(),
				(int) $depositObject->getObjectId(),
				$depositObject->getObjectType(),
				(int)$depositObject->getDepositId()
			]
		);

		$depositObject->setId($this->getInsertId());
		return $depositObject->getId();
	}

	/**
	 * Update deposit object
	 * @param DepositObject $depositObject
	 */
	public function updateObject($depositObject) {
		$this->update(
			sprintf('
				UPDATE pln_deposit_objects SET
					journal_id = ?,
					object_type = ?,
					object_id = ?,
					deposit_id = ?,
					date_created = %s,
					date_modified = NOW()
				WHERE deposit_object_id = ?',
				$this->datetimeToDB($depositObject->getDateCreated())
			),
			[
				(int) $depositObject->getJournalId(),
				$depositObject->getObjectType(),
				(int) $depositObject->getObjectId(),
				(int) $depositObject->getDepositId(),
				(int) $depositObject->getId()
			]
		);
	}

	/**
	 * Get the ID of the last inserted deposit object.
	 * @return int
	 */
	public function getInsertId(): int {
		return $this->_getInsertId();
	}

	/**
	 * Construct a new data object corresponding to this DAO.
	 * @return DepositObject
	 */
	public function newDataObject() {
		return new DepositObject();
	}

	/**
	 * Internal function to return a deposit object from a row.
	 * @param array $row
	 * @return DepositObject
	 */
	public function _fromRow($row) {
		$depositObject = $this->newDataObject();
		$depositObject->setId($row['deposit_object_id']);
		$depositObject->setJournalId($row['journal_id']);
		$depositObject->setObjectType($row['object_type']);
		$depositObject->setObjectId($row['object_id']);
		$depositObject->setDepositId($row['deposit_id']);
		$depositObject->setDateCreated($this->datetimeFromDB($row['date_created']));
		$depositObject->setDateModified($this->datetimeFromDB($row['date_modified']));

		Hook::call('DepositObjectDAO::_fromRow', [&$depositObject, &$row]);

		return $depositObject;
	}

	/**
	 * Delete deposit objects assigned to non-existent journal/deposit IDs.
	 */
	public function pruneOrphaned() {
		$this->update(
			'DELETE
			FROM pln_deposit_objects
			WHERE
				journal_id NOT IN (
					SELECT journal_id
					FROM journals
				)
				OR deposit_id NOT IN (
					SELECT deposit_id
					FROM pln_deposits
				)'
		);
	}
}