<?php

/**
 * @file classes/migration/PLNPluginSchemaMigration.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PLNPluginSchemaMigration
 * @brief Describe database table structures.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class PLNPluginSchemaMigration extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		// Before the version 2.0.4.3, it's needed to check for a missing "export_deposit_error" field
		if (Capsule::schema()->hasTable('pln_deposits') && !Capsule::schema()->hasColumn('pln_deposits', 'export_deposit_error')) {
			Capsule::schema()->table('pln_deposits', function (Blueprint $table) {
				$table->string('export_deposit_error', 1000)->nullable();
			});
		}

		// Introduced after version 2.0.4.3
		if (Capsule::schema()->hasTable('pln_deposits') && !Capsule::schema()->hasColumn('pln_deposits', 'date_preserved')) {
			Capsule::schema()->table('pln_deposits', function (Blueprint $table) {
				$table->datetime('date_preserved')->nullable();
			});
		}

		// PLN Deposit Objects
		if (!Capsule::schema()->hasTable('pln_deposit_objects')) {
			Capsule::schema()->create('pln_deposit_objects', function (Blueprint $table) {
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
		if (!Capsule::schema()->hasTable('pln_deposits')) {
			Capsule::schema()->create('pln_deposits', function (Blueprint $table) {
				$table->bigInteger('deposit_id')->autoIncrement();
				$table->bigInteger('journal_id');
				$table->string('uuid', 36)->nullable();
				$table->bigInteger('status')->default(0)->nullable();
				$table->datetime('date_status')->nullable();
				$table->datetime('date_created');
				$table->datetime('date_modified')->nullable();
				$table->string('export_deposit_error', 1000)->nullable();
				$table->datetime('date_preserved')->nullable();
			});
		}

		// Create a new scheduled_tasks entry for this plugin
		Capsule::table('scheduled_tasks')->insertOrIgnore(['class_name' => 'plugins.generic.pln.classes.tasks.Depositor']);
	}
}
