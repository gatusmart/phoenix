<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdminMenuTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('admin_menu', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('unique_id');
            $table->string('icon')->nullable();
            $table->string('route')->nullable();
            $table->string('component')->nullable();
            $table->string('params')->nullable();
            $table->integer('parent')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('visible')->default(true);
            $table->string('permission')->nullable();
            $table->timestamps();
        });
    }
}
