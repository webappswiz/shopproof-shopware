<?php declare(strict_types=1);

namespace Shopproof\Compliance\Assistant;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The AI assistant connection's switches, connection keys, preview codes and change list
 * (decision 0047). Switches in the system config; keys, previews and changes in the plugin's own
 * table `shopproof_assistant`, so the storefront never loads them.
 *
 * A key is shown once when it is made; only its SHA-256 hash is kept. Keys belong to the admin
 * user who made them, stop after KEY_DAYS days and work only while that user is active.
 */
final class AssistantStore
{
    public const TABLE = 'shopproof_assistant';

    /**
     * System config key of the three switches. Outside the plugin configuration on purpose:
     * neither the settings import nor the assistant itself can turn them on.
     */
    public const SWITCHES = 'ShopproofCompliance.assistant';

    public const KEY_DAYS = 90;

    /** Calls per key and hour, the same as the Shopify app. */
    public const CALLS_PER_HOUR = 600;

    public const PREVIEW_MINUTES = 30;

    public const CHANGES_KEPT = 100;

    /** Every key starts with this, so a leaked one is easy to recognise. */
    public const KEY_PREFIX = 'spsw_';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * @return array{on: bool, changes: bool, details: bool}
     */
    public function switches(): array
    {
        return self::readSwitches($this->config->get(self::SWITCHES));
    }

    /**
     * Pure, so it is unit tested alone. Allow changes and customer details need the connection on.
     *
     * @return array{on: bool, changes: bool, details: bool}
     */
    public static function readSwitches(mixed $stored): array
    {
        $stored = \is_array($stored) ? $stored : [];
        $on = ($stored['on'] ?? false) === true;

        return [
            'on' => $on,
            'changes' => $on && ($stored['changes'] ?? false) === true,
            'details' => $on && ($stored['details'] ?? false) === true,
        ];
    }

    public function saveSwitches(bool $on, bool $changes, bool $details): void
    {
        $this->config->set(self::SWITCHES, ['on' => $on, 'changes' => $on && $changes, 'details' => $on && $details]);
    }

    /**
     * Makes a key for an admin user. Returns the key itself, shown once, and its id.
     *
     * @return array{id: string, key: string, expires: string}
     */
    public function createKey(string $userId, int $now): array
    {
        $key = self::KEY_PREFIX . bin2hex(random_bytes(24));
        $id = bin2hex(random_bytes(8));
        $this->connection->insert(self::TABLE, [
            'kind' => 'key',
            'code' => $id,
            'user_id' => Uuid::isValid($userId) ? Uuid::fromHexToBytes($userId) : null,
            'data' => (string) json_encode(['hash' => hash('sha256', $key)]),
            'created_at' => self::time($now),
            'expires_at' => self::time($now + self::KEY_DAYS * 86400),
        ]);

        return ['id' => $id, 'key' => $key, 'expires' => gmdate('c', $now + self::KEY_DAYS * 86400)];
    }

    /**
     * @return list<array{id: string, user: string, created: string, expires: string, lastUsed: string|null, expired: bool}>
     */
    public function keys(int $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.code, a.created_at, a.expires_at, a.used_at, COALESCE(u.username, \'\') AS username
             FROM ' . self::TABLE . ' a LEFT JOIN `user` u ON u.id = a.user_id
             WHERE a.kind = \'key\' ORDER BY a.created_at DESC'
        );
        $out = [];
        foreach ($rows as $row) {
            $expires = self::str($row['expires_at'] ?? null);
            $out[] = [
                'id' => self::str($row['code'] ?? null),
                'user' => self::str($row['username'] ?? null),
                'created' => self::atom(self::str($row['created_at'] ?? null)),
                'expires' => self::atom($expires),
                'lastUsed' => self::str($row['used_at'] ?? null) !== '' ? self::atom(self::str($row['used_at'])) : null,
                'expired' => $expires === '' || strtotime($expires . ' UTC') <= $now,
            ];
        }

        return $out;
    }

    public function removeKey(string $id): bool
    {
        $this->connection->delete(self::TABLE, ['kind' => 'rate', 'code' => $id]);

        return $this->connection->delete(self::TABLE, ['kind' => 'key', 'code' => $id]) > 0;
    }

    /**
     * The admin user a key belongs to, with what that user may do now, or null when the key is
     * unknown, expired, or its user is gone, no longer active or no longer has the right to read
     * Shopproof's settings. The user's rights are read again on every call, so a key stops as soon
     * as its user loses them. Records the time it was used.
     *
     * @return array{user: string, key: string, rights: array{settings: bool, change: bool, orders: bool}}|null
     */
    public function userForKey(string $key, int $now): ?array
    {
        if (!str_starts_with($key, self::KEY_PREFIX) || \strlen($key) !== \strlen(self::KEY_PREFIX) + 48) {
            return null;
        }
        $hash = hash('sha256', $key);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.code, a.data, a.expires_at, LOWER(HEX(u.id)) AS user_id, u.active, u.admin
             FROM ' . self::TABLE . ' a LEFT JOIN `user` u ON u.id = a.user_id
             WHERE a.kind = \'key\''
        );
        foreach ($rows as $row) {
            $data = json_decode(self::str($row['data'] ?? null), true);
            $stored = \is_array($data) && \is_string($data['hash'] ?? null) ? $data['hash'] : '';
            if ($stored === '' || !hash_equals($stored, $hash)) {
                continue;
            }
            $expires = self::str($row['expires_at'] ?? null);
            $user = self::str($row['user_id'] ?? null);
            if (!self::keyUsable($expires, $user, is_numeric($row['active'] ?? null) && (int) $row['active'] === 1, $now)) {
                return null;
            }
            $admin = is_numeric($row['admin'] ?? null) && (int) $row['admin'] === 1;
            $rights = self::rights($admin, $admin ? [] : $this->privileges($user));
            if (!$rights['settings']) {
                return null;
            }
            $code = self::str($row['code'] ?? null);
            $this->connection->update(self::TABLE, ['used_at' => self::time($now)], ['kind' => 'key', 'code' => $code]);

            return ['user' => $user, 'key' => $code, 'rights' => $rights];
        }

        return null;
    }

    /**
     * The privileges of the user's roles, as Shopware stores them (one JSON list per role).
     *
     * @return list<string>
     */
    private function privileges(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }
        $lists = $this->connection->fetchFirstColumn(
            'SELECT r.privileges FROM `acl_user_role` ur INNER JOIN `acl_role` r ON r.id = ur.acl_role_id
             WHERE ur.user_id = :user AND r.deleted_at IS NULL',
            ['user' => Uuid::fromHexToBytes($userId)]
        );
        $out = [];
        foreach ($lists as $list) {
            $decoded = json_decode(self::str($list), true);
            foreach (\is_array($decoded) ? $decoded : [] as $privilege) {
                if (\is_string($privilege)) {
                    $out[] = $privilege;
                }
            }
        }

        return $out;
    }

    /**
     * What a key's user may do, from Shopware's admin flag or role privileges: the same rights
     * the Shopproof page asks for (settings read and update) and order read for withdrawals.
     * Pure, so it is unit tested alone.
     *
     * @param list<string> $privileges
     *
     * @return array{settings: bool, change: bool, orders: bool}
     */
    public static function rights(bool $admin, array $privileges): array
    {
        if ($admin) {
            return ['settings' => true, 'change' => true, 'orders' => true];
        }
        $has = static fn (string $privilege): bool => \in_array($privilege, $privileges, true);
        $settings = $has('system_config:read');

        return [
            'settings' => $settings,
            'change' => $settings && $has('system_config:update'),
            'orders' => $has('order:read'),
        ];
    }

    /**
     * Counts one call (or one batch of calls) against the key's hourly limit. Returns the seconds
     * to wait when the limit is reached, or 0 when the call may go ahead. The count lives in the
     * same table and is raised in one statement, so two calls at once cannot both slip through.
     */
    public function overLimit(string $keyCode, int $calls, int $now): int
    {
        $calls = max(1, $calls);
        $end = self::time($now + 3600);
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (`kind`, `code`, `data`, `created_at`, `expires_at`)
             VALUES (\'rate\', :code, :calls1, :now1, :end1)
             ON DUPLICATE KEY UPDATE
               `data` = IF(`expires_at` <= :now2, :calls2, CAST(`data` AS UNSIGNED) + :calls3),
               `expires_at` = IF(`expires_at` <= :now3, :end2, `expires_at`)',
            [
                'code' => $keyCode,
                'calls1' => (string) $calls, 'calls2' => (string) $calls, 'calls3' => $calls,
                'now1' => self::time($now), 'now2' => self::time($now), 'now3' => self::time($now),
                'end1' => $end, 'end2' => $end,
            ]
        );
        $row = $this->connection->fetchAssociative(
            'SELECT `data`, `expires_at` FROM ' . self::TABLE . ' WHERE `kind` = \'rate\' AND `code` = :code',
            ['code' => $keyCode]
        );
        $count = \is_array($row) && is_numeric($row['data'] ?? null) ? (int) $row['data'] : 0;
        $resets = \is_array($row) ? strtotime(self::str($row['expires_at'] ?? null) . ' UTC') : false;

        return self::waitFor($count, $resets === false ? $now + 3600 : $resets, $now);
    }

    /**
     * Seconds to wait once the count is above the hourly limit, else 0. Pure, so it is unit
     * tested alone.
     */
    public static function waitFor(int $count, int $resets, int $now): int
    {
        return $count > self::CALLS_PER_HOUR ? max(1, $resets - $now) : 0;
    }

    /**
     * Whether a key that matched may be used now. Pure, so it is unit tested alone.
     */
    public static function keyUsable(string $expiresAt, string $userId, bool $userActive, int $now): bool
    {
        $expires = $expiresAt !== '' ? strtotime($expiresAt . ' UTC') : false;

        return $expires !== false && $now < $expires && $userId !== '' && $userActive;
    }

    /**
     * Keeps a checked change under a new code for PREVIEW_MINUTES minutes.
     *
     * @param array<string, mixed> $data
     */
    public function savePreview(string $userId, array $data, int $now): string
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE kind = \'preview\' AND expires_at < :now',
            ['now' => self::time($now)]
        );
        $code = self::code(12);
        $this->connection->insert(self::TABLE, [
            'kind' => 'preview',
            'code' => $code,
            'user_id' => Uuid::fromHexToBytes($userId),
            'data' => (string) json_encode($data, \JSON_UNESCAPED_UNICODE),
            'created_at' => self::time($now),
            'expires_at' => self::time($now + self::PREVIEW_MINUTES * 60),
        ]);

        return $code;
    }

    /**
     * Takes a preview: returns it and removes it, so each code works once. Null when unknown,
     * expired or made for another user.
     *
     * @return array<string, mixed>|null
     */
    public function takePreview(string $code, string $userId, int $now): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{12}$/', $code) !== 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT data, LOWER(HEX(user_id)) AS user_id, expires_at FROM ' . self::TABLE . ' WHERE kind = \'preview\' AND code = :code',
            ['code' => $code]
        );
        if ($row === false) {
            return null;
        }
        $deleted = $this->connection->delete(self::TABLE, ['kind' => 'preview', 'code' => $code]);
        $expires = strtotime(self::str($row['expires_at'] ?? null) . ' UTC');
        if ($deleted !== 1 || self::str($row['user_id'] ?? null) !== $userId || $expires === false || $expires <= $now) {
            return null;
        }
        $data = json_decode(self::str($row['data'] ?? null), true);

        return \is_array($data) ? self::stringKeys($data) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addChange(string $id, ?string $userId, array $data, int $now): void
    {
        $this->connection->insert(self::TABLE, [
            'kind' => 'change',
            'code' => $id,
            'user_id' => $userId !== null && Uuid::isValid($userId) ? Uuid::fromHexToBytes($userId) : null,
            'data' => (string) json_encode($data, \JSON_UNESCAPED_UNICODE),
            'created_at' => self::time($now),
        ]);
        $keep = $this->connection->fetchFirstColumn(
            'SELECT code FROM ' . self::TABLE . ' WHERE kind = \'change\' ORDER BY created_at DESC, code DESC LIMIT ' . self::CHANGES_KEPT
        );
        if (\count($keep) >= self::CHANGES_KEPT) {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE kind = \'change\' AND code NOT IN (:keep)',
                ['keep' => $keep],
                ['keep' => ArrayParameterType::STRING]
            );
        }
    }

    /**
     * Changes, newest first.
     *
     * @return list<array{id: string, time: string, user: string, undone: string|null, data: array<string, mixed>}>
     */
    public function changes(int $limit, ?string $id = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.code, a.data, a.created_at, a.undone_at, COALESCE(u.username, \'\') AS username
             FROM ' . self::TABLE . ' a LEFT JOIN `user` u ON u.id = a.user_id
             WHERE a.kind = \'change\'' . ($id !== null ? ' AND a.code = :code' : '') . '
             ORDER BY a.created_at DESC, a.code DESC LIMIT ' . max(1, min(self::CHANGES_KEPT, $limit)),
            $id !== null ? ['code' => $id] : []
        );
        $out = [];
        foreach ($rows as $row) {
            $data = json_decode(self::str($row['data'] ?? null), true);
            $out[] = [
                'id' => self::str($row['code'] ?? null),
                'time' => self::atom(self::str($row['created_at'] ?? null)),
                'user' => self::str($row['username'] ?? null),
                'undone' => self::str($row['undone_at'] ?? null) !== '' ? self::atom(self::str($row['undone_at'])) : null,
                'data' => \is_array($data) ? self::stringKeys($data) : [],
            ];
        }

        return $out;
    }

    /**
     * Marks a change as undone. False when it was undone already (by another request too).
     */
    public function markUndone(string $id, int $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET undone_at = :now WHERE kind = \'change\' AND code = :code AND undone_at IS NULL',
            ['now' => self::time($now), 'code' => $id]
        ) === 1;
    }

    /** Takes back an undo mark when the undo could not be finished. */
    public function unmarkUndone(string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET undone_at = NULL WHERE kind = \'change\' AND code = :code',
            ['code' => $id]
        );
    }

    /** Removes a change from the list when it could not be finished. */
    public function removeChange(string $id): void
    {
        $this->connection->delete(self::TABLE, ['kind' => 'change', 'code' => $id]);
    }

    /** A change id: time and four random characters, as the WooCommerce plugin's. */
    public static function changeId(int $now): string
    {
        return gmdate('Ymd-His', $now) . '-' . self::code(4);
    }

    private static function code(int $length): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $chars[random_int(0, \strlen($chars) - 1)];
        }

        return $out;
    }

    private static function time(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    private static function atom(string $mysql): string
    {
        $time = $mysql !== '' ? strtotime($mysql . ' UTC') : false;

        return $time === false ? '' : gmdate('c', $time);
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
