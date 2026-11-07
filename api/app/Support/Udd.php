<?php

namespace App\Support;

/**
 * Lookups over config/udd.php — UDD's schools (departments) and courses.
 */
final class Udd
{
    /**
     * @return array<string, array{name: string, courses: array<string, string>}>
     */
    public static function departments(): array
    {
        return config('udd.departments');
    }

    /** @return array<int, string> */
    public static function departmentCodes(): array
    {
        return array_keys(self::departments());
    }

    /** @return array<int, string> */
    public static function courseCodes(): array
    {
        return array_keys(self::courseIndex());
    }

    /** The department a course belongs to, or null for an unknown course. */
    public static function departmentOfCourse(?string $course): ?string
    {
        return $course === null ? null : (self::courseIndex()[$course] ?? null);
    }

    public static function departmentName(?string $code): ?string
    {
        return $code === null ? null : (self::departments()[$code]['name'] ?? null);
    }

    public static function courseName(?string $code): ?string
    {
        $department = self::departmentOfCourse($code);

        return $department === null ? null : self::departments()[$department]['courses'][$code];
    }

    /**
     * The list in the shape the apps render: departments in config order,
     * each with its courses.
     *
     * @return array<int, array{code: string, name: string, courses: array<int, array{code: string, name: string}>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::departments() as $code => $department) {
            $courses = [];

            foreach ($department['courses'] as $courseCode => $courseName) {
                $courses[] = ['code' => $courseCode, 'name' => $courseName];
            }

            $catalog[] = ['code' => $code, 'name' => $department['name'], 'courses' => $courses];
        }

        return $catalog;
    }

    /**
     * @return array<string, string> course code => department code
     */
    private static function courseIndex(): array
    {
        static $index = null;

        if ($index !== null) {
            return $index;
        }

        $index = [];

        foreach (self::departments() as $code => $department) {
            foreach (array_keys($department['courses']) as $course) {
                $index[$course] = $code;
            }
        }

        return $index;
    }
}
