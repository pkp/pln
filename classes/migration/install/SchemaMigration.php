<?php

/**
 * @file classes/migration/install/SchemaMigration.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class SchemaMigration
 *
 * @brief Describe database table structures.
 */

namespace APP\plugins\generic\pln\classes\migration\install;

use APP\plugins\generic\pln\classes\migration\upgrade\I28_FixDepositStatus;
use APP\plugins\generic\pln\classes\migration\upgrade\I35_FixMissingField;
use APP\plugins\generic\pln\classes\migration\upgrade\I57_RemoveScheduledTask;
use APP\plugins\generic\pln\classes\migration\upgrade\I57_UpdateSettings;
use APP\plugins\generic\pln\classes\tasks\Depositor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\install\DowngradeNotSupportedException;

class SchemaMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PLN Deposit Objects
        if (!Schema::hasTable('pln_deposit_objects')) {
            Schema::create('pln_deposit_objects', function (Blueprint $table) {
                $table->bigInteger('deposit_object_id')->autoIncrement();
                $table->bigInteger('journal_id');
                $table->bigInteger('object_id');
                $table->string('object_type', 36);
                $table->bigInteger('deposit_id')->nullable();
                $table->datetime('date_created');
                $table->datetime('date_modified')->nullable();
            });
        }

        // PLN Deposits
        if (!Schema::hasTable('pln_deposits')) {
            Schema::create('pln_deposits', function (Blueprint $table) {
                $table->bigInteger('deposit_id')->autoIncrement();
                $table->bigInteger('journal_id');
                $table->string('uuid', 36)->nullable();
                $table->bigInteger('status')->default(0)->nullable();
                $table->string('staging_state')->nullable();
                $table->string('lockss_state')->nullable();
                $table->datetime('date_status')->nullable();
                $table->datetime('date_created');
                $table->datetime('date_modified')->nullable();
                $table->string('export_deposit_error', 1000)->nullable();
                $table->datetime('date_preserved')->nullable();
            });
        }

        // Create a new scheduled_tasks entry for this plugin
        DB::table('scheduled_tasks')->insertOrIgnore(['class_name' => Depositor::class]);

        /** @var class-string<Migration> $class */
        foreach ([I35_FixMissingField::class, I28_FixDepositStatus::class, I57_RemoveScheduledTask::class, I57_UpdateSettings::class] as $class) {
            $migration = new $class();
            $migration->up();
        }
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        throw new DowngradeNotSupportedException();
    }
}
