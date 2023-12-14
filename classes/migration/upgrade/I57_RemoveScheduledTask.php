<?php

/**
 * @file classes/migration/upgrade/I57_RemoveScheduledTask.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I57_RemoveScheduledTask
 *
 * @brief The scheduled task now uses a full qualified class name, this migration will clear the old task
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I57_RemoveScheduledTask extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('scheduled_tasks')
            ->where('class_name', '=', 'plugins.generic.pln.classes.tasks.Depositor')
            ->delete();
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
