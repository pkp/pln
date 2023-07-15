<?php

/**
 * @file classes/depositObject/DAO.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DAO
 *
 * @see DepositObject
 *
 * @brief Operations for retrieving and modifying deposit object objects.
 */

namespace APP\plugins\generic\pln\classes\depositObject;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PKP\core\EntityDAO;
use PKP\core\traits\EntityWithParent;

/**
 * @template T of DepositObject
 * @extends EntityDAO<T>
 */
class DAO extends EntityDAO
{
    use EntityWithParent;

    /** @copydoc EntityDAO::$schema */
    public $schema = 'preservationNetworkDepositObject';

    /** @copydoc EntityDAO::$table */
    public $table = 'pln_deposit_objects';

    /** @copydoc EntityDAO::$primaryKeyColumn */
    public $primaryKeyColumn = 'deposit_object_id';

    /** @copydoc EntityDAO::$primaryTableColumns */
    public $primaryTableColumns = [
        'id' => 'deposit_object_id',
        'journalId' => 'journal_id',
        'objectId' => 'object_id',
        'objectType' => 'object_type',
        'depositId' => 'deposit_id',
        'dateCreated' => 'date_created',
        'dateModified' => 'date_modified'
    ];

    /**
     * Get the parent object ID column name
     */
    public function getParentColumn(): string
    {
        return 'journal_id';
    }

    /**
     * Instantiate a new DataObject
     */
    public function newDataObject(): DepositObject
    {
        return app(DepositObject::class);
    }

    /**
     * Get the total count of rows matching the configured query
     */
    public function getCount(Collector $query): int
    {
        return $query
            ->getQueryBuilder()
            ->count();
    }

    /**
     * Get a list of ids matching the configured query
     *
     * @return Collection<int,int>
     */
    public function getIds(Collector $query): Collection
    {
        return $query
            ->getQueryBuilder()
            ->select('do.' . $this->primaryKeyColumn)
            ->pluck('do.' . $this->primaryKeyColumn);
    }

    /**
     * Get a collection of publications matching the configured query
     * @return LazyCollection<int,T>
     */
    public function getMany(Collector $query): LazyCollection
    {
        $rows = $query
            ->getQueryBuilder()
            ->get();

        return LazyCollection::make(function () use ($rows) {
            foreach ($rows as $row) {
                yield $row->deposit_id => $this->fromRow($row);
            }
        });
    }

    /**
     * @copydoc EntityDAO::fromRow()
     */
    public function fromRow(object $row): DepositObject
    {
        $depositObject = parent::fromRow($row);

        return $depositObject;
    }

    /**
     * @copydoc EntityDAO::insert()
     */
    public function insert(DepositObject $depositObject): int
    {
        return parent::_insert($depositObject);
    }

    /**
     * @copydoc EntityDAO::update()
     */
    public function update(DepositObject $depositObject)
    {
        parent::_update($depositObject);
    }

    /**
     * @copydoc EntityDAO::delete()
     */
    public function delete(DepositObject $depositObject)
    {
        parent::_delete($depositObject);
    }
}
