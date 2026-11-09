<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * The official EU legal guarantee notice (Implementing Regulation (EU) 2025/1960, Annex I; rule
 * EU-GU-01), built from the rules data into Resources/config/guarantee-notice.json.
 */
final class GuaranteeNotice
{
    /** @var array{countries: array<string, string>, notices: array<string, array{file: string, title: string, alt: string, textVersion: string, width: int, height: int, text: list<string>}>}|null */
    private ?array $data = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * The notice in the page language when there is one, else in the shop country's language, else
     * in English. Null when the data file is missing or damaged.
     *
     * @return array{lang: string, file: string, title: string, alt: string, textVersion: string, width: int, height: int, text: list<string>}|null
     */
    public function forPage(string $pageLanguage, string $shopCountry = ''): ?array
    {
        $data = $this->data();
        $page = strtolower(substr($pageLanguage, 0, 2));
        $country = $data['countries'][strtoupper($shopCountry)] ?? '';
        foreach ([$page, $country, 'en'] as $lang) {
            if ($lang !== '' && isset($data['notices'][$lang])) {
                return ['lang' => $lang, ...$data['notices'][$lang]];
            }
        }

        return null;
    }

    /**
     * @return array{countries: array<string, string>, notices: array<string, array{file: string, title: string, alt: string, textVersion: string, width: int, height: int, text: list<string>}>}
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $this->data = ['countries' => [], 'notices' => []];
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            return $this->data;
        }
        foreach (\is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [] as $country => $lang) {
            if (\is_string($country) && \is_string($lang)) {
                $this->data['countries'][$country] = $lang;
            }
        }
        foreach (\is_array($decoded['notices'] ?? null) ? $decoded['notices'] : [] as $lang => $notice) {
            if (!\is_string($lang) || !\is_array($notice)) {
                continue;
            }
            $file = $notice['file'] ?? null;
            if (!\is_string($file) || preg_match('/^guarantee-notice-[a-z]{2}\.jpg$/', $file) !== 1) {
                continue;
            }
            $text = [];
            foreach (\is_array($notice['text'] ?? null) ? $notice['text'] : [] as $line) {
                if (\is_string($line)) {
                    $text[] = $line;
                }
            }
            $this->data['notices'][$lang] = [
                'file' => $file,
                'title' => \is_string($notice['title'] ?? null) ? $notice['title'] : '',
                'alt' => \is_string($notice['alt'] ?? null) ? $notice['alt'] : '',
                'textVersion' => \is_string($notice['textVersion'] ?? null) ? $notice['textVersion'] : '',
                'width' => \is_int($notice['width'] ?? null) ? $notice['width'] : 0,
                'height' => \is_int($notice['height'] ?? null) ? $notice['height'] : 0,
                'text' => $text,
            ];
        }

        return $this->data;
    }
}
