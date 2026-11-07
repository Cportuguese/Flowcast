<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Medical Certificate {{ $certificate->certificate_no }}</title>
    <style>
        @page { margin: 56px 64px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #0f172a; line-height: 1.6; }
        .header { border-bottom: 2px solid #0b1636; padding-bottom: 12px; margin-bottom: 28px; }
        .clinic { font-size: 18px; font-weight: bold; color: #0b1636; letter-spacing: 0.5px; }
        .institution { color: #475569; }
        .mark { float: left; width: 40px; height: 40px; margin: 3px 12px 0 0; }
        .meta { float: right; text-align: right; color: #475569; font-size: 11px; }
        .title { text-align: center; font-size: 20px; font-weight: bold; letter-spacing: 2px; margin: 24px 0 28px; }
        p { margin: 0 0 12px; }
        .body { font-size: 13px; }
        .label { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .box { border: 1px solid #e2e8f0; padding: 12px 16px; margin: 16px 0; }
        .signature { margin-top: 56px; width: 260px; }
        .signature .line { border-top: 1px solid #0f172a; padding-top: 6px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    </style>
</head>
<body>
    @php
        // Official Flowcast mark, generated from brand/logo-mark.svg. Embedded
        // as data so dompdf never has to fetch a file or URL.
        $mark = base64_encode(file_get_contents(resource_path('images/logo-mark.png')));
    @endphp
    <div class="header">
        <img class="mark" src="data:image/png;base64,{{ $mark }}" alt="Flowcast">
        <div class="meta">
            Certificate No. <strong>{{ $certificate->certificate_no }}</strong><br>
            Issued {{ $certificate->created_at->format('F j, Y') }}
        </div>
        <div class="clinic">{{ $clinic['name'] }}</div>
        <div class="institution">{{ implode(' · ', array_filter([$clinic['institution'], $clinic['address']])) }}</div>
        <div style="clear: both;"></div>
    </div>

    <div class="title">MEDICAL CERTIFICATE</div>

    @php
        // "a BS Information Technology student (SITE)", "a faculty member of
        // the School of Teacher Education" — whatever the record supports.
        $course = \App\Support\Udd::courseName($patient->course);
        $department = \App\Support\Udd::departmentName($patient->department);
        $role = match ($patient->patient_type) {
            'student' => $course ? "a {$course} student" : 'a student',
            'faculty' => 'a faculty member',
            'staff' => 'an employee',
            default => null,
        };
        $of = $department && ! $course ? " of the {$department}" : ($department && $course ? " ({$patient->department})" : '');
        $who = implode(' ', array_filter([
            $role ? ', '.$role.$of : null,
            $patient->school_id ? '(ID No. '.$patient->school_id.')' : null,
        ]));
    @endphp

    <div class="body">
        <p>To whom it may concern,</p>

        <p>
            This is to certify that <strong>{{ $patient->name }}</strong>{{ $who }},
            was seen at {{ $clinic['name'] }} on
            <strong>{{ $visit->created_at->format('F j, Y') }}</strong>
            for <strong>{{ Str::lower($visit->complaint) }}</strong>.
        </p>

        <div class="box">
            <div class="label">Intervention</div>
            <div>{{ $visit->intervention }}</div>
            @if ($visit->referred_to)
                <div class="label" style="margin-top: 10px;">Referred to</div>
                <div>{{ $visit->referred_to }}</div>
            @endif
        </div>

        @if ($certificate->rest_days)
            <p>
                The patient is advised to rest for
                <strong>{{ $certificate->rest_days }} {{ Str::plural('day', $certificate->rest_days) }}</strong>,
                from {{ $certificate->valid_from->format('F j, Y') }}
                @if ($certificate->valid_until && ! $certificate->valid_until->equalTo($certificate->valid_from))
                    to {{ $certificate->valid_until->format('F j, Y') }}
                @endif
                and may be excused from classes or work for that period.
            </p>
        @else
            <p>The patient was assessed and is fit to return to classes or work.</p>
        @endif

        @if ($certificate->remarks)
            <div class="label">Remarks</div>
            <p>{{ $certificate->remarks }}</p>
        @endif

        <p>This certificate is issued upon the request of the patient for whatever purpose it may serve.</p>
    </div>

    <div class="signature">
        <div class="line">
            <strong>{{ $issuer->signatureName() }}</strong><br>
            <span style="color: #475569;">{{ $issuer->job_title ?? 'Clinic Personnel' }}, {{ $clinic['name'] }}</span>
        </div>
    </div>

    <div class="footer">
        {{ $certificate->certificate_no }} · Generated by Flowcast on {{ $certificate->created_at->format('Y-m-d H:i') }}.
        Valid only with the clinic's signature or stamp. Not valid for legal or insurance purposes.
    </div>
</body>
</html>
