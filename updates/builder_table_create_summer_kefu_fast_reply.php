<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuFastReply extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_fast_reply', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('admin_id')->nullable()->default(0);
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->integer('status')->nullable()->default(1);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('summer_kefu_fast_reply');
    }
}
