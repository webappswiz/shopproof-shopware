<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * The EU model withdrawal form (Directive 2011/83/EU, Annex I(B)), which shops give their
 * customers with the withdrawal information (Article 6(1)(h)). The official EUR-Lex text in the
 * storefront language (model-form.json, the same file the WooCommerce plugin ships), with the
 * shop's name, postal address and email address filled in where the Annex asks the trader to
 * insert them. Irish has no official version and uses English. Pure, so it is unit tested
 * without Shopware; ModelFormExtension feeds it the shop's details.
 */
final class ModelForm
{
    /** @var array{countries: array<string, string>, forms: array<string, array{title: string, note: string, to: string, lines: list<string>, footnote: string}>}|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * The language to show for a storefront locale such as "de-AT": its own form when there is
     * one, else English.
     */
    public function language(string $localeCode): string
    {
        $lang = strtolower(substr($localeCode, 0, 2));

        return isset($this->data()['forms'][$lang]) ? $lang : 'en';
    }

    /**
     * The trader line: the non-empty parts of name, address and email, in that order.
     *
     * @param array{name?: string, street?: string, zip?: string, city?: string, country?: string, email?: string} $shop
     *
     * @return list<string>
     */
    public static function trader(array $shop): array
    {
        $clean = static fn (?string $value): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $town = trim($clean($shop['zip'] ?? '') . ' ' . $clean($shop['city'] ?? ''));
        $address = implode(', ', array_values(array_filter([$clean($shop['street'] ?? ''), $town, $clean($shop['country'] ?? '')], static fn (string $v): bool => $v !== '')));
        $email = $clean($shop['email'] ?? '');
        if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        return array_values(array_filter([$clean($shop['name'] ?? ''), $address, $email], static fn (string $v): bool => $v !== ''));
    }

    /**
     * The form as HTML for a storefront locale, or '' when the texts are missing. A lang
     * attribute marks the form when it is not in the page's language (Irish pages get English).
     *
     * @param list<string> $trader
     */
    public function render(string $localeCode, array $trader): string
    {
        $lang = $this->language($localeCode);
        $form = $this->data()['forms'][$lang] ?? null;
        if ($form === null || $form['lines'] === []) {
            return '';
        }
        $e = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $page = strtolower(substr($localeCode, 0, 2));
        $to = str_replace($e('{trader}'), implode(', ', array_map($e, $trader)), $e($form['to']));

        $html = '<section class="shopproof-model-form"' . ($page !== $lang ? ' lang="' . $e($lang) . '"' : '') . '>';
        $html .= '<h2>' . $e($form['title']) . '</h2>';
        $html .= '<p>' . $e($form['note']) . '</p>';
        $html .= '<ul><li>' . $to . '</li>';
        foreach ($form['lines'] as $line) {
            $html .= '<li>' . $e($line) . '</li>';
        }
        $html .= '</ul>';
        $html .= '<p><small>(*) ' . $e($form['footnote']) . '</small></p>';

        return $html . '</section>';
    }

    /**
     * Languages with an official form.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        return array_keys($this->data()['forms']);
    }

    /**
     * @return array{countries: array<string, string>, forms: array<string, array{title: string, note: string, to: string, lines: list<string>, footnote: string}>}
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $raw = is_file($this->file) ? file_get_contents($this->file) : false;
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        $forms = [];
        foreach (\is_array($decoded) && \is_array($decoded['forms'] ?? null) ? $decoded['forms'] : [] as $lang => $form) {
            if (!\is_string($lang) || !\is_array($form) || !\is_array($form['lines'] ?? null)) {
                continue;
            }
            $text = static fn (string $key): string => \is_string($form[$key] ?? null) ? $form[$key] : '';
            $forms[$lang] = [
                'title' => $text('title'),
                'note' => $text('note'),
                'to' => $text('to'),
                'lines' => array_values(array_filter($form['lines'], 'is_string')),
                'footnote' => $text('footnote'),
            ];
        }
        $countries = [];
        foreach (\is_array($decoded) && \is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [] as $code => $lang) {
            if (\is_string($code) && \is_string($lang)) {
                $countries[$code] = $lang;
            }
        }

        return $this->data = ['countries' => $countries, 'forms' => $forms];
    }
}
