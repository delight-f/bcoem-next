<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ContactMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Standalone volunteers + contact public pages (legacy volunteers.sec.php /
 * contact.sec.php). Pins the legacy contract: the judges/stewards blurb with
 * its window/auth branches, the non-judging staff block, the
 * contestVolunteers body, and the prefsContact gate (X nav / N list / Y
 * form + submission).
 */
final class PublicSurfacesVolunteersContactTest extends PublicSurfaceTestCase
{
    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();
        // anon-base fixture: contacts display in list mode. Reset each run
        // so the Y-mode tests do not leak prefsContact into the N-mode ones.
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'N']);
        // Snapshot contest_info: the volunteers body test mutates it.
        $row = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $row === null ? [] : (array) $row;
    }

    protected function tearDown(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'N']);
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }
        parent::tearDown();
    }

    public function test_volunteers_page_renders_public_surface(): void
    {
        $this->get('/volunteers')
            ->assertOk()
            ->assertSeeInOrder([
                'Judges and Stewards',
                'If you have registered, log in and then choose Edit Account from the My Account menu indicated by the icon on the top menu.',
                'Staff',
                'If you would like to volunteer to be a competition staff member, please register or update your account to indicate that you wish to be a part of the competition staff.',
            ])
            // The test DB's contestVolunteers is empty, so the Other
            // Volunteer Info block must be absent (legacy renders nothing
            // when the body is empty — no coming-soon fallback, Slice 4).
            ->assertDontSee('Other Volunteer Info');
    }

    public function test_volunteers_page_other_info_block_from_body_only(): void
    {
        // Legacy volunteers.sec.php:62-74: the "Other Volunteer Info"
        // header + body render only when contestVolunteers is non-empty;
        // there is no fallback text (P3 Slice 4, PARITY-009).
        DB::table('contest_info')->where('id', 1)->update(['contestVolunteers' => '<p>Volunteer information coming soon!</p>']);
        $html = $this->get('/volunteers')->assertOk()->getContent();
        self::assertIsString($html);
        $this->assertStringContainsString('Other Volunteer Info', $html);
        $this->assertStringContainsString('Volunteer information coming soon!', $html);

        DB::table('contest_info')->where('id', 1)->update(['contestVolunteers' => '']);
        $html = $this->get('/volunteers')->assertOk()->getContent();
        self::assertIsString($html);
        $this->assertStringNotContainsString('Other Volunteer Info', $html);
        $this->assertStringNotContainsString('coming soon', $html);

        // Restore the fixture body — this test mutates shared contest_info
        // and must not leak into the next test in the run.
        DB::table('contest_info')->where('id', 1)->update(['contestVolunteers' => '<p>Volunteer information coming soon!</p>']);
    }

    public function test_contact_page_lists_officials_when_mode_n(): void
    {
        $this->assertSame('N', (string) DB::table('preferences')->where('id', 1)->value('prefsContact'));

        $this->get('/contact')
            ->assertOk()
            ->assertSeeInOrder([
                'Use the links below to contact individuals involved with coordinating this competition:',
                'Default Admin',
                'user.baseline@brewingcompetitions.com',
            ]);
    }

    public function test_contact_page_renders_form_when_mode_y(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('Use the form below to contact a competition official. All fields with a star are required.')
            ->assertSee('Send Message');
    }

    public function test_contact_page_renders_empty_when_mode_x(): void
    {
        // Legacy contact.sec.php has no prefsContact == "X" branch — a
        // disabled contact surface renders NOTHING in the section (P3
        // Slice 3, PARITY-008; previously the port emitted a
        // "Display of competition contacts has been disabled" paragraph
        // the legacy page does not have).
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'X']);

        $response = $this->get('/contact')->assertOk();
        $html = $response->getContent();
        self::assertIsString($html);
        $this->assertStringNotContainsString('Display of competition contacts has been disabled', $html);
    }

    public function test_contact_page_uses_section_salutation_not_interest_line(): void
    {
        // Legacy index.pub.php:106-111: non-default sections render the
        // contest-name h1 (+ Welcome when logged in); the "Thank you for
        // your interest..." interest line is landing-only (129-135).
        $response = $this->get('/contact')->assertOk();
        $html = $response->getContent();
        self::assertIsString($html);
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('Thank you for your interest in the', $html);
    }

    public function test_contact_form_submits_and_sends_mail(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        Mail::fake();

        $this->post('/contact', [
            'to' => (int) $contact->id,
            'from_name' => 'Jane Tester',
            'from_email' => 'jane.tester@example.com',
            'subject' => 'Question about entries',
            'message' => 'Hello, when do entries close?',
        ])->assertRedirect('/contact');

        Mail::assertSent(ContactMail::class, 1);
        Mail::assertSent(ContactMail::class, function (ContactMail $mail) use ($contact): bool {
            $this->assertSame($contact->contactEmail, $mail->to[0]['address']);
            $this->assertStringContainsString('Question about entries', (string) $mail->envelope()->subject);
            $this->assertStringContainsString('jane.tester@example.com', (string) $mail->render());

            return true;
        });
    }

    public function test_contact_form_validates_required_fields(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);

        $this->post('/contact', [
            'to' => 1,
            'from_name' => '',
            'from_email' => 'not-an-email',
            'subject' => '',
            'message' => '',
        ])->assertSessionHasErrors(['from_name', 'from_email', 'subject', 'message']);
    }
}
