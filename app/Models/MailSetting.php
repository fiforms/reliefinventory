<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1), same shape as FeedbackAggregationSetting —
 * outgoing mail settings edited on /setup/email. Every column is nullable and
 * null means "use the .env value"; App\Services\MailSettings does the merging
 * and pushes the result into config before a mailer is built. The two
 * secrets use Eloquent's `encrypted` cast (registered in
 * config/backup.php's encrypted_columns, so a restore blanks them).
 */
class MailSetting extends Model
{
    protected $table = 'mail_settings';

    protected $fillable = [
        'mailer',
        'from_address',
        'from_name',
        'resend_key',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'feedback_notify_emails',
    ];

    protected $casts = [
        'smtp_port' => 'integer',
        'resend_key' => 'encrypted',
        'smtp_password' => 'encrypted',
    ];

    public static function current(): self
    {
        return self::findOrFail(1);
    }
}
