<?php

/**
 * @file controllers/grid/PLNStatusGridRow.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNStatusGridRow
 * @brief Handle PLNStatus deposit grid row requests.
 */

import('lib.pkp.classes.controllers.grid.GridRow');

class PLNStatusGridRow extends GridRow {
	//
	// Overridden template methods
	//
	/**
	 * @copydoc GridRow::initialize()
	 */
	public function initialize($request, $template = null) {
		parent::initialize($request, PLNStatusGridHandler::$plugin->getTemplateResource('gridRow.tpl'));
	}

	/**
	 * Retrieves the deposit
	 */
	public function getDeposit(): Deposit
	{
		return $this->getData();
	}
}
