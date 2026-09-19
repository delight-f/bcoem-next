<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ContactMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

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
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        $this->get('/contact')
            ->assertOk()
            ->assertSeeInOrder([
                'Use the links below to contact individuals involved with coordinating this competition:',
                'Default Admin',
            ])
            // Issue #54: the address is never printed — each row links through a
            // signed, throttled redirect so scrapers harvest nothing.
            ->assertDontSee((string) $contact->contactEmail)
            ->assertSee('/contact/'.$contact->id.'/email?signature=', false);
    }

    public function test_contact_email_link_redirects_to_the_mail_client(): void
    {
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        $this->get(URL::signedRoute('contact.email', ['contact' => $contact->id]))
            ->assertRedirect('mailto:'.$contact->contactEmail);
    }

    public function test_contact_email_link_rejects_an_unsigned_url(): void
    {
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        // No signature: the signed middleware must refuse it.
        $this->get('/contact/'.$contact->id.'/email')->assertForbidden();
    }

    public function test_home_contact_section_renders_the_form_when_mode_y(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);

        // Issue #54: the landing #contact section used to show the officials
        // list even in form mode, so "Enable Contact Form" had no effect here.
        $this->get('/')
            ->assertOk()
            ->assertSee('Use the form below to contact a competition official.')
            ->assertSee('Send Message');
    }

    public function test_contact_page_renders_form_when_mode_y(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);

        $this->get('/contact')
            ->assertOk()
            ->assertSee('Use the form below to contact a competition official. All fields with a star are required.')
            ->assertSee('Send Message')
            // The "not all required fields" text is a validation message, not a
            // permanent note: on a clean GET the form must not carry it, or a
            // visitor who filled everything in still reads it as an error.
            ->assertDontSee('Not all required fields have been filled out or selected');
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

    /**
     * Live symptom: on a host whose mail program is missing or misconfigured
     * (scabs.nfshost.com ran "/usr/sbin/sendmail -t -i" and got "exit code
     * 127: not found"), pressing Send returned a 500 and the sender lost the
     * message they had typed. The form must come back with their input and an
     * explanation instead.
     */
    public function test_contact_form_survives_a_broken_mail_transport(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('Process failed with exit code 127: sh: /usr/sbin/sendmail: not found'));

        $this->from('/contact')->post('/contact', [
            'to' => (int) $contact->id,
            'from_name' => 'Jane Tester',
            'from_email' => 'jane.tester@example.com',
            'subject' => 'Question about entries',
            'message' => 'Hello, when do entries close?',
        ])->assertRedirect('/contact')
            ->assertSessionHasErrors('message')
            // The typed message is kept so the sender does not retype it.
            ->assertSessionHasInput('message', 'Hello, when do entries close?');
    }

    /** prefsEmailCC ("Contact Form CC") copies the sender on the message. */
    public function test_contact_form_ccs_the_sender_when_enabled(): void
    {
        $origCc = DB::table('preferences')->where('id', 1)->value('prefsEmailCC');
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y', 'prefsEmailCC' => '1']);
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        try {
            Mail::fake();

            $this->post('/contact', [
                'to' => (int) $contact->id,
                'from_name' => 'Jane Tester',
                'from_email' => 'jane.tester@example.com',
                'subject' => 'CC me',
                'message' => 'Hello',
            ])->assertRedirect('/contact');

            Mail::assertSent(ContactMail::class, fn (ContactMail $mail): bool => collect($mail->cc)
                ->contains(fn (array $a): bool => $a['address'] === 'jane.tester@example.com'));
        } finally {
            DB::table('preferences')->where('id', 1)->update(['prefsEmailCC' => $origCc]);
        }
    }

    /**
     * Issue #54: the contact form carries the same honeypot belt as
     * registration — a bot that fills the hidden trap field is discarded
     * silently and nothing is sent.
     */
    public function test_contact_form_is_honeypot_guarded(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsContact' => 'Y']);
        $contact = DB::table('contacts')->orderBy('id')->first();
        $this->assertNotNull($contact);

        $html = (string) $this->get('/contact')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/name="valid_from"/', $html);
        self::assertSame(1, preg_match('/name="(my_name_[A-Za-z0-9]+)"/', $html, $m));
        $trap = (string) ($m[1] ?? '');
        self::assertNotSame('', $trap, 'the honeypot trap field must render in the form');

        Mail::fake();

        $this->post('/contact', [
            'to' => (int) $contact->id,
            'from_name' => 'Spam Bot',
            'from_email' => 'bot@example.com',
            'subject' => 'buy things',
            'message' => 'spam',
            $trap => 'filled in by a bot',
        ])->assertOk();

        Mail::assertNothingSent();
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
