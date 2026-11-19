<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'gather:platform-admin';

    protected $description = 'Create a new platform administrator using interactive prompts';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 12 characters)');
        $v = Validator::make(compact('name', 'email', 'password'), ['name' => 'required|string|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|max:128']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

return self::FAILURE;
        }
        $u = User::create(compact('name', 'email', 'password'));
        $u->forceFill(['is_super_admin' => true])->save();
        $this->info('Platform administrator created. Sign in at /login and open Platform administration.');

        return self::SUCCESS;
    }
}
