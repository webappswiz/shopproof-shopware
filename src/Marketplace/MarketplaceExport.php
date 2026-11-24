<?php declare(strict_types=1);

namespace Shopproof\Compliance\Marketplace;

/**
 * Product safety (GPSR) details in eBay and Amazon formats (decision 0046). Pure: the caller
 * passes the shop's safety details per SKU and, for eBay, the listings file eBay itself made.
 *
 * The same logic lives in packages/widgets/src/marketplace-export.mjs (Shopify app) and in the
 * WooCommerce plugin; all three run packages/widgets/test/marketplace-export-cases.json. The column
 * names, lengths and patterns come from Resources/config/marketplace-export.json, a copy of
 * packages/widgets/data/marketplace-export.json, and the country names from
 * Resources/config/countries.json. Sources for the eBay and Amazon names are in the decision record.
 */
final class MarketplaceExport
{
    /** Folder with marketplace-export.json and countries.json; the plugin's config folder by default. */
    public static string $dir = __DIR__ . '/../Resources/config/';

    /**
     * Unicode spaces turned into one plain space, the same set the JavaScript version uses.
     */
    private const SPACES = '/[\s\x{00a0}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}\x{feff}]+/u';

    /**
     * Loaded field names and patterns.
     *
     * @var array{max:array<string,int>,manufacturer:list<string>,responsible:list<string>,responsibleType:string,amazon:list<string>,euEea:list<string>,postcodes:array<string,string>,prefixes:array<string,string>,patterns:array<string,string>}|null
     */
    private static $spec = null;

    /**
     * Loaded country names.
     *
     * @var array{codes:list<string>,names:array<string,string>}|null
     */
    private static $countries = null;

    /**
     * Field names, lengths and patterns, from the shipped copy of the shared file.
     *
     * @return array{max:array<string,int>,manufacturer:list<string>,responsible:list<string>,responsibleType:string,amazon:list<string>,euEea:list<string>,postcodes:array<string,string>,prefixes:array<string,string>,patterns:array<string,string>}
     */
    public static function spec() {
        if (null === self::$spec) {
            $data       = json_decode((string) file_get_contents(self::$dir . 'marketplace-export.json'), true);
            $data       = is_array($data) ? $data : [];
            $ebay       = isset($data['ebay']) && is_array($data['ebay']) ? $data['ebay'] : [];
            $amazon     = isset($data['amazon']) && is_array($data['amazon']) ? $data['amazon'] : [];
            $max        = [];
            foreach (isset($ebay['max']) && is_array($ebay['max']) ? $ebay['max'] : [] as $key => $value) {
                $max[(string) $key] = is_int($value) ? $value : 0;
            }
            self::$spec = [
                'max'             => $max,
                'manufacturer'    => self::strings(isset($ebay['manufacturer']) ? $ebay['manufacturer'] : null),
                'responsible'     => self::strings(isset($ebay['responsible']) ? $ebay['responsible'] : null),
                'responsibleType' => isset($ebay['responsibleType']) && is_string($ebay['responsibleType']) ? $ebay['responsibleType'] : '',
                'amazon'          => self::strings(isset($amazon['listing']) ? $amazon['listing'] : null),
                'euEea'           => self::strings(isset($data['euEea']) ? $data['euEea'] : null),
                'postcodes'       => self::stringMap(isset($data['postcodes']) ? $data['postcodes'] : null),
                'prefixes'        => self::stringMap(isset($data['prefixes']) ? $data['prefixes'] : null),
                'patterns'        => self::stringMap(isset($data['patterns']) ? $data['patterns'] : null),
            ];
        }
        return self::$spec;
    }

    /**
     * The strings of a JSON list.
     *
     * @param mixed $value Decoded JSON.
     * @return list<string>
     */
    private static function strings($value) {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * The string values of a JSON object.
     *
     * @param mixed $value Decoded JSON.
     * @return array<string,string>
     */
    private static function stringMap($value) {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($item)) {
                $out[(string) $key] = $item;
            }
        }
        return $out;
    }

    /**
     * A longest length from the spec.
     *
     * @param string $key name, line, city, postalCode, state or email.
     * @return int
     */
    private static function max($key) {
        $spec = self::spec();
        return isset($spec['max'][$key]) ? $spec['max'][$key] : 0;
    }

    /**
     * Country names and codes.
     *
     * @return array{codes:list<string>,names:array<string,string>}
     */
    private static function countries() {
        if (null === self::$countries) {
            $data            = json_decode((string) file_get_contents(self::$dir . 'countries.json'), true);
            self::$countries = [
                'codes' => self::strings(is_array($data) && isset($data['codes']) ? $data['codes'] : null),
                'names' => self::stringMap(is_array($data) && isset($data['names']) ? $data['names'] : null),
            ];
        }
        return self::$countries;
    }

    /**
     * Whether a pattern from the spec matches.
     *
     * @param string $name Pattern name.
     * @param string $text Text.
     * @return bool
     */
    private static function match($name, $text) {
        return [] !== self::groups($name, $text);
    }

    /**
     * The groups of a pattern from the spec: '' for a group that took part in nothing, and an
     * empty array when the pattern does not match.
     *
     * @param string $name Pattern name.
     * @param string $text Text.
     * @return array<int,string>
     */
    private static function groups($name, $text) {
        $spec    = self::spec();
        $pattern = isset($spec['patterns'][$name]) ? $spec['patterns'][$name] : '(?!)';
        $matches = [];
        if (1 !== preg_match('~' . $pattern . '~u', $text, $matches)) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $out[$i] = isset($matches[$i]) ? $matches[$i] : '';
        }
        return $out;
    }

    /**
     * Single spaces, trimmed.
     *
     * @param mixed $value Value.
     * @return string
     */
    public static function tidy($value) {
        $value = is_scalar($value) ? (string) $value : '';
        return trim((string) preg_replace(self::SPACES, ' ', $value), ' ');
    }

    /**
     * Lower case.
     *
     * @param string $value Value.
     * @return string
     */
    private static function lower($value) {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Length in characters.
     *
     * @param string $value Value.
     * @return int
     */
    private static function length($value) {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * Names match when they differ only in case or spacing.
     *
     * @param string $a Name.
     * @param string $b Name.
     * @return bool
     */
    public static function sameName($a, $b) {
        return self::lower(self::tidy($a)) === self::lower(self::tidy($b));
    }

    /**
     * ISO code of a written country name or code, or ''.
     *
     * @param string $text Country as written.
     * @return string
     */
    public static function countryCode($text) {
        $value     = (string) preg_replace('/\.$/u', '', self::tidy($text));
        $countries = self::countries();
        if (1 === preg_match('/^[A-Z]{2}$/', $value) && in_array($value, $countries['codes'], true)) {
            return $value;
        }
        $key = self::lower($value);
        return isset($countries['names'][$key]) ? $countries['names'][$key] : '';
    }

    /**
     * A free-text postal address split into eBay's fields. Never guesses: anything it cannot read
     * comes back as a problem code, and the merchant fixes the address in the shop.
     *
     * @param string $text Address as written (lines, or one line with commas).
     * @param string $name Company name, dropped when the address repeats it on its first line.
     * @return array{street1:string,street2:string,city:string,postalCode:string,state:string,country:string,problems:list<string>}
     */
    public static function splitAddress($text, $name = '') {
        $spec  = self::spec();
        $out   = [
            'street1'    => '',
            'street2'    => '',
            'city'       => '',
            'postalCode' => '',
            'state'      => '',
            'country'    => '',
            'problems'   => [],
        ];
        $raw   = (string) preg_replace('/\r\n?/', "\n", $text);
        $parts = [];
        foreach (explode("\n", $raw) as $line) {
            foreach (explode(',', $line) as $piece) {
                $part = self::tidy($piece);
                if ('' === $part) {
                    continue;
                }
                // "Via Roma, 12": a house number after a comma belongs to the street before it.
                $count = count($parts);
                if ($count && self::match('houseNumber', $part) && 1 !== preg_match('/\d$/u', $parts[$count - 1])) {
                    $parts[$count - 1] .= ' ' . $part;
                } else {
                    $parts[] = $part;
                }
            }
        }
        $parts = array_values(
            array_filter(
                $parts,
                static function ($p) {
                    return ! self::match('contact', $p) && ! self::match('phone', $p);
                }
            )
        );
        if ($parts && '' !== $name && self::sameName($parts[0], $name)) {
            array_shift($parts);
        }
        if (! $parts) {
            $out['problems'][] = 'address';
            return $out;
        }
        $last = self::countryCode($parts[count($parts) - 1]);
        if ('' !== $last) {
            $out['country'] = $last;
            array_pop($parts);
        }
        // The postcode and town: the last part that reads as one.
        $at  = -1;
        $end = -1;
        for ($i = count($parts) - 1; $i >= 0 && $at < 0; $i--) {
            $part = $parts[$i];
            if ('GB' === $out['country']) {
                $m = self::groups('gbPostal', $part);
                if ($m) {
                    $out['city']       = $m[1];
                    $out['postalCode'] = $m[2];
                    $at                = $i;
                    $end               = $i;
                } elseif ($i > 0 && self::match('gbPostcode', $part)) {
                    $out['city']       = $parts[$i - 1];
                    $out['postalCode'] = $part;
                    $at                = $i - 1;
                    $end               = $i;
                }
            } elseif ('US' === $out['country']) {
                $m = self::groups('usStateZip', $part);
                if ($m && ('' !== $m[1] || $i > 0)) {
                    $own               = '' !== $m[1];
                    $out['city']       = $own ? $m[1] : $parts[$i - 1];
                    $out['state']      = $m[2];
                    $out['postalCode'] = $m[3];
                    $at                = $own ? $i : $i - 1;
                    $end               = $i;
                }
            } elseif ([] !== self::groups('euPostal', $part)) {
                $m      = self::groups('euPostal', $part);
                $prefix = '';
                if ('' !== $m[1]) {
                    $prefix = isset($spec['prefixes'][$m[1]]) ? $spec['prefixes'][$m[1]] : '?';
                }
                if ('?' === $prefix) {
                    continue;
                }
                if ('' !== $prefix && '' === $out['country']) {
                    $out['country'] = $prefix;
                }
                if ('' !== $prefix && $prefix !== $out['country']) {
                    $out['problems'][] = 'country';
                    return $out;
                }
                $out['postalCode'] = $m[2];
                $out['city']       = $m[3];
                $at                = $i;
                $end               = $i;
            }
        }
        if ('' === $out['country']) {
            $out['problems'][] = 'country';
        }
        if ($at < 0) {
            $out['problems'][] = 'postcode';
            return $out;
        }
        $pattern = isset($spec['postcodes'][$out['country']]) ? $spec['postcodes'][$out['country']] : null;
        if ('' !== $out['country'] && (null === $pattern || 1 !== preg_match('~' . $pattern . '~u', $out['postalCode']))) {
            $out['problems'][] = 'postcode';
        }
        $street = array_slice($parts, 0, $at);
        $after  = array_slice($parts, $end + 1);
        if (! $street) {
            $out['problems'][] = 'street';
        }
        // One street line when it fits eBay's length, else the first part and the rest.
        $joined         = implode(', ', $street);
        $fits           = self::length($joined) <= self::max('line');
        $out['street1'] = $fits ? $joined : $street[0];
        $out['street2'] = $fits ? '' : implode(', ', array_slice($street, 1));
        if (1 === count($after) && '' === $out['state']) {
            $out['state'] = $after[0];
        } elseif ($after) {
            $out['problems'][] = 'address';
        }
        if (self::length($out['street1']) > self::max('line') || self::length($out['street2']) > self::max('line') || self::length($out['city']) > self::max('city') || self::length($out['postalCode']) > self::max('postalCode') || self::length($out['state']) > self::max('state')) {
            $out['problems'][] = 'too-long';
        }
        return $out;
    }

    /**
     * A party's value.
     *
     * @param mixed  $party Party array or null.
     * @param string $key   Field.
     * @return string
     */
    private static function field($party, $key) {
        return is_array($party) && isset($party[$key]) && is_scalar($party[$key]) ? (string) $party[$key] : '';
    }

    /**
     * One party (manufacturer or responsible person) as eBay cells, with its problems.
     *
     * @param mixed $party Party: name, address, email, url.
     * @return array{cells:list<string>,country:string,problems:list<string>}
     */
    private static function ebayParty($party) {
        $name     = self::tidy(self::field($party, 'name'));
        $email    = self::tidy(self::field($party, 'email'));
        $address  = self::splitAddress(self::field($party, 'address'), $name);
        $problems = $address['problems'];
        if (! self::match('email', $email)) {
            $problems[] = 'email';
        }
        if (self::length($name) > self::max('name') || self::length($email) > self::max('email')) {
            $problems[] = 'too-long';
        }
        return [
            'cells'    => [$name, $address['street1'], $address['street2'], $address['city'], $address['country'], $address['postalCode'], $address['state'], $email],
            'country'  => $address['country'],
            'problems' => array_values(array_unique($problems)),
        ];
    }

    /**
     * Whether a maker in this country needs an EU responsible person flagged when none is set.
     * Northern Ireland follows the GPSR too, so a maker in the United Kingdom is not flagged.
     *
     * @param string $country ISO code.
     * @return bool
     */
    private static function outsideEu($country) {
        $spec = self::spec();
        return '' !== $country && 'GB' !== $country && ! in_array($country, $spec['euEea'], true);
    }

    /**
     * A lookup result as two parties, each an array of strings or null.
     *
     * @param array<mixed> $data manufacturer and responsible.
     * @return array{manufacturer:array<string,string>|null,responsible:array<string,string>|null}
     */
    private static function parties(array $data) {
        $party = static function ($value) {
            if (! is_array($value)) {
                return null;
            }
            $out = [];
            foreach (['name', 'address', 'email', 'url'] as $key) {
                $out[$key] = isset($value[$key]) && is_scalar($value[$key]) ? (string) $value[$key] : '';
            }
            return $out;
        };
        return [
            'manufacturer' => $party(isset($data['manufacturer']) ? $data['manufacturer'] : null),
            'responsible'  => $party(isset($data['responsible']) ? $data['responsible'] : null),
        ];
    }

    /**
     * The eBay cells for one product's safety details.
     *
     * @param array<mixed>|null $data manufacturer and responsible parties.
     * @return array{manufacturer:list<string>,responsible:list<string>|null,problems:list<string>,warnings:list<string>}
     */
    public static function ebayFields($data) {
        $spec      = self::spec();
        $maker_in  = is_array($data) && isset($data['manufacturer']) ? $data['manufacturer'] : null;
        $person_in = is_array($data) && isset($data['responsible']) ? $data['responsible'] : null;
        if ('' === self::tidy(self::field($maker_in, 'name'))) {
            return [
                'manufacturer' => [],
                'responsible'  => null,
                'problems'     => ['manufacturer.missing'],
                'warnings'     => [],
            ];
        }
        $maker    = self::ebayParty($maker_in);
        $problems = [];
        $warnings = [];
        foreach ($maker['problems'] as $p) {
            $problems[] = 'manufacturer.' . $p;
        }
        $responsible = null;
        if ('' !== self::tidy(self::field($person_in, 'name'))) {
            $person = self::ebayParty($person_in);
            foreach ($person['problems'] as $p) {
                $problems[] = 'responsible.' . $p;
            }
            $responsible = array_merge([$person['cells'][0], $spec['responsibleType']], array_slice($person['cells'], 1));
        } elseif (self::outsideEu($maker['country'])) {
            $warnings[] = 'responsible.missing';
        }
        return [
            'manufacturer' => $maker['cells'],
            'responsible'  => $responsible,
            'problems'     => $problems,
            'warnings'     => $warnings,
        ];
    }

    /**
     * Read CSV text (RFC 4180).
     *
     * @param string $text      CSV text.
     * @param string $delimiter Cell separator.
     * @return array{rows:list<list<string>>,bom:bool}
     */
    public static function parseCsv($text, $delimiter = ',') {
        $s   = (string) $text;
        $bom = 0 === strncmp($s, "\xEF\xBB\xBF", 3);
        if ($bom) {
            $s = substr($s, 3);
        }
        $rows   = [];
        $row    = [];
        $cell   = '';
        $quoted = false;
        $len    = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quoted) {
                if ('"' === $c) {
                    if ($i + 1 < $len && '"' === $s[$i + 1]) {
                        $cell .= '"';
                        ++$i;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $cell .= $c;
                }
            } elseif ('"' === $c && '' === $cell) {
                $quoted = true;
            } elseif ($c === $delimiter) {
                $row[] = $cell;
                $cell  = '';
            } elseif ("\n" === $c || "\r" === $c) {
                if ("\r" === $c && $i + 1 < $len && "\n" === $s[$i + 1]) {
                    ++$i;
                }
                $row[]  = $cell;
                $rows[] = $row;
                $row    = [];
                $cell   = '';
            } else {
                $cell .= $c;
            }
        }
        if ('' !== $cell || $row) {
            $row[]  = $cell;
            $rows[] = $row;
        }
        return [
            'rows' => $rows,
            'bom'  => $bom,
        ];
    }

    /**
     * CSV text with CRLF line ends; cells with a comma, quote, line break or edge space are quoted.
     *
     * @param list<list<string>> $rows Rows.
     * @param bool               $bom  Start with a byte order mark.
     * @return string
     */
    public static function toCsv(array $rows, $bom = false) {
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $value) {
                $value   = (string) $value;
                $cells[] = 1 === preg_match('/[",\r\n]|^ | $/', $value) ? '"' . str_replace('"', '""', $value) . '"' : $value;
            }
            $lines[] = implode(',', $cells);
        }
        return ($bom ? "\xEF\xBB\xBF" : '') . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Where the field names are in an eBay Seller Hub Reports file, and its key columns.
     *
     * @param list<list<string>> $rows Rows.
     * @return array{index:int,item:int,sku:int,relationship:int}|null
     */
    private static function ebayHeader(array $rows) {
        foreach ($rows as $i => $row) {
            $cells  = array_map(
                static function ($c) {
                    return self::lower(self::tidy($c));
                },
                $row
            );
            $action = isset($cells[0]) ? (string) preg_replace('/^\*/', '', $cells[0]) : '';
            $item   = self::find($cells, ['item number', 'itemid', 'item id']);
            if (0 === strpos($action, 'action') && $item >= 0) {
                return [
                    'index'        => $i,
                    'item'         => $item,
                    'sku'          => self::find($cells, ['custom label (sku)', 'customlabel', 'sku']),
                    'relationship' => self::find($cells, ['relationship']),
                ];
            }
        }
        return null;
    }

    /**
     * First position of one of the names, or -1.
     *
     * @param list<string> $cells Cells.
     * @param list<string> $names Names.
     * @return int
     */
    private static function find(array $cells, array $names) {
        foreach ($cells as $i => $cell) {
            if (in_array($cell, $names, true)) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * A row's cell.
     *
     * @param list<string> $row   Row.
     * @param int          $index Column.
     * @return string
     */
    private static function cell(array $row, $index) {
        return $index >= 0 && isset($row[$index]) ? $row[$index] : '';
    }

    /**
     * Fill eBay's own "Edit price and quantity" file with the shop's product safety details.
     * Only listings that can be filled completely stay in the file (eBay removes details sent blank).
     *
     * @param string   $text   CSV file downloaded from eBay Seller Hub > Reports > Uploads.
     * @param callable $lookup SKU to safety details (manufacturer, responsible) or null.
     * @return array<string,mixed> error, or error '' with csv, rows and filled
     */
    public static function ebayMerge($text, callable $lookup) {
        $spec   = self::spec();
        $parsed = self::parseCsv($text, ',');
        $header = self::ebayHeader($parsed['rows']);
        if (null === $header) {
            $parsed = self::parseCsv($text, ';');
            $header = self::ebayHeader($parsed['rows']);
        }
        if (null === $header) {
            return ['error' => 'not-ebay-file'];
        }
        $rows  = $parsed['rows'];
        $names = $rows[$header['index']];
        $ours  = array_map([__CLASS__, 'lower'], array_merge($spec['manufacturer'], $spec['responsible']));
        $keep  = [];
        foreach ($names as $i => $name) {
            if (! in_array(self::lower(self::tidy($name)), $ours, true)) {
                $keep[] = $i;
            }
        }
        // Listings: a row with an action ($heads) and its variation rows after it ($kids).
        $heads = [];
        $kids  = [];
        foreach (array_slice($rows, $header['index'] + 1) as $row) {
            $blank = true;
            foreach ($row as $c) {
                if ('' !== self::tidy($c)) {
                    $blank = false;
                    break;
                }
            }
            if ($blank) {
                continue;
            }
            $action       = self::tidy(self::cell($row, 0));
            $is_variation = $header['relationship'] >= 0 && 'variation' === self::lower(self::tidy(self::cell($row, $header['relationship'])));
            if ('' === $action && $is_variation && $heads) {
                $kids[count($heads) - 1][] = $row;
            } else {
                $heads[] = $row;
                $kids[]  = [];
            }
        }
        foreach ($heads as $head_row) {
            if (self::match('scientific', self::tidy(self::cell($head_row, $header['item'])))) {
                return ['error' => 'item-number-format'];
            }
        }
        $report = [];
        $filled = [];
        foreach ($heads as $n => $head_row) {
            $listing = array_merge([$head_row], $kids[$n]);
            $item    = self::tidy(self::cell($head_row, $header['item']));
            $skus    = [];
            foreach ($listing as $r) {
                $sku = $header['sku'] >= 0 ? self::tidy(self::cell($r, $header['sku'])) : '';
                if ('' !== $sku) {
                    $skus[] = $sku;
                }
            }
            $entry = [
                'item'     => $item,
                'sku'      => $skus ? $skus[0] : '',
                'status'   => 'left-out',
                'problems' => [],
                'warnings' => [],
            ];
            $at    = count($report);
            if ('revise' !== self::lower(self::tidy(self::cell($head_row, 0))) || 1 !== preg_match('/^\d+$/', $item)) {
                $entry['problems'][] = 'not-revise';
                $report[]            = $entry;
                continue;
            }
            if (! $skus) {
                $entry['problems'][] = 'no-sku';
                $report[]            = $entry;
                continue;
            }
            $found = null;
            foreach ($skus as $sku) {
                $data = call_user_func($lookup, $sku);
                if (! is_array($data)) {
                    continue;
                }
                $fields = self::ebayFields(self::parties($data));
                if (null === $found) {
                    $found = [
                        'sku'    => $sku,
                        'fields' => $fields,
                    ];
                } elseif ($found['fields'] !== $fields) {
                    $found               = null;
                    $entry['problems'][] = 'variations-differ';
                    break;
                }
            }
            if (null === $found) {
                if (! $entry['problems']) {
                    $entry['problems'][] = 'not-in-shop';
                }
                $report[] = $entry;
                continue;
            }
            $entry['sku']      = $found['sku'];
            $entry['problems'] = array_merge($entry['problems'], $found['fields']['problems']);
            $entry['warnings'] = array_merge($entry['warnings'], $found['fields']['warnings']);
            if (! $entry['problems']) {
                $entry['status'] = 'filled';
                $filled[]        = [
                    'listing' => $listing,
                    'fields'  => $found['fields'],
                    'at'      => $at,
                ];
            }
            $report[] = $entry;
        }
        $with_responsible = false;
        foreach ($filled as $f) {
            if (null !== $f['fields']['responsible']) {
                $with_responsible = true;
            }
        }
        $added = array_merge($spec['manufacturer'], $with_responsible ? $spec['responsible'] : []);
        $blank = array_fill(0, count($spec['responsible']), '');
        $out   = array_slice($rows, 0, $header['index']);
        $head  = [];
        foreach ($keep as $i) {
            $head[] = isset($names[$i]) ? $names[$i] : '';
        }
        $out[] = array_merge($head, $added);
        foreach ($filled as $f) {
            // Sent blank, eBay removes a responsible person the listing has: the shop has none for it.
            if ($with_responsible && null === $f['fields']['responsible']) {
                $report[$f['at']]['warnings'][] = 'responsible.cleared';
            }
            $values = $f['fields']['manufacturer'];
            if ($with_responsible) {
                $values = array_merge($values, null !== $f['fields']['responsible'] ? $f['fields']['responsible'] : $blank);
            }
            foreach ($f['listing'] as $row) {
                $cells = [];
                foreach ($keep as $i) {
                    $cells[] = isset($row[$i]) ? $row[$i] : '';
                }
                $out[] = array_merge($cells, $values);
            }
        }
        return [
            'error'  => '',
            'csv'    => self::toCsv($out, $parsed['bom']),
            'rows'   => $report,
            'filled' => count($filled),
        ];
    }

    /**
     * Amazon files: the contacts to enter once in Seller Central, and the SKU columns for the
     * inventory file (partial update), which name each contact by its email address.
     *
     * @param list<array<string,mixed>> $products sku, manufacturer, responsible.
     * @return array{listings:string,contacts:string,rows:list<array<string,mixed>>,filled:int}
     */
    public static function amazonFiles(array $products) {
        $spec     = self::spec();
        $listings = [$spec['amazon']];
        $contacts = [];
        $emails   = [];
        $report   = [];
        foreach ($products as $p) {
            $sku   = self::tidy(isset($p['sku']) ? $p['sku'] : '');
            $entry = [
                'sku'      => $sku,
                'status'   => 'left-out',
                'problems' => [],
                'warnings' => [],
            ];
            if ('' === $sku) {
                $entry['problems'][] = 'no-sku';
                $report[]            = $entry;
                continue;
            }
            $parties = self::parties($p);
            $maker   = $parties['manufacturer'];
            if (null === $maker || '' === self::tidy($maker['name'])) {
                $entry['problems'][] = 'manufacturer.missing';
                $report[]            = $entry;
                continue;
            }
            if (! self::match('email', self::tidy($maker['email']))) {
                $entry['problems'][] = 'manufacturer.email';
            }
            $person = null !== $parties['responsible'] && '' !== self::tidy($parties['responsible']['name']) ? $parties['responsible'] : null;
            if (null !== $person && ! self::match('email', self::tidy($person['email']))) {
                $entry['problems'][] = 'responsible.email';
            }
            if (null === $person) {
                $split = self::splitAddress($maker['address'], $maker['name']);
                if (self::outsideEu($split['country'])) {
                    $entry['warnings'][] = 'responsible.missing';
                }
            }
            // Amazon names a contact by its email address: one address must mean one set of details.
            $roles = ['Manufacturer' => $maker];
            if (null !== $person) {
                $roles['EU responsible person'] = $person;
            }
            foreach ($roles as $role => $party) {
                $key     = $role . "\n" . self::lower(self::tidy($party['email']));
                $details = self::lower(self::tidy($party['name'])) . "\n" . self::lower(self::tidy($party['address']));
                if ($entry['problems']) {
                    break;
                }
                if (! isset($emails[$key])) {
                    $emails[$key] = $details;
                }
                if ($emails[$key] !== $details) {
                    $entry['problems'][] = ('Manufacturer' === $role ? 'manufacturer' : 'responsible') . '.email-shared';
                }
            }
            if ($entry['problems']) {
                $report[] = $entry;
                continue;
            }
            $entry['status'] = 'filled';
            $report[]        = $entry;
            foreach ($roles as $role => $party) {
                $key = $role . "\n" . self::lower(self::tidy($party['name'])) . "\n" . self::lower(self::tidy($party['email'])) . "\n" . self::lower(self::tidy($party['address']));
                if (! isset($contacts[$key])) {
                    $contacts[$key] = [
                        'cells' => self::contactCells($role, $party),
                        'skus'  => [],
                    ];
                }
                $contacts[$key]['skus'][] = $sku;
            }
            $listings[] = [$sku, self::tidy($maker['email']), null !== $person ? self::tidy($person['email']) : ''];
        }
        $contact_rows = [['Role', 'Name', 'Address as written', 'Street', 'Street 2', 'Postcode', 'Town', 'State or province', 'Country', 'Email', 'Website', 'SKUs']];
        foreach ($contacts as $c) {
            $count          = count($c['skus']);
            $skus           = $count > 20 ? implode(' ', array_slice($c['skus'], 0, 20)) . ' (+' . ($count - 20) . ')' : implode(' ', $c['skus']);
            $contact_rows[] = array_merge($c['cells'], [$skus]);
        }
        return [
            'listings' => self::toCsv($listings),
            'contacts' => self::toCsv($contact_rows),
            'rows'     => $report,
            'filled'   => count($listings) - 1,
        ];
    }

    /**
     * One contact row for Amazon: the address as written, and split where it could be read.
     *
     * @param string               $role  Manufacturer or EU responsible person.
     * @param array<string,string> $party name, address, email, url.
     * @return list<string>
     */
    private static function contactCells($role, array $party) {
        $name = self::tidy($party['name']);
        $a    = self::splitAddress($party['address'], $name);
        $ok   = [] === $a['problems'];
        return [
            $role,
            $name,
            self::tidy((string) preg_replace('/\r?\n/', ', ', $party['address'])),
            $ok ? $a['street1'] : '',
            $ok ? $a['street2'] : '',
            $ok ? $a['postalCode'] : '',
            $ok ? $a['city'] : '',
            $ok ? $a['state'] : '',
            $ok ? $a['country'] : '',
            self::tidy($party['email']),
            self::tidy($party['url']),
        ];
    }
}
