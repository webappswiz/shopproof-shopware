<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\Installer\MailTemplateInstaller;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Where a withdrawal stands for the customer, as in the WooCommerce plugin: received, return
 * arrived, refund sent. The merchant moves it on the Shopproof page; a payment Shopware marks as
 * fully refunded moves it to "Refund sent", and an order whose deliveries Shopware marks as
 * returned moves to "Return arrived" (a partial refund changes nothing, since it may not
 * cover what is owed). Moving forward emails the customer through the shop's own mail setup with
 * the Shopware email template for that step, unless the setting is off. The email links to the
 * customer's status page.
 */
class WithdrawalStatus
{
    private const CONFIG = 'ShopproofCompliance.config.';

    public const ROUTE = '/shopproof/withdrawal-status/';

    public function __construct(
        private readonly WithdrawalStore $store,
        private readonly OrderLink $link,
        private readonly OrderEmailSubscriber $email,
        private readonly AbstractMailService $mail,
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ?ProofLog $proofLog = null,
    ) {
    }

    /**
     * Moves a withdrawal and records the change in the proof log (decision 0051).
     *
     * @param string $source who moved it: 'shop' (the merchant, on the Shopproof page),
     *                       'shopware_refund' (the payment marked as refunded) or 'shopware_return'
     *                       (every delivery marked as returned)
     *
     * @return array{changed: bool, emailed: bool}
     */
    public function set(string $id, string $state, bool $notify = true, string $source = 'shop'): array
    {
        $record = $this->store->find($id);
        if ($record === null || !$this->store->setStatus($id, $state)) {
            return ['changed' => false, 'emailed' => false];
        }
        $this->logProof($id, $state, $source);
        $forward = array_search($state, WithdrawalStore::STATES, true) > array_search($record['status'], WithdrawalStore::STATES, true);
        $emailed = $notify && $forward && $state !== WithdrawalStore::STATES[0] && $this->send($record, $state);

        return ['changed' => true, 'emailed' => $emailed];
    }

    /**
     * A payment Shopware marks as refunded: each open withdrawal for that order is refunded.
     */
    public function orderRefunded(string $orderId): int
    {
        $count = 0;
        foreach ($this->store->openForOrder($orderId) as $id) {
            $count += $this->set($id, WithdrawalStore::STATES[2], true, 'shopware_refund')['changed'] ? 1 : 0;
        }

        return $count;
    }

    /**
     * Every delivery of the order came back (ReturnSubscriber::allBack): each withdrawal for it that is still at "Received"
     * moves to "Return arrived". One already further along is left as it is, so a refund that was
     * sent first is never undone.
     */
    public function orderReturned(string $orderId): int
    {
        if (!ReturnSubscriber::allBack($this->store->deliveryStates($orderId))) {
            return 0;
        }
        $count = 0;
        foreach ($this->store->openForOrder($orderId) as $id) {
            $record = $this->store->find($id);
            if ($record === null || $record['status'] !== WithdrawalStore::STATES[0]) {
                continue;
            }
            $count += $this->set($id, WithdrawalStore::STATES[1], true, 'shopware_return')['changed'] ? 1 : 0;
        }

        return $count;
    }

    /**
     * The customer's status page for a record, '' when its storefront has no domain.
     */
    public function url(string $id, string $channel, string $language): string
    {
        if (!Uuid::isValid($id) || !Uuid::isValid($channel)) {
            return '';
        }
        $domain = $this->email->domain($channel, Uuid::isValid($language) ? $language : Defaults::LANGUAGE_SYSTEM);

        return $domain === '' ? '' : $domain . self::ROUTE . strtolower($id) . '/' . $this->link->statusToken($id);
    }

    public function emailsOn(?string $channel): bool
    {
        return $this->config->get(self::CONFIG . 'statusEmails', $channel) !== false;
    }

    /**
     * @param array{id: string, salesChannelId: string, languageId: string, firstName: string, lastName: string, email: string, contractNumber: string, orderId: string, submittedAt: string, status: string, returnArrivedAt: string, refundSentAt: string} $record
     */
    private function send(array $record, string $state): bool
    {
        $channel = $record['salesChannelId'];
        if (!Uuid::isValid($channel) || !$this->emailsOn($channel) || filter_var($record['email'], \FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $language = Uuid::isValid($record['languageId']) ? $record['languageId'] : Defaults::LANGUAGE_SYSTEM;
        $template = $this->template($state === WithdrawalStore::STATES[1] ? MailTemplateInstaller::RETURN_ARRIVED : MailTemplateInstaller::REFUND_SENT, $language);
        if ($template === null) {
            return false;
        }
        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, array_values(array_unique([$language, Defaults::LANGUAGE_SYSTEM])));
        try {
            $sent = $this->mail->send(
                [
                    'recipients' => [$record['email'] => trim($record['firstName'] . ' ' . $record['lastName'])],
                    'salesChannelId' => $channel,
                    'senderName' => $template['senderName'],
                    'subject' => $template['subject'],
                    'contentHtml' => $template['contentHtml'],
                    'contentPlain' => $template['contentPlain'],
                ],
                $context,
                [
                    'withdrawal' => [
                        'firstName' => $record['firstName'],
                        'lastName' => $record['lastName'],
                        'contractNumber' => $record['contractNumber'],
                        'status' => $state,
                    ],
                    'statusUrl' => $this->url($record['id'], $channel, $language),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not send the withdrawal status email: ' . $e->getMessage());

            return false;
        }

        return $sent !== null;
    }

    /**
     * The template's texts in a language, else in the system language.
     *
     * @return array{senderName: string, subject: string, contentHtml: string, contentPlain: string}|null
     */
    private function template(string $type, string $language): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT tt.sender_name, tt.subject, tt.content_html, tt.content_plain
             FROM mail_template_type ty
             INNER JOIN mail_template t ON t.mail_template_type_id = ty.id
             INNER JOIN mail_template_translation tt ON tt.mail_template_id = t.id
             WHERE ty.technical_name = :type AND tt.language_id IN (:language, :system) AND tt.content_html IS NOT NULL AND tt.content_html <> \'\'
             ORDER BY tt.language_id = :language DESC, t.system_default DESC LIMIT 1',
            ['type' => $type, 'language' => Uuid::fromHexToBytes($language), 'system' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );
        if ($row === false) {
            return null;
        }
        $text = static fn (mixed $value): string => \is_string($value) ? $value : '';
        $plain = $text($row['content_plain'] ?? null);

        return [
            'senderName' => $text($row['sender_name'] ?? null),
            'subject' => $text($row['subject'] ?? null),
            'contentHtml' => $text($row['content_html'] ?? null),
            'contentPlain' => $plain !== '' ? $plain : strip_tags($text($row['content_html'] ?? null)),
        ];
    }

    /**
     * The proof log entry for a status change, the same fields as the WooCommerce plugin: the
     * reference, the new status, when (UTC), by what, and for a refund the day it was due and
     * whether it was paid by then (yes, no, or check when it came after the 14 days while the goods
     * were not marked back, since the shop may wait for them, Art. 13(3)). Shopware refunds whole
     * payments, so no item amounts. No name, email address or order number. A failed write is
     * logged and never stops the status.
     */
    /**
     * Whether a refund on this day was in time: "yes" on or before the due day; "check" after it
     * while the goods were not marked back or no delivery day is known (the shop may have waited
     * for the goods or proof of sending, Article 13(3), which Shopproof cannot see); else "no".
     *
     * @param array<mixed> $clock
     */
    public static function onTime(string $day, array $clock): string
    {
        $due = \is_string($clock['refundDue'] ?? null) ? $clock['refundDue'] : '';
        if ($day !== '' && $due !== '' && $day <= $due) {
            return 'yes';
        }
        if ($day === '' || (($clock['basis'] ?? '') !== 'order' && ($clock['goodsBack'] ?? '') === '')) {
            return 'check';
        }

        return 'no';
    }

    /**
     * The shop's time zone for proof log days: the time zone of the first administrator in the
     * Shopware admin (webhooks and order events carry none), else the server's, else UTC.
     */
    private function shopZone(): \DateTimeZone
    {
        try {
            $name = $this->connection->fetchOne('SELECT `time_zone` FROM `user` WHERE `admin` = 1 AND `active` = 1 ORDER BY `created_at` ASC LIMIT 1');
            if (\is_string($name) && \in_array($name, \DateTimeZone::listIdentifiers(), true)) {
                return new \DateTimeZone($name);
            }
        } catch (\Throwable) {
            // Fall through to the server's time zone.
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    private function logProof(string $id, string $state, string $source): void
    {
        if ($this->proofLog === null) {
            return;
        }
        try {
            $at = gmdate('Y-m-d\TH:i:s\Z');
            $summary = ['reference' => $id, 'status' => $state, 'source' => $source, 'at' => $at];
            if ($state === WithdrawalStore::STATES[2]) {
                $days = OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays'));
                $zone = $this->shopZone();
                $clock = $this->store->list(1, 1, '', $zone, $days, $id)['items'][0]['clock'] ?? null;
                if (\is_array($clock) && $clock['refundDue'] !== '') {
                    $day = \is_string($clock['refunded'] ?? null) && $clock['refunded'] !== '' ? $clock['refunded'] : WithdrawalClock::day($at, $zone);
                    $summary['refund_day'] = $day;
                    $summary['refund_due'] = $clock['refundDue'];
                    $summary['on_time'] = self::onTime($day, $clock);
                }
            }
            if (!$this->proofLog->append('status', $summary)) {
                $this->logger->error('Shopproof could not add a status change to the proof log (lock not available).');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not add a status change to the proof log: ' . $e->getMessage());
        }
    }
}
