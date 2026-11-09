<?php declare(strict_types=1);

namespace Shopproof\Compliance\Rules;

use Doctrine\DBAL\Connection;

/**
 * The last good copy of the rules file, in the plugin's own table.
 */
class RulesStore
{
    private const NAME = 'rules';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $file a file that passed RulesFile::parse()
     */
    public function save(array $file, string $body, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'REPLACE INTO `shopproof_rules_cache` (`name`, `version`, `body`, `fetched_at`) VALUES (:name, :version, :body, :fetched)',
            [
                'name' => self::NAME,
                'version' => RulesFile::text($file, 'version'),
                'body' => $body,
                'fetched' => $now->format('Y-m-d H:i:s.v'),
            ]
        );
    }

    /**
     * The stored file, or null if none has been downloaded yet. The stored copy is parsed again,
     * so a damaged row is treated as missing.
     *
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $body = $this->connection->fetchOne(
            'SELECT `body` FROM `shopproof_rules_cache` WHERE `name` = :name',
            ['name' => self::NAME]
        );

        return \is_string($body) ? RulesFile::parse($body) : null;
    }

    /**
     * When the stored copy was downloaded, or null when there is none.
     */
    public function fetchedAt(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT `fetched_at` FROM `shopproof_rules_cache` WHERE `name` = :name',
            ['name' => self::NAME]
        );
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
