<?php declare(strict_types=1);

namespace Shopproof\Compliance\Rules;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The rules the plugin works with: the copy shipped inside the plugin, or a newer one the merchant
 * chose to download. Nothing is downloaded unless the merchant asks for it (the button in the
 * Shopproof overview, the daily update setting, or the console command). A failed or unusable
 * download keeps the last good copy.
 */
class RulesUpdater
{
    /** The public rules file as it was when this plugin version was built. */
    public const BUNDLED = __DIR__ . '/../Resources/rules/rules.json';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RulesStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when a new copy was stored
     */
    public function refresh(): bool
    {
        try {
            $response = $this->client->request('GET', RulesFile::URL, [
                'timeout' => 10,
                'connect_timeout' => 5,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $error) {
            $this->logger->warning('Shopproof: rules download failed, keeping the last good copy.', ['error' => $error->getMessage()]);

            return false;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning('Shopproof: rules download answered ' . $response->getStatusCode() . ', keeping the last good copy.');

            return false;
        }

        $body = (string) $response->getBody();
        $file = RulesFile::parse($body);
        if ($file === null) {
            $this->logger->warning('Shopproof: downloaded rules file is not usable, keeping the last good copy.');

            return false;
        }

        $this->store->save($file, $body, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return true;
    }

    /**
     * The newer of the downloaded copy and the copy shipped with the plugin. Never downloads.
     *
     * @return array{file: array<string, mixed>|null, downloaded: bool}
     */
    public function current(): array
    {
        return self::newer($this->store->get(), self::bundled());
    }

    /**
     * The copy shipped with the plugin, or null if it is missing or damaged.
     *
     * @return array<string, mixed>|null
     */
    public static function bundled(string $path = self::BUNDLED): ?array
    {
        $body = is_file($path) ? file_get_contents($path) : false;

        return \is_string($body) ? RulesFile::parse($body) : null;
    }

    /**
     * Picks the downloaded copy unless the shipped copy is newer (after a plugin update).
     *
     * @param array<string, mixed>|null $downloaded
     * @param array<string, mixed>|null $bundled
     *
     * @return array{file: array<string, mixed>|null, downloaded: bool}
     */
    public static function newer(?array $downloaded, ?array $bundled): array
    {
        if ($downloaded === null) {
            return ['file' => $bundled, 'downloaded' => false];
        }
        if ($bundled !== null && version_compare(RulesFile::text($bundled, 'version'), RulesFile::text($downloaded, 'version'), '>')) {
            return ['file' => $bundled, 'downloaded' => false];
        }

        return ['file' => $downloaded, 'downloaded' => true];
    }
}
