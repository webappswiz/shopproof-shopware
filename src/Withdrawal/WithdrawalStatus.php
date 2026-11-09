<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
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
 * fully refunded moves it to "Refund sent" (a partial refund changes nothing, since it may not
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
    ) {
    }

    /**
     * @return array{changed: bool, emailed: bool}
     */
    public function set(string $id, string $state, bool $notify = true): array
    {
        $record = $this->store->find($id);
        if ($record === null || !$this->store->setStatus($id, $state)) {
            return ['changed' => false, 'emailed' => false];
        }
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
            $count += $this->set($id, WithdrawalStore::STATES[2])['changed'] ? 1 : 0;
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
}
