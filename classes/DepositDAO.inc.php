<?php

/**
 * @file classes/DepositDAO.inc.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositDAO
 * @brief Operations for adding a PLN deposit
 */

class DepositDAO extends \PKP\db\DAO {
	/**
	 * Construct a new deposit object.
	 * @return Deposit
	 */
	public function newDataObject() {
		return new Deposit(null);
	}

	/**
	 * Retrieve deposit by ID.
	 * @param int $depositId
	 * @param int $journalId optional
	 * @return Deposit Object
	 */
	public function getById($depositId, $journalId = null) {
		$params = [(int) $depositId];
		if ($journalId !== null) $params[] = (int) $journalId;
		$result = $this->retrieve(
			'SELECT *
			FROM pln_deposits
			WHERE deposit_id = ?'
			. ($journalId !== null?' AND journal_id = ?':''),
			$params
		);

		$row = $result->current();
		if ($row) return $this->_fromRow((array) $row);

		return $row;
	}

	/**
	 * Insert deposit object
	 * @param Deposit $deposit
	 * @return int inserted Deposit id
	 */
	public function insertObject($deposit) {
		$this->update(
			sprintf('
				INSERT INTO pln_deposits
					(journal_id,
					uuid,
					status,
					date_status,
					date_created,
					date_modified)
				VALUES
					(?, ?, ?, %s, NOW(), %s)',
				$this->datetimeToDB($deposit->getLastStatusDate()),
				$this->datetimeToDB($deposit->getDateModified())
			),
			[
				(int) $deposit->getJournalId(),
				$deposit->getUUID(),
				(int) $deposit->getStatus()
			]
		);
		$deposit->setId($this->getInsertId());
		return $deposit->getId();
	}

	/**
	 * Update deposit
	 * @param Deposit $deposit
	 */
	public function updateObject($deposit) {
		$this->update(
			sprintf('
				UPDATE pln_deposits SET
					journal_id = ?,
					uuid = ?,
					status = ?,
					date_status = %s,
					date_created = %s,
					date_modified = NOW(),
					export_deposit_error = ?
				WHERE deposit_id = ?',
				$this->datetimeToDB($deposit->getLastStatusDate()),
				$this->datetimeToDB($deposit->getDateCreated())
			),
			[
				(int) $deposit->getJournalId(),
				$deposit->getUUID(),
				(int) $deposit->getStatus(),
				$deposit->getExportDepositError(),
				(int) $deposit->getId()
			]
		);
	}

	/**
	 * Delete deposit
	 * @param Deposit $deposit
	 * @return bool True on success
	 */
	public function deleteObject($deposit) {
		if (!(new DepositPackage($deposit))->remove()) {
			return false;
		}

		$this->update('DELETE from pln_deposits WHERE deposit_id = ?', [(int) $deposit->getId()]);
		return true;
	}

	/**
	 * Get the ID of the last inserted deposit.
	 * @return int
	 */
	public function getInsertId(): int {
		return $this->_getInsertId('pln_deposits', 'deposit_id');
	}

	/**
	 * Internal function to return a deposit from a row.
	 * @param array $row
	 * @return Deposit
	 */
	public function _fromRow($row) {
		$deposit = $this->newDataObject();
		$deposit->setId($row['deposit_id']);
		$deposit->setJournalId($row['journal_id']);
		$deposit->setUUID($row['uuid']);
		$deposit->setStatus($row['status']);
		$deposit->setLastStatusDate($this->datetimeFromDB($row['date_status']));
		$deposit->setDateCreated($this->datetimeFromDB($row['date_created']));
		$deposit->setDateModified($this->datetimeFromDB($row['date_modified']));
		$deposit->setExportDepositError($row['export_deposit_error']);

		HookRegistry::call('DepositDAO::_fromRow', [&$deposit, &$row]);

		return $deposit;
	}

	/**
	 * Retrieve a deposit by deposit uuid and journal id.
	 * @param int $journalId
	 * @param string $depositUuid
	 * @return Deposit
	 */
	public function getByUUID($journalId, $depositUuid) {
		$result = $this->retrieve(
			'SELECT *
			FROM pln_deposits
			WHERE journal_id = ?
			AND uuid = ?',
			[(int) $journalId, $depositUuid]
		);

		$row = $result->current();
		if ($row) return $this->_fromRow((array) $row);

		return $row;
	}

	/**
	 * Retrieve all deposits.
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getByJournalId($journalId, $dbResultRange = null) {
		$params[] = $journalId;

		$result = $this->retrieveRange(
			$sql = 'SELECT *
			FROM pln_deposits
			WHERE journal_id = ?
			ORDER BY deposit_id',
			$params,
			$dbResultRange
		);

		return new DAOResultFactory($result, $this, '_fromRow', [], $sql, $params, $dbResultRange);
	}

	/**
	 * Retrieve all newly-created deposits (ones with new status)
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getNew($journalId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposits WHERE journal_id = ? AND status = ?',
			[(int) $journalId, (int) PLN_PLUGIN_DEPOSIT_STATUS_NEW]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposits that need packaging
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getNeedTransferring($journalId) {
		$result = $this->retrieve(
			'SELECT *
			FROM pln_deposits AS d
			WHERE d.journal_id = ?
			AND d.status & ? = 0
			AND d.status & ? = 0
			AND d.status & ? = 0
			ORDER BY d.export_deposit_error, d.deposit_id',
			[
				(int) $journalId,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_PACKAGING_FAILED,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT
			]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposits that need packaging
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getNeedPackaging($journalId) {
		$result = $this->retrieve(
			'SELECT *
			FROM pln_deposits AS d
			WHERE d.journal_id = ?
			AND d.status & ? = 0
			AND d.status & ? = 0
			AND d.status & ? = 0
			ORDER BY d.export_deposit_error, d.deposit_id',
			[
				(int) $journalId,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_PACKAGING_FAILED
			]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposits that need a status update
	 * @param int $journalId
	 * @return DAOResultFactory
	 */
	public function getNeedStagingStatusUpdate($journalId) {
		$result = $this->retrieve(
			'SELECT *
			FROM pln_deposits AS d
			WHERE d.journal_id = ?
			AND d.status & ? <> 0
			AND d.status & ? = 0
			AND d.status & ? = 0
			ORDER BY d.export_deposit_error, d.deposit_id',
			[
				(int) $journalId,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT,
				(int) PLN_PLUGIN_DEPOSIT_STATUS_PACKAGING_FAILED
			]
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Delete deposits assigned to non-existent journal IDs.
	 * @return int[] Deposit IDs which failed to be removed
	 */
	public function pruneOrphaned() {
		$result = $this->retrieveRange(
			$sql = 'SELECT *
			FROM pln_deposits
			WHERE journal_id NOT IN (
				SELECT journal_id
				FROM journals
			)
			ORDER BY deposit_id'
		);
		$failedIds = [];
		$deposits = new DAOResultFactory($result, $this, '_fromRow', [], $sql);
		/** @var Deposit */
		foreach($deposits->toIterator() as $deposit) {
			if (!$this->deleteObject($deposit)) {
				$failedIds[] = $deposit->getId();
			}
		}
		return $failedIds;
	}
}
