<?php
/**
 * @file classes/depositObject/Repository.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Repository
 *
 * @brief A repository to find and manage deposit objects.
 */

namespace APP\plugins\generic\pln\classes\depositObject;

use APP\core\Request;
use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\generic\pln\classes\deposit\Repository as DepositRepository;
use APP\plugins\generic\pln\PlnPlugin;
use Exception;
use PKP\core\Core;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use PKP\validation\ValidatorFactory;

class Repository
{
    /** The name of the class to map this entity to its schema */
    public string $schemaMap = Schema::class;

    /**
     * @param PKPSchemaService<DepositObject> $schemaService
     */
    public function __construct(public DAO $dao, protected Request $request, protected PKPSchemaService $schemaService)
    {
    }

    /** @copydoc DAO::newDataObject() */
    public function newDataObject(array $params = []): DepositObject
    {
        $object = $this->dao->newDataObject();
        if (!empty($params)) {
            $object->setAllData($params);
        }
        return $object;
    }

    /** @copydoc DAO::get() */
    public function get(int $id, int $contextId = null): ?DepositObject
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
     * Get an instance of the map class for mapping deposit objects to their schema
     */
    public function getSchemaMap(): Schema
    {
        return app('maps')->withExtensions($this->schemaMap);
    }

    /**
     * Validate properties for a deposit object
     *
     * Perform validation checks on data used to add or edit a deposit object.
     *
     * @param DepositObject|null $depositObject The deposit object being edited. Pass `null` if creating a new deposit object
     * @param array $props A key/value array with the new data to validate
     * @param array $allowedLocales The context's supported submission locales
     * @param string $primaryLocale The submission's primary locale
     *
     * @return array A key/value array with validation errors. Empty if no errors
     */
    public function validate(?DepositObject $depositObject, array $props, array $allowedLocales, string $primaryLocale): array
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
            $depositObject,
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

        Hook::call('PreservationNetwork::DepositObject::validate', [$errors, $depositObject, $props, $allowedLocales, $primaryLocale]);

        return $errors;
    }

    /**
     * Adds a deposit object
     */
    public function add(DepositObject $depositObject): int
    {
        if (!$depositObject->getDateCreated()) {
            $depositObject->setDateCreated(Core::getCurrentDate());
        }
        $depositObjectId = $this->dao->insert($depositObject);
        $depositObject = $this->get($depositObjectId);

        Hook::call('PreservationNetwork::DepositObject::add', [$depositObject]);

        return $depositObject->getId();
    }

    /**
     * Edits a deposit object
     */
    public function edit(DepositObject $depositObject, array $params = []): void
    {
        $newDeposit = $this->newDataObject(array_merge($depositObject->_data, $params));

        Hook::call('PreservationNetwork::DepositObject::edit', [$newDeposit, $depositObject, $params]);

        $this->dao->update($newDeposit);

        $this->get($newDeposit->getId());
    }

    /**
     * Deletes a deposit object
     */
    public function delete(DepositObject $depositObject): void
    {
        Hook::call('PreservationNetwork::DepositObject::delete::before', [$depositObject]);

        $this->dao->delete($depositObject);

        Hook::call('PreservationNetwork::DepositObject::delete', [$depositObject]);
    }

    /**
     * Retrieves an instance o this repository
     */
    public static function instance(): static
    {
        return app(static::class);
    }

    /**
     * Retrieve all deposit object objects with no deposit object id.
     */
    public function markHavingUpdatedContent(int $journalId, string $objectType): void
    {
        switch ($objectType) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PlnPlugin::DEPOSIT_TYPE_SUBMISSION:
                $outdatedSubmissions = $this->dao->getOutdatedSubmissions(
                    Repo::submission()->getCollector()->filterByContextIds([$journalId])
                );
                foreach ($outdatedSubmissions as $row) {
                    $depositObject = $this->get($row->deposit_object_id, $journalId);
                    $depositObject->setDateModified($row->last_modified);
                    $this->edit($depositObject);
                    $deposit = DepositRepository::instance()->get($depositObject->getDepositId());
                    $deposit->setNewStatus();
                    DepositRepository::instance()->edit($deposit);
                }
                break;
            case PlnPlugin::DEPOSIT_TYPE_ISSUE:
                $outdatedIssues = $this->dao->getOutdatedIssues(
                    Repo::issue()->getCollector()->filterByContextIds([$journalId])
                );
                foreach ($outdatedIssues as $row) {
                    $depositObject = $this->get($row->deposit_object_id, $journalId);
                    $depositObject->setDateModified(max($row->issue_modified, $row->article_modified));
                    $this->edit($depositObject);
                    $deposit = DepositRepository::instance()->get($depositObject->getDepositId());
                    $deposit->setNewStatus();
                    DepositRepository::instance()->edit($deposit);
                }
                break;
            default:
                throw new Exception("Invalid object type \"{$objectType}\"");
        }
    }

    /**
     * Create a new deposit object object for OJS content that doesn't yet have one
     *
     * @return DepositObject[] Deposit objects ordered by sequence
     */
    public function createNew(int $journalId, string $objectType): array
    {
        $objects = [];
        switch ($objectType) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PlnPlugin::DEPOSIT_TYPE_SUBMISSION:
                $objects = $this->dao
                    ->getNewSubmissions(
                        Repo::submission()
                            ->getCollector()
                            ->filterByContextIds([$journalId])
                    )
                    ->toArray();
                break;
            case PlnPlugin::DEPOSIT_TYPE_ISSUE:
                $objects = $this->dao
                    ->getNewIssues(
                        Repo::issue()
                            ->getCollector()
                            ->filterByContextIds([$journalId])
                    )->toArray();
                break;
            default:
                throw new Exception("Invalid object type \"{$objectType}\"");
        }

        $depositObjects = [];
        foreach ($objects as $object) {
            $depositObject = $this->newDataObject();
            $depositObject->setContent($object);
            $depositObject->setJournalId($journalId);
            $this->add($depositObject);
            $depositObjects[] = $depositObject;
        }

        return $depositObjects;
    }

    /**
     * Delete deposit object objects assigned to non-existent journal/deposit IDs.
     */
    public function pruneOrphaned(): void
    {
        $this->dao->pruneOrphaned();
    }
}
