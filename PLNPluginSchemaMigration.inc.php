<?php

/**
 * @file classes/migration/PLNPluginSchemaMigration.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PLNPluginSchemaMigration
 * @brief Describe database table structures.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PLNPluginSchemaMigration extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		// Before the version 2.0.4.3, it's needed to check for a missing "export_deposit_error" field
		if (Schema::schema()->hasTable('pln_deposits') && !Schema::schema()->hasColumn('pln_deposits', 'export_deposit_error')) {
			Schema::schema()->table('pln_deposits', function (Blueprint $table) {
				$table->string('export_deposit_error', 1000)->nullable();
			});
		}

		// PLN Deposit Objects
		if (!Schema::schema()->hasTable('pln_deposit_objects')) {
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
		if (!Schema::schema()->hasTable('pln_deposits')) {
			Schema::create('pln_deposits', function (Blueprint $table) {
				$table->bigInteger('deposit_id')->autoIncrement();
				$table->bigInteger('journal_id');
				$table->string('uuid', 36)->nullable();
				$table->bigInteger('status')->default(0)->nullable();
				$table->datetime('date_status')->nullable();
				$table->datetime('date_created');
				$table->datetime('date_modified')->nullable();
				$table->string('export_deposit_error', 1000)->nullable();
			});
		}

		// Temporarily back up the old scheduled_tasks entry for this plugin, if there is one
		DB::unprepared("UPDATE scheduled_tasks SET class_name='plugins.generic.pln.classes.tasks.Depositor_TEMP' WHERE class_name='plugins.generic.pln.classes.tasks.Depositor'");
		// Create a new scheduled_tasks entry for this plugin
		DB::unprepared("INSERT INTO scheduled_tasks (class_name) VALUES ('plugins.generic.pln.classes.tasks.Depositor')");
		// Update the last_run for the new entry from the old one, if it exists
		switch (DB::getDriverName()) {
			case 'mysql': DB::unprepared("UPDATE scheduled_tasks sta JOIN scheduled_tasks stb ON (stb.class_name='plugins.generic.pln.classes.tasks.Depositor_TEMP') SET sta.last_run = stb.last_run WHERE sta.class_name='plugins.generic.pln.classes.tasks.Depositor'"); break;
			case 'pgsql': DB::unprepared("UPDATE scheduled_tasks sta SET last_run=stb.last_run FROM scheduled_tasks stb WHERE sta.class_name='plugins.generic.pln.classes.tasks.Depositor' AND stb.class_name='plugins.generic.pln.classes.tasks.Depositor_TEMP'"); break;
			default: throw new Exception('Unsupported database');
		}
		// Delete the old scheduled tasks entry
		DB::unprepared("DELETE FROM scheduled_tasks WHERE class_name='plugins.generic.pln.classes.tasks.Depositor_TEMP'");
	}
}
