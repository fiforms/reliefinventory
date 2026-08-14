<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 1,
            'link_text' => 'Receiving',
            'link_url' => '/receiving',
            'graphic_url' => '/img/donation-entry-icon.webp',
            'order' => 25,
        ]);
    }

    public function down(): void
    {
        MenuItem::where([
            'page_id' => 1,
            'link_url' => '/receiving',
        ])->delete();
    }
};
