<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The Return arrived and Refund sent emails, as Shopware email templates (Settings > Email
 * templates), so the merchant can change their words like any other Shopware email. Created on
 * install and update for every language (German, French, Italian or Dutch text by the language's
 * locale, English for any other), never
 * overwriting a template that exists; removed on uninstall unless the merchant keeps the data.
 */
final class MailTemplateInstaller
{
    public const RETURN_ARRIVED = 'shopproof_withdrawal_return_arrived';
    public const REFUND_SENT = 'shopproof_withdrawal_refund_sent';
    public const TYPES = [self::RETURN_ARRIVED, self::REFUND_SENT];

    /**
     * Name, subject, opening text. The opening text ends in the status link and the shop's name.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string}>>
     */
    public const TEXTS = [
        self::RETURN_ARRIVED => [
            'en' => [
                'Withdrawal: return arrived (Shopproof)',
                '{{ salesChannel.translated.name }}: your return arrived ({{ withdrawal.contractNumber }})',
                'your return for order {{ withdrawal.contractNumber }} has arrived at {{ salesChannel.translated.name }}. Your refund comes next.',
            ],
            'de' => [
                'Widerruf: Rücksendung angekommen (Shopproof)',
                '{{ salesChannel.translated.name }}: Ihre Rücksendung ist angekommen ({{ withdrawal.contractNumber }})',
                'Ihre Rücksendung zur Bestellung {{ withdrawal.contractNumber }} ist bei {{ salesChannel.translated.name }} angekommen. Als Nächstes folgt Ihre Erstattung.',
            ],
            'fr' => [
                'Rétractation : retour reçu (Shopproof)',
                '{{ salesChannel.translated.name }} : votre retour est arrivé ({{ withdrawal.contractNumber }})',
                'Votre retour pour la commande {{ withdrawal.contractNumber }} est arrivé chez {{ salesChannel.translated.name }}. Votre remboursement suit.',
            ],
            'it' => [
                'Recesso: reso arrivato (Shopproof)',
                '{{ salesChannel.translated.name }}: il tuo reso è arrivato ({{ withdrawal.contractNumber }})',
                'Il tuo reso per l\'ordine {{ withdrawal.contractNumber }} è arrivato a {{ salesChannel.translated.name }}. Il prossimo passo è il rimborso.',
            ],
            'nl' => [
                'Herroeping: retour ontvangen (Shopproof)',
                '{{ salesChannel.translated.name }}: uw retour is aangekomen ({{ withdrawal.contractNumber }})',
                'Uw retour voor bestelling {{ withdrawal.contractNumber }} is aangekomen bij {{ salesChannel.translated.name }}. Daarna volgt uw terugbetaling.',
            ],
        ],
        self::REFUND_SENT => [
            'en' => [
                'Withdrawal: refund sent (Shopproof)',
                '{{ salesChannel.translated.name }}: your refund is on its way ({{ withdrawal.contractNumber }})',
                '{{ salesChannel.translated.name }} has sent the refund for your withdrawal from order {{ withdrawal.contractNumber }}. Depending on your payment method, it can take a few days to reach your account.',
            ],
            'de' => [
                'Widerruf: Erstattung gesendet (Shopproof)',
                '{{ salesChannel.translated.name }}: Ihre Erstattung ist unterwegs ({{ withdrawal.contractNumber }})',
                '{{ salesChannel.translated.name }} hat die Erstattung für Ihren Widerruf der Bestellung {{ withdrawal.contractNumber }} gesendet. Je nach Zahlungsart kann es einige Tage dauern, bis sie auf Ihrem Konto ist.',
            ],
            'fr' => [
                'Rétractation : remboursement envoyé (Shopproof)',
                '{{ salesChannel.translated.name }} : votre remboursement est en route ({{ withdrawal.contractNumber }})',
                '{{ salesChannel.translated.name }} a envoyé le remboursement de votre rétractation de la commande {{ withdrawal.contractNumber }}. Selon votre moyen de paiement, il peut falloir quelques jours avant qu\'il n\'arrive sur votre compte.',
            ],
            'it' => [
                'Recesso: rimborso inviato (Shopproof)',
                '{{ salesChannel.translated.name }}: il tuo rimborso è in arrivo ({{ withdrawal.contractNumber }})',
                '{{ salesChannel.translated.name }} ha inviato il rimborso per il tuo recesso dall\'ordine {{ withdrawal.contractNumber }}. A seconda del metodo di pagamento, possono volerci alcuni giorni prima che arrivi sul tuo conto.',
            ],
            'nl' => [
                'Herroeping: terugbetaling verzonden (Shopproof)',
                '{{ salesChannel.translated.name }}: uw terugbetaling is onderweg ({{ withdrawal.contractNumber }})',
                '{{ salesChannel.translated.name }} heeft de terugbetaling voor uw herroeping van bestelling {{ withdrawal.contractNumber }} verzonden. Afhankelijk van uw betaalmethode kan het een paar dagen duren voordat het bedrag op uw rekening staat.',
            ],
        ],
    ];

    /**
     * Greeting, the line before the status link, and the closing. The status line uses the same
     * words as the confirmation email's (email-line.json "status").
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const FRAME = [
        'en' => ['Hello {{ withdrawal.firstName }} {{ withdrawal.lastName }},', 'See where your withdrawal stands:', 'Kind regards,'],
        'de' => ['Hallo {{ withdrawal.firstName }} {{ withdrawal.lastName }},', 'So steht Ihr Widerruf:', 'Viele Grüße'],
        'fr' => ['Bonjour {{ withdrawal.firstName }} {{ withdrawal.lastName }},', 'Suivez l\'état de votre rétractation ici :', 'Cordialement,'],
        'it' => ['Gentile {{ withdrawal.firstName }} {{ withdrawal.lastName }},', 'Segui lo stato del tuo recesso qui:', 'Cordiali saluti,'],
        'nl' => ['Beste {{ withdrawal.firstName }} {{ withdrawal.lastName }},', 'Bekijk de status van uw herroeping hier:', 'Met vriendelijke groet,'],
    ];

    /** The template languages; any other locale gets English. */
    public const LANGUAGES = ['en', 'de', 'fr', 'it', 'nl'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function install(): void
    {
        $languages = $this->connection->fetchAllAssociative(
            'SELECT l.id, lo.code FROM language l LEFT JOIN locale lo ON lo.id = l.locale_id'
        );
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        foreach (self::TYPES as $type) {
            if ($this->typeId($type) !== null) {
                continue;
            }
            $typeId = Uuid::randomBytes();
            $templateId = Uuid::randomBytes();
            $this->connection->insert('mail_template_type', [
                'id' => $typeId,
                'technical_name' => $type,
                'available_entities' => json_encode(['salesChannel' => 'sales_channel'], \JSON_THROW_ON_ERROR),
                'template_data' => json_encode(self::sample(), \JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
            $this->connection->insert('mail_template', [
                'id' => $templateId,
                'mail_template_type_id' => $typeId,
                'system_default' => 1,
                'created_at' => $now,
            ]);
            foreach ($languages as $language) {
                $id = $language['id'] ?? null;
                if (!\is_string($id)) {
                    continue;
                }
                $lang = self::language(\is_string($language['code'] ?? null) ? $language['code'] : '');
                [$name, $subject, $intro] = self::TEXTS[$type][$lang];
                $this->connection->insert('mail_template_type_translation', [
                    'mail_template_type_id' => $typeId,
                    'language_id' => $id,
                    'name' => $name,
                    'created_at' => $now,
                ]);
                $this->connection->insert('mail_template_translation', [
                    'mail_template_id' => $templateId,
                    'language_id' => $id,
                    'sender_name' => '{{ salesChannel.translated.name }}',
                    'subject' => $subject,
                    'description' => $name,
                    'content_html' => self::html($lang, $intro),
                    'content_plain' => self::plain($lang, $intro),
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function uninstall(): void
    {
        foreach (self::TYPES as $type) {
            $typeId = $this->typeId($type);
            if ($typeId === null) {
                continue;
            }
            $this->connection->executeStatement('DELETE FROM mail_template WHERE mail_template_type_id = :id', ['id' => $typeId]);
            $this->connection->executeStatement('DELETE FROM mail_template_type WHERE id = :id', ['id' => $typeId]);
        }
    }

    /**
     * The template language for a Shopware locale code such as fr-FR, nl-BE or de-AT.
     */
    public static function language(string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));

        return \in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
    }

    /**
     * The HTML and plain text body of one template, for the tests.
     *
     * @return array{html: string, plain: string}
     */
    public static function body(string $type, string $lang): array
    {
        $intro = self::TEXTS[$type][$lang][2];

        return ['html' => self::html($lang, $intro), 'plain' => self::plain($lang, $intro)];
    }

    private function typeId(string $type): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', ['name' => $type]);

        return \is_string($id) ? $id : null;
    }

    private static function html(string $lang, string $intro): string
    {
        [$hello, $see, $bye] = self::FRAME[$lang];

        return '<div style="font-family:arial; font-size:12px;">' . "\n"
            . '<p>' . $hello . '</p>' . "\n"
            . '<p>' . $intro . '</p>' . "\n"
            . '{% if statusUrl %}<p>' . $see . ' <a href="{{ statusUrl }}">{{ statusUrl }}</a></p>{% endif %}' . "\n"
            . '<p>' . $bye . '<br>{{ salesChannel.translated.name }}</p>' . "\n"
            . '</div>';
    }

    private static function plain(string $lang, string $intro): string
    {
        [$hello, $see, $bye] = self::FRAME[$lang];

        return $hello . "\n\n" . $intro . "\n\n" . '{% if statusUrl %}' . $see . ' {{ statusUrl }}' . "\n\n" . '{% endif %}' . $bye . "\n" . '{{ salesChannel.translated.name }}' . "\n";
    }

    /**
     * Preview data for the email template editor.
     *
     * @return array<string, mixed>
     */
    private static function sample(): array
    {
        return [
            'withdrawal' => ['firstName' => 'Erika', 'lastName' => 'Muster', 'contractNumber' => '10001', 'status' => 'return_arrived'],
            'statusUrl' => 'https://shop.example/shopproof/withdrawal-status/0123456789abcdef0123456789abcdef/0123456789abcdef0123456789abcdef',
        ];
    }
}
