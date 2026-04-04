<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class FixUserPasswords extends Command
{
    protected $signature = 'users:fix-passwords {--password= : The password to set (will be prompted if not provided)}';
    protected $description = 'Fix user passwords that are not hashed with Bcrypt';

    public function handle()
    {
        $password = $this->option('password');

        if (empty($password)) {
            $password = $this->secret('Enter the password to set for users');
            if (empty($password)) {
                $this->error('Password is required.');
                return 1;
            }
        }

        $user = User::first();

        if ($user) {
            $user->password = Hash::make($password);
            $user->save();
            $this->info("Password for user '{$user->name}' has been updated.");
            $this->info("Please log in with these credentials and change your password if needed.");
        } else {
            $this->error('No users found in the database.');
            return 1;
        }

        return 0;
    }
}
