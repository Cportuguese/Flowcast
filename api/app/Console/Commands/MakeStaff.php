<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The only supported way to create admin/personnel accounts. `role` is not
 * mass-assignable on User (so no request can ever set it), which means a
 * plain `User::create([... 'role' => 'admin'])` in tinker silently produces
 * a patient. This command sets the role explicitly.
 */
class MakeStaff extends Command
{
    protected $signature = 'make:staff
        {email : Login email for the new account}
        {--name= : Display name (defaults to the part before the @)}
        {--role=personnel : admin or personnel}
        {--profession= : nurse, dentist or physician — makes the account bookable}
        {--password= : Password (a random one is generated and printed if omitted)}';

    protected $description = 'Create an admin or personnel account';

    public function handle(): int
    {
        $input = [
            'email' => $this->argument('email'),
            'name' => $this->option('name') ?: Str::before($this->argument('email'), '@'),
            'role' => $this->option('role'),
            'profession' => $this->option('profession'),
            'password' => $this->option('password') ?: Str::password(16),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'personnel'])],
            'profession' => ['nullable', Rule::in(array_keys(User::PROFESSION_TITLES))],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
        $user->role = $input['role'];
        // Like role, profession is never mass-assignable: it decides who can
        // be booked and whose name signs a certificate.
        $user->profession = $input['profession'];
        $user->save();

        // No request user on the CLI, so the actor is null; the row itself
        // records who was created and with which role.
        app(AuditLogger::class)->log(null, 'staff.created', $user, [
            'email' => $user->email,
            'role' => $user->role,
            'profession' => $user->profession,
        ]);

        $this->info("Created {$user->role} account for {$user->email} (id {$user->id}).");

        if (! $this->option('password')) {
            $this->warn("Generated password: {$input['password']}");
            $this->line('Share it out-of-band and have them change it.');
        }

        return self::SUCCESS;
    }
}
