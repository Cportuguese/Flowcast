{{ config('clinic.name') }} · Flowcast

Hi {{ $patient->first_name ?? $patient->name }},

Your booking code is: {{ $code }}

With: {{ $details['provider'] }}
When: {{ $details['when'] }}

The code works for {{ $validMinutes }} minutes and only for this booking.
Didn't try to book? Ignore this email — nothing is booked without the code.
