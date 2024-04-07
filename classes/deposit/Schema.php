<?php
/**
 * @file classes/deposit/Schema.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Schema
 *
 * @brief Map deposits to the properties defined in the deposit schema
 */

namespace APP\plugins\generic\pln\classes\deposit;

use Illuminate\Support\Enumerable;
use PKP\plugins\Hook;

class Schema extends \PKP\core\maps\Schema
{
    public const SCHEMA_NAME = 'preservationNetworkDeposit';
    public string $schema = self::SCHEMA_NAME;

    /**
     * Registers schema
     */
    public static function register(): void
    {
        $path = dirname(__DIR__, 2) . '/schemas/deposit.json';
        Hook::add(
            'Schema::get::' . static::SCHEMA_NAME,
            fn (string $hookName, array $args) => $args[0] = json_decode(file_get_contents($path))
        );
    }

    /**
     * Map an author
     *
     * Includes all properties in the announcement schema.
     */
    public function map(Deposit $item): array
    {
        return $this->mapByProperties($this->getProps(), $item);
    }

    /**
     * Summarize an author
     *
     * Includes properties with the apiSummary flag in the author schema.
     */
    public function summarize(Deposit $item): array
    {
        return $this->mapByProperties($this->getSummaryProps(), $item);
    }

    /**
     * Map a collection of Authors
     *
     * @see self::map
     */
    public function mapMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (Deposit $item) => $this->map($item));
    }

    /**
     * Summarize a collection of Authors
     *
     * @see self::summarize
     */
    public function summarizeMany(Enumerable $collection): Enumerable
    {
        return $collection->map(fn (Deposit $item) => $this->summarize($item));
    }

    /**
     * Map schema properties of an Author to an assoc array
     */
    protected function mapByProperties(array $props, Deposit $item): array
    {
        $values = collect($props)
            ->map(fn (string $prop) => $item->getData($prop))
            ->toArray();
        $output = $this->schemaService->addMissingMultilingualValues($this->schema, $values, $this->context->getSupportedSubmissionLocales());
        ksort($output);
        return $this->withExtensions($output, $item);
    }
}
