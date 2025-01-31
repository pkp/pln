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
            $isUpdateRequired = false;
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Exception) {
                $value = @unserialize($value);
                // It's safe to strictly compare with false, as we're never storing false on these settings
                $isUpdateRequired = $value !== false;
            }

            // Attempt to unserialize again to catch an edge case
            if (!is_array($value)) {
                $newValue = @unserialize((string) $value);
                if ($newValue !== false) {
                    $isUpdateRequired = true;
                    $value = $newValue;
                }
            }

            // The "terms" are not that important (the user might accept them again), so if we're still unable to decode it, a cleanup is acceptable
            if (!is_array($value)) {
                $value = [];
                $isUpdateRequired = true;
            }

            if ($isUpdateRequired) {
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
