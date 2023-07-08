<?php

/**
 * @file controllers/grid/StatusGridCellProvider.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class StatusGridCellProvider
 *
 * @brief Class for a cell provider to display information about PLN Deposits
 */

namespace APP\plugins\generic\pln\controllers\grid;

use APP\core\Application;
use APP\plugins\generic\pln\classes\DepositObject;
use APP\plugins\generic\pln\form\Deposit;
use Exception;
use LinkAction;
use PKP\controllers\grid\GridCellProvider;
use PKP\linkAction\request\RemoteActionConfirmationModal;

class StatusGridCellProvider extends GridCellProvider
{
    /**
     * @copydoc GridCellProvider::getTemplateVarsFromRowColumn()
     */
    public function getTemplateVarsFromRowColumn($row, $column): array
    {
        $deposit = $row->getData(); /** @var Deposit $deposit */

        switch ($column->getId()) {
            case 'id':
                // The action has the label
                return ['label' => $deposit->getId()];
            case 'objectId':
                $label = [];
                /** @var DepositObject $object */
                foreach ($deposit->getDepositObjects()->toIterator() as $object) {
                    $content = $object->getContent();
                    if ($content instanceof Issue) {
                        $label[] = $content->getIssueIdentification();
                    } elseif ($content) {
                        $label[] = $content->getLocalizedData('title');
                    } else {
                        $label[] = __('plugins.generic.pln.status.unknown');
                    }
                }
                return ['label' => implode(' ', $label)];
            case 'status':
                return ['label' => $deposit->getDisplayedStatus()];
            case 'latestUpdate':
                return ['label' => $deposit->getLastStatusDate()];
            case 'actions':
                return ['label' => ''];
            default:
                throw new Exception('Unexpected column');
        }
    }

    /**
     * @copydoc GridColumn::getCellActions()
     */
    public function getCellActions($request, $row, $column, $position = GRID_ACTION_POSITION_DEFAULT): array
    {
        if ($column->getId() !== 'actions') {
            return [];
        }

        $request = Application::get()->getRequest();
        $rowId = $row->getId();
        $actionArgs['depositId'] = $rowId;
        if (empty($rowId)) {
            return [];
        }

        $router = $request->getRouter();
        // Create the "reset deposit" action
        $link = new LinkAction(
            'resetDeposit',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.pln.status.confirmReset'),
                __('form.resubmit'),
                $router->url(request: $request, op: 'resetDeposit', params: $actionArgs, anchor: 'modal_reset')
            ),
            __('form.resubmit'),
            'reset'
        );
        return [$link];
    }
}
