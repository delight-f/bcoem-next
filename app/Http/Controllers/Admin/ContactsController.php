<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contacts admin (spec §7 P5.4) — port of admin/contacts.admin.php +
 * process_contacts.inc.php (admin branch). Four columns, blank_to_null,
 * email lowercased through FILTER_SANITIZE_EMAIL; names/position go through
 * ucwords like legacy's standardize_name/capitalize pipeline (the
 * HTMLPurifier pass is dropped — inputs are plain text fields rendered with
 * {{ }} escaping).
 */
final class ContactsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.contacts', [
            'ctx' => TenantContext::load(),
            'contacts' => DB::table('contacts')->orderBy('contactLastName')->get(),
            'editing' => null,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        return $this->index($request);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('contacts')->insert(self::row($request));

        return redirect('/admin/contacts?msg=9');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.contacts', [
            'ctx' => TenantContext::load(),
            'contacts' => DB::table('contacts')->orderBy('contactLastName')->get(),
            'editing' => DB::table('contacts')->where('id', $id)->first(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('contacts')->where('id', $id)->update(self::row($request));

        return redirect('/admin/contacts?msg=9');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        if (! DB::table('contacts')->where('id', $id)->exists()) {
            return redirect('/admin/contacts');
        }

        DB::table('contacts')->delete($id);

        return redirect('/admin/contacts?msg=9');
    }

    /**
     * Exact process_contacts.inc.php column map.
     *
     * @return array<string, mixed>
     */
    private static function row(Request $request): array
    {
        $data = $request->validate([
            'contactFirstName' => ['required', 'string', 'max:100'],
            'contactLastName' => ['required', 'string', 'max:100'],
            'contactPosition' => ['required', 'string', 'max:100'],
            'contactEmail' => ['required', 'email:filter', 'max:255'],
        ]);

        $sanitizedEmail = filter_var((string) $data['contactEmail'], FILTER_SANITIZE_EMAIL);

        return [
            'contactFirstName' => self::blankToNull(ucwords(strtolower((string) $data['contactFirstName']))),
            'contactLastName' => self::blankToNull(ucwords(strtolower((string) $data['contactLastName']))),
            'contactPosition' => self::blankToNull(ucwords(strtolower((string) $data['contactPosition']))),
            'contactEmail' => self::blankToNull(strtolower(is_string($sanitizedEmail) ? $sanitizedEmail : '')),
        ];
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
