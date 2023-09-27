<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuSession extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_session', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('god_id')->unsigned()->default(0);
            $table->integer('csr_id')->unsigned()->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_session');
    }
}
