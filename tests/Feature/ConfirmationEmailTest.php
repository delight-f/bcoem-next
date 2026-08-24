<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaymentConfirmMail;
use App\Mail\RegistrationConfirmMail;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;
use App\Support\Payments\PaymentService;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Confirmation emails (ticket 16 / P3.6). Registration confirm fires only
 * when prefsEmailRegConfirm == 1 (legacy process_users_register.inc.php
 * gate); payment confirm fires on every verified paid event routed through
 * PaymentService::markPaid() and never references PayPal (ledger D7).
 */
final class ConfirmationEmailTest extends PublicSurfaceTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    /** @var list<int> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        MySqlTestCase::ensureMigrated();

        DB::table('judging_locations')->delete();
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',
            'contestRegistrationDeadline' => '4102444800',
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }
        if ($this->entries !== []) {
            DB::table('brewing')->whereIn('id', $this->entries)->delete();
        }
        DB::table('payments')->where('event_id', 'evt_confirm')->delete();

        parent::tearDown();
    }

    private function setRegConfirmPref(string|int|null $value): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsEmailRegConfirm' => $value]);
    }

    /** @return array<string, mixed> */
    private function registerPayload(): array
    {
        return [
            'user_name' => 'mail.entrant@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => 'pabst',
            'brewerFirstName' => 'Mail',
            'brewerLastName' => 'Entrant',
            'brewerCountry' => 'United States',
            'brewerAddress' => '123 Main St',
            'brewerCity' => 'Anytown',
            'brewerState' => 'CO',
            'brewerZip' => '80001',
            'brewerPhone1' => '555-1234',
            'brewerProAm' => '0',
            'brewerClubs' => '',
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ];
    }

    public function test_registration_confirm_fires_when_pref_on(): void
    {
        $this->setRegConfirmPref(1);
        Mail::fake();

        $this->post('/register/entrant', $this->registerPayload())
            ->assertRedirect('/?section=list&msg=7');

        $user = DB::table('users')->where('user_name', 'mail.entrant@example.com')->first();
        $this->assertNotNull($user);
        $this->createdUsers[] = (int) $user->id;

        Mail::assertSent(RegistrationConfirmMail::class, 1);
        Mail::assertSent(RegistrationConfirmMail::class, function (RegistrationConfirmMail $mail): bool {
            $rendered = (string) $mail->render();
            $this->assertSame('mail.entrant@example.com', $mail->to[0]['address']);
            $this->assertStringContainsString('Registration Confirmation', (string) $mail->envelope()->subject);
            // Legacy content: name, username/email, security question,
            // address, phone, role flags.
            $this->assertStringContainsString('Mail Entrant', $rendered);
            $this->assertStringContainsString('mail.entrant@example.com', $rendered);
            $this->assertStringContainsString('What is your favorite all-time beer to drink?', $rendered);
            $this->assertStringContainsString('Judge: No', strip_tags($rendered));
            $this->assertStringContainsString('555-1234', $rendered);

            return true;
        });
    }

    public function test_registration_confirm_suppressed_when_pref_off(): void
    {
        foreach ([0, null] as $value) {
            $this->setRegConfirmPref($value);
            Mail::fake();

            $payload = $this->registerPayload();
            $payload['user_name'] = "off{$value}.entrant@example.com";
            $this->post('/register/entrant', $payload)->assertRedirect('/?section=list&msg=7');

            $user = DB::table('users')->where('user_name', "off{$value}.entrant@example.com")->first();
            $this->assertNotNull($user);
            $this->createdUsers[] = (int) $user->id;

            Mail::assertNothingSent();
        }
    }

    private function seedPaidFixture(): int
    {
        DB::table('brewing')->insert([
            'brewName' => 'Confirm Fixture',
            'brewCategorySort' => '7',
            'brewCategory' => '7',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 9901,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ]);
        $entryId = (int) DB::getPdo()->lastInsertId();
        $this->entries[] = $entryId;

        DB::table('users')->insert(['user_name' => 'paid.entrant@example.com', 'userLevel' => '2', 'password' => 'x']);
        $userId = (int) DB::getPdo()->lastInsertId();
        $this->createdUsers[] = $userId;

        DB::table('brewer')->insert([
            'uid' => $userId,
            'brewerFirstName' => 'Paid',
            'brewerLastName' => 'Entrant',
            'brewerEmail' => 'paid.entrant@example.com',
        ]);

        return $userId;
    }

    public function test_payment_confirm_fires_on_mark_paid_without_paypal_wording(): void
    {
        $userId = $this->seedPaidFixture();
        Mail::fake();

        self::assertTrue((new PaymentService)->markPaid(
            [$this->entries[0]], $userId, '25.00', PaymentService::METHOD_STRIPE, 'pay_c1', 'evt_confirm',
        ));

        Mail::assertSent(PaymentConfirmMail::class, 1);
        Mail::assertSent(PaymentConfirmMail::class, function (PaymentConfirmMail $mail): bool {
            $this->assertSame('paid.entrant@example.com', $mail->to[0]['address']);
            $this->assertStringContainsString('Payment Received', (string) $mail->envelope()->subject);

            $rendered = (string) $mail->render();
            $this->assertStringContainsString('payment has been received', $rendered);
            $this->assertStringContainsString('(1)', $rendered); // Entries (:count)
            $this->assertStringContainsString((string) $this->entries[0], $rendered);
            $this->assertStringContainsString('25.00', $rendered);
            $this->assertStringNotContainsString('PayPal', $rendered);

            return true;
        });
    }

    public function test_no_payment_mail_on_duplicate_or_failed_events(): void
    {
        $userId = $this->seedPaidFixture();
        $service = new PaymentService;
        Mail::fake();

        self::assertTrue($service->markPaid(
            [$this->entries[0]], $userId, '25.00', PaymentService::METHOD_MANUAL, null, 'evt_confirm',
        ));
        self::assertFalse($service->markPaid(
            [$this->entries[0]], $userId, '25.00', PaymentService::METHOD_MANUAL, null, 'evt_confirm',
        ));
        self::assertFalse($service->apply(
            new PaymentResult(PaymentEvent::Failed, 'evt_confirm_fail'),
            [$this->entries[0]], $userId, PaymentService::METHOD_STRIPE, '25.00',
        ));

        // Exactly one mail: the first success only.
        Mail::assertSent(PaymentConfirmMail::class, 1);
    }
}
