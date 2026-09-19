<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded account password
    |--------------------------------------------------------------------------
    |
    | Password given to the structural accounts created by UserSeeder. Override
    | it per environment via SEED_USER_PASSWORD; the default is only ever
    | appropriate for local development.
    |
    */

    'seed_password' => env('SEED_USER_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | Password reset OTP
    |--------------------------------------------------------------------------
    |
    | Settings for the emailed one-time code. The code is stored hashed, expires
    | after the configured window, and is discarded once max_attempts wrong
    | guesses have been made against it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Production statistics
    |--------------------------------------------------------------------------
    |
    | The factory's own worksheet reports three totals: everything, everything
    | except packaging, and everything except packaging and sewing. Department
    | names are free text and spelling varies between the worksheet and the
    | data-entry screens (خياطة / خياطه), so every accepted variant is listed.
    |
    */

    'statistics' => [
        'packaging_departments' => ['امبلاج', 'أمبلاج', 'إمبلاج', 'التغليف'],
        'sewing_departments' => ['خياطة', 'خياطه', 'الخياطة'],

        // Basic stands outside the production line. Its pieces are reported on
        // their own and are kept out of the production_only figures, so a Basic
        // batch never inflates a department total it has nothing to do with.
        'basic_departments' => ['البيزك', 'بيزك', 'الببزك', 'Basic', 'basic'],

        // Since the monthly workbook, Basic is a product *line*: its rows carry
        // a real production stage in the department column (مسلم، أمبلاج …)
        // and are recognised by the line they belong to instead. The
        // department list above is kept so rows filed under البيزك directly
        // are still reported as Basic.
        'basic_product_lines' => ['البيزك'],
    ],

    'password_reset' => [
        'otp_length' => 6,
        'expires_in_minutes' => (int) env('PASSWORD_RESET_OTP_TTL', 10),
        'max_attempts' => (int) env('PASSWORD_RESET_OTP_MAX_ATTEMPTS', 5),
    ],

];
