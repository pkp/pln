<?php

/**
 * @file classes/migration/upgrade/I28_FixDepositStatus.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I28_FixDepositStatus
 * @brief Adds new fields to manage the deposit status and resets the status for all deposits.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;
use PKP\install\DowngradeNotSupportedException;

class I28_FixDepositStatus extends Migration {
	/**
	 * Run the migrations.
	 * @return void
	 */
	public function up() {
		if (Capsule::schema()->hasColumn('pln_deposits', 'date_preserved')) {
			return;
		}
		Capsule::schema()->table('pln_deposits', function (Blueprint $table) {
			$table->datetime('date_preserved')->nullable();
			$table->string('staging_state')->nullable();
			$table->string('lockss_state')->nullable();
		});
		// Reset status
		Capsule::table('pln_deposits')->update(['status' => null]);
	}

	/**
	 * Rollback the migrations.
	 * @return void
	 */
	public function down() {
		throw new DowngradeNotSupportedException();
	}
}
