<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * QR mobile check-in (legacy qr.php, PARITY-002). Public surface gated by
 * contest_info.contestCheckInPassword; msg codes 1-7 mirror legacy, msg=8
 * is the port's no-password-configured state (issue #30).
 */
final class QrCheckinTest extends AdminScreensTestCase
{
    private const PW = 'qr-test-pw';

    private int $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('contest_info')->where('id', 1)
            ->update(['contestCheckInPassword' => password_hash(self::PW, PASSWORD_BCRYPT)]);
        $this->entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'QRTEST entry',
            'brewCategory' => '21',
            'brewCategorySort' => '21',
            'brewSubCategory' => 'A',
            'brewPaid' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('id', $this->entryId)->delete();
        DB::table('contest_info')->where('id', 1)->update(['contestCheckInPassword' => null]);
        parent::tearDown();
    }

    public function test_anon_sees_password_form(): void
    {
        $this->get('/qr')->assertOk()->assertSee('QR Code Entry Check-In', false)
            ->assertSee('inputPassword', false)
            ->assertSee('set by the competition organizer');
    }

    public function test_no_password_configured_shows_not_available_notice(): void
    {
        DB::table('contest_info')->where('id', 1)->update(['contestCheckInPassword' => null]);

        $this->get('/qr')->assertOk()
            ->assertSee('QR Code check-in is not available')
            ->assertSee('contact the competition organizer')
            ->assertDontSee('inputPassword', false);
    }

    public function test_wrong_password_rejected(): void
    {
        $this->post('/qr/password-check', ['inputPassword' => 'wrong'])
            ->assertRedirect('/qr?action=default&msg=1');
    }

    public function test_password_check_when_none_configured_reports_msg_8(): void
    {
        DB::table('contest_info')->where('id', 1)->update(['contestCheckInPassword' => null]);

        $this->post('/qr/password-check', ['inputPassword' => 'anything'])
            ->assertRedirect('/qr?action=default&msg=8');

        $this->get('/qr?action=default&msg=8')->assertOk()
            ->assertSee('QR Code check-in is not available');
    }

    public function test_correct_password_accepted_then_checkin_with_judging_number(): void
    {
        $this->post('/qr/password-check', ['inputPassword' => self::PW])
            ->assertRedirect('/qr?action=default&msg=2');

        $this->post('/qr/checkin?id='.$this->entryId, [
            'brewJudgingNumber' => '123456',
            'brewBoxNum' => '7',
            'brewPaid' => '1',
        ])->assertRedirect();

        $entry = DB::table('brewing')->where('id', $this->entryId)
            ->first(['brewReceived', 'brewJudgingNumber', 'brewBoxNum', 'brewPaid']);
        self::assertNotNull($entry);
        self::assertSame(1, (int) $entry->brewReceived);
        self::assertSame('123456', (string) $entry->brewJudgingNumber);
        self::assertSame('7', (string) $entry->brewBoxNum);
        self::assertSame(1, (int) $entry->brewPaid);
    }

    public function test_duplicate_judging_number_refused(): void
    {
        $this->post('/qr/password-check', ['inputPassword' => self::PW]);
        DB::table('brewing')->where('id', '<>', $this->entryId)
            ->where('brewJudgingNumber', '123456')->update(['brewJudgingNumber' => null]);

        $other = DB::table('brewing')->insertGetId([
            'brewName' => 'QRTEST other',
            'brewCategory' => '21',
            'brewCategorySort' => '21',
            'brewSubCategory' => 'B',
            'brewJudgingNumber' => '123456',
        ]);

        $this->post('/qr/checkin?id='.$this->entryId, ['brewJudgingNumber' => '123456'])
            ->assertRedirect('/qr?action=default&go=default&view='.$other.'^123456&msg=5');

        DB::table('brewing')->where('id', $other)->delete();
    }

    public function test_checkin_without_session_flag_denied(): void
    {
        $this->post('/qr/checkin?id='.$this->entryId, ['brewJudgingNumber' => '654321'])
            ->assertRedirect('/qr?msg=1');
        self::assertSame(
            0,
            (int) DB::table('brewing')->where('id', $this->entryId)->value('brewReceived'),
        );
    }

    public function test_unknown_entry_reports_msg_4(): void
    {
        $this->post('/qr/password-check', ['inputPassword' => self::PW]);
        $this->post('/qr/checkin?id=99999999', [])
            ->assertRedirect('/qr?action=default&go=success&view=99999999^000000&msg=4');
    }
}
