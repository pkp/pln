<?php

/**
 * @file classes/migration/upgrade/I57_UpdateSettings.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class I57_UpdateSettings
 *
 * @brief Fix the double serialization for the settings terms_of_use and terms_of_use_agreement.
 */

namespace APP\plugins\generic\pln\classes\migration\upgrade;

use Exception;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PKP\install\DowngradeNotSupportedException;

class I57_UpdateSettings extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop extra serialization from plugin settings
        $settings = DB::table('plugin_settings')
            ->where('plugin_name', '=', 'plnplugin')
            ->whereIn('setting_name', ['terms_of_use', 'terms_of_use_agreement'])
            ->pluck('setting_value', 'setting_name');
        foreach ($settings as $name => $value) {
            $isSerialized = false;
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Exception) {
                error_clear_last();
                $value = unserialize($value);
                $isSerialized = !error_get_last();
            }

            error_clear_last();
            $value = unserialize((string) $value);
            $isSerialized = !error_get_last() || $isSerialized;

            if ($isSerialized) {
                DB::table('plugin_settings')
                    ->where('plugin_name', '=', 'plnplugin')
                    ->where('setting_name', '=', $name)
                    ->update(['setting_value' => json_encode($value)]);
            }
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
