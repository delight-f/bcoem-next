<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Admin write feedback. Every write on the Competition Preparation surface now
 * reports its outcome, and a write against a row that no longer exists is a
 * rejected no-op rather than a false success:
 *
 *  - judging / non-judging sessions, drop-off locations, custom categories →
 *    flash `status` on success;
 *  - update/delete of a missing row → flash `error`, never `status`;
 *  - upload delete of a missing file → flash `error`;
 *  - sponsors bulk update with no ids → flash `error`.
 *
 * (The flash is rendered globally by the layout; these assertions pin the
 * controller side, which is where the silence used to live.)
 */
final class AdminWriteFeedbackTest extends PublicSurfaceTestCase
{
    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const ADMIN_ID = 98601;

    private const ADMIN_EMAIL = 'feedback.admin@brewingcompetitions.com';

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<int> */
    private array $dropoffIds = [];

    /** @var list<int> */
    private array $specialBestIds = [];

    private ?int $maxContactId = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->maxContactId = (int) (DB::table('contacts')->max('id') ?? 0);

        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    protected function tearDown(): void
    {
        DB::table('judging_locations')->whereIn('id', $this->locationIds ?: [0])->delete();
        DB::table('drop_off')->whereIn('id', $this->dropoffIds ?: [0])->delete();
        DB::table('special_best_data')->whereIn('sid', $this->specialBestIds ?: [0])->delete();
        DB::table('special_best_info')->whereIn('id', $this->specialBestIds ?: [0])->delete();

        if ($this->maxContactId !== null) {
            DB::table('contacts')->where('id', '>', $this->maxContactId)->delete();
        }

        DB::table('brewer')->where('uid', self::ADMIN_ID)->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    public function test_judging_session_write_reports_success(): void
    {
        $this->post('/admin/judging/locations', [
            'judgingLocName' => 'Feedback Session',
            'judgingLocType' => '0',
            'judgingDate' => '2030-06-15 09:00 AM',
            'judgingDateEnd' => '',
            'judgingLocation' => 'Feedback Hall',
            'judgingRounds' => '1',
            'judgingLocNotes' => '',
        ])
            ->assertRedirect('/admin/judging/locations')
            ->assertSessionHas('status');

        $this->locationIds[] = (int) DB::table('judging_locations')->max('id');
    }

    public function test_non_judging_session_write_reports_success(): void
    {
        $this->post('/admin/judging/non-judging', [
            'judgingLocName' => 'Feedback Non-Judging',
            'judgingDate' => '2030-06-14 06:00 PM',
            'judgingLocation' => 'Feedback Warehouse',
            'judgingLocNotes' => '',
        ])
            ->assertRedirect('/admin/judging/non-judging')
            ->assertSessionHas('status');

        $this->locationIds[] = (int) DB::table('judging_locations')->max('id');
    }

    public function test_updating_a_missing_judging_session_is_a_rejected_no_op(): void
    {
        $this->put('/admin/judging/locations/999999', [
            'judgingLocName' => 'Ghost Session',
            'judgingLocType' => '0',
            'judgingDate' => '2030-06-15 09:00 AM',
            'judgingDateEnd' => '',
            'judgingLocation' => 'Nowhere',
            'judgingRounds' => '1',
            'judgingLocNotes' => '',
        ])
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        self::assertNull(DB::table('judging_locations')->where('id', 999999)->first());
    }

    public function test_dropoff_writes_report_success(): void
    {
        $payload = [
            'dropLocationName' => 'Feedback Dropoff',
            'dropLocationPhone' => '555-0100',
            'dropLocation' => '1 Feedback Way',
            'dropLocationWebsite' => '',
            'dropLocationNotes' => '',
        ];

        $this->post('/admin/dropoff', $payload)
            ->assertRedirect('/admin/dropoff')
            ->assertSessionHas('status');

        $id = (int) DB::table('drop_off')->max('id');
        $this->dropoffIds[] = $id;

        $this->put('/admin/dropoff/'.$id, $payload)
            ->assertRedirect('/admin/dropoff')
            ->assertSessionHas('status');

        $this->delete('/admin/dropoff/'.$id)
            ->assertRedirect('/admin/dropoff')
            ->assertSessionHas('status');
    }

    public function test_a_missing_dropoff_update_and_delete_are_rejected(): void
    {
        $payload = [
            'dropLocationName' => 'Ghost Dropoff',
            'dropLocationPhone' => '555-0199',
            'dropLocation' => 'Nowhere',
            'dropLocationWebsite' => '',
            'dropLocationNotes' => '',
        ];

        $this->put('/admin/dropoff/999999', $payload)
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $this->delete('/admin/dropoff/999999')
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }

    public function test_custom_category_write_reports_success(): void
    {
        $this->post('/admin/judging/special-best', [
            'sbi_name' => 'Feedback Category',
            'sbi_places' => '3',
            'sbi_rank' => '1',
            'sbi_description' => '',
        ])
            ->assertRedirect('/admin/judging/special-best')
            ->assertSessionHas('status');

        $this->specialBestIds[] = (int) DB::table('special_best_info')->max('id');
    }

    public function test_updating_a_missing_contact_is_rejected(): void
    {
        $this->put('/admin/contacts/999999', [
            'contactFirstName' => 'Ghost',
            'contactLastName' => 'Contact',
            'contactPosition' => 'Nobody',
            'contactEmail' => 'ghost@example.com',
        ])
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }

    public function test_deleting_a_missing_upload_file_reports_an_error_not_a_success(): void
    {
        $this->post('/admin/upload/delete', ['file' => 'definitely-not-here-xyz.png'])
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }

    public function test_sponsors_bulk_update_with_no_ids_is_rejected(): void
    {
        $this->put('/admin/sponsors', [])
            ->assertRedirect('/admin/sponsors')
            ->assertSessionHas('error')
            ->assertSessionMissing('status');
    }
}
