<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The consumer dispute resolution statement (Germany § 36 VSBG; similar duties in other EU
 * countries since the EU online dispute resolution platform closed on 20 July 2025): whether the
 * shop takes part in proceedings before a consumer arbitration board. The shop picks its statement
 * in the plugin settings and pastes it into its terms and imprint pages. It is never added to
 * pages by itself, as that would put it on every page (owner decision, 7 Nov 2026). The setup
 * check finds it on the terms and imprint pages, or takes the shop's tick that it pasted it. Same
 * choices and wording as the Shopify app and the WooCommerce plugin. Pure, unit tested alone.
 */
final class DisputeStatement
{
    public const CHOICES = ['', 'no', 'willing', 'obliged'];

    /**
     * The statement as plain text in a language, or '' until the shop has picked one (or picked
     * "willing" or "obliged" without naming the board).
     */
    public static function text(string $choice, string $board, string $url, NotesTexts $texts, string $lang): string
    {
        if ($choice === 'no') {
            return $texts->text('notices.disputeNo', $lang);
        }
        if ($choice !== 'willing' && $choice !== 'obliged') {
            return '';
        }
        $board = FoodInformation::plain($board, 200);
        if ($board === '') {
            return '';
        }
        $url = self::https($url);

        return $texts->text($choice === 'willing' ? 'notices.disputeWilling' : 'notices.disputeObliged', $lang, [
            'board' => $board . ($url !== '' ? ', ' . $url : ''),
        ]);
    }

    /**
     * Whether a page text holds the statement in any of the languages, compared without markup,
     * case or spacing, so a pasted copy counts even after the editor wrapped it in a paragraph.
     *
     * @param list<string> $statements
     */
    public static function foundIn(string $pageText, array $statements): bool
    {
        $page = self::normalise($pageText);
        foreach ($statements as $statement) {
            $needle = self::normalise($statement);
            if ($needle !== '' && str_contains($page, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An https address, or '' (anything else could run script in a link).
     */
    public static function https(string $value): string
    {
        $value = trim($value);

        return preg_match('#^https://[^\s<>"\']+$#i', $value) === 1 ? $value : '';
    }

    private static function normalise(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $text)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(str_replace("\u{00A0}", ' ', $text))));
    }
}
