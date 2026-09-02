{{-- QR mobile check-in (legacy qr.php). Standalone page — no site layout,
     password-gated, mobile-first. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin: 0; padding: 0; font-family: system-ui, sans-serif; }
        .container { max-width: 400px; padding: 15px; margin: 0 auto; }
        .form-control { display: block; width: 100%; box-sizing: border-box; padding: 10px; font-size: 16px; margin-bottom: 10px; }
        .btn-block { display: block; width: 100%; padding: 10px; background: #337ab7; color: #fff; border: 0; border-radius: 4px; font-size: 16px; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 12px; }
        .alert-danger { background: #f2dede; color: #a94442; }
        .alert-success { background: #dff0d8; color: #3c763d; }
        .alert-info { background: #d9edf7; color: #31708f; }
        .well { background: #f5f5f5; border: 1px solid #e3e3e3; border-radius: 4px; padding: 12px; }
        .badge { background: #337ab7; color: #fff; border-radius: 4px; padding: 2px 8px; }
        .text-danger { color: #a94442; } .text-primary { color: #337ab7; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>
<div class="container">
    <div class="container-signin">
        @php($msg = (string) $msg)
        @if ($msg !== 'default')
            <div class="alert {{ in_array($msg, ['2', '3', '6'], true) ? 'alert-success' : 'alert-danger' }} alert-dismissible fade in" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                @if ($msg === '1')
                    <span class="fa fa-exclamation-circle"></span> <strong>Password incorrect.</strong> Please try again.
                @elseif ($msg === '2')
                    <span class="fa fa-check-circle"></span> <strong>Password accepted.</strong>
                @elseif ($msg === '3')
                    <p><span class="fa fa-check-circle"></span> <strong>Entry number <span class="text-danger">{{ $checkedIn[0] ?? '' }}</span> is checked in with <span class="text-danger">{{ $checkedIn[1] ?? '' }}</span> as its judging number.</strong></p>
                    <p>If this judging number is <em>not</em> correct, <strong>re-scan the code and re-enter the correct judging number.</strong></p>
                @elseif ($msg === '4')
                    <span class="fa fa-exclamation-circle"></span> <strong>Entry number {{ $checkedIn[0] ?? '' }} was not found in the database. Set the bottle(s) aside and alert the competition organizer.</strong>
                @elseif ($msg === '5')
                    <span class="fa fa-exclamation-circle"></span> <strong>The judging number you entered - {{ $checkedIn[1] ?? '' }} - is already assigned to entry number {{ $checkedIn[0] ?? '' }}.</strong>
                @elseif ($msg === '6')
                    <p><span class="fa fa-check-circle"></span> <strong>Entry number {{ $checkedIn[0] ?? '' }} is checked in.</strong></p>
                @elseif ($msg === '7')
                    <span class="fa fa-exclamation-circle"></span> <strong>There was a problem with the last request, please try again.</strong>
                @endif
            </div>
        @endif

        <div class="qr-checkin-head">
            <h3>{{ $ctx->contestStr('contestName') }}: QR Code Entry Check-In</h3>
        </div>

        @if (! session('qrPasswordOK'))
            <div align="center" class="text-primary"><span class="fa fa-qrcode fa-5x"></span></div>
            <p class="lead"><small><strong class="text-danger">QR Code scanning is available natively on most modern mobile operating systems. Simply point your camera to the QR Code on a bottle label and follow the prompts. For older mobile operating systems, a QR Code scanning app is required to utilize this feature.</strong></small></p>
            <p>Scan a QR Code located on a bottle label, enter the required password, and check in the entry.</p>
            <p style="margin-bottom: 15px;" class="container-signin-heading">To check in entries via QR code, please provide the correct password. You will only need to provide the password once per session - be sure to keep the QR Code scanning app open.</p>

            <form name="form1" action="{{ url('/qr/password-check'.($id !== null ? '?id='.$id : '')) }}" method="post">
                @csrf
                <div class="mb-3">
                    <label for="inputPassword" class="visually-hidden">Password</label>
                    <input type="password" name="inputPassword" id="inputPassword" class="form-control" placeholder="Password" autofocus required>
                </div>
                <button class="btn btn-lg btn-primary btn-block" type="submit">Log In</button>
            </form>
            <p style="margin-top: 15px;" class="well"><small>Need a QR Code scanning app? Search <a href="https://play.google.com/store/search?q=qr%20code%20scanner&c=apps&hl=en" target="_blank" rel="noopener">Google Play</a> (Android) or <a href="https://itunes.apple.com/store/" target="_blank" rel="noopener">iTunes</a> (iOS).</small></p>
        @else
            @if ($id === null)
                <p class="lead text-primary"><span class="fa fa-spinner fa-spin"></span> <strong>Waiting for scanned QR code input.</strong></p>
                <p class="alert alert-info"><span class="fa fa-info-circle"></span> Scan the next QR Code. Close this browser tab if you wish.</p>
            @else
                <p class="lead text-primary"><strong>Assign a judging number and/or box number to entry <span class="badge">{{ sprintf('%06d', $id) }}</span></strong></p>
                <p class="lead text-danger"><small><strong>ONLY input a judging number if your competition is using judging number labels at sorting.</strong></small></p>
                <form name="form1" action="{{ url('/qr/checkin?id='.$id) }}" method="post">
                    @csrf
                    <div class="mb-3">
                        <label for="brewJudgingNumber">Judging Number</label>
                        <input type="tel" pattern="[^^]+" maxlength="6" minlength="6" name="brewJudgingNumber" id="brewJudgingNumber" class="form-control" placeholder="Six numbers with leading zeros - e.g., 000021." autofocus>
                        <div class="form-text small">Be sure to double-check your input and affix the appropriate judging number labels to each bottle and bottle label (if applicable).</div>
                    </div>
                    <div class="mb-3">
                        <label for="brewBoxNum">Box Number</label>
                        <input type="text" name="brewBoxNum" id="brewBoxNum" class="form-control" placeholder="">
                    </div>
                    <div class="mb-3">
                        <label for="brewPaid"><input type="checkbox" name="brewPaid" id="brewPaid" value="1"> Paid</label>
                    </div>
                    <button class="btn btn-lg btn-primary btn-block" type="submit">Check In</button>
                </form>
            @endif
        @endif
    </div>
</div>
</body>
</html>
