<?php
/**
 * @file classes/deposit/Repository.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Repository
 *
 * @brief A repository to find and manage deposits.
 */

namespace APP\plugins\generic\pln\classes\deposit;

use APP\core\Request;
use APP\core\Services;
use APP\plugins\generic\pln\classes\DepositPackage;
use APP\plugins\generic\pln\PLNPlugin;
use PKP\db\DAOResultFactory;
use PKP\db\DBResultRange;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use PKP\validation\ValidatorFactory;

class Repository
{
    public DAO $dao;

    /** The name of the class to map this entity to its schema */
    public string $schemaMap = Schema::class;

    protected Request $request;

    /** @var PKPSchemaService<Deposit> */
    protected PKPSchemaService $schemaService;

    public function __construct(DAO $dao, Request $request, PKPSchemaService $schemaService)
    {
        $this->dao = $dao;
        $this->request = $request;
        $this->schemaService = $schemaService;
    }

    /** @copydoc DAO::newDataObject() */
    public function newDataObject(array $params = [])
    {
        $object = $this->dao->newDataObject();
        if (!empty($params)) {
            $object->setAllData($params);
        }
        return $object;
    }

    /** @copydoc DAO::get() */
    public function get(int $id, int $contextId = null): ?Deposit
    {
        return $this->dao->get($id, $contextId);
    }

    /** @copydoc DAO::exists() */
    public function exists(int $id, int $contextId = null): bool
    {
        return $this->dao->exists($id, $contextId);
    }

    /**
     * Retrieves the collector
     */
    public function getCollector(): Collector
    {
        return app(Collector::class);
    }

    /**
     * Get an instance of the map class for mapping deposits to their schema
     */
    public function getSchemaMap(): Schema
    {
        return app('maps')->withExtensions($this->schemaMap);
    }

    /**
     * Validate properties for a deposit
     *
     * Perform validation checks on data used to add or edit a deposit.
     *
     * @param Deposit|null $deposit The deposit being edited. Pass `null` if creating a new deposit
     * @param array $props A key/value array with the new data to validate
     * @param array $allowedLocales The context's supported submission locales
     * @param string $primaryLocale The submission's primary locale
     *
     * @return array A key/value array with validation errors. Empty if no errors
     */
    public function validate($deposit, $props, $allowedLocales, $primaryLocale)
    {
        /** @var PKPSchemaService */
        $schemaService = Services::get('schema');

        $validator = ValidatorFactory::make(
            $props,
            $schemaService->getValidationRules(Schema::SCHEMA_NAME, $allowedLocales)
        );

        // Check required fields
        ValidatorFactory::required(
            $validator,
            $deposit,
            $schemaService->getRequiredProps(Schema::SCHEMA_NAME),
            $schemaService->getMultilingualProps(Schema::SCHEMA_NAME),
            $allowedLocales,
            $primaryLocale
        );

        // Check for input from disallowed locales
        ValidatorFactory::allowedLocales($validator, $schemaService->getMultilingualProps(Schema::SCHEMA_NAME), $allowedLocales);

        $errors = [];
        if ($validator->fails()) {
            $errors = $schemaService->formatValidationErrors($validator->errors());
        }

        Hook::call('PreservationNetwork::Deposit::validate', [$errors, $deposit, $props, $allowedLocales, $primaryLocale]);

        return $errors;
    }

    public function add(Deposit $deposit): int
    {
        $depositId = $this->dao->insert($deposit);
        $deposit = $this->get($depositId);

        Hook::call('PreservationNetwork::Deposit::add', [$deposit]);

        return $deposit->getId();
    }

    public function edit(Deposit $deposit, array $params = [])
    {
        $newDeposit = $this->newDataObject(array_merge($deposit->_data, $params));

        Hook::call('PreservationNetwork::Deposit::edit', [$newDeposit, $deposit, $params]);

        $this->dao->update($newDeposit);

        $this->get($newDeposit->getId());
    }

    public function delete(Deposit $deposit)
    {
        Hook::call('PreservationNetwork::Deposit::delete::before', [$deposit]);

        $this->dao->delete($deposit);

        Hook::call('PreservationNetwork::Deposit::delete', [$deposit]);
    }

    /**
     * Retrieves an instance o this repository
     */
    public static function instance(): static
    {
        return app(static::class);
    }

    /**
     * Retrieve all newly-created deposits (ones with new status)
     *
     * @return DAOResultFactory<Deposit>
     */
    public function getNew(int $journalId): DAOResultFactory
    {
        $result = $this->retrieve(
            'SELECT * FROM pln_deposits WHERE journal_id = ? AND status = ?',
            [(int) $journalId, (int) PLNPlugin::DEPOSIT_STATUS_NEW]
        );

        return new DAOResultFactory($result, $this, 'fromRow');
    }

    /**
     * Retrieve all deposits that need to be transferred
     *
     * @return DAOResultFactory<Deposit>
     */
    public function getNeedTransferring(int $journalId): DAOResultFactory
    {
        $result = $this->retrieve(
            'SELECT *
            FROM pln_deposits AS d
            WHERE d.journal_id = ?
            AND d.status & ? <> 0
            AND d.status & ? = 0
            ORDER BY d.export_deposit_error, d.deposit_id',
            [
                (int) $journalId,
                (int) PLNPlugin::DEPOSIT_STATUS_PACKAGED,
                (int) PLNPlugin::DEPOSIT_STATUS_TRANSFERRED
            ]
        );

        return new DAOResultFactory($result, $this, 'fromRow');
    }

    /**
     * Retrieve all deposits that need packaging
     *
     * @return DAOResultFactory<Deposit>
     */
    public function getNeedPackaging(int $journalId): DAOResultFactory
    {
        $result = $this->retrieve(
            'SELECT *
            FROM pln_deposits AS d
            WHERE d.journal_id = ?
            AND d.status & ? = 0
            ORDER BY d.export_deposit_error, d.deposit_id',
            [
                (int) $journalId,
                (int) PLNPlugin::DEPOSIT_STATUS_PACKAGED
            ]
        );

        return new DAOResultFactory($result, $this, 'fromRow');
    }

    /**
     * Retrieve all deposits that need a status update
     *
     * @return DAOResultFactory<Deposit>
     */
    public function getNeedStagingStatusUpdate(int $journalId): DAOResultFactory
    {
        $result = $this->retrieve(
            'SELECT *
            FROM pln_deposits AS d
            WHERE d.journal_id = ?
            AND (
                d.status IS NULL
                OR (
                    d.status & ? <> 0
                    AND d.status & ? = 0
                )
            )
            ORDER BY d.export_deposit_error, d.deposit_id',
            [
                (int) $journalId,
                (int) PLNPlugin::DEPOSIT_STATUS_TRANSFERRED,
                (int) PLNPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT
            ]
        );

        return new DAOResultFactory($result, $this, 'fromRow');
    }

    /**
     * Delete deposits assigned to non-existent journal IDs.
     *
     * @return int[] Deposit IDs which failed to be removed
     */
    public function pruneOrphaned(): array
    {
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
        $deposits = new DAOResultFactory($result, $this, 'fromRow', [], $sql);
        /** @var Deposit */
        foreach ($deposits->toIterator() as $deposit) {
            if (!$this->deleteObject($deposit)) {
                $failedIds[] = $deposit->getId();
            }
        }
        return $failedIds;
    }
}
