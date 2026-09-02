<?php

/**
 * @file classes/deposit/Collector.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Copyright (c) 2026 Thuan Huynh
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Collector
 *
 * @brief A helper class to configure a query builder to get a collection of deposits
 */

namespace APP\plugins\generic\pln\classes\deposit;

use APP\plugins\generic\pln\PlnPlugin;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use PKP\core\interfaces\CollectorInterface;
use PKP\plugins\Hook;

/**
 * @template T of Deposit
 */
class Collector implements CollectorInterface
{
    public const STATUS_NEW = 'STATUS_NEW';
    public const STATUS_READY_TO_TRANSFER = 'STATUS_READY_TO_TRANSFER';
    public const STATUS_READY_TO_PACKAGE = 'STATUS_READY_TO_PACKAGE';
    public const STATUS_READY_FOR_UPDATE = 'STATUS_READY_FOR_UPDATE';
    public const ORDER_BY_ERROR = 'error';
    public const ORDER_DIR_ASC = 'ASC';
    public const ORDER_DIR_DESC = 'DESC';
    public const DISPLAY_STATUS_PENDING = 'pending';
    public const DISPLAY_STATUS_IN_PROGRESS = 'inProgress';
    public const DISPLAY_STATUS_COMPLETED = 'completed';
    public const DISPLAY_STATUS_ERROR = 'error';

    public ?int $count = null;

    public ?int $offset = null;

    /** @var int[]|null */
    public ?array $ids = null;

    /** @var int[]|null */
    public ?array $uuids = null;

    /** @var int[]|null */
    public ?array $contextIds = null;

    public ?string $status = null;

    /** Search by deposit UUID, issue ID, or issue title */
    public ?string $searchPhrase = null;

    /** One of the DISPLAY_STATUS_* constants, or null for all statuses */
    public ?string $displayedStatus = null;

    /** @var bool|null null = no filter, true = only orphans, false = exclude orphans */
    public ?bool $isOrphaned = false;

    public string $orderBy = self::ORDER_BY_ERROR;
    public string $orderDirection = 'ASC';

    public function __construct(public DAO $dao)
    {
    }

    public function getCount(): int
    {
        return $this->dao->getCount($this);
    }

    /**
     * @return Collection<int,int>
     */
    public function getIds(): Collection
    {
        return $this->dao->getIds($this);
    }

    /**
     * @copydoc DAO::getMany()
     *
     * @return LazyCollection<int,T>
     */
    public function getMany(): LazyCollection
    {
        return $this->dao->getMany($this);
    }

    /**
     * Limit the number of objects retrieved
     */
    public function limit(?int $count): static
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Offset the number of objects retrieved, for example to
     * retrieve the second page of contents
     */
    public function offset(?int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Filter by ID
     */
    public function filterByIds(?array $ids): static
    {
        $this->ids = $ids;
        return $this;
    }

    /**
     * Filter by UUID
     */
    public function filterByUUIDs(?array $uuids): static
    {
        $this->uuids = $uuids;
        return $this;
    }

    /**
     * Limit results to deposits in these context IDs
     */
    public function filterByContextIds(?array $contextIds): static
    {
        $this->contextIds = $contextIds;
        return $this;
    }

    /**
     * Limit results to deposits that match the given status
     */
    public function filterByStatus(?string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Limit results to deposits matching a UUID, issue ID, or issue title
     */
    public function filterBySearchPhrase(?string $searchPhrase): static
    {
        $this->searchPhrase = $searchPhrase;
        return $this;
    }

    /**
     * Limit results to a status as displayed in the deposits grid.
     */
    public function filterByDisplayedStatus(?string $displayedStatus): static
    {
        $this->displayedStatus = in_array($displayedStatus, [
            static::DISPLAY_STATUS_PENDING,
            static::DISPLAY_STATUS_IN_PROGRESS,
            static::DISPLAY_STATUS_COMPLETED,
            static::DISPLAY_STATUS_ERROR,
        ]) ? $displayedStatus : null;
        return $this;
    }

    /**
     * Include or exclude orphaned deposits
     *
     * A deposit is orphaned when its journal is missing, it has no deposit objects,
     * or any deposit object references a missing submission/issue.
     */
    public function filterByOrphaned(?bool $isOrphaned): static
    {
        $this->isOrphaned = $isOrphaned;
        return $this;
    }

    /**
     * Order the results
     *
     * @param string $sorter One of the static::ORDER_BY_ constants
     * @param string $direction One of the static::ORDER_DIR_ constants
     */
    public function orderBy(string $sorter, string $direction = self::ORDER_DIR_DESC): static
    {
        if (!in_array($sorter, [static::ORDER_BY_ERROR])) {
            throw new InvalidArgumentException("Invalid order by: {$sorter}");
        }

        if (!in_array($direction, [static::ORDER_DIR_ASC, static::ORDER_DIR_DESC])) {
            throw new InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->orderBy = $sorter;
        $this->orderDirection = $direction;
        return $this;
    }

    /**
     * @copydoc CollectorInterface::getQueryBuilder()
     *
     * @hook PreservationNetwork::Deposit::Collector [[&$q, $this]]
     */
    public function getQueryBuilder(): Builder
    {
        $orderBy = [static::ORDER_BY_ERROR => 'd.export_deposit_error'][$this->orderBy] ?? null;
        $q = DB::table('pln_deposits as d')
            ->select('d.*')
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('d.deposit_id', $this->ids))
            ->when($this->uuids !== null, fn (Builder $query) => $query->whereIn('d.uuid', $this->uuids))
            ->when($this->contextIds !== null, fn (Builder $query) => $query->whereIn('d.journal_id', $this->contextIds))
            ->when(
                $this->searchPhrase !== null && $this->searchPhrase !== '',
                function (Builder $q) {
                    $searchPhrase = '%' . $this->searchPhrase . '%';
                    $q->where(function (Builder $q) use ($searchPhrase) {
                        $q->where('d.uuid', 'like', $searchPhrase)
                            ->orWhereExists(
                                fn (Builder $q) => $q
                                    ->from('pln_deposit_objects AS do')
                                    ->whereColumn('do.deposit_id', 'd.deposit_id')
                                    ->where(function (Builder $q) use ($searchPhrase) {
                                        $q->where('do.object_id', 'like', $searchPhrase)
                                            ->orWhereExists(
                                                fn (Builder $q) => $q
                                                    ->from('issue_settings AS i_s')
                                                    ->whereColumn('i_s.issue_id', 'do.object_id')
                                                    ->where('do.object_type', PlnPlugin::DEPOSIT_TYPE_ISSUE)
                                                    ->where('i_s.setting_name', 'title')
                                                    ->where('i_s.setting_value', 'like', $searchPhrase)
                                            );
                                    })
                            );
                    });
                }
            )
            ->when(
                $this->displayedStatus !== null,
                fn (Builder $q) => match ($this->displayedStatus) {
                    static::DISPLAY_STATUS_ERROR => $q->whereNotNull('d.export_deposit_error')->where('d.export_deposit_error', '<>', ''),
                    static::DISPLAY_STATUS_COMPLETED => $q
                        ->where(fn (Builder $q) => $q->whereNull('d.export_deposit_error')->orWhere('d.export_deposit_error', ''))
                        ->whereRaw('d.status & ? <> 0', [PlnPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT]),
                    static::DISPLAY_STATUS_PENDING => $q
                        ->where(fn (Builder $q) => $q->whereNull('d.export_deposit_error')->orWhere('d.export_deposit_error', ''))
                        ->where(fn (Builder $q) => $q->whereNull('d.status')->orWhere('d.status', PlnPlugin::DEPOSIT_STATUS_NEW)),
                    static::DISPLAY_STATUS_IN_PROGRESS => $q
                        ->where(fn (Builder $q) => $q->whereNull('d.export_deposit_error')->orWhere('d.export_deposit_error', ''))
                        ->where(fn (Builder $q) => $q->whereNull('d.status')->orWhereRaw('d.status & ? = 0', [PlnPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT]))
                        ->where('d.status', '<>', PlnPlugin::DEPOSIT_STATUS_NEW),
                }
            )
            ->when(
                $this->status !== null,
                fn (Builder $q) =>
                    match ($this->status) {
                        static::STATUS_NEW => $q->where('d.status', '=', PlnPlugin::DEPOSIT_STATUS_NEW),
                        static::STATUS_READY_TO_TRANSFER => $q
                            ->whereRaw('d.status & ? <> 0', [PlnPlugin::DEPOSIT_STATUS_PACKAGED])
                            ->whereRaw('d.status & ? = 0', [PlnPlugin::DEPOSIT_STATUS_TRANSFERRED]),
                        static::STATUS_READY_TO_PACKAGE => $q
                            ->whereRaw('d.status & ? = 0', [PlnPlugin::DEPOSIT_STATUS_PACKAGED]),
                        static::STATUS_READY_FOR_UPDATE => $q->where(
                            fn (Builder $q) => $q
                                ->whereNull('d.status')
                                ->orWhere(
                                    fn (Builder $q) => $q
                                        ->whereRaw('d.status & ? <> 0', [PlnPlugin::DEPOSIT_STATUS_TRANSFERRED])
                                        ->whereRaw('d.status & ? = 0', [PlnPlugin::DEPOSIT_STATUS_LOCKSS_AGREEMENT])
                                )
                        ),
                    }
            )
            ->when(
                $this->isOrphaned !== null,
                fn (Builder $q) => $this->isOrphaned
                    ? $this->applyOrphanConstraints($q)
                    : $q->whereNot(fn (Builder $q) => $this->applyOrphanConstraints($q))
            )
            ->when($this->count !== null, fn (Builder $q) => $q->limit($this->count))
            ->when($this->offset !== null, fn (Builder $q) => $q->offset($this->offset))
            ->when(
                $orderBy,
                fn (Builder $q) => $q
                    ->orderBy($orderBy, $this->orderDirection)
                    ->orderBy('d.deposit_id')
            );

        // Add app-specific query statements
        Hook::call('PreservationNetwork::Deposit::Collector', [&$q, $this]);

        return $q;
    }

    /**
     * Constrain the query to orphaned deposits
     */
    protected function applyOrphanConstraints(Builder $q): Builder
    {
        return $q->where(
            fn (Builder $q) => $q
                ->whereNotExists(
                    fn (Builder $q) => $q
                        ->from('journals AS j')
                        ->whereColumn('j.journal_id', 'd.journal_id')
                )
                ->orWhereNotExists(
                    fn (Builder $q) => $q
                        ->from('pln_deposit_objects AS do')
                        ->whereColumn('do.deposit_id', 'd.deposit_id')
                )
                ->orWhereExists(
                    fn (Builder $q) => $q
                        ->from('pln_deposit_objects AS do')
                        ->whereColumn('do.deposit_id', 'd.deposit_id')
                        ->where(
                            fn (Builder $q) => $q
                                ->where(
                                    fn (Builder $q) => $q
                                        ->whereIn('do.object_type', [PlnPlugin::DEPOSIT_TYPE_SUBMISSION, 'PublishedArticle'])
                                        ->whereNotExists(
                                            fn (Builder $q) => $q
                                                ->from('submissions AS s')
                                                ->whereColumn('s.submission_id', 'do.object_id')
                                                ->whereColumn('s.context_id', 'do.journal_id')
                                        )
                                )
                                ->orWhere(
                                    fn (Builder $q) => $q
                                        ->where('do.object_type', PlnPlugin::DEPOSIT_TYPE_ISSUE)
                                        ->whereNotExists(
                                            fn (Builder $q) => $q
                                                ->from('issues AS i')
                                                ->whereColumn('i.issue_id', 'do.object_id')
                                                ->whereColumn('i.journal_id', 'do.journal_id')
                                        )
                                )
                        )
                )
        );
    }
}
