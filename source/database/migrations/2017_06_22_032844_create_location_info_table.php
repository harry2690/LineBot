<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateLocationInfoTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('location_info', function (Blueprint $table) {
            $table->increments('id');
            $table->longtext('address')->nullable();
            $table->longtext('name')->nullable();
            $table->longtext('lat')->nullable();
            $table->longtext('lng')->nullable();
            $table->longtext('telphone')->nullable();
            $table->longtext('start_time')->nullable();
            $table->longtext('end_time')->nullable();
            $table->string('status',2)->default('P');
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
        Schema::dropIfExists('location_info');
    }
}
