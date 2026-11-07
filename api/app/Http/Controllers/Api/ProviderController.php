<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderSchedule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The clinic's professionals — the head nurse, the dentist and the school
 * physician — and the weekly hours each can be booked.
 */
class ProviderController extends Controller
{
    /** Listed in this order everywhere: the nurse first, as she is in daily. */
    private const PROFESSION_ORDER = ['nurse' => 0, 'dentist' => 1, 'physician' => 2];

    /**
     * Who a patient can book, with the days and hours they are in. Only
     * active schedule rows are listed: a professional with none is not
     * offered at all.
     */
    public function index()
    {
        $providers = $this->providers(activeOnly: true)
            ->filter(fn (User $provider) => $provider->providerSchedules->isNotEmpty())
            ->values();

        return response()->json($providers->map(fn (User $provider) => $this->format($provider))->all());
    }

    /** Admin view: every professional with every schedule row, active or not. */
    public function adminIndex()
    {
        return response()->json($this->providers(activeOnly: false)->map(fn (User $provider) => $this->format($provider, withInactive: true))->values()->all());
    }

    /**
     * Set one professional's hours for one weekday (creating the row or
     * replacing it), the same way Clinic Settings saves the clinic's hours.
     */
    public function storeSchedule(Request $request, User $user)
    {
        if ($user->profession === null || ! $user->isStaff()) {
            return response()->json(['message' => 'Only clinic professionals have a booking schedule.'], 422);
        }

        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schedule = ProviderSchedule::updateOrCreate(
            ['user_id' => $user->id, 'day_of_week' => $validated['day_of_week']],
            [
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'slot_minutes' => $validated['slot_minutes'],
                'capacity' => $validated['capacity'],
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        app(AuditLogger::class)->log($request->user()->id, 'provider_schedule.updated', $schedule, [
            'provider_id' => $user->id,
            ...$validated,
        ]);

        return response()->json($this->formatSchedule($schedule));
    }

    /**
     * @return Collection<int, User>
     */
    private function providers(bool $activeOnly): Collection
    {
        return User::providers()
            ->with(['providerSchedules' => fn ($query) => $query
                ->when($activeOnly, fn ($q) => $q->where('is_active', true))
                ->orderBy('day_of_week')])
            ->get()
            ->sortBy(fn (User $provider) => [self::PROFESSION_ORDER[$provider->profession] ?? 9, $provider->name])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function format(User $provider, bool $withInactive = false): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'display_name' => $provider->display_name,
            'job_title' => $provider->job_title,
            'profession' => $provider->profession,
            'schedule' => $provider->providerSchedules
                ->filter(fn (ProviderSchedule $schedule) => $withInactive || $schedule->is_active)
                ->map(fn (ProviderSchedule $schedule) => $this->formatSchedule($schedule))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSchedule(ProviderSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'slot_minutes' => $schedule->slot_minutes,
            'capacity' => $schedule->capacity,
            'is_active' => $schedule->is_active,
        ];
    }
}
