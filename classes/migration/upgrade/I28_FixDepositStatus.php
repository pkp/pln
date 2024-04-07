<?php

/**
 * @file classes/migration/upgrade/I28_FixDepositStatus.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I28_FixDepositStatus
 *
 * @brief Adds new fields to manage the deposit status and resets the status for all deposits.
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\install\DowngradeNotSupportedException;

class I28_FixDepositStatus extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('pln_deposits', 'date_preserved')) {
            return;
        }
        Schema::table('pln_deposits', function (Blueprint $table) {
            $table->datetime('date_preserved')->nullable();
            $table->string('staging_state')->nullable();
            $table->string('lockss_state')->nullable();
        });
        // Reset status
        DB::table('pln_deposits')->update(['status' => null]);
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
