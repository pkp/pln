<?php

/**
 * @file classes/migration/PLNPluginSchemaUpgrade.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PLNPluginSchemaUpgrade
 * @brief Describe database table structures.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;
use PKP\install\DowngradeNotSupportedException;

class PLNPluginSchemaUpgrade extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		// Before the version 2.0.4.3, it's needed to check for a missing "export_deposit_error" field
		if (!Capsule::schema()->hasColumn('pln_deposits', 'export_deposit_error')) {
			Capsule::schema()->table('pln_deposits', function (Blueprint $table) {
				$table->string('export_deposit_error', 1000)->nullable();
			});
		}

		// Changes introduced after version 2.0.4.3
		if (!Capsule::schema()->hasColumn('pln_deposits', 'date_preserved')) {
			Capsule::schema()->table('pln_deposits', function (Blueprint $table) {
				$table->datetime('date_preserved')->nullable();
				$table->string('staging_state')->nullable();
				$table->string('lockss_state')->nullable();
			});
			// Reset status
			Capsule::table('pln_deposits')->update(['status' => null]);
		}
	}

	/**
	 * Rollback the migrations.
	 * @return void
	 */
	public function down() {
		throw new DowngradeNotSupportedException();
	}
}
