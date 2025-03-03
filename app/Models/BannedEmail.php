<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BannedEmail extends Model
{
    use HasFactory;

    protected $table = 'banned_emails'; // Explicitly set table name (optional)

    protected $fillable = ['pattern']; // Fields that are mass assignable

    /**
     * Checks if an email matches a banned pattern.
     *
     * @param string $email
     * @return bool
     */
    public static function isBanned($email)
    {
        return self::pluck('pattern')->contains(function ($pattern) use ($email) {
            return self::matchesPattern($email, $pattern);
        });
    }

    /**
     * Find the pattern that causes an email to be banned
     *
     * @param string $email
     * @return string
     */
    public static function bannedBy($email)
    {
        return self::pluck('pattern')->first(function ($pattern) use ($email) {
            return self::matchesPattern($email, $pattern);
        });
    }
    
    
    /**
     * Helper function to check if an email matches a pattern.
     *
     * @param string $email
     * @param string $pattern
     * @return bool
     */
    private static function matchesPattern($email, $pattern)
    {
        if ($pattern === $email) {
            return true; // Exact match
        }

        if (str_starts_with($pattern, '*@')) {
            $domain = substr($pattern, 2);
            return str_ends_with($email, "@{$domain}");
        }

        if (str_starts_with($pattern, '*')) {
            return str_ends_with($email, substr($pattern, 1));
        }

        return false;
    }
}
