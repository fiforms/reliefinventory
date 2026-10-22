<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton (always id=1) — which label media pallet labels use. See the 2026_10_22 migration.
 * The media key is one of config('printing.pallet_label_media'); the label views lay themselves out
 * from that media's width/height, and the Print menus only offer printers loaded with it.
 */
class LabelSetting extends Model
{
    protected $table = 'label_settings';

    protected $fillable = ['pallet_label_media'];

    public static function current(): self
    {
        return self::findOrFail(1);
    }

    /** The media key pallet labels use now; falls back to the default if the saved one was removed from config. */
    public static function palletMedia(): string
    {
        $options = config('printing.pallet_label_media');
        $saved = optional(self::query()->find(1))->pallet_label_media;

        return in_array($saved, $options, true) ? $saved : $options[0];
    }

    /** True for a label too narrow for the full-size layout (the compact view is used). */
    public static function palletLabelIsCompact(): bool
    {
        return config('printing.media.'.self::palletMedia().'.width') < 3;
    }
}
