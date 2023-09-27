<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuKbs extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_kbs', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('questions')->nullable(); //知识点
            $table->smallInteger('match'); // 自动回复匹配度
            $table->text('answer')->nullable(); // 问题答案
            $table->smallInteger('status'); // 状态:0=关闭,1=启用,2=启用为万能知识
            $table->integer('weigh'); // 权重
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_kbs');
    }
}
