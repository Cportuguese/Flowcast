<?php

/*
|--------------------------------------------------------------------------
| Universidad de Dagupan — schools, courses and ID numbers
|--------------------------------------------------------------------------
|
| The single list every part of Flowcast reads: registration, the walk-in
| form, visit search, analytics and the certificate. Schools and courses
| follow udd.edu.ph/programs (October 2026).
|
| Only the SITE, STE and SIHM short codes are confirmed from UDD's own
| pages; the rest are best guesses. The codes are stored on each patient,
| so correct them here BEFORE real patients register.
|
*/

return [

    // Student ID as printed on UDD IDs, e.g. 23-1057-651.
    'student_id_pattern' => '/^\d{2}-\d{4}-\d{3}$/',

    // Faculty and employee ID numbers follow no single format.
    'employee_id_pattern' => '/^[A-Za-z0-9][A-Za-z0-9-]{2,19}$/',

    /*
     * Department code => name and its courses (course code => name).
     * Course codes are unique across departments, so a student's course
     * alone tells which department they belong to.
     */
    'departments' => [
        'SITE' => [
            'name' => 'School of Information Technology Education',
            'courses' => [
                'BSCS' => 'BS Computer Science',
                'BSIT' => 'BS Information Technology',
            ],
        ],
        'SOE' => [
            'name' => 'School of Engineering',
            'courses' => [
                'BSCE' => 'BS Civil Engineering',
                'BSCpE' => 'BS Computer Engineering',
                'BSEE' => 'BS Electrical Engineering',
                'BSECE' => 'BS Electronics Engineering',
            ],
        ],
        'STE' => [
            'name' => 'School of Teacher Education',
            'courses' => [
                'BECEd' => 'Bachelor of Early Childhood Education',
                'BEEd' => 'Bachelor of Elementary Education',
                'BSEd' => 'Bachelor of Secondary Education',
                'BSNEd' => 'Bachelor of Special Needs Education',
            ],
        ],
        'SBA' => [
            'name' => 'School of Business and Accountancy',
            'courses' => [
                'BSA' => 'BS Accountancy',
                'BSBA' => 'BS Business Administration',
            ],
        ],
        'SIHM' => [
            'name' => 'School of International Hospitality Management',
            'courses' => [
                'BSTM' => 'BS Tourism Management',
                'BSHM' => 'BS Hospitality Management',
            ],
        ],
        'SOH' => [
            'name' => 'School of Humanities',
            'courses' => [
                'ABComm' => 'BA Communication',
                'BSPsych' => 'BS Psychology',
            ],
        ],
        'SOHS' => [
            'name' => 'School of Health and Sciences',
            'courses' => [
                'BSN' => 'BS Nursing',
            ],
        ],
        'SOC' => [
            'name' => 'School of Criminology',
            'courses' => [
                'BSCrim' => 'BS Criminology',
            ],
        ],
        'SBE' => [
            'name' => 'School of Basic Education',
            'courses' => [
                'GS' => 'Grade School',
                'JHS' => 'Junior High School',
                'SHS' => 'Senior High School',
            ],
        ],
        'SPS' => [
            'name' => 'School of Professional Studies',
            'courses' => [
                'MIT' => 'Master in Information Technology',
                'MAEd' => 'MA in Education',
                'MBA' => 'Master of Business Administration',
                'DBA' => 'Doctor of Business Administration',
                'PhD' => 'PhD in Educational Leadership and Management',
            ],
        ],
        // Non-teaching employees (registrar, finance, guards...) belong to
        // an office rather than a school.
        'ADMIN' => [
            'name' => 'Administration and Offices',
            'courses' => [],
        ],
    ],

];
