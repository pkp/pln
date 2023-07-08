<?php

/**
 * @file classes/migration/upgrade/I35_FixMissingField.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I35_FixMissingField
 *
 * @brief Before the version 2.0.4.3, it's needed to check for a missing "export_deposit_error" field
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PKP\install\DowngradeNotSupportedException;

class I35_FixMissingField extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('pln_deposits', 'export_deposit_error')) {
            return;
        }
        Schema::table('pln_deposits', function (Blueprint $table) {
            $table->string('export_deposit_error', 1000)->nullable();
        });
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
