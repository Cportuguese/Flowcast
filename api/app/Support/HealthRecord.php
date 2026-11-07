<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation for the UDD Health Services record — the patient details the
 * clinic keeps on file — shared by sign-up, the patient's own edits and the
 * nurse's walk-in form, so all three accept and reject exactly the same
 * things.
 */
final class HealthRecord
{
    /**
     * Letters in any language (ñ, é...), spaces, periods ("Ma.", "Sto."),
     * apostrophes and hyphens, starting with a letter.
     */
    private const NAME_PATTERN = "/^\pL[\pL\s.'\-]*$/u";

    private const CONTACT_PATTERN = '/^[0-9+\-\s()]{7,20}$/';

    /** How the fields read in error messages ("The ID number field is required."). */
    public const ATTRIBUTE_NAMES = [
        'school_id' => 'ID number',
        'birthdate' => 'date of birth',
        'home_address' => 'home address',
        'patient_type' => 'patient type',
        'contact_no' => 'contact number',
        'first_name' => 'first name',
        'middle_name' => 'middle name',
        'last_name' => 'last name',
    ];

    public const MESSAGES = [
        'school_id.unique' => 'This ID number is already registered.',
        'middle_name.prohibited' => 'Leave the middle name empty if you have none.',
        'first_name.regex' => 'Names may only contain letters, spaces, periods, apostrophes and hyphens.',
        'middle_name.regex' => 'Names may only contain letters, spaces, periods, apostrophes and hyphens.',
        'last_name.regex' => 'Names may only contain letters, spaces, periods, apostrophes and hyphens.',
        'contact_no.regex' => 'The contact number may only contain digits, spaces, +, - and brackets.',
        'birthdate.before' => 'The date of birth must be in the past.',
    ];

    /**
     * The full record, as a patient fills it in at sign-up or edits it.
     *
     * @param  array<string, mixed>  $input  the raw request input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $input, ?User $ignore = null): array
    {
        $noMiddleName = filter_var($input['no_middle_name'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'first_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::realName('first name')],
            'middle_name' => $noMiddleName
                ? ['nullable', 'prohibited']
                : ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::notAnInitial()],
            'no_middle_name' => ['sometimes', 'boolean'],
            'last_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::realName('last name')],
            'birthdate' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'home_address' => ['required', 'string', 'min:5', 'max:255'],
            'patient_type' => ['required', Rule::in(['student', 'faculty', 'staff'])],
            // A student picks a course and the department follows from it;
            // faculty and employees pick a department and have no course.
            'course' => [Rule::requiredIf(($input['patient_type'] ?? null) === 'student'), 'nullable', Rule::in(Udd::courseCodes())],
            'department' => [Rule::requiredIf(($input['patient_type'] ?? null) !== 'student'), 'nullable', Rule::in(Udd::departmentCodes())],
            'school_id' => ['required', 'string', self::idNumberFormat($input), Rule::unique('users', 'school_id')->ignore($ignore?->id)],
            'contact_no' => ['nullable', 'string', 'regex:'.self::CONTACT_PATTERN],
        ];
    }

    /**
     * The nurse's walk-in form: only what is needed to queue someone and
     * count them by department. The patient can complete the rest on their
     * phone later; booking asks them to.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    public static function walkInRules(array $input): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::realName('first name')],
            'middle_name' => ['nullable', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::notAnInitial()],
            'last_name' => ['required', 'string', 'max:60', 'regex:'.self::NAME_PATTERN, self::realName('last name')],
            'patient_type' => ['nullable', Rule::in(['student', 'faculty', 'staff'])],
            'course' => ['nullable', Rule::in(Udd::courseCodes())],
            'department' => ['required_without:course', 'nullable', Rule::in(Udd::departmentCodes())],
            'school_id' => ['nullable', 'string', self::idNumberFormat($input), Rule::unique('users', 'school_id')],
            'sex' => ['nullable', Rule::in(['male', 'female'])],
            'birthdate' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            'contact_no' => ['nullable', 'string', 'regex:'.self::CONTACT_PATTERN],
        ];
    }

    /**
     * A student ID typed without its dashes ("231057651") in the UDD format
     * (23-1057-651); anything else is returned trimmed, as typed.
     */
    public static function normalizeSchoolId(?string $schoolId): ?string
    {
        if ($schoolId === null) {
            return null;
        }

        $schoolId = trim($schoolId);

        return preg_match('/^(\d{2})(\d{4})(\d{3})$/', $schoolId, $parts)
            ? "{$parts[1]}-{$parts[2]}-{$parts[3]}"
            : $schoolId;
    }

    /**
     * Validated input as User attributes: names tidied, the department
     * derived from the course, and no course kept for non-students.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated): array
    {
        $attributes = [];

        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = self::tidyName($validated[$field]);
            }
        }

        if (filter_var($validated['no_middle_name'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $attributes['middle_name'] = null;
        }

        foreach (['birthdate', 'sex', 'home_address', 'patient_type', 'school_id', 'contact_no'] as $field) {
            if (array_key_exists($field, $validated)) {
                $value = is_string($validated[$field]) ? trim($validated[$field]) : $validated[$field];
                $attributes[$field] = $value === '' ? null : $value;
            }
        }

        $course = $validated['course'] ?? null;
        $isStudent = ($validated['patient_type'] ?? 'student') === 'student';

        if ($course !== null && $isStudent) {
            $attributes['course'] = $course;
            $attributes['department'] = Udd::departmentOfCourse($course);
        } else {
            $attributes['course'] = null;
            $attributes['department'] = $validated['department'] ?? Udd::departmentOfCourse($course);
        }

        return $attributes;
    }

    /**
     * "D", "D." and "D. R." are initials; "Sta. Maria" and "Dela Cruz" are
     * names. A middle name needs at least one word of two letters or more.
     */
    private static function notAnInitial(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! preg_match('/\pL{2,}/u', $value)) {
                $fail('Type your full middle name, not just an initial.');
            }
        };
    }

    private static function realName(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && ! preg_match('/\pL{2,}/u', $value)) {
                $fail("Type your full {$label}, not just an initial.");
            }
        };
    }

    /**
     * Students carry the UDD format (23-1057-651); faculty and employee IDs
     * vary, so those only need to look like an ID.
     *
     * @param  array<string, mixed>  $input
     */
    private static function idNumberFormat(array $input): Closure
    {
        $isStudent = ($input['patient_type'] ?? 'student') === 'student';

        return function (string $attribute, mixed $value, Closure $fail) use ($isStudent): void {
            if (! is_string($value)) {
                return;
            }

            if ($isStudent && ! preg_match(config('udd.student_id_pattern'), $value)) {
                $fail('The student ID number must look like 23-1057-651.');
            } elseif (! $isStudent && ! preg_match(config('udd.employee_id_pattern'), $value)) {
                $fail('The ID number may only contain letters, numbers and dashes.');
            }
        };
    }

    /**
     * Collapse runs of spaces and fix names typed in all lower- or all
     * upper-case ("juan" / "JUAN" -> "Juan"); anything typed with mixed case
     * ("dela Cruz") is kept exactly as the patient wrote it.
     */
    private static function tidyName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            return null;
        }

        if ($name === mb_strtolower($name) || $name === mb_strtoupper($name)) {
            return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }

        return $name;
    }
}
