<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

/**
 * P5.4 CRUD screens: sponsors, contacts, mods, style_types, hero_images —
 * plus the account ops make_admin / change_user_password.
 */
final class AdminScreensCrudTest extends AdminScreensTestCase
{
    /** @var list<int> */
    private array $sponsorIds = [];

    /** @var list<int> */
    private array $contactIds = [];

    /** @var list<int> */
    private array $modIds = [];

    /** @var list<int> */
    private array $styleTypeIds = [];

    /** @var list<string> */
    private array $heroFiles = [];

    final protected function tearDown(): void
    {
        DB::table('sponsors')->whereIn('id', $this->sponsorIds ?: [0])->delete();
        DB::table('contacts')->whereIn('id', $this->contactIds ?: [0])->delete();
        DB::table('mods')->whereIn('id', $this->modIds ?: [0])->delete();
        DB::table('judging_scores_bos')->whereIn('scoreType', $this->styleTypeIds ?: [0])->delete();
        DB::table('style_types')->whereIn('id', $this->styleTypeIds ?: [0])->delete();
        foreach ($this->heroFiles as $file) {
            @unlink(public_path('images/'.$file));
        }

        parent::tearDown();
    }

    public function test_every_screen_renders_for_an_admin(): void
    {
        $sponsorId = (int) DB::table('sponsors')->insertGetId(['sponsorName' => 'P54 Render', 'sponsorEnable' => 1]);
        $contactId = (int) DB::table('contacts')->insertGetId([
            'contactFirstName' => 'P54', 'contactLastName' => 'Render',
            'contactPosition' => 'Staff', 'contactEmail' => 'p54.render@brewingcompetitions.com',
        ]);
        $modId = (int) DB::table('mods')->insertGetId([
            'mod_name' => 'P54 Render Mod', 'mod_filename' => 'p54_render.php', 'mod_rank' => 25,
        ]);
        $styleTypeId = (int) DB::table('style_types')->insertGetId([
            'id' => 9400, 'styleTypeName' => 'P54 Render Type', 'styleTypeOwn' => 'custom',
            'styleTypeBOS' => 'N', 'styleTypeBOSMethod' => '1',
        ]);
        $this->sponsorIds[] = $sponsorId;
        $this->contactIds[] = $contactId;
        $this->modIds[] = $modId;
        $this->styleTypeIds[] = $styleTypeId;

        foreach (
            [
                '/admin/competition-info',
                '/admin/dates',
                '/admin/site-preferences', '/admin/site-preferences/default', '/admin/site-preferences/email',
                '/admin/site-preferences/entries', '/admin/site-preferences/payment', '/admin/site-preferences/best',
                '/admin/hero-images',
                '/admin/sponsors', '/admin/sponsors/create', '/admin/sponsors/'.$sponsorId.'/edit',
                '/admin/contacts', '/admin/contacts/create', '/admin/contacts/'.$contactId.'/edit',
                '/admin/mods', '/admin/mods/create', '/admin/mods/'.$modId.'/edit',
                '/admin/style-types', '/admin/style-types/create', '/admin/style-types/'.$styleTypeId.'/edit',
                '/admin/styles', '/admin/styles/create',
            ] as $url
        ) {
            $this->get($url)->assertOk();
        }
    }

    public function test_contacts_round_trip(): void
    {
        $response = $this->post('/admin/contacts', [
            'contactFirstName' => 'jane',
            'contactLastName' => 'doe',
            'contactPosition' => 'registrar',
            'contactEmail' => 'Jane.Doe@Example.ORG',
        ]);
        $response->assertRedirect('/admin/contacts?msg=9');

        $row = (array) DB::table('contacts')->where('contactEmail', 'jane.doe@example.org')->first();
        self::assertNotEmpty($row);
        $this->contactIds[] = (int) $row['id'];
        // Legacy standardize/capitalize pipeline: ucwords on names.
        self::assertSame('Jane', $row['contactFirstName']);
        self::assertSame('Doe', $row['contactLastName']);
        self::assertSame('Registrar', $row['contactPosition']);

        $this->put('/admin/contacts/'.$row['id'], [
            'contactFirstName' => 'Janet',
            'contactLastName' => 'Doe',
            'contactPosition' => 'Head Registrar',
            'contactEmail' => 'janet@example.org',
        ])->assertRedirect('/admin/contacts?msg=9');
        $contact = (array) DB::table('contacts')->find($row['id']);
        self::assertNotEmpty($contact);
        self::assertSame('Head Registrar', $contact['contactPosition']);

        $this->delete('/admin/contacts/'.$row['id'])->assertRedirect('/admin/contacts?msg=9');
        self::assertNull(DB::table('contacts')->find($row['id']));
    }

    public function test_sponsors_store_update_and_inline_bulk_save(): void
    {
        $id = (int) DB::table('sponsors')->insertGetId([
            'sponsorName' => 'P54 Sponsor',
            'sponsorEnable' => 1,
            'sponsorLevel' => '2',
        ]);
        $this->sponsorIds[] = $id;

        // Inline bulk update path (legacy action=update).
        $this->put('/admin/sponsors', [
            'id' => [$id],
            'sponsorLevel'.$id => '4',
            'sponsorImage'.$id => 'p54-logo.png',
            'sponsorText'.$id => '<em>Fine beer</em>',
            'sponsorEnable'.$id => '1',
        ])->assertRedirect('/admin/sponsors?msg=9');

        $row = (array) DB::table('sponsors')->find($id);
        self::assertSame(1, (int) $row['sponsorEnable']);
        self::assertSame('4', (string) $row['sponsorLevel']);
        self::assertSame('p54-logo.png', $row['sponsorImage']);
        self::assertSame('<em>Fine beer</em>', $row['sponsorText']);

        // Add/edit form path: check_http + blank_to_null.
        $newId = 0;
        $this->post('/admin/sponsors', [
            'sponsorName' => 'P54 Second',
            'sponsorURL' => 'second.example.org',
            'sponsorLocation' => 'Denver',
            'sponsorLevel' => '1',
            'sponsorEnable' => '0',
        ])->assertRedirect('/admin/sponsors?msg=9');

        $inserted = (array) DB::table('sponsors')->where('sponsorName', 'P54 Second')->first();
        $newId = (int) $inserted['id'];
        $this->sponsorIds[] = $newId;
        self::assertSame('http://second.example.org', $inserted['sponsorURL']);
        self::assertNull($inserted['sponsorImage']);

        $this->put('/admin/sponsors/'.$newId, [
            'sponsorName' => 'P54 Second Renamed',
            'sponsorURL' => '',
            'sponsorLevel' => '5',
            'sponsorEnable' => '1',
        ])->assertRedirect('/admin/sponsors?msg=9');
        $updated = (array) DB::table('sponsors')->find($newId);
        self::assertSame('P54 Second Renamed', $updated['sponsorName']);
        self::assertNull($updated['sponsorURL']); // blank_to_null
    }

    public function test_mods_crud_and_enable_bulk(): void
    {
        $this->post('/admin/mods', [
            'mod_name' => 'P54 Mod',
            'mod_filename' => 'p54_mod.php',
            'mod_description' => 'test mod',
            'mod_type' => '0',
            'mod_permission' => '2',
            'mod_extend_function' => '9',
            // extend-function 9 without an admin target falls back to 'default'.
            'mod_rank' => '7',
            'mod_display_rank' => '2',
            'mod_enable' => '1',
        ])->assertRedirect('/admin/mods?msg=9');

        $row = (array) DB::table('mods')->where('mod_name', 'P54 Mod')->first();
        self::assertNotEmpty($row);
        $this->modIds[] = (int) $row['id'];
        self::assertSame('default', $row['mod_extend_function_admin']);
        self::assertSame(7, (int) $row['mod_rank']);

        $this->put('/admin/mods', [
            'id' => [$row['id']],
            'mod_enable'.$row['id'] => '0',
        ])->assertRedirect('/admin/mods?msg=9');
        $mod = (array) DB::table('mods')->find($row['id']);
        self::assertNotEmpty($mod);
        self::assertSame(0, (int) $mod['mod_enable']);

        $this->delete('/admin/mods/'.$row['id'])->assertRedirect('/admin/mods?msg=9');
        self::assertNull(DB::table('mods')->find($row['id']));
    }

    public function test_style_types_custom_ids_start_at_16_and_combine_separate(): void
    {
        $this->remember('preferences');

        $this->post('/admin/style-types', [
            'styleTypeName' => 'P54 Specialty',
            'styleTypeBOS' => 'Y',
            'styleTypeBOSMethod' => '2',
            'styleTypeEntryLimit' => '10',
        ])->assertRedirect('/admin/style-types?msg=9');

        $custom = (array) DB::table('style_types')->where('styleTypeName', 'P54 Specialty')->first();
        $this->styleTypeIds[] = (int) $custom['id'];
        // ids 1-15 reserved for system use.
        self::assertTrue((int) $custom['id'] >= 16);
        self::assertSame('custom', $custom['styleTypeOwn']);

        // Combine: Mead/Cider gains BOS; Cider(2)/Mead(3) lose it and their
        // limits; stray BOS scores for the retired types are deleted.
        DB::table('judging_scores_bos')->insert(['scoreType' => 2]);
        try {
            $this->post('/admin/style-types/combine')->assertRedirect('/admin/style-types?msg=2');

            $meadCider = (array) DB::table('style_types')->where('styleTypeName', 'Mead/Cider')->first();
            self::assertSame('Y', $meadCider['styleTypeBOS']);
            foreach ([2, 3] as $sysId) {
                $type = (array) DB::table('style_types')->find($sysId);
                self::assertSame('N', $type['styleTypeBOS']);
                self::assertNull($type['styleTypeEntryLimit']);
            }
            self::assertSame(0, DB::table('judging_scores_bos')->whereIn('scoreType', [2, 3])->count());

            $this->post('/admin/style-types/separate')->assertRedirect('/admin/style-types?msg=2');
            $typeTwo = (array) DB::table('style_types')->find(2);
            $typeThree = (array) DB::table('style_types')->find(3);
            self::assertNotEmpty($typeTwo);
            self::assertNotEmpty($typeThree);
            self::assertSame('Y', $typeTwo['styleTypeBOS']);
            self::assertSame('Y', $typeThree['styleTypeBOS']);
            $meadCiderAfter = (array) DB::table('style_types')->where('styleTypeName', 'Mead/Cider')->first();
            self::assertSame('N', $meadCiderAfter['styleTypeBOS']);
        } finally {
            DB::table('judging_scores_bos')->whereIn('scoreType', [2, 3])->delete();
            DB::table('judging_scores_bos')->where('scoreType', $meadCider['id'] ?? 0)->delete();
        }
    }

    public function test_hero_images_save_persists_choices_json(): void
    {
        $this->remember('preferences');

        // Drop a convention-named candidate into the images directory.
        $image = 'beer-p54-banner.jpg';
        file_put_contents(public_path('images/'.$image), 'not-a-real-image-but-name-matches');
        $this->heroFiles[] = $image;

        // Checkbox absent → disabled=false in the stored map.
        $this->post('/admin/hero-images/save')->assertRedirect('/admin/hero-images?msg=saved');

        $map = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsHeroImages'), true);
        self::assertIsArray($map);
        self::assertFalse($map[$image]);

        // Checked → true.
        $field = 'hero_image_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $image);
        $this->post('/admin/hero-images/save', [$field => '1'])->assertRedirect('/admin/hero-images?msg=saved');
        $map = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsHeroImages'), true);
        self::assertTrue($map[$image]);

        // Delete removes the file and prunes the map.
        $this->post('/admin/hero-images/delete', ['hero_image_delete' => $image])
            ->assertRedirect('/admin/hero-images?msg=deleted');
        self::assertFileDoesNotExist(public_path('images/'.$image));
        $map = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsHeroImages'), true);
        self::assertArrayNotHasKey($image, $map);
        $this->heroFiles = [];

        // Unknown delete targets are rejected.
        $this->from('/admin/hero-images')
            ->post('/admin/hero-images/delete', ['hero_image_delete' => '../../etc/passwd'])
            ->assertSessionHasErrors('hero_image_delete');
    }

    public function test_hero_images_upload_rejects_bad_extension(): void
    {
        // Extension whitelist fires before any dimension checks.
        $file = UploadedFile::fake()->createWithContent('p54.txt', 'nope');

        $this->from('/admin/hero-images')
            ->post('/admin/hero-images/upload', [
                'hero_image_category' => '1',
                'hero_image_file' => $file,
            ])
            ->assertSessionHasErrors();

        $errors = session('errors');
        self::assertInstanceOf(ViewErrorBag::class, $errors);
        $messages = collect($errors->getBag('default')->all())->flatten()->implode(' ');
        self::assertStringContainsString(
            'Unsupported file type',
            $messages,
        );
    }

    public function test_make_admin_flips_user_level_and_obfuscate_defaults(): void
    {
        $targetId = 9402;
        DB::table('users')->whereIn('id', [$targetId])->delete();
        DB::table('users')->insert([
            'id' => $targetId,
            'user_name' => 'p54.participant@brewingcompetitions.com',
            'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 1,
        ]);

        try {
            $this->get('/admin/users/'.$targetId.'/level')->assertOk();

            // Promote to admin with the obfuscate box unchecked → legacy
            // default stores 0 for non-participants.
            $this->put('/admin/users/'.$targetId.'/level', [
                'userLevel' => '1',
            ])->assertRedirect('/admin/users/'.$targetId.'/level?msg=2');

            $user = (array) DB::table('users')->find($targetId);
            self::assertSame('1', $user['userLevel']);
            self::assertSame(0, (int) $user['userAdminObfuscate']);

            // Back to participant with obfuscate checked → stays 1.
            $this->put('/admin/users/'.$targetId.'/level', [
                'userLevel' => '2',
                'userAdminObfuscate' => '1',
            ]);
            $user = (array) DB::table('users')->find($targetId);
            self::assertSame('2', $user['userLevel']);
            self::assertSame(1, (int) $user['userAdminObfuscate']);
        } finally {
            DB::table('users')->where('id', $targetId)->delete();
        }
    }

    public function test_change_user_password_updates_hash_and_invalidates_old(): void
    {
        Auth::logout();

        $targetEmail = 'p54.changepw@brewingcompetitions.com';
        DB::table('users')->where('user_name', $targetEmail)->delete();
        DB::table('users')->insert([
            'id' => 9403,
            'user_name' => $targetEmail,
            'password' => password_hash('old-password', PASSWORD_BCRYPT),
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
        ]);
        $originalUser = (array) DB::table('users')->find(9403);
        self::assertNotEmpty($originalUser);
        $originalHash = (string) $originalUser['password'];

        try {
            $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);

            // Mismatch is a server-side validation error (documented divergence).
            $this->put('/admin/users/9403/password', [
                'password1' => 'brand-new-secret',
                'password' => 'different-confirm',
            ])->assertSessionHasErrors('password');
            $unchanged = (array) DB::table('users')->find(9403);
            self::assertNotEmpty($unchanged);
            self::assertSame($originalHash, $unchanged['password']);

            $this->put('/admin/users/9403/password', [
                'password1' => 'brand-new-secret',
                'password' => 'brand-new-secret',
            ])->assertRedirect('/admin/users/9403/password?msg=2');

            $updatedUser = (array) DB::table('users')->find(9403);
            self::assertNotEmpty($updatedUser);
            $hash = (string) $updatedUser['password'];
            self::assertNotSame($originalHash, $hash);
            self::assertTrue(password_verify('brand-new-secret', $hash));

            // Old password no longer logs in.
            Auth::logout();
            $response = $this->post('/login', ['loginUsername' => $targetEmail, 'loginPassword' => 'old-password']);
            $user = Auth::user();
            self::assertTrue(Auth::guest() || ($user instanceof User && ! $user->isAdmin()));
        } finally {
            DB::table('users')->where('id', 9403)->delete();
        }
    }

    public function test_non_admin_users_cannot_reach_account_ops(): void
    {
        $participantId = 9404;
        DB::table('users')->whereIn('id', [$participantId])->delete();
        DB::table('users')->insert([
            'id' => $participantId,
            'user_name' => 'p54.lowlevel@brewingcompetitions.com',
            'password' => password_hash('pw', PASSWORD_BCRYPT),
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
        ]);

        try {
            $this->post('/login', ['loginUsername' => 'p54.lowlevel@brewingcompetitions.com', 'loginPassword' => 'pw']);

            foreach (
                [
                    ['get', '/admin/competition-info'],
                    ['get', '/admin/users/'.self::ADMIN_ID.'/level'],
                    ['put', '/admin/users/'.self::ADMIN_ID.'/level'],
                    ['get', '/admin/styles'],
                    ['get', '/admin/dates'],
                    ['put', '/admin/dates'],
                    ['get', '/admin/site-preferences'],
                    ['put', '/admin/site-preferences/default'],
                    ['get', '/admin/send-test-email'],
                    ['post', '/admin/hero-images/save'],
                    ['delete', '/admin/sponsors/1'],
                ] as [$method, $url]
            ) {
                $this->{$method}($url)
                    ->assertRedirect('/?msg=99');
            }
        } finally {
            DB::table('users')->where('id', $participantId)->delete();
        }
    }
}
