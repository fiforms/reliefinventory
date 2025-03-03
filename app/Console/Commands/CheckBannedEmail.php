<?php 
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BannedEmail;

class CheckBannedEmail extends Command
{
    protected $signature = 'user:is-banned {email}';
    protected $description = 'Check if an email address is banned';

    public function handle()
    {
        $email = trim($this->argument('email'));

        if (empty($email)) {
            $this->error("Please provide a valid email address.");
            return 1;
        }

        if (BannedEmail::isBanned($email)) {
            $pattern = BannedEmail::bannedBy($email);
            $this->info("Yes, this email is banned by pattern: {$pattern}");
        } else {
            $this->info("No, this email is not banned.");
        }

        return 0;
    }
}
