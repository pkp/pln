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
use APP\plugins\generic\pln\PlnPlugin;
use Illuminate\Support\LazyCollection;
use PKP\core\Core;
use PKP\file\ContextFileManager;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use PKP\validation\ValidatorFactory;

class Repository
{
    /** The name of the class to map this entity to its schema */
    public string $schemaMap = Schema::class;

    /**
     * @param PKPSchemaService<Deposit> $schemaService
     */
    public function __construct(public DAO $dao, protected Request $request, protected PKPSchemaService $schemaService)
    {
    }

    /** @copydoc DAO::newDataObject() */
    public function newDataObject(array $params = []): Deposit
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
    public function validate($deposit, $props, $allowedLocales, $primaryLocale): array
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

    /**
     * Adds a new deposit
     */
    public function add(Deposit $deposit): int
    {
        if (!$deposit->getDateCreated()) {
            $deposit->setDateCreated(Core::getCurrentDate());
        }
        $depositId = $this->dao->insert($deposit);
        $deposit = $this->get($depositId);

        Hook::call('PreservationNetwork::Deposit::add', [$deposit]);

        return $deposit->getId();
    }

    /**
     * Edits a deposit
     */
    public function edit(Deposit $deposit, array $params = []): void
    {
        $newDeposit = $this->newDataObject(array_merge($deposit->_data, $params));

        Hook::call('PreservationNetwork::Deposit::edit', [$newDeposit, $deposit, $params]);

        $this->dao->update($newDeposit);

        $this->get($newDeposit->getId());
    }

    /**
     * Deletes a deposit
     */
    public function delete(Deposit $deposit): bool
    {
        Hook::call('PreservationNetwork::Deposit::delete::before', [$deposit]);

        $fileManager = new ContextFileManager($deposit->getJournalId());
        $path = $fileManager->getBasePath() . PlnPlugin::DEPOSIT_FOLDER . "/{$deposit->getUUID()}";
        if (!$fileManager->rmtree($path)) {
            return false;
        }

        $this->dao->delete($deposit);

        Hook::call('PreservationNetwork::Deposit::delete', [$deposit]);

        return true;
    }

    /**
     * Retrieves an instance o this repository
     */
    public static function instance(): static
    {
        return app(static::class);
    }

    /**
     * Retrieve deposits which match the new status
     *
     * @return LazyCollection<int,Deposit>
     */
    public function getNew(int $contextId): LazyCollection
    {
        $collector = $this->getCollector();
        $collector->filterByContextIds([$contextId])
            ->filterByStatus($collector::STATUS_NEW);
        return $this->dao->getMany($collector);
    }

    /**
     * Retrieve deposits that need to be transferred
     *
     * @return LazyCollection<int,Deposit>
     */
    public function getNeedTransferring(int $contextId): LazyCollection
    {
        $collector = $this->getCollector();
        $collector->filterByContextIds([$contextId])
            ->filterByStatus($collector::STATUS_READY_TO_TRANSFER);
        return $this->dao->getMany($collector);
    }

    /**
     * Retrieve deposits that need packaging
     *
     * @return LazyCollection<int,Deposit>
     */
    public function getNeedPackaging(int $contextId): LazyCollection
    {
        $collector = $this->getCollector();
        $collector->filterByContextIds([$contextId])
            ->filterByStatus($collector::STATUS_READY_TO_PACKAGE);
        return $this->dao->getMany($collector);
    }

    /**
     * Retrieve deposits that need a status update
     *
     * @return LazyCollection<int,Deposit>
     */
    public function getNeedStagingStatusUpdate(int $contextId): LazyCollection
    {
        $collector = $this->getCollector();
        $collector->filterByContextIds([$contextId])
            ->filterByStatus($collector::STATUS_READY_FOR_UPDATE);
        return $this->dao->getMany($collector);
    }

    /**
     * Delete deposits assigned to non-existent journal IDs.
     *
     * @return int[] Deposit IDs which failed to be removed
     */
    public function pruneOrphaned(): array
    {
        $deposits = $this->dao->getOrphaned($this->getCollector());
        $failedIds = [];
        foreach ($deposits as $deposit) {
            if (!$this->delete($deposit)) {
                $failedIds[] = $deposit->getId();
            }
        }
        return $failedIds;
    }
}
