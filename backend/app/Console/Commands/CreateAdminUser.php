<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'user:create-admin {--name=Admin} {--phone=+10000000000} {--password=admin123}';
    protected $description = 'Create an admin user with specified credentials';

    public function handle()
    {
        $name = $this->option('name');
        $phone = $this->option('phone');
        $password = $this->option('password');

        // Check if user already exists
        $existingUser = User::where('name', $name)->orWhere('phone', $phone)->first();
        
        if ($existingUser) {
            $this->error("User already exists with name '{$existingUser->name}' and phone '{$existingUser->phone}'");
            
            if ($this->confirm('Do you want to update the password?', false)) {
                $existingUser->password = Hash::make($password);
                $existingUser->save();
                $this->info("Password updated successfully for user '{$existingUser->name}'");
            }
            
            return 0;
        }

        // Create new admin user
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => Hash::make($password),
            'status' => 'offline'
        ]);

        $this->info("Admin user created successfully!");
        $this->info("Name: {$user->name}");
        $this->info("Phone: {$user->phone}");
        $this->info("Password: {$password}");
        $this->warn("Please change the password after first login!");

        return 0;
    }
}
