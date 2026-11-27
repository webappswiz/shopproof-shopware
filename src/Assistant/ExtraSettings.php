<?php declare(strict_types=1);

namespace Shopproof\Compliance\Assistant;

/**
 * Settings another Shopproof plugin adds to the AI assistant (Shopproof Pro: the three product
 * questions). Services with the tag shopproof.assistant_settings implement it. The assistant
 * treats them like its own settings: preview first, apply with the preview's code, change list,
 * proof log and undo.
 */
interface ExtraSettings
{
    public const TAG = 'shopproof.assistant_settings';

    /**
     * The settings on offer now, or none (for example without a licence). Names are lower case
     * letters and underscores.
     *
     * @return array<string, array{type: 'enum'|'list', allowed: list<string>, label: string, description: string}>
     */
    public function settings(): array;

    /**
     * The current values: a string for an enum ('' while never answered), a list for a list.
     *
     * @return array<string, mixed>
     */
    public function values(): array;

    /**
     * Problems with a change, from every value as it is now and as the change would leave it.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    public function errors(array $current, array $after): array;

    /**
     * Saves new values (only the changed ones are passed).
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void;
}
