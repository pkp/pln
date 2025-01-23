<?php

/**
 * @file classes/migration/upgrade/I102_ResetCompletedDeposits.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I102_ResetCompletedDeposits
 * @brief Due to an issue in the Preservation Network server, deposits completed before 2025-01-23 should have their status refreshed.
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use DateTimeImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I102_ResetCompletedDeposits extends Migration
{
    public function up(): void
    {
        // Reset status
        DB::table('pln_deposits')
            // PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT (128) is set
            ->whereRaw('(status & 128) <> 0')
            // The preserved date is set by the plugin, and not taken from the server, so this is enough to make the query safe to be re-executed
            ->whereDate('date_preserved', '<', new DateTimeImmutable('2025-01-23'))
            ->update(['status' => null]);
    }

    public function down() {
        throw new DowngradeNotSupportedException();
    }
}
