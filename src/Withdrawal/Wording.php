<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The wording of the withdrawal button and its confirm button, as in the WooCommerce plugin's
 * wording picker: automatic (the national wording in the plugin's snippets, the default), a
 * reviewed alternative where the law of the language's country names more than one (from
 * withdrawal-alternatives.json, built from the rules data), or the shop's own text, used only
 * after the merchant ticked that they understand the legal wording. labels() returns null while
 * the snippets apply.
 */
class Wording
{
    public const AUTO = 'auto';
    public const ALTERNATIVE = 'alternative';
    public const OWN = 'own';

    private const CONFIG = 'ShopproofCompliance.config.';

    /** @var array<mixed>|null */
    private ?array $alternatives = null;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly string $file,
    ) {
    }

    /**
     * @return array{withdraw: string, confirm: string, source: string}|null
     */
    public function labels(?string $channel, string $lang): ?array
    {
        $mode = $this->config->getString(self::CONFIG . 'buttonWording', $channel);
        if ($mode === self::OWN) {
            return self::own(
                $this->config->getString(self::CONFIG . 'customWithdraw', $channel),
                $this->config->getString(self::CONFIG . 'customConfirm', $channel),
                $this->config->getBool(self::CONFIG . 'customWordingAck', $channel)
            );
        }
        if ($mode === self::ALTERNATIVE) {
            return $this->alternative($lang);
        }

        return null;
    }

    /**
     * The shop's own text, only with both texts and the "I understand" tick.
     *
     * @return array{withdraw: string, confirm: string, source: string}|null
     */
    public static function own(string $withdraw, string $confirm, bool $ack): ?array
    {
        $withdraw = trim(strip_tags($withdraw));
        $confirm = trim(strip_tags($confirm));
        if (!$ack || $withdraw === '' || $confirm === '') {
            return null;
        }

        return ['withdraw' => mb_substr($withdraw, 0, 80), 'confirm' => mb_substr($confirm, 0, 80), 'source' => 'own'];
    }

    /**
     * The first reviewed alternative for a language, or null where there is none.
     *
     * @return array{withdraw: string, confirm: string, source: string}|null
     */
    public function alternative(string $lang): ?array
    {
        $list = $this->data()[strtolower(substr($lang, 0, 2))] ?? null;
        $entry = \is_array($list) ? ($list[0] ?? null) : null;
        if (!\is_array($entry) || !\is_string($entry['withdraw'] ?? null) || !\is_string($entry['confirm'] ?? null)) {
            return null;
        }

        return ['withdraw' => $entry['withdraw'], 'confirm' => $entry['confirm'], 'source' => 'alternative'];
    }

    /**
     * Languages that have a reviewed alternative, for the setup check.
     *
     * @return list<string>
     */
    public function alternativeLanguages(): array
    {
        return array_map('strval', array_keys($this->data()));
    }

    /**
     * Puts the chosen wording into the footer button's HTML (Shopware renders it from a snippet).
     */
    public static function swapLink(string $html, string $label): string
    {
        $safe = htmlspecialchars($label, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return (string) preg_replace_callback(
            '#(<div class="footer-revocation-button[^"]*"[^>]*>\s*<a\b[^>]*>)(.*?)(</a>)#s',
            static fn (array $m): string => $m[1] . $safe . $m[3],
            $html,
            1
        );
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        if ($this->alternatives === null) {
            $raw = is_file($this->file) ? file_get_contents($this->file) : false;
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $this->alternatives = \is_array($decoded) && \is_array($decoded['languages'] ?? null) ? $decoded['languages'] : [];
        }

        return $this->alternatives;
    }
}
