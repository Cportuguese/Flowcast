<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalCertificate;
use App\Models\VisitRecord;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Medical certificates for a visit. Personnel issue them (route-gated);
 * admin and the patient concerned can only view/download.
 */
class CertificateController extends Controller
{
    /**
     * Issue the certificate for a visit and render its PDF. One per visit —
     * a second request returns 409 rather than silently re-issuing.
     */
    public function store(Request $request, VisitRecord $visit)
    {
        $validated = $request->validate([
            'rest_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($visit->certificate()->exists()) {
            return response()->json(['message' => 'A certificate has already been issued for this visit.'], 409);
        }

        $restDays = $validated['rest_days'] ?? null;
        $validFrom = $visit->created_at->copy()->startOfDay();

        $certificate = DB::transaction(function () use ($visit, $request, $restDays, $validated, $validFrom) {
            $certificate = MedicalCertificate::create([
                'visit_record_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'issued_by_id' => $request->user()->id,
                'certificate_no' => MedicalCertificate::nextNumber(now()->year),
                'rest_days' => $restDays ?: null,
                'remarks' => $validated['remarks'] ?? null,
                'valid_from' => $validFrom,
                'valid_until' => $restDays ? $validFrom->copy()->addDays($restDays - 1) : null,
                'file_path' => '', // set once the number is known
            ]);

            $certificate->file_path = sprintf('certificates/%d/%s.pdf', $validFrom->year, $certificate->certificate_no);
            $certificate->save();

            Storage::put($certificate->file_path, $this->render($certificate));

            return $certificate;
        });

        app(AuditLogger::class)->log($request->user()->id, 'certificate.issued', $certificate, [
            'visit_record_id' => $visit->id,
            'certificate_no' => $certificate->certificate_no,
            'rest_days' => $certificate->rest_days,
        ]);

        return response()->json(self::format($certificate), 201);
    }

    /** Certificate metadata for a visit, or 404 if none has been issued. */
    public function show(Request $request, VisitRecord $visit)
    {
        $this->abortUnlessStaffOrOwner($request->user(), $visit->patient_id);

        $certificate = $visit->certificate()->firstOrFail();

        return response()->json(self::format($certificate));
    }

    /** The PDF itself. */
    public function download(Request $request, VisitRecord $visit)
    {
        $this->abortUnlessStaffOrOwner($request->user(), $visit->patient_id);

        $certificate = $visit->certificate()->firstOrFail();

        if (! Storage::exists($certificate->file_path)) {
            // Regenerate from the stored data if the file went missing (disk swap, etc.).
            Storage::put($certificate->file_path, $this->render($certificate));
        }

        // Handing out a medical document is a PHI access event — record who
        // pulled which certificate, staff or patient alike.
        app(AuditLogger::class)->log($request->user()->id, 'certificate.downloaded', $certificate, [
            'visit_record_id' => $visit->id,
            'certificate_no' => $certificate->certificate_no,
        ]);

        return Storage::download($certificate->file_path, $certificate->certificate_no.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function render(MedicalCertificate $certificate): string
    {
        $certificate->loadMissing('visit', 'patient', 'issuedBy');

        return Pdf::loadView('certificates.medical', [
            'certificate' => $certificate,
            'visit' => $certificate->visit,
            'patient' => $certificate->patient,
            'issuer' => $certificate->issuedBy,
            'clinic' => config('clinic'),
        ])->setPaper('a4')->output();
    }

    /**
     * Shared with VisitController so visit payloads can embed it.
     *
     * @return array<string, mixed>
     */
    public static function format(MedicalCertificate $certificate): array
    {
        $certificate->loadMissing('issuedBy:id,name');

        return [
            'id' => $certificate->id,
            'certificate_no' => $certificate->certificate_no,
            'rest_days' => $certificate->rest_days,
            'remarks' => $certificate->remarks,
            'valid_from' => $certificate->valid_from->toDateString(),
            'valid_until' => $certificate->valid_until?->toDateString(),
            'issued_by' => $certificate->issuedBy->name,
            'issued_at' => $certificate->created_at->toIso8601String(),
            'download_url' => "/visits/{$certificate->visit_record_id}/certificate/download",
        ];
    }
}
