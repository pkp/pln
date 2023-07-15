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

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PKP\core\interfaces\CollectorInterface;
use PKP\plugins\Hook;

/**
 * @template T of Deposit
 */
class Collector implements CollectorInterface
{
    /** @var DAO */
    public $dao;

    public ?int $count = null;

    public ?int $offset = null;

    /** @var int[]|null */
    public ?array $ids = null;

    /** @var int[]|null */
    public ?array $uuids = null;

        /** @var int[]|null */
    public ?array $contextIds = null;

    public function __construct(DAO $dao)
    {
        $this->dao = $dao;
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
     * @copydoc CollectorInterface::getQueryBuilder()
     */
    public function getQueryBuilder(): Builder
    {
        $q = DB::table('pln_deposits as d')
            ->select('d.*')
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('d.deposit_id', $this->ids))
            ->when($this->uuids !== null, fn (Builder $query) => $query->whereIn('d.uuid', $this->uuids))
            ->when($this->contextIds !== null, fn (Builder $query) => $query->whereIn('d.journal_id', $this->contextIds))
            ->orderByDesc('d.export_deposit_error')
            ->orderByDesc('d.deposit_id');

        // Add app-specific query statements
        Hook::call('PreservationNetwork::Deposit::Collector', [&$q, $this]);

        return $q;
    }
}
