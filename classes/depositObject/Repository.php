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
use APP\plugins\generic\pln\PLNPlugin;
use PKP\db\DAOResultFactory;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use PKP\validation\ValidatorFactory;

class Repository
{
    public DAO $dao;

    /** The name of the class to map this entity to its schema */
    public string $schemaMap = Schema::class;

    protected Request $request;

    /** @var PKPSchemaService<DepositObject> */
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
    public function validate($depositObject, $props, $allowedLocales, $primaryLocale)
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

    public function add(DepositObject $depositObject): int
    {
        $depositObjectId = $this->dao->insert($depositObject);
        $depositObject = $this->get($depositObjectId);

        Hook::call('PreservationNetwork::DepositObject::add', [$depositObject]);

        return $depositObject->getId();
    }

    public function edit(DepositObject $depositObject, array $params = [])
    {
        $newDeposit = $this->newDataObject(array_merge($depositObject->_data, $params));

        Hook::call('PreservationNetwork::DepositObject::edit', [$newDeposit, $depositObject, $params]);

        $this->dao->update($newDeposit);

        $this->get($newDeposit->getId());
    }

    public function delete(DepositObject $depositObject)
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
     *
     * @return DAOResultFactory<DepositObject>
     */
    public function getNew(int $journalId): DAOResultFactory
    {
        $result = $this->retrieve(
            'SELECT * FROM pln_deposit_objects WHERE journal_id = ? AND deposit_id = 0',
            [(int) $journalId]
        );

        return new DAOResultFactory($result, $this, 'fromRow');
    }

    /**
     * Retrieve all deposit object objects with no deposit object id.
     */
    public function markHavingUpdatedContent(int $journalId, string $objectType): void
    {
        /** @var DepositDAO */
        $depositObjectDao = DAORegistry::getDAO('DepositDAO');

        switch ($objectType) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PLNPlugin::DEPOSIT_TYPE_SUBMISSION:
                $result = $this->retrieve(
                    'SELECT pdo.deposit_object_id, s.last_modified FROM pln_deposit_objects pdo
                    JOIN submissions s ON pdo.object_id = s.submission_id
                    JOIN publications p ON p.publication_id = s.current_publication_id
                    WHERE s.context_id = ? AND pdo.journal_id = ? AND pdo.date_modified < p.last_modified',
                    [(int) $journalId, (int) $journalId]
                );
                foreach ($result as $row) {
                    $depositObject = $this->getById($journalId, $row->deposit_object_id);
                    $depositObject = $depositObjectDao->getById($depositObject->getDepositId());
                    $depositObject->setDateModified($row->last_modified);
                    $this->updateObject($depositObject);
                    $depositObject->setNewStatus();
                    $depositObjectDao->updateObject($depositObject);
                }
                break;
            case PLNPlugin::DEPOSIT_TYPE_ISSUE:
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
                    ['issueId', Submission::STATUS_PUBLISHED, (int) $journalId]
                );
                foreach ($result as $row) {
                    $depositObject = $this->getById($journalId, $row->deposit_object_id);
                    $depositObject = $depositObjectDao->getById($depositObject->getDepositId());
                    $depositObject->setDateModified(max($row->issue_modified, $row->article_modified));
                    $this->updateObject($depositObject);
                    $depositObject->setNewStatus();
                    $depositObjectDao->updateObject($depositObject);
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
            case PLNPlugin::DEPOSIT_TYPE_SUBMISSION:
                $result = $this->retrieve(
                    'SELECT p.submission_id FROM publications p
                    JOIN submissions s ON s.current_publication_id = p.publication_id
                    LEFT JOIN pln_deposit_objects pdo ON s.submission_id = pdo.object_id
                    WHERE s.journal_id = ? AND pdo.object_id is null AND p.status = ?',
                    [(int) $journalId, Submission::STATUS_PUBLISHED]
                );
                foreach ($result as $row) {
                    $objects[] = Repo::submission()->get($row->submission_id);
                }
                break;
            case PLNPlugin::DEPOSIT_TYPE_ISSUE:
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
                    $objects[] = Repo::issue()->get($row->issue_id);
                }
                break;
            default:
                throw new Exception("Invalid object type \"{$objectType}\"");
        }

        $depositObjectObjects = [];
        foreach ($objects as $object) {
            $depositObject = $this->newDataObject();
            $depositObject->setContent($object);
            $depositObject->setJournalId($journalId);
            $this->insertObject($depositObject);
            $depositObjectObjects[] = $depositObject;
        }

        return $depositObjectObjects;
    }

    /**
     * Delete deposit object objects assigned to non-existent journal/deposit IDs.
     */
    public function pruneOrphaned(): void
    {
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
