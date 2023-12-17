<?php
/**
 * @file classes/deposit/Collector.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
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

    public ?int $count = null;

    public ?int $offset = null;

    /** @var int[]|null */
    public ?array $ids = null;

    /** @var int[]|null */
    public ?array $uuids = null;

    /** @var int[]|null */
    public ?array $contextIds = null;

    /** @var ?string */
    public ?string $status = null;

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
            ->when($orderBy, fn (Builder $q) => $q
                ->orderBy($orderBy, $this->orderDirection)
                ->orderBy('d.deposit_id')
            );

        // Add app-specific query statements
        Hook::call('PreservationNetwork::Deposit::Collector', [&$q, $this]);

        return $q;
    }
}
