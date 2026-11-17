<?php declare(strict_types=1);

namespace Shopproof\Compliance\Cancel;

/**
 * The cancel button for contracts that run over time (Germany § 312k BGB, France
 * L. 215-1-1 Code de la consommation), as in the WooCommerce plugin and the Shopify app: the same
 * texts (cancel-button.json, copied from the WooCommerce plugin), the same fields and the same
 * checks. Pure, so it is unit tested without a shop. Technical help, not legal advice.
 */
final class CancelForm
{
    public const SETTING = 'ShopproofCompliance.config.cancelButton';

    /** The form fields and their longest length. */
    public const LIMITS = ['name' => 200, 'email' => 200, 'contract' => 200, 'reason' => 2000, 'endDate' => 10];

    public const STEP = 'shopproof_cancel_step';

    /** A hidden field that people leave empty and bots fill in. */
    public const HONEYPOT = 'website';

    /** Cancellations per connection and per shop in an hour. */
    public const RATE_LIMIT = 5;
    public const SITE_LIMIT = 100;

    public const LANGUAGES = ['en', 'de', 'fr', 'it', 'nl'];

    /** @var array<string, array<string, string>> */
    private array $texts;

    /**
     * @param array<mixed> $texts decoded cancel-button.json
     */
    public function __construct(array $texts)
    {
        $this->texts = [];
        foreach ($texts as $lang => $table) {
            if (!\is_string($lang) || !\is_array($table)) {
                continue;
            }
            foreach ($table as $key => $text) {
                if (\is_string($key) && \is_string($text)) {
                    $this->texts[$lang][$key] = $text;
                }
            }
        }
    }

    public static function fromFile(string $path): self
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return new self(\is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function texts(): array
    {
        return $this->texts;
    }

    /**
     * The page language from a locale such as de-DE: one with texts, else English.
     */
    public function lang(string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));

        return isset($this->texts[$lang]) ? $lang : 'en';
    }

    /**
     * One text with its placeholders ({date}, {name} and so on) filled in.
     *
     * @param array<string, string> $values
     */
    public function text(string $key, string $lang, array $values = []): string
    {
        $text = $this->texts[$lang][$key] ?? $this->texts['en'][$key] ?? '';
        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * The form data from the posted fields, trimmed and cut to length. A reason only counts for
     * an extraordinary cancellation, a date only when the customer chose one.
     *
     * @param array<mixed> $posted
     *
     * @return array{name: string, email: string, contract: string, cancelType: string, reason: string, endMode: string, endDate: string}
     */
    public static function input(array $posted): array
    {
        $field = static function (string $key) use ($posted): string {
            $value = $posted[$key] ?? '';
            if (!\is_string($value)) {
                return '';
            }
            $value = str_replace("\0", '', $value);
            $value = $key === 'reason' ? preg_replace("/[^\\P{C}\n]+/u", '', str_replace("\r\n", "\n", $value)) : preg_replace('/\p{C}+/u', ' ', $value);

            return trim(mb_substr(trim((string) $value), 0, self::LIMITS[$key] ?? 20));
        };
        $type = $field('cancelType');
        $end = $field('endMode');
        $data = [
            'name' => $field('name'),
            'email' => $field('email'),
            'contract' => $field('contract'),
            'cancelType' => $type === 'extraordinary' ? 'extraordinary' : 'ordinary',
            'reason' => $field('reason'),
            'endMode' => $end === 'date' ? 'date' : 'earliest',
            'endDate' => $field('endDate'),
        ];
        if ($data['cancelType'] !== 'extraordinary') {
            $data['reason'] = '';
        }
        if ($data['endMode'] !== 'date') {
            $data['endDate'] = '';
        }

        return $data;
    }

    /**
     * What is wrong with the data, by field, as text keys. Empty when fine.
     *
     * @param array{name: string, email: string, contract: string, cancelType: string, reason: string, endMode: string, endDate: string} $data
     *
     * @return array<string, string>
     */
    public static function errors(array $data, string $today): array
    {
        $errors = [];
        foreach (['name', 'email', 'contract'] as $field) {
            if ($data[$field] === '') {
                $errors[$field] = 'required';
            }
        }
        if (!isset($errors['email']) && filter_var($data['email'], \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalidEmail';
        }
        if ($data['cancelType'] === 'extraordinary' && $data['reason'] === '') {
            $errors['reason'] = 'reasonRequired';
        }
        if ($data['endMode'] === 'date') {
            $valid = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data['endDate'], $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
            if (!$valid || $data['endDate'] < $today) {
                $errors['endDate'] = 'invalidDate';
            }
        }

        return $errors;
    }

    /**
     * A short reference the customer can quote: C- and 8 letters or digits that cannot be mixed up.
     */
    public static function reference(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = 'C-';
        for ($i = 0; $i < 8; ++$i) {
            $out .= $chars[random_int(0, \strlen($chars) - 1)];
        }

        return $out;
    }

    /**
     * The same cancellation sent twice (a page reload) has the same fingerprint.
     *
     * @param array<string, string> $data
     */
    public static function fingerprint(array $data, string $salesChannelId): string
    {
        return hash('sha256', mb_strtolower($salesChannelId . "\n" . implode("\n", $data)));
    }

    /**
     * The end of the contract as a sentence.
     *
     * @param array<string, string> $record
     */
    public function endLine(array $record, string $lang): string
    {
        if (($record['endMode'] ?? '') !== 'date') {
            return $this->text('endsEarliest', $lang);
        }

        return $this->text('endsOn', $lang, ['end' => $record['endDate'] ?? '']);
    }

    /**
     * The details as label and value pairs, in the order the form asks them.
     *
     * @param array<string, string> $record record or form data
     *
     * @return list<array{0: string, 1: string}>
     */
    public function details(array $record, string $lang): array
    {
        $extraordinary = ($record['cancelType'] ?? '') === 'extraordinary';
        $rows = [
            [$this->text('name', $lang), $record['name'] ?? ''],
            [$this->text('email', $lang), $record['email'] ?? ''],
            [$this->text('contract', $lang), $record['contract'] ?? ''],
            [$this->text('type', $lang), $this->text($extraordinary ? 'extraordinary' : 'ordinary', $lang)],
        ];
        if ($extraordinary) {
            $rows[] = [$this->text('reason', $lang), $record['reason'] ?? ''];
        }
        $rows[] = [$this->text('end', $lang), $this->endLine($record, $lang)];
        if (($record['reference'] ?? '') !== '') {
            $rows[] = [$this->text('reference', $lang), $record['reference']];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $record
     */
    public function detailsText(array $record, string $lang): string
    {
        return implode("\n", array_map(static fn (array $row): string => $row[0] . ': ' . $row[1], $this->details($record, $lang)));
    }

    /**
     * The confirmation to the customer, in the language of the page they used.
     *
     * @param array<string, string> $record with reference and lang
     *
     * @return array{subject: string, body: string}
     */
    public function receipt(array $record, string $date, string $time, string $shop): array
    {
        $lang = $record['lang'] ?? 'en';

        return [
            'subject' => $this->text('subject', $lang, ['contract' => $record['contract'] ?? '']),
            'body' => $this->text('hello', $lang, ['name' => $record['name'] ?? '']) . "\n\n"
                . $this->text('received', $lang, ['date' => $date, 'time' => $time]) . "\n\n"
                . $this->detailsText($record, $lang) . "\n\n"
                . $this->text('keep', $lang) . "\n\n"
                . $this->text('sentBy', $lang, ['shop' => $shop]),
        ];
    }

    /**
     * The copy to the shop, with what to do next and whether the customer got the confirmation.
     *
     * @param array<string, string> $record
     *
     * @return array{subject: string, body: string}
     */
    public function shopCopy(array $record, string $lang, string $date, string $time, bool $receiptSent): array
    {
        $body = $this->text('shopIntro', $lang, ['date' => $date, 'time' => $time]) . "\n\n"
            . $this->detailsText($record, $lang) . "\n\n"
            . $this->text('shopNext', $lang);
        if (!$receiptSent) {
            $body .= "\n\n" . $this->text('shopNoReceipt', $lang);
        }

        return [
            'subject' => $this->text('shopSubject', $lang, ['reference' => $record['reference'] ?? '', 'contract' => $record['contract'] ?? '']),
            'body' => $body . "\n\n" . $this->text('disclaimer', $lang),
        ];
    }
}
