<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Your Flowcast booking code</title>
</head>
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;">
        <p style="margin:0 0 4px;font-size:13px;color:#64748b;">{{ config('clinic.name') }} · Flowcast</p>
        <h1 style="margin:0 0 20px;font-size:20px;color:#0b1636;">Confirm your booking</h1>

        <p style="margin:0 0 16px;font-size:14px;">Hi {{ $patient->first_name ?? $patient->name }}, enter this code in the app to send your booking for review:</p>

        <p style="margin:0 0 20px;text-align:center;font-size:34px;font-weight:bold;letter-spacing:8px;color:#0b1636;">{{ $code }}</p>

        <table style="width:100%;margin:0 0 20px;font-size:14px;border-collapse:collapse;">
            <tr>
                <td style="padding:6px 0;color:#64748b;">With</td>
                <td style="padding:6px 0;text-align:right;">{{ $details['provider'] }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0;color:#64748b;">When</td>
                <td style="padding:6px 0;text-align:right;">{{ $details['when'] }}</td>
            </tr>
        </table>

        <p style="margin:0 0 8px;font-size:13px;color:#475569;">The code works for {{ $validMinutes }} minutes and only for this booking.</p>
        <p style="margin:0;font-size:13px;color:#475569;">Didn't try to book? You can ignore this email — nothing is booked without the code.</p>
    </div>
</body>
</html>
