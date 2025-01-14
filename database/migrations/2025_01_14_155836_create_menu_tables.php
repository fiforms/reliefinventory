<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMenuTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Create the pages table
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('menu_title'); // Title used in the menu
            $table->string('header_text'); // Header text for the page
            $table->text('content')->nullable(); // Optional content for the page
            $table->timestamps();
        });

        // Create the menu_items table
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->nullable()->constrained('pages')->onDelete('cascade'); // Links to the associated page
            $table->string('link_text'); // Text displayed for the menu link
            $table->string('link_url')->nullable(); // URL for the menu link
            $table->foreignId('submenu_page_id')->nullable()->constrained('pages')->onDelete('cascade'); // Link to another page for sub-menus
            $table->string('graphic_url')->nullable(); // Optional graphic associated with the menu item
            $table->integer('order')->default(0); // Display order of the menu item
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('pages');
    }
}

