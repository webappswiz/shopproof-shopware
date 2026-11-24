<?php declare(strict_types=1);

namespace Shopproof\Compliance\Assistant;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\Setup\OverviewData;
use Shopproof\Compliance\Setup\Recommended;
use Shopproof\Compliance\Setup\SettingsFile;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The tools an AI assistant may call (decision 0047, packages/assistant/tools.json), the same
 * ten as the WooCommerce plugin.
 *
 * Reads go through the same code as the Shopproof page, so the assistant and the page always
 * agree. Every change is previewed first and applied only with that preview's code, is checked
 * against config.xml like the settings page and the settings import, is recorded in the change
 * list and the proof log, and can be undone. Settings are changed shop-wide; a sales channel with
 * its own value keeps it. The assistant never changes orders, customers or withdrawals, never
 * sends an email, never deletes anything and cannot touch its own switches, keys, the webhook
 * address or anything that sends data elsewhere.
 */
final class AssistantTools
{
    public const CONFIG = 'ShopproofCompliance.config.';

    /** Shopware's own withdrawal button (6.7.9 and later); its page is set in Basic information. */
    public const BUTTON = SetupFacts::BUTTON;

    /**
     * Settings the assistant may change, and whether shoppers see them. Types, choices, limits
     * and labels come from config.xml. Left out on purpose: own button wording (needs the
     * owner's "I understand" tick), texts and addresses the owner types (manufacturer, dispute
     * board, checkout boxes, registration numbers), the returns tool addresses (they send
     * withdrawals elsewhere), rule downloads (contact a server) and the proof log retention.
     */
    public const REGISTRY = [
        'countries' => false,
        'establishedOutsideEu' => false,
        'showSafety' => true,
        'safetyPlacement' => true,
        'showGuaranteeNotice' => true,
        'priorPriceLine' => true,
        'deliveryLine' => true,
        'digitalConsent' => true,
        'serviceConsent' => true,
        'cancelButton' => true,
        'withdrawalDays' => true,
        'accountWithdrawalLink' => true,
        'orderEmailWithdrawalLine' => true,
        'orderEmailLegalLinks' => true,
        'modelFormOnPolicyPage' => true,
        'statusEmails' => true,
        'withdrawalCreditLine' => true,
        'withdrawalOrderDate' => true,
        'withdrawalComment' => true,
        'itemPicker' => true,
        'excludeDigital' => true,
        'exclusionNote' => true,
    ];

    /** Longest text value. */
    public const TEXT_MAX = 300;

    /** "Switch on what your shop needs", in the words of the Shopproof page. */
    public const SWITCH_TEXTS = [
        'guarantee' => ['Legal guarantee notice', 'On product pages, below the product description.', 'EU law asks shops that sell goods to show it.'],
        'priorPrice' => ['Lowest price before a sale', 'Next to reduced prices only. Products not on sale show nothing.', 'EU rules ask for the lowest price of the 30 days before a reduction.'],
        'deliveryLine' => ['Delivery and payment line', 'Above the Go to checkout button in the cart and at the top of the checkout page, read from your storefronts\' countries and payment methods.', 'Shoppers must see where you deliver and how they can pay before they order.'],
        'digital' => ['Download consent at checkout', 'Two tick boxes at checkout, only when the cart has a download.', 'Without them, customers keep the right to withdraw after downloading.'],
    ];

    private const PROOF_TYPES = [
        'snapshot' => 'Daily snapshot',
        'withdrawal' => 'Withdrawal',
        'retention' => 'Retention changed',
        'anchor' => 'Stamp held by Shopproof',
        'prices' => 'Price history sealed',
        'assistant' => 'Changed by AI assistant',
    ];

    private const NO_LOG = 'The proof log entry could not be written, so nothing was changed. Try again in a moment.';

    private const OFF = 'Changes are off. The owner must turn on Allow changes on the Shopproof page (Extensions > Shopproof, card "AI assistant") first.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly SettingsFile $schema,
        private readonly AssistantStore $store,
        private readonly OverviewData $overview,
        private readonly Recommended $recommended,
        private readonly WithdrawalStore $withdrawals,
        private readonly ProofLog $log,
        private readonly SetupFacts $setup,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Runs a tool for the admin user who owns the key.
     *
     * @param array<mixed>                                       $args
     * @param array{on: bool, changes: bool, details: bool}      $switches
     * @param array{settings: bool, change: bool, orders: bool} $rights   what the key's user may do in Shopware now
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function call(string $name, array $args, array $switches, string $userId, array $rights = ['settings' => true, 'change' => true, 'orders' => true]): array
    {
        $refused = self::refused($name, $rights);
        if ($refused !== null) {
            return self::fail($refused);
        }

        return match ($name) {
            'get_setup_status' => self::ok($this->setupStatus($switches, $userId)),
            'list_settings' => self::ok($this->listSettings()),
            'recommend_settings' => self::ok(['switches' => self::switchList(Recommended::plan($this->recommended->facts()))]),
            'preview_change' => $this->preview($args, $switches, $userId),
            'apply_change' => $this->apply($args, $switches, $userId),
            'list_changes' => self::ok(['changes' => $this->changeList(self::limit($args, 20, 50))]),
            'undo_change' => $this->undo($args, $switches, $userId),
            'list_withdrawals' => self::ok($this->withdrawalList($args, $switches['details'], $userId)),
            'get_withdrawal' => $this->withdrawal($args, $switches['details'], $userId),
            'proof_log' => self::ok($this->proofLog(self::limit($args, 20, 50))),
            default => self::fail('Unknown tool.'),
        };
    }

    /**
     * Why the key's user may not run a tool, or null when they may. The rights are the same the
     * Shopproof page and Shopware's withdrawal list ask for. Pure, so it is unit tested alone.
     *
     * @param array{settings: bool, change: bool, orders: bool} $rights
     */
    public static function refused(string $name, array $rights): ?string
    {
        if (!$rights['settings']) {
            return 'The admin user of this key may no longer read Shopproof\'s settings in Shopware.';
        }
        if (\in_array($name, ['apply_change', 'undo_change'], true) && !$rights['change']) {
            return 'The admin user of this key may not change settings in Shopware (its role needs the right to edit settings). Nothing was changed.';
        }
        if (\in_array($name, ['list_withdrawals', 'get_withdrawal'], true) && !$rights['orders']) {
            return 'The admin user of this key may not see orders in Shopware, so withdrawals are not shown.';
        }

        return null;
    }

    /**
     * @param array{on: bool, changes: bool, details: bool} $switches
     *
     * @return array<string, mixed>
     */
    private function setupStatus(array $switches, string $userId): array
    {
        $data = $this->overview->build($this->zone($userId), true);
        $verdict = \is_array($data['verdict'] ?? null) ? $data['verdict'] : ['clear' => false, 'items' => []];
        $items = \is_array($verdict['items'] ?? null) ? $verdict['items'] : [];
        $report = \is_array($data['report'] ?? null) && \is_array($data['report']['lines'] ?? null) ? $data['report']['lines'] : [];

        return [
            'summary' => !empty($verdict['clear']) ? 'Nothing needs the owner right now.' : \count($items) . ' things need the owner.',
            'needs_owner' => array_map(static fn (mixed $item): array => \is_array($item) ? [
                'key' => $item['key'] ?? '',
                'state' => $item['state'] ?? '',
                'detail' => $item['text'] ?? '',
                'values' => $item['values'] ?? [],
            ] : [], $items),
            'steps' => $data['steps'] ?? [],
            'features' => array_map(static fn (mixed $card): array => \is_array($card) ? [
                'key' => $card['key'] ?? '',
                'state' => $card['state'] ?? '',
                'detail' => $card['text'] ?? '',
                'values' => $card['values'] ?? [],
            ] : [], \is_array($data['cards'] ?? null) ? $data['cards'] : []),
            'setup_check' => array_values(array_filter($report, static fn (mixed $line): bool => \is_string($line) && $line !== '')),
            'non_eu' => $data['nonEu'] ?? null,
            'recommended' => self::switchList(Recommended::plan($this->recommended->facts())),
            'withdrawal_button' => $this->buttonState(),
            'assistant_changes' => $switches['changes'] ? 'allowed' : 'off: the owner must turn on Allow changes on the Shopproof page (card "AI assistant")',
            'where' => 'Settings are on the Shopproof page (Extensions > Shopproof) and in the plugin configuration. Shopware\'s withdrawal button and its page are under Settings > Basic information.',
            'note' => 'Technical help, not legal advice. A check that did not run says not checked; it is never a pass.',
        ];
    }

    /**
     * @param list<string> $plan
     *
     * @return list<array{key: string, title: string, where: string, why: string}>
     */
    public static function switchList(array $plan): array
    {
        $out = [];
        foreach ($plan as $key) {
            if (isset(self::SWITCH_TEXTS[$key])) {
                [$title, $where, $why] = self::SWITCH_TEXTS[$key];
                $out[] = ['key' => $key, 'title' => $title, 'where' => $where, 'why' => $why];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function listSettings(): array
    {
        $current = $this->current();
        $rows = [];
        foreach (self::REGISTRY as $name => $shoppers) {
            $field = $this->schema->field($name);
            if ($field === null) {
                continue;
            }
            $row = [
                'key' => $name,
                'value' => $current[$name] ?? null,
                'type' => self::typeName($field['type']),
                'description' => $field['label'],
                'shoppers_see' => $shoppers,
            ];
            if ($field['options'] !== []) {
                $row['allowed'] = $field['options'];
            }
            if ($field['min'] !== null) {
                $row['min'] = $field['min'];
            }
            if ($field['max'] !== null) {
                $row['max'] = $field['max'];
            } elseif ($field['type'] === 'text') {
                $row['max'] = self::TEXT_MAX;
            }
            $rows[] = $row;
        }

        return [
            'settings' => $rows,
            'scope' => 'Shop-wide values (All sales channels). A sales channel with its own value keeps it; preview_change names them.',
            'not_here' => 'Own button wording, manufacturer and responsible person details, registration numbers, checkout boxes, the dispute statement, the returns tool addresses, rule downloads and the proof log retention stay on the Shopproof settings, where the owner confirms them.',
            'switch_on_via' => 'recommend_settings and preview_change switch_on',
        ];
    }

    private static function typeName(string $type): string
    {
        return match ($type) {
            'single-select' => 'enum',
            'multi-select' => 'list',
            default => $type,
        };
    }

    /**
     * The current shop-wide values, as the types config.xml gives them; the default when none is
     * stored.
     *
     * @return array<string, mixed>
     */
    private function current(): array
    {
        $out = [];
        foreach (array_keys(self::REGISTRY) as $name) {
            $out[$name] = self::value($this->schema, $name, $this->config->get(self::CONFIG . $name));
        }

        return $out;
    }

    /**
     * A stored value as the setting's type, or its default when none is stored. Pure.
     */
    public static function value(SettingsFile $schema, string $name, mixed $stored): mixed
    {
        $field = $schema->field($name);
        if ($stored === null) {
            $stored = $field['default'] ?? null;
            if ($stored === null && $field !== null) {
                $stored = match ($field['type']) {
                    'bool' => false,
                    'multi-select' => [],
                    'text', 'textarea', 'url', 'email' => '',
                    default => null,
                };
            }
        }

        return $schema->normalise($name, $stored);
    }

    /**
     * Checks new values against the registry and config.xml. Pure, so it is unit tested alone.
     *
     * @param array<string, mixed> $current
     * @param array<mixed>         $values
     *
     * @return array{changes: array<string, array{from: mixed, to: mixed}>, errors: list<string>}
     */
    public static function checkValues(SettingsFile $schema, array $current, array $values): array
    {
        $changes = [];
        $errors = [];
        foreach ($values as $name => $value) {
            if (!\is_string($name) || !isset(self::REGISTRY[$name]) || $schema->field($name) === null) {
                $errors[] = 'Unknown or not changeable here: ' . substr((string) $name, 0, 60) . '.';
                continue;
            }
            $checked = $schema->checkValue($name, $value);
            $type = $schema->field($name)['type'];
            if ($checked['error'] === null && \is_string($checked['value']) && \in_array($type, ['text', 'textarea'], true)
                && (mb_strlen($checked['value']) > self::TEXT_MAX || strip_tags($checked['value']) !== $checked['value'])) {
                $checked['error'] = \sprintf('"%s" must be plain text of up to %d characters.', $name, self::TEXT_MAX);
            }
            if ($checked['error'] !== null) {
                $errors[] = 'Not an allowed value for ' . $name . ': ' . $checked['error'];
                continue;
            }
            $from = $current[$name] ?? null;
            if ($from !== $checked['value']) {
                $changes[self::CONFIG . $name] = ['from' => $from, 'to' => $checked['value']];
            }
        }

        return ['changes' => $changes, 'errors' => $errors];
    }

    /**
     * Plain lines for a change. Pure, so it is unit tested alone.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes keyed by system config key
     *
     * @return list<array{text: string, shoppers_see: bool}>
     */
    public static function describe(SettingsFile $schema, array $changes): array
    {
        $lines = [];
        foreach ($changes as $key => $change) {
            if ($key === self::BUTTON) {
                $lines[] = ['text' => 'Shopware\'s withdrawal button in the storefront footer (Settings > Basic information): ' . self::show($change['from']) . ' -> ' . self::show($change['to']), 'shoppers_see' => true];
                continue;
            }
            $name = substr($key, \strlen(self::CONFIG));
            $label = $schema->field($name)['label'] ?? $name;
            $lines[] = ['text' => ($label !== '' ? $label : $name) . ': ' . self::show($change['from']) . ' -> ' . self::show($change['to']), 'shoppers_see' => self::REGISTRY[$name] ?? true];
        }

        return $lines;
    }

    private static function show(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'on' : 'off';
        }
        if (\is_array($value)) {
            $items = array_map(static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '', $value);

            return $items === [] ? 'none' : implode(', ', $items);
        }
        if ($value === '' || $value === null) {
            return 'empty';
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Checks a change and keeps it under a code for apply_change. Nothing is saved.
     *
     * @param array<mixed>                                  $args
     * @param array{on: bool, changes: bool, details: bool} $switches
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    private function preview(array $args, array $switches, string $userId): array
    {
        $current = $this->current();
        $values = \is_array($args['settings'] ?? null) ? $args['settings'] : [];
        $checked = self::checkValues($this->schema, $current, $values);

        $plan = Recommended::plan($this->recommended->facts());
        $keys = \is_array($args['switch_on'] ?? null) ? array_values(array_unique(array_filter($args['switch_on'], 'is_string'))) : [];
        foreach ($keys as $key) {
            $field = Recommended::SWITCHES[$key] ?? null;
            if ($field === null || !\in_array($key, $plan, true)) {
                $checked['errors'][] = 'Not recommended for this shop, or already on: ' . substr($key, 0, 40) . '.';
                continue;
            }
            $full = self::CONFIG . $field;
            if (isset($checked['changes'][$full]) && $checked['changes'][$full]['to'] !== true) {
                $checked['errors'][] = 'switch_on ' . $key . ' and settings ' . $field . ' disagree.';
                continue;
            }
            $checked['changes'][$full] = ['from' => $current[$field] ?? false, 'to' => true];
        }

        $notes = [];
        if (!empty($args['create_withdrawal_page'])) {
            $button = $this->buttonState();
            if ($button === 'ready') {
                $checked['errors'][] = 'The withdrawal button and its page are already set up.';
            } elseif ($button === 'off') {
                $checked['changes'][self::BUTTON] = ['from' => false, 'to' => true];
                $notes[] = 'Shopware keeps the withdrawal page itself; Shopproof switches on Shopware\'s own button, which links to the page set in Settings > Basic information.';
            } else {
                $checked['errors'][] = match ($button) {
                    'unsupported' => 'This Shopware version has no withdrawal button. Update to Shopware 6.7.9 or later.',
                    default => 'Shopware\'s withdrawal button needs a page with the withdrawal request form, set under Settings > Basic information (Online revocation request page). The owner chooses or makes that page in Shopware; then preview again.',
                };
            }
        }

        if ($checked['errors'] !== []) {
            return self::fail(implode(' ', $checked['errors']));
        }
        if ($checked['changes'] === []) {
            return self::fail('Nothing would change.');
        }
        $notes = [...$notes, ...$this->ownValues(array_keys($checked['changes']))];
        $lines = self::describe($this->schema, $checked['changes']);
        $code = $this->store->savePreview($userId, ['changes' => $checked['changes']], time());

        return self::ok([
            'preview_code' => $code,
            'will_change' => $lines,
            'notes' => $notes,
            'next' => $switches['changes']
                ? 'Show these lines to the owner. Only after a clear yes, call apply_change with this preview_code.'
                : self::OFF,
            'works_minutes' => AssistantStore::PREVIEW_MINUTES,
        ]);
    }

    /**
     * Sales channels that keep their own value for a setting, so the owner knows a shop-wide
     * change does not reach them.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function ownValues(array $keys): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.configuration_key, COALESCE(t.name, LOWER(HEX(c.sales_channel_id))) AS channel
             FROM system_config c
             LEFT JOIN sales_channel sc ON sc.id = c.sales_channel_id
             LEFT JOIN sales_channel_translation t ON t.sales_channel_id = sc.id AND t.language_id = sc.language_id
             WHERE c.sales_channel_id IS NOT NULL AND c.configuration_key IN (:keys)',
            ['keys' => $keys],
            ['keys' => ArrayParameterType::STRING]
        );
        $out = [];
        foreach ($rows as $row) {
            $key = \is_string($row['configuration_key'] ?? null) ? $row['configuration_key'] : '';
            $name = $key === self::BUTTON ? 'the withdrawal button' : substr($key, \strlen(self::CONFIG));
            $out[] = \sprintf('Sales channel "%s" has its own value for %s, which stays as it is.', \is_scalar($row['channel'] ?? null) ? (string) $row['channel'] : '', $name);
        }

        return $out;
    }

    /**
     * Shopware's own withdrawal button: ready, off (page with the form set, button off), page
     * (no page with the form), or unsupported (before Shopware 6.7.9).
     */
    private function buttonState(): string
    {
        if (!class_exists(SetupFacts::EVENT_CLASS)) {
            return 'unsupported';
        }
        $page = $this->config->get(SetupFacts::PAGE);
        $ready = \is_string($page) && Uuid::isValid($page) && $this->setup->pageExists($page) && $this->setup->pageHasForm($page);
        if (!$ready) {
            return 'page';
        }

        return $this->config->getBool(self::BUTTON) ? 'ready' : 'off';
    }

    /**
     * Saves a previewed change, records it and logs it.
     *
     * @param array<mixed>                                  $args
     * @param array{on: bool, changes: bool, details: bool} $switches
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    private function apply(array $args, array $switches, string $userId): array
    {
        if (!$switches['changes']) {
            return self::fail(self::OFF);
        }
        $code = \is_string($args['preview_code'] ?? null) ? $args['preview_code'] : '';
        $now = time();
        $preview = $this->store->takePreview($code, $userId, $now);
        if ($preview === null) {
            return self::fail('This preview code is unknown, was used already or has run out. Call preview_change again.');
        }
        $changes = self::storedChanges($preview['changes'] ?? null);
        if ($changes === []) {
            return self::fail('This preview has nothing to change. Call preview_change again.');
        }
        // Values the shop changed since the preview make the preview stale.
        foreach ($changes as $key => $change) {
            if ($this->now($key) !== $change['from']) {
                return self::fail('Settings changed since the preview. Call preview_change again.');
            }
        }
        foreach ($changes as $key => $change) {
            $this->config->set($key, self::storable($change['to']));
        }
        $id = AssistantStore::changeId($now);
        $lines = self::describe($this->schema, $changes);
        $this->store->addChange($id, $userId, ['lines' => $lines, 'changes' => $changes], $now);
        if (!$this->log->append('assistant', self::logSummary($id, $changes))) {
            // Every change has its proof log entry, or it does not happen.
            foreach ($changes as $key => $change) {
                $this->config->set($key, self::storable($change['from']));
            }
            $this->store->removeChange($id);

            return self::fail(self::NO_LOG);
        }

        return self::ok([
            'change_id' => $id,
            'done' => $lines,
            'undo' => 'undo_change with this change_id puts the old values back.',
        ]);
    }

    /**
     * Values come back from the database as JSON scalars and lists; anything else is stored as
     * no value (the setting's default).
     *
     * @return array<mixed>|bool|float|int|string|null
     */
    private static function storable(mixed $value): array|bool|float|int|string|null
    {
        return \is_array($value) || \is_scalar($value) ? $value : null;
    }

    /**
     * The current shop-wide value of a key as the preview compared it.
     */
    private function now(string $key): mixed
    {
        if ($key === self::BUTTON) {
            return $this->config->getBool(self::BUTTON);
        }

        return self::value($this->schema, substr($key, \strlen(self::CONFIG)), $this->config->get($key));
    }

    /**
     * Only keys the assistant may change survive the round trip through the database.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function storedChanges(mixed $stored): array
    {
        $out = [];
        foreach (\is_array($stored) ? $stored : [] as $key => $change) {
            $key = (string) $key;
            $allowed = $key === self::BUTTON || (str_starts_with($key, self::CONFIG) && isset(self::REGISTRY[substr($key, \strlen(self::CONFIG))]));
            if ($allowed && \is_array($change) && \array_key_exists('from', $change) && \array_key_exists('to', $change)) {
                $out[$key] = ['from' => $change['from'], 'to' => $change['to']];
            }
        }

        return $out;
    }

    /**
     * What the proof log keeps about a change: setting names and plain values, never text the
     * owner typed. Pure, so it is unit tested alone.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes
     *
     * @return array<string, mixed>
     */
    public static function logSummary(string $id, array $changes): array
    {
        $plain = static fn (mixed $value): bool => \is_bool($value) || \is_int($value) || (\is_string($value) && preg_match('/^[a-z_-]{1,20}$/i', $value) === 1)
            || (\is_array($value) && \count($value) <= 30 && array_filter($value, static fn (mixed $v): bool => !\is_string($v) || preg_match('/^[A-Z]{2}$/', $v) !== 1) === []);
        $settings = [];
        foreach ($changes as $key => $change) {
            $name = $key === self::BUTTON ? 'withdrawalButton' : substr($key, \strlen(self::CONFIG));
            $settings[$name] = $plain($change['from']) && $plain($change['to']) ? ['from' => $change['from'], 'to' => $change['to']] : 'changed';
        }

        return ['by' => 'ai_assistant', 'change' => $id, 'settings' => $settings];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changeList(int $limit): array
    {
        return array_map(static fn (array $change): array => [
            'change_id' => $change['id'],
            'time' => $change['time'],
            'by_user' => $change['user'],
            'lines' => \is_array($change['data']['lines'] ?? null) ? $change['data']['lines'] : [],
            'undone' => $change['undone'] ?? 'no',
        ], $this->store->changes($limit));
    }

    /**
     * Undoes one change: puts back the values from before it where they are still as the change
     * left them. Used by the assistant and by the Undo button on the Shopproof page.
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    public function undoChange(string $id, string $by): array
    {
        $found = $id !== '' ? $this->store->changes(1, $id) : [];
        if ($found === []) {
            return self::fail('No change with this id.');
        }
        $now = time();
        if ($found[0]['undone'] !== null || !$this->store->markUndone($id, $now)) {
            return self::fail('This change was already undone.');
        }
        $changes = self::storedChanges($found[0]['data']['changes'] ?? null);
        $kept = [];
        $restored = [];
        foreach ($changes as $key => $change) {
            if ($this->now($key) !== $change['to']) {
                $kept[] = $key === self::BUTTON ? 'the withdrawal button' : substr($key, \strlen(self::CONFIG));
                continue;
            }
            $this->config->set($key, self::storable($change['from']));
            $restored[$key] = $change['to'];
        }
        if (!$this->log->append('assistant', ['by' => $by, 'undo' => $id, 'change' => $id, 'kept' => $kept])) {
            // Without its proof log entry the undo does not happen: put the values back.
            foreach ($restored as $key => $value) {
                $this->config->set($key, self::storable($value));
            }
            $this->store->unmarkUndone($id);

            return self::fail(self::NO_LOG);
        }

        return self::ok([
            'undone' => $id,
            'notes' => $kept === [] ? [] : ['Changed since by someone else, so left as it is: ' . implode(', ', $kept) . '.'],
        ]);
    }

    /**
     * @param array<mixed>                                  $args
     * @param array{on: bool, changes: bool, details: bool} $switches
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    private function undo(array $args, array $switches, string $userId): array
    {
        if (!$switches['changes']) {
            return self::fail(self::OFF);
        }

        return $this->undoChange(\is_string($args['change_id'] ?? null) ? $args['change_id'] : '', 'ai_assistant');
    }

    /**
     * Hides customer details unless the owner allows them. Pure, so it is unit tested alone.
     *
     * @param array<string, mixed> $item one item of WithdrawalStore::list()
     *
     * @return array<string, mixed>
     */
    public static function mask(array $item, bool $details): array
    {
        $clock = \is_array($item['clock'] ?? null) ? $item['clock'] : null;
        $out = [
            'reference' => $item['id'] ?? '',
            'order' => $item['orderNumber'] ?? null,
            'order_match' => \is_string($item['orderId'] ?? null) ? 'matched' : 'not matched',
            'order_date' => $item['orderDate'] ?? null,
            'items' => $item['items'] ?? '',
            'sales_channel' => $item['salesChannel'] ?? '',
            'received_at' => $item['submittedAt'] ?? '',
            'state' => $item['status'] ?? 'received',
            'return_arrived_at' => $item['returnArrivedAt'] ?? '',
            'refund_sent_at' => $item['refundSentAt'] ?? '',
            'delivered_on' => $item['deliveredOn'] ?? '',
            'business_signs' => $item['business'] ?? [],
        ];
        if ($clock !== null) {
            $out['window'] = $clock['window'] ?? '';
            $out['window_end'] = $clock['windowEnd'] ?? '';
            $out['refund'] = $clock['refund'] ?? '';
            $out['refund_due'] = $clock['refundDue'] ?? '';
            $out['refund_overdue'] = self::overdue($item);
            $out['refunded'] = $clock['refunded'] ?? '';
            $out['goods_back'] = $clock['goodsBack'] ?? '';
        }
        if ($details) {
            $out['name'] = trim((\is_string($item['firstName'] ?? null) ? $item['firstName'] : '') . ' ' . (\is_string($item['lastName'] ?? null) ? $item['lastName'] : ''));
            $out['email'] = $item['email'] ?? '';
            $out['comment'] = $item['comment'] ?? '';
        } else {
            $out['customer'] = 'hidden (the owner can turn on Include customer details)';
        }

        return $out;
    }

    /**
     * Whether the refund is late: the deadline clock says overdue (not "today", not "waiting"
     * for the goods). Pure.
     *
     * @param array<string, mixed> $item
     */
    public static function overdue(array $item): bool
    {
        $clock = \is_array($item['clock'] ?? null) ? $item['clock'] : [];

        return ($item['status'] ?? '') !== 'refund_sent' && ($clock['refund'] ?? '') === 'overdue';
    }

    /**
     * @param array<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function withdrawalList(array $args, bool $details, string $userId): array
    {
        $state = \is_string($args['state'] ?? null) && \in_array($args['state'], ['all', 'received', 'return_arrived', 'refund_sent', 'refund_overdue'], true) ? $args['state'] : 'all';
        $limit = self::limit($args, 20, 100);
        $zone = $this->zone($userId);
        $days = OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays'));
        $rows = [];
        // Filters need each record's clock, so read up to 500 records page by page.
        for ($page = 1; $page <= 5 && \count($rows) < $limit; ++$page) {
            $list = $this->withdrawals->list($page, 100, '', $zone, $days);
            foreach ($list['items'] as $item) {
                $matches = $state === 'all' || ($state === 'refund_overdue' ? self::overdue($item) : ($item['status'] ?? '') === $state);
                if ($matches && \count($rows) < $limit) {
                    $rows[] = self::mask($item, $details);
                }
            }
            if (\count($list['items']) < 100) {
                break;
            }
        }

        return ['withdrawals' => $rows, 'customer_details' => $details ? 'shown' : 'hidden'];
    }

    /**
     * @param array<mixed> $args
     *
     * @return array{ok: bool, data?: mixed, error?: string}
     */
    private function withdrawal(array $args, bool $details, string $userId): array
    {
        $reference = \is_string($args['reference'] ?? null) ? strtolower(trim($args['reference'])) : '';
        if ($reference === '') {
            return self::fail('Give the withdrawal reference.');
        }
        $days = OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays'));
        $list = Uuid::isValid($reference) ? $this->withdrawals->list(1, 1, '', $this->zone($userId), $days, $reference) : ['items' => []];
        if ($list['items'] === []) {
            return self::fail('No withdrawal with this reference.');
        }

        return self::ok(self::mask($list['items'][0], $details));
    }

    /**
     * @return array<string, mixed>
     */
    private function proofLog(int $limit): array
    {
        if (!$this->log->exists()) {
            return ['chain' => 'not checked: the proof log table is missing', 'entries' => []];
        }
        $check = $this->log->verifyAll();
        $rows = [];
        foreach ($this->log->page(1, $limit)['items'] as $row) {
            $rows[] = [
                'time' => $row['created_at'],
                'type' => self::PROOF_TYPES[$row['type']] ?? $row['type'],
                'summary' => json_decode($row['summary'], true),
            ];
        }

        return ['chain' => $check['ok'] ? 'intact' : 'broken', 'checked' => $check['checked'], 'entries' => $rows];
    }

    /** The admin user's time zone, for the deadline clock's days. */
    private function zone(string $userId): \DateTimeZone
    {
        $name = Uuid::isValid($userId) ? $this->connection->fetchOne('SELECT time_zone FROM `user` WHERE id = :id', ['id' => Uuid::fromHexToBytes($userId)]) : false;
        try {
            return new \DateTimeZone(\is_string($name) && \in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * @param array<mixed> $args
     */
    private static function limit(array $args, int $fallback, int $max): int
    {
        $limit = is_numeric($args['limit'] ?? null) ? (int) $args['limit'] : $fallback;

        return max(1, min($max, $limit));
    }

    /**
     * @return array{ok: bool, data: mixed}
     */
    private static function ok(mixed $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    /**
     * @return array{ok: bool, error: string}
     */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
