<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.app)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Singleton (always id = 1), same pattern as PinLoginSetting — see App\Services\TrainingMode. Event details, trainers and what Prepare Training loaded; whether the warehouse is on is its status, not a flag here. */
class TrainingSetting extends Model
{
    protected $table = 'training_settings';

    protected $fillable = ['prepared_at', 'prepared_by', 'prepared_summary', 'event_title', 'event_location',
        'trainer1_name', 'trainer1_signature', 'trainer2_name', 'trainer2_signature'];

    protected $casts = [
        'prepared_at' => 'datetime',
        'prepared_summary' => 'array',
    ];

    /** The two trainers who sign the certificates. */
    public const TRAINER_SLOTS = [1, 2];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /** A trainer's uploaded signature as a data: URI for the certificate PDF, or null if none (or the file is gone). */
    public function signatureDataUri(int $slot): ?string
    {
        $path = $this->{"trainer{$slot}_signature"};
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path));
    }
}
