{{-- Printable contact card(s) (legacy output/print.output.php, section=contact). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Contacts</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; }
    h2 { font-size: 14pt; margin: 10pt 0 2pt 0; }
    h2 small { font-size: 10pt; font-weight: normal; color: #444; }
    p { margin: 3pt 0; }
</style>
</head>
<body>

@if ($notFound || $contacts === [])
    <div style="padding: 25px; min-height: 400px;">
        <h2>Error</h2>
        <p>The requested competition contact could not be found.</p>
        <p><small><em>Please note: contact information may be unavailable if it was withheld for security reasons. Contact this competition official via other means (social media, the organizing website, etc.).</em></small></p>
    </div>
@else
    @foreach ($contacts as $contact)
        <div style="padding: 25px; min-height: 300px;">
            <h2><strong>Contact — {{ $contact->contactFirstName }} {{ $contact->contactLastName }}</strong><br><small>{{ $contact->contactPosition }}</small></h2>
            {{-- Divergence: legacy obfuscated the address with a JS cipher; a PDF needs it in plain text. --}}
            <p><strong>{{ $contact->contactEmail }}</strong></p>
            <p>Select the email address above to launch your native email application, or copy the address into a new message when using a web-based email service.</p>
            <p><small><em>Please note: this document is intended for printing and does not include spam-bot protections. Contact information is published with the competition organizer's consent.</em></small></p>
        </div>
    @endforeach
@endif

</body>
</html>
