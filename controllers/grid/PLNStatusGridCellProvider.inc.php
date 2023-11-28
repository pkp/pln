<?php

/**
 * @file controllers/grid/PLNStatusGridCellProvider.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNStatusGridCellProvider
 * @brief Class for a cell provider to display information about PLN Deposits
 */

use PKP\controllers\grid\GridCellProvider;
use PKP\controllers\grid\GridColumn;
use PKP\linkAction\request\RedirectAction;

class PLNStatusGridCellProvider extends GridCellProvider {
	/**
	 * Extracts variables for a given column from a data element
	 * so that they may be assigned to template before rendering.
	 * @param \PKP\controllers\grid\GridRow $row
	 * @param GridColumn $column
	 * @return array
	 */
	public function getTemplateVarsFromRowColumn($row, $column) {
		$deposit = $row->getData(); /** @var Deposit $deposit */

		switch ($column->getId()) {
			case 'id':
				// The action has the label
				return array('label' => $deposit->getId());
			case 'objectId':
				$label = [];
				/** @var DepositObject $object */
				foreach ($deposit->getDepositObjects()->toIterator() as $object) {
					$content = $object->getContent();
					if ($content instanceof Issue) {
						$label[] = $content->getIssueIdentification();
					} elseif ($content) {
						$label[] = $content->getLocalizedTitle();
					} else {
						$label[] = __('plugins.generic.pln.status.unknown');
					}
				}
				return array('label' => implode(' ', $label));
			case 'status':
				return array('label' => $deposit->getDisplayedStatus());
			case 'latestUpdate':
				return array('label' => $deposit->getLastStatusDate());
			case 'actions':
				return array('label' => '');
			default:
				throw new Exception('Unexpected column');
		}
	}

	/**
	 * @copydoc GridColumn::getCellActions()
	 */
	public function getCellActions($request, $row, $column, $position = GRID_ACTION_POSITION_DEFAULT) {
		if ($column->getId() !== 'actions') {
			return [];
		}

		$request = Application::get()->getRequest();
		$rowId = $row->getId();
		$actionArgs['depositId'] = $rowId;
		if (!empty($rowId)) {
			$router = $request->getRouter();
			// Create the "reset deposit" action
			import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
			$link = new LinkAction(
				'resetDeposit',
				new RemoteActionConfirmationModal(
					$request->getSession(),
					__('plugins.generic.pln.status.confirmReset'),
					__('form.resubmit'),

					$router->url($request, null, null, 'resetDeposit', null, $actionArgs, 'modal_reset')
				),
				__('form.resubmit'),
				'reset'
			);
			return [$link];
		}
	}
}
