<?php

/**
 * @file PLNPluginSchemaMigration.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PLNPluginSchemaMigration
 *
 * @brief Placeholder file to avoid upgrade issues
 */

use Illuminate\Database\Migrations\Migration;

class PLNPluginSchemaMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // This file must be kept to avoid issues when upgrading the plugin from a previous version (< v2.0.4.3)
    }

    /**
     * Rollback the migrations.
     */
    public function down()
    {
    }
}
