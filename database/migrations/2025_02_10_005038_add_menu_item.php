<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\MenuItem;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        MenuItem::create([
            'page_id' => 3,
            'link_text' => 'Edit Location Names',
            'link_url' => '/setup/locations',
            'graphic_url' => '/img/edit-statuses-icon.webp',
            'order' => 33
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        MenuItem::where([
            'page_id' => 3,
            'link_url' => '/setup/locations',
        ])->delete();
    }
};
