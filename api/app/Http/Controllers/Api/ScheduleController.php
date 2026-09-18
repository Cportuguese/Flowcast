<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index()
    {
        return response()->json(
            ClinicSchedule::orderBy('day_of_week')->get()
        );
    }

    /**
     * The active weekly schedule, readable by any authenticated user
     * (patients need this to know which days to offer for booking).
     */
    public function active()
    {
        return response()->json(
            ClinicSchedule::where('is_active', true)->orderBy('day_of_week')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'capacity' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $schedule = ClinicSchedule::updateOrCreate(
            ['day_of_week' => $validated['day_of_week']],
            [
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'slot_minutes' => $validated['slot_minutes'],
                'capacity' => $validated['capacity'],
                'is_active' => $validated['is_active'] ?? true,
            ]
        );

        return response()->json($schedule, 200);
    }

    public function eventDays()
    {
        return response()->json(
            EventDay::orderBy('date')->get()
        );
    }

    public function storeEventDay(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'unique:event_days,date'],
            'label' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $eventDay = EventDay::create($validated);

        return response()->json($eventDay, 201);
    }

    public function destroyEventDay(EventDay $eventDay)
    {
        $eventDay->delete();

        return response()->json(['message' => 'Event day removed.']);
    }
}
