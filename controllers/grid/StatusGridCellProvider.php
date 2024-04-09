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
use APP\issue\Issue;
use APP\plugins\generic\pln\classes\deposit\Deposit;
use Exception;
use PKP\controllers\grid\GridCellProvider;
use PKP\linkAction\LinkAction;
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
                return ['label' => $deposit->getUUID()];
            case 'objectId':
                $label = [];
                foreach ($deposit->getDepositObjects() as $object) {
                    $content = $object->getContent();
                    $label[] = "#{$content->getId()}: " . ($content ? ($content instanceof Issue ? $content->getIssueIdentification() : $content->getLocalizedData('title')) : __('plugins.generic.pln.status.unknown'));
                }
                if (!count($label)) {
                    $label[] = __('plugins.generic.pln.status.unknown');
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
