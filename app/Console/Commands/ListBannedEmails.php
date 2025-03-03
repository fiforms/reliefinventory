<?php 
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListBannedEmails extends Command
{
    protected $signature = 'user:list-banned {--limit=10000}';
    protected $description = 'List all banned email patterns';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $bannedEmails = DB::table('banned_emails')
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get(['pattern', 'created_at'])
        ->map(fn($email) => (array) $email) // Convert objects to arrays
        ->toArray(); // Ensure it's a plain array
        
        if (empty($bannedEmails)) {
            $this->info("No banned email patterns found.");
            return 0;
        }
        
        $this->table(['Pattern', 'Added On'], $bannedEmails);
        
        $this->info("Showing the latest {$limit} banned email patterns.");
        return 0;
    }
}
