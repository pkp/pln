<?php

/**
 * @file controllers/grid/StatusGridHandler.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class StatusGridHandler
 *
 * @brief Handle PLNStatus grid requests.
 */

namespace APP\plugins\generic\pln\controllers\grid;

use APP\core\Request;
use APP\plugins\generic\pln\classes\DepositDAO;
use APP\plugins\generic\pln\form\Deposit;
use APP\plugins\generic\pln\PLNPlugin;
use PKP\controllers\grid\feature\PagingFeature;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class StatusGridHandler extends GridHandler
{
    public static PLNPlugin $plugin;

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
        /** @var PLNPlugin */
        $plugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);
        self::$plugin = $plugin;
    }

    /**
     * Set the plugin
     */
    public static function setPlugin(PLNPlugin $plugin): void
    {
        self::$plugin = $plugin;
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
     * @return DAOResultFactory<Deposit>
     */
    protected function loadData($request, $filter): DAOResultFactory
    {
        $context = $request->getContext();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        $rangeInfo = $this->getGridRangeInfo($request, $this->getId());
        return $depositDao->getByJournalId($context->getId(), $rangeInfo);
    }

    /**
     * Reset Deposit
     */
    public function resetDeposit(array $args, Request $request): JSONMessage
    {
        $depositId = $args['depositId'];
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        $journal = $request->getJournal();

        if ($depositId) {
            $deposit = $depositDao->getById($depositId, $journal->getId());

            $deposit->setNewStatus();

            $depositDao->updateObject($deposit);
        }

        return DAO::getDataChangedEvent();
    }
}
