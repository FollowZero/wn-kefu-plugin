<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuLeaveMessage extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_leave_message', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('god_id')->unsigned()->default(0);
            $table->string('name');
            $table->string('contact');
            $table->text('message');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('summer_kefu_leave_message');
    }
}
