<?php

/**
 * @file controllers/grid/StatusGridRow.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class StatusGridRow
 *
 * @brief Handle PLNStatus deposit grid row requests.
 */

namespace APP\plugins\generic\pln\controllers\grid;

use APP\plugins\generic\pln\classes\deposit\Deposit;
use APP\plugins\generic\pln\PlnPlugin;
use PKP\controllers\grid\GridRow;

class StatusGridRow extends GridRow
{
    /**
     * @copydoc GridRow::initialize()
     *
     * @param null|mixed $template
     */
    public function initialize($request, $template = null): void
    {
        parent::initialize($request, PlnPlugin::loadPlugin()->getTemplateResource('gridRow.tpl'));
    }

    /**
     * Retrieves the deposit
     */
    public function getDeposit(): Deposit
    {
        return $this->getData();
    }
}
