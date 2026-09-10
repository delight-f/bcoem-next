<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestEmailMail;
use App\Support\Mail\MailSettings;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Send test email (spec §7 P5.4) — port of admin/send_test_email.admin.php.
 *
 * Legacy sends during page render to the logged-in admin's own address
 * (users.user_name) from the preferences' SMTP identity, then prints the
 * PHPMailer transcript inside a <pre>. The port keeps those semantics: GET
 * renders the settings summary, attempts the send via Laravel Mail wrapped
 * in try/catch, and surfaces success or the failure message inline (the
 * legacy "errors will be displayed below" contract).
 *
 * Divergence: legacy rebuilt the SMTP transport from prefsEmailHost/port/
 * encryption on every send; the port displays those preferences but sends
 * through the application's configured mailer (the standalone build has one
 * mailer, not per-tenant transports — see spec §9 native-replace).
 */
final class SendTestEmailController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $user = $request->user();

        // Report the transport actually in force, so the summary reflects
        // what will happen rather than only the (possibly unused) SMTP row.
        $settings = [
            'transport' => MailSettings::transport($ctx) === null
                ? (string) config('mail.default')
                : MailSettings::label((string) MailSettings::transport($ctx)),
            'delivers' => MailSettings::delivers($ctx),
            'from' => strtolower((string) filter_var((string) $ctx->prefsStr('prefsEmailFrom'), FILTER_SANITIZE_EMAIL)),
            'host' => (string) $ctx->prefsStr('prefsEmailHost'),
            'username' => (string) $ctx->prefsStr('prefsEmailUsername'),
            'encryption' => (string) $ctx->prefsStr('prefsEmailEncrypt'),
            'port' => (string) $ctx->prefsStr('prefsEmailPort'),
        ];

        $brewer = DB::table('brewer')->where('uid', (int) $user->id)->first();
        $toName = html_entity_decode(trim(($brewer->brewerFirstName ?? '').' '.($brewer->brewerLastName ?? '')));

        $sent = null;
        $error = null;

        try {
            Mail::to((string) $user->user_name, $toName)
                ->send(new TestEmailMail(
                    html_entity_decode((string) $ctx->contestStr('contestName')),
                    $settings,
                ));
            // A mailer that writes to the log (or the array transport) does
            // not throw, so "no exception" alone would report success for a
            // message that was never handed to a delivery agent.
            $sent = $settings['delivers'];
        } catch (\Throwable $e) {
            // Legacy echoes "Message could not be sent. Mailer Error: ..." inline.
            report($e);
            $sent = false;
            $error = e($e->getMessage());
        }

        return view('admin.send-test-email', [
            'ctx' => $ctx,
            'email' => (string) $user->user_name,
            'settings' => $settings,
            'sent' => $sent,
            'error' => $error,
        ]);
    }
}
