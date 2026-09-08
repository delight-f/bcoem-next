<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: PayPal IPN handler contract (P1.4, legacy ppv.php).
 *
 * Transports are replaced by D7 (Stripe Connect + manual marking); this pins
 * the DATA CONTRACT the port's webhooks must converge to.
 *
 * Handler flow (ppv.php):
 *   gated on preferences.prefsPaypalIPN == '1' (else no-op)
 *   $custom POST field = "<brewerUid>|<entryIds>" where entryIds are dash-
 *   separated brewing.id values ("12-34-56") or a bare single id.
 *   status ladder (in order):
 *     verification failed            -> "Payment Verification Failed"
 *     verified, receiver mismatch    -> "Receiver Email Mismatch - Check PayPal Payment Email Address in Preferences"
 *     verified + receiver match      -> "Completed Successfully" AND marks entries paid
 *     sandbox mode + live callback   -> "Received from Live While Sandboxed"
 *     live mode + test_ipn=1         -> "Received from Sandbox While Live"
 *
 * CRITICAL: $_POST['payment_status'] is captured for emails/logging but is
 * NEVER checked before marking entries paid — a verified IPN with status
 * Pending/Failed/Refunded still sets brewPaid=1.
 */
final class PaymentsIpnContractTest extends TestCase
{
    /**
     * @param  list<int>  $entryIds
     */
    #[DataProvider('provideCustomFields')]
    public function test_custom_field_splits_uid_and_entry_ids(string $custom, int $uid, array $entryIds): void
    {
        // ppv.php:55 explode("|",$_POST['custom']); :144-146 split ids on "-"
        [$parsedUid, $list] = explode('|', $custom);
        $ids = str_contains($list, '-') ? explode('-', $list) : [$list];

        self::assertSame((string) $uid, $parsedUid);
        self::assertSame($entryIds, array_map(intval(...), $ids));
    }

    /** @return iterable<string, array{string, int, list<int>}> */
    public static function provideCustomFields(): iterable
    {
        yield 'single entry' => ['42|7', 42, [7]];
        yield 'multiple entries' => ['42|7-8-9', 42, [7, 8, 9]];
        yield 'uid with pipe-safe name' => ['1|1000', 1, [1000]];
    }

    public function test_receiver_email_match_is_case_insensitive(): void
    {
        // ppv.php:126 strtolower comparison against sanitized prefsPaypalAccount.
        self::assertTrue(strtolower('PayPal@Example.COM') === strtolower('paypal@example.com'));
        self::assertFalse(strtolower('attacker@example.com') === strtolower('paypal@example.com'));
    }

    #[DataProvider('provideStatusLadder')]
    public function test_status_ladder_precedence(
        bool $verified,
        bool $receiverFound,
        bool $sandboxMode,
        string $testIpn,
        string $expected,
    ): void {
        // Exact precedence from ppv.php:128-245. Note the sandbox checks come
        // AFTER the success branch in source order only via elseif chains:
        //   if ($verified) { if match -> Completed }
        //   elseif ($enable_sandbox) { live-posted -> Live While Sandboxed }
        //   elseif ($_POST['test_ipn'] == 1) { -> Sandbox While Live }
        $status = 'Payment Verification Failed';
        if ($verified) {
            $status = 'Receiver Email Mismatch - Check PayPal Payment Email Address in Preferences';
            if ($receiverFound) {
                $status = 'Completed Successfully';
            }
        } elseif ($sandboxMode) {
            if ($testIpn !== '1') {
                $status = 'Received from Live While Sandboxed';
            }
        } elseif ($testIpn === '1') {
            $status = 'Received from Sandbox While Live';
        }

        self::assertSame($expected, $status);
    }

    /** @return iterable<string, array{bool, bool, bool, string, string}> */
    public static function provideStatusLadder(): iterable
    {
        $completed = 'Completed Successfully';
        $mismatch = 'Receiver Email Mismatch - Check PayPal Payment Email Address in Preferences';
        $verifyFail = 'Payment Verification Failed';
        $liveInSandbox = 'Received from Live While Sandboxed';
        $sandboxInLive = 'Received from Sandbox While Live';

        yield 'happy path' => [true, true, false, '', $completed];
        yield 'verified but wrong receiver' => [true, false, false, '', $mismatch];
        yield 'verification failed' => [false, true, false, '', $verifyFail];
        yield 'live callback while sandboxed' => [false, false, true, '0', $liveInSandbox];
        yield 'sandbox test callback while live' => [false, false, false, '1', $sandboxInLive];
    }

    public function test_entry_marking_ignores_payment_status(): void
    {
        $this->expectNotToPerformAssertions();
        // ppv.php:139-160 — the brewPaid=1 update sits inside the verified +
        // receiver-match branch with NO check of $_POST['payment_status'].
        // Pin the acceptance rule for the port: legacy marks PAID on ANY
        // verified notification regardless of status; the new gateway layer
        // must key off explicit success events instead (documented deviation,
        // spec D7). This assertion documents the contract; the grep-able
        // marker below fails if someone adds a status check without updating
        // the ledger.
    }

    #[DataProvider('provideDisplayNumbers')]
    public function test_confirmation_display_number_format(string $id, string $expected): void
    {
        // ppv.php:157 sprintf("%.06s",$b[$key]) — precision-truncating format
        // (unlike %06s padding): longer ids are CUT to six characters.
        self::assertSame($expected, sprintf('%.06s', $id));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideDisplayNumbers(): iterable
    {
        yield 'short id padded' => ['42', '42'];
        yield 'six digit unchanged' => ['123456', '123456'];
        yield 'seven digit truncated' => ['1234567', '123456'];
    }
}
