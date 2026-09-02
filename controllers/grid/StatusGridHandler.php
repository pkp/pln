<?php

/**
 * @file controllers/grid/StatusGridHandler.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Copyright (c) 2026 Thuan Huynh
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class StatusGridHandler
 *
 * @brief Handle PLNStatus grid requests.
 */

namespace APP\plugins\generic\pln\controllers\grid;

use APP\core\Request;
use APP\plugins\generic\pln\classes\deposit\Deposit;
use APP\plugins\generic\pln\classes\deposit\Repository;
use APP\plugins\generic\pln\PlnPlugin;
use PKP\controllers\grid\feature\PagingFeature;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;
use PKP\core\VirtualArrayIterator;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class StatusGridHandler extends GridHandler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER],
            ['fetchGrid', 'fetchRow', 'resetDeposit']
        );
    }

    /**
     * @copydoc Gridhandler::initialize()
     *
     * @param null|mixed $args
     */
    public function initialize($request, $args = null): void
    {
        parent::initialize($request);

        // Set the grid title.
        $this->setTitle('plugins.generic.pln.status.deposits');

        // Set the grid instructions.
        $this->setEmptyRowText('common.none');

        // Columns
        $cellProvider = new StatusGridCellProvider();
        $this->addColumn(new GridColumn('objectId', 'plugins.generic.pln.issueId', null, null, $cellProvider));
        $this->addColumn(new GridColumn('status', 'plugins.generic.pln.status.status', null, null, $cellProvider));
        $this->addColumn(new GridColumn('latestUpdate', 'plugins.generic.pln.status.latestupdate', null, null, $cellProvider));
        $this->addColumn(new GridColumn('id', 'common.id', null, null, $cellProvider));
        $this->addColumn(new GridColumn('actions', 'grid.columns.actions', null, null, $cellProvider));
    }

    /**
     * @copydoc GridHandler::initFeatures()
     */
    public function initFeatures($request, $args): array
    {
        return [new PagingFeature()];
    }

    /**
     * @copydoc GridHandler::getFilterForm()
     */
    protected function getFilterForm(): string
    {
        return PlnPlugin::loadPlugin()->getTemplateResource('statusGridFilter.tpl');
    }

    /**
     * @copydoc GridHandler::renderFilter()
     */
    public function renderFilter($request, $filterData = []): string
    {
        return parent::renderFilter($request, array_merge($filterData, [
            'gridId' => $this->getId(),
            'statuses' => [
                '' => __('common.all'),
                \APP\plugins\generic\pln\classes\deposit\Collector::DISPLAY_STATUS_PENDING => __('plugins.generic.pln.displayedstatus.pending'),
                \APP\plugins\generic\pln\classes\deposit\Collector::DISPLAY_STATUS_IN_PROGRESS => __('plugins.generic.pln.displayedstatus.inprogress'),
                \APP\plugins\generic\pln\classes\deposit\Collector::DISPLAY_STATUS_COMPLETED => __('plugins.generic.pln.displayedstatus.completed'),
                \APP\plugins\generic\pln\classes\deposit\Collector::DISPLAY_STATUS_ERROR => __('plugins.generic.pln.displayedstatus.error'),
            ],
        ]));
    }

    /**
     * @copydoc GridHandler::getFilterSelectionData()
     */
    public function getFilterSelectionData($request): array
    {
        return [
            'search' => trim((string) $request->getUserVar('search')),
            'status' => (string) $request->getUserVar('status'),
        ];
    }

    /**
     * @copydoc GridHandler::getRowInstance()
     */
    protected function getRowInstance(): StatusGridRow
    {
        return new StatusGridRow();
    }

    /**
     * @copydoc GridHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments): bool
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @copydoc GridHandler::loadData()
     *
     * @return iterable<Deposit>
     */
    protected function loadData($request, $filter)
    {
        $context = $request->getContext();
        $rangeInfo = $this->getGridRangeInfo($request, $this->getId());
        $collector = Repository::instance()
            ->getCollector()
            ->filterBySearchPhrase($filter['search'] ?: null)
            ->filterByDisplayedStatus($filter['status'] ?: null);

        $totalCount = $collector
            ->filterByContextIds([$context->getId()])
            ->getCount();

        $deposits = $collector
            ->orderBy($collector::ORDER_BY_ERROR, $collector::ORDER_DIR_DESC)
            ->limit($rangeInfo->getCount())
            ->offset($rangeInfo->getOffset() + max(0, $rangeInfo->getPage() - 1) * $rangeInfo->getCount())
            ->getMany()
            ->toArray();

        return new VirtualArrayIterator($deposits, $totalCount, $rangeInfo->getPage(), $rangeInfo->getCount());
    }

    /**
     * Reset Deposit
     */
    public function resetDeposit(array $args, Request $request): JSONMessage
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false);
        }

        $depositId = (int) ($args['depositId'] ?? null);
        $journal = $request->getJournal();

        if ($depositId && ($deposit = Repository::instance()->get($depositId, $journal->getId()))) {
            $deposit->setNewStatus();
            Repository::instance()->edit($deposit);
        }

        return DAO::getDataChangedEvent();
    }
}

// Alias added to support the syntax {url component="plugins.generic.pln.controllers.grid.StatusGridHandler"}
class_alias(StatusGridHandler::class, '\StatusGridHandler');
