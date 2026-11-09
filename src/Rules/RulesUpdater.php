<?php declare(strict_types=1);

namespace Shopproof\Compliance\Rules;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads the public rules file. A failed or unusable download keeps the last good copy.
 */
class RulesUpdater
{
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
     * The stored file, downloading it first when there is none yet (right after install).
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $file = $this->store->get();
        if ($file === null && $this->refresh()) {
            $file = $this->store->get();
        }

        return $file;
    }
}
