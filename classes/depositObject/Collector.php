<?php
/**
 * @file classes/depositObject/Collector.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Collector
 *
 * @brief A helper class to configure a query builder to get a collection of deposit objects
 */

namespace APP\plugins\generic\pln\classes\depositObject;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PKP\core\interfaces\CollectorInterface;
use PKP\plugins\Hook;

/**
 * @template T of DepositObject
 */
class Collector implements CollectorInterface
{
    public ?int $count = null;

    public ?int $offset = null;

    /** @var int[]|null */
    public ?array $ids = null;

    /** @var int[]|null */
    public ?array $contextIds = null;


    /** @var int[]|null */
    public ?array $depositIds = null;

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
    public function limit(?int $count): self
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Offset the number of objects retrieved, for example to
     * retrieve the second page of contents
     */
    public function offset(?int $offset): self
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
    public function filterByDepositIds(?array $depositIds): static
    {
        $this->depositIds = $depositIds;
        return $this;
    }

    /**
     * Limit results to deposit objects in these context IDs
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
        $q = DB::table('pln_deposit_objects as do')
            ->select('do.*')
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('do.deposit_object_id', $this->ids))
            ->when($this->depositIds !== null, fn (Builder $query) => $query->whereIn('do.deposit_id', $this->depositIds))
            ->when($this->contextIds !== null, fn (Builder $query) => $query->whereIn('do.journal_id', $this->contextIds));

        // Add app-specific query statements
        Hook::call('PreservationNetwork::DepositObject::Collector', [&$q, $this]);

        return $q;
    }
}
