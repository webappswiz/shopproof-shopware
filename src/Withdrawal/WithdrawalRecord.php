<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Turns the data of Shopware's withdrawal form into the row the plugin keeps. Pure, so it is unit
 * tested alone. Shopware has already validated the form; this only trims, limits lengths and picks
 * the submission time.
 */
final class WithdrawalRecord
{
    /** Field lengths, the same limits Shopware's form validation uses. */
    public const LIMITS = [
        'first_name' => 255,
        'last_name' => 255,
        'email' => 320,
        'contract_number' => 255,
        'comment' => 4096,
    ];

    private const FORM_KEYS = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'email' => 'email',
        'contract_number' => 'contractNumber',
        'comment' => 'comment',
    ];

    /** Shopware\Core\Content\Flow\Dispatching\Action\FlowMailVariables::REVOCATION_REQUEST_FORM_DATA */
    public const FORM_DATA = 'revocationRequestFormData';

    /**
     * The submitted form. Shopware 6.7.12 added getRevocationRequestFormData(); 6.7.9 to 6.7.11 only
     * hand it to the flow through getValues().
     *
     * @return array<mixed>|null
     */
    public static function formFromEvent(object $event): ?array
    {
        if (method_exists($event, 'getRevocationRequestFormData')) {
            $form = $event->getRevocationRequestFormData();

            return \is_array($form) ? $form : null;
        }
        if (method_exists($event, 'getValues')) {
            $values = $event->getValues();
            $form = \is_array($values) ? ($values[self::FORM_DATA] ?? null) : null;

            return \is_array($form) ? $form : null;
        }

        return null;
    }

    /**
     * @param array<mixed> $form the event's form data
     *
     * @return array{first_name: string, last_name: string, email: string, contract_number: string, comment: string, submitted_at: \DateTimeImmutable}|null
     *                                                                                                                                                null when the form has no email or contract number
     */
    public static function fromForm(array $form, \DateTimeImmutable $now): ?array
    {
        $row = [];
        foreach (self::FORM_KEYS as $column => $key) {
            $value = $form[$key] ?? '';
            $value = \is_string($value) ? trim($value) : '';
            $row[$column] = mb_substr($value, 0, self::LIMITS[$column]);
        }
        if ($row['email'] === '' || $row['contract_number'] === '') {
            return null;
        }
        $time = $form['submitTime'] ?? null;

        return [
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'contract_number' => $row['contract_number'],
            'comment' => $row['comment'],
            'submitted_at' => $time instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($time) : $now,
        ];
    }

    /**
     * The order numbers to look for when a shopper types one: as typed, and without what shoppers
     * often add (spaces, a leading "#", "Order", "Order no.", "Bestellung", "Bestellnummer", "Nr.").
     * The record keeps what was typed.
     *
     * @return list<string>
     */
    public static function orderNumbers(string $typed): array
    {
        $typed = trim($typed);
        $bare = (string) preg_replace(
            '/^(?:(?:order|bestellung|bestellnummer)(?:\s*(?:number|nummer|no|nr)\b)?\s*\.?\s*:?)?\s*#?/iu',
            '',
            $typed
        );
        $bare = (string) preg_replace('/\s+/u', '', $bare);

        return array_values(array_unique(array_filter([$typed, $bare], static fn (string $number): bool => $number !== '')));
    }

    /**
     * Whether an order's customer email is the one the request gives, ignoring case and spaces.
     */
    public static function sameEmail(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));

        return $a !== '' && $a === mb_strtolower(trim($b));
    }
}
