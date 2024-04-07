<?php

/**
 * @file classes/deposit/DAO.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DAO
 *
 * @see Deposit
 *
 * @brief Operations for retrieving and modifying deposit objects.
 */

namespace APP\plugins\generic\pln\classes\deposit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PKP\core\EntityDAO;
use PKP\core\traits\EntityWithParent;

/**
 * @template T of Deposit
 *
 * @extends EntityDAO<T>
 */
class DAO extends EntityDAO
{
    use EntityWithParent;

    /** @copydoc EntityDAO::$schema */
    public $schema = 'preservationNetworkDeposit';

    /** @copydoc EntityDAO::$table */
    public $table = 'pln_deposits';

    /** @copydoc EntityDAO::$primaryKeyColumn */
    public $primaryKeyColumn = 'deposit_id';

    /** @copydoc EntityDAO::$primaryTableColumns */
    public $primaryTableColumns = [
        'id' => 'deposit_id',
        'journalId' => 'journal_id',
        'uuid' => 'uuid',
        'status' => 'status',
        'stagingState' => 'staging_state',
        'lockssState' => 'lockss_state',
        'dateStatus' => 'date_status',
        'dateCreated' => 'date_created',
        'dateModified' => 'date_modified',
        'exportDepositError' => 'export_deposit_error',
        'datePreserved' => 'date_preserved',
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
    public function newDataObject(): Deposit
    {
        return app(Deposit::class);
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
            ->select('d.' . $this->primaryKeyColumn)
            ->pluck('d.' . $this->primaryKeyColumn);
    }

    /**
     * Get a collection of publications matching the configured query
     *
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
    public function fromRow(object $row): Deposit
    {
        $deposit = parent::fromRow($row);

        return $deposit;
    }

    /**
     * @copydoc EntityDAO::insert()
     */
    public function insert(Deposit $deposit): int
    {
        return parent::_insert($deposit);
    }

    /**
     * @copydoc EntityDAO::update()
     */
    public function update(Deposit $deposit): void
    {
        parent::_update($deposit);
    }

    /**
     * @copydoc EntityDAO::delete()
     */
    public function delete(Deposit $deposit): void
    {
        parent::_delete($deposit);
    }

    /**
     * Get a collection of orphaned deposits
     *
     * @return LazyCollection<int,T>
     */
    public function getOrphaned(Collector $query): LazyCollection
    {
        $rows = $query
            ->getQueryBuilder()
            ->whereNotIn(
                'd.journal_id',
                fn (Builder $q) => $q
                    ->from('journals AS j')
                    ->select('j.journal_id')
            )
            ->get();

        return LazyCollection::make(function () use ($rows) {
            foreach ($rows as $row) {
                yield $row->deposit_id => $this->fromRow($row);
            }
        });
    }
}
