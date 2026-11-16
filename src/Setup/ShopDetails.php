<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The shop's own name, postal address, email and website from Settings > Basic information: the
 * company details where they are filled in, else the shop name, the address box and the shop
 * email. Used to fill in the EU model withdrawal form and, on the merchant's click, the default
 * manufacturer for goods the shop makes itself (as the Shopify app's safety profile prefill).
 */
class ShopDetails
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{name: string, street: string, zip: string, city: string, country: string, email: string, url: string}
     */
    public function get(?string $channel, string $languageId = Defaults::LANGUAGE_SYSTEM): array
    {
        $get = fn (string $key): string => trim(strip_tags($this->config->getString('core.basicInformation.' . $key, $channel)));
        $street = $get('companyStreet');
        $zip = $get('companyZipcode');
        $city = $get('companyCity');
        $country = '';
        $countryId = $this->config->get('core.basicInformation.companyCountryId', $channel);
        if (\is_string($countryId) && Uuid::isValid($countryId) && Uuid::isValid($languageId)) {
            $name = $this->connection->fetchOne(
                'SELECT COALESCE(t.name, d.name) FROM country c
                 LEFT JOIN country_translation t ON t.country_id = c.id AND t.language_id = :language
                 LEFT JOIN country_translation d ON d.country_id = c.id AND d.language_id = UNHEX(:default)
                 WHERE c.id = :id',
                ['language' => Uuid::fromHexToBytes($languageId), 'default' => Defaults::LANGUAGE_SYSTEM, 'id' => Uuid::fromHexToBytes($countryId)]
            );
            $country = \is_string($name) ? $name : '';
        }
        if ($street === '' && $zip === '' && $city === '') {
            $street = self::addressLine($this->config->getString('core.basicInformation.address', $channel));
            $country = '';
        }
        $url = $get('companyUrl');

        return [
            'name' => $get('companyName') !== '' ? $get('companyName') : $get('shopName'),
            'street' => $street,
            'zip' => $zip,
            'city' => $city,
            'country' => $country,
            'email' => $get('companyEmail') !== '' ? $get('companyEmail') : $get('email'),
            'url' => preg_match('#^https://\S+$#i', $url) === 1 ? $url : '',
        ];
    }

    /**
     * The free address box (lines or <br>) as one line.
     */
    public static function addressLine(string $box): string
    {
        $lines = preg_split('/\R|<br\s*\/?>/i', $box) ?: [];
        $lines = array_map(static fn (string $line): string => trim(strip_tags($line)), $lines);

        return implode(', ', array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    /**
     * The postal address as lines, for the manufacturer address field.
     *
     * @param array{street: string, zip: string, city: string, country: string} $shop
     */
    public static function postal(array $shop): string
    {
        $town = trim($shop['zip'] . ' ' . $shop['city']);

        return implode("\n", array_values(array_filter([$shop['street'], $town, $shop['country']], static fn (string $v): bool => $v !== '')));
    }

    /**
     * What the shop offers, what is set, and which empty fields a click fills.
     *
     * @param array{name: string, address: string, email: string, url: string} $shop
     * @param array{name: string, address: string, email: string, url: string} $current
     *
     * @return array{shop: array{name: string, address: string, email: string, url: string}, current: array{name: string, address: string, email: string, url: string}, fills: list<string>}
     */
    public static function offer(array $shop, array $current): array
    {
        $fills = [];
        // Without a name the details would describe nobody; a set name that is someone else's
        // means the default manufacturer is another company, so nothing is mixed in.
        $sameMaker = $current['name'] === '' || mb_strtolower(trim($current['name'])) === mb_strtolower(trim($shop['name']));
        if ($shop['name'] !== '' && $sameMaker) {
            foreach (['name', 'address', 'email', 'url'] as $key) {
                if ($current[$key] === '' && $shop[$key] !== '') {
                    $fills[] = $key;
                }
            }
        }

        return ['shop' => $shop, 'current' => $current, 'fills' => $fills];
    }
}
