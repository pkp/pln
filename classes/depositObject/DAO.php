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

use APP\facades\Repo;
use APP\plugins\generic\pln\PlnPlugin;
use APP\submission\Submission;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PKP\core\EntityDAO;
use PKP\core\traits\EntityWithParent;

/**
 * @template T of DepositObject
 *
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
    public function update(DepositObject $depositObject): void
    {
        parent::_update($depositObject);
    }

    /**
     * @copydoc EntityDAO::delete()
     */
    public function delete(DepositObject $depositObject): void
    {
        parent::_delete($depositObject);
    }

    /**
     * Get a collection of outdated submission deposit objects
     */
    public function getOutdatedSubmissions(\APP\submission\Collector $query): Collection
    {
        return $query
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getQueryBuilder()
            ->join('pln_deposit_objects AS do', 'do.object_id', '=', 's.submission_id')
            ->where('do.date_modified', '<', 's.last_modified')
            ->where('do.object_type', '=', PlnPlugin::DEPOSIT_TYPE_SUBMISSION)
            ->select('do.deposit_object_id', 's.last_modified')
            ->get();
    }

    /**
     * Get a collection of outdated issue deposit objects
     */
    public function getOutdatedIssues(\APP\issue\Collector $query): Collection
    {
        return $query
            ->filterByPublished(true)
            ->getQueryBuilder()
            ->join('pln_deposit_objects AS do', 'do.object_id', '=', 'i.issue_id')
            ->join(
                'publication_settings AS ps',
                fn (JoinClause $j) => $j
                    ->on(DB::raw('CAST(i.issue_id AS CHAR)'), '=', 'ps.setting_value')
                    ->where('ps.setting_name', '=', 'issueId')
            )
            ->join(
                'publications AS p',
                fn (JoinClause $j) => $j
                    ->on('p.publication_id', '=', 'ps.publication_id')
                    ->where('p.status', '=', Submission::STATUS_PUBLISHED)
            )
            ->join('submissions AS s', 's.current_publication_id', '=', 'p.publication_id')
            ->where('do.object_type', '=', PlnPlugin::DEPOSIT_TYPE_ISSUE)
            ->where(
                fn (Builder $q) => $q
                    ->where('do.date_modified', '<', 's.last_modified')
                    ->orWhere('do.date_modified', '<', 'i.last_modified')
            )
            ->select(
                'do.deposit_object_id',
                DB::raw('MAX(i.last_modified) as issue_modified'),
                DB::raw('MAX(s.last_modified) as article_modified')
            )
            ->groupBy('do.deposit_object_id')
            ->get();
    }

    /**
     * Get a collection of new submissions
     *
     * @return Collection<int,Submission>
     */
    public function getNewSubmissions(\APP\submission\Collector $query): Collection
    {
        return $query
            ->filterByStatus([Submission::STATUS_PUBLISHED])
            ->getQueryBuilder()
            ->leftJoin('pln_deposit_objects AS do', 'do.object_id', '=', 's.submission_id')
            ->whereNull('do.object_id')
            ->pluck('s.submission_id')
            ->mapWithKeys(fn (int $submissionId) => [$submissionId => Repo::submission()->get($submissionId)]);
    }

    /**
     * Get a collection of new issues
     *
     * @return LazyCollection<int,\APP\issue\Issue>
     */
    public function getNewIssues(\APP\issue\Collector $query): LazyCollection
    {
        $issueIds = $query
            ->filterByPublished(true)
            ->getQueryBuilder()
            ->leftJoin('pln_deposit_objects AS do', 'do.object_id', '=', 'i.issue_id')
            ->whereNull('do.object_id')
            ->pluck('i.issue_id')
            ->toArray();

        return $query
            ->filterByIssueIds($issueIds)
            ->getMany();
    }

    /**
     * Deletes orphaned deposit objects
     */
    public function pruneOrphaned(): void
    {
        DB::table('pln_deposit_objects')
            ->whereNotIn(
                'journal_id',
                fn (Builder $q) => $q
                    ->from('journals AS j')
                    ->select('j.journal_id')
            )
            ->orWhereNotIn(
                'deposit_id',
                fn (Builder $q) => $q
                    ->from('pln_deposits AS d')
                    ->select('d.deposit_id')
            )
            ->delete();
    }
}
