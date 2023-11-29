<?php

/**
 * @file classes/PLNPluginSchemaUpgrade.inc.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PLNPluginSchemaUpgrade
 * @brief Describe database table structures.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\install\DowngradeNotSupportedException;

class PLNPluginSchemaUpgrade extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		// Before the version 2.0.4.3, it's needed to check for a missing "export_deposit_error" field
		if (!Schema::hasColumn('pln_deposits', 'export_deposit_error')) {
			Schema::table('pln_deposits', function (Blueprint $table) {
				$table->string('export_deposit_error', 1000)->nullable();
			});
		}

		// Changes introduced at version 3.0.0.0
		if (!Schema::hasColumn('pln_deposits', 'date_preserved')) {
			Schema::table('pln_deposits', function (Blueprint $table) {
				$table->datetime('date_preserved')->nullable();
				$table->string('staging_state')->nullable();
				$table->string('lockss_state')->nullable();
			});
			// Reset status
			DB::table('pln_deposits')->update(['status', null]);
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
