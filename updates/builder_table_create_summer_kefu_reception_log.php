<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuReceptionLog extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_reception_log', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('god_id');
            $table->integer('csr_id');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('summer_kefu_reception_log');
    }
}
