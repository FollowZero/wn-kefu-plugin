<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuTrajectory extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_trajectory', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('god_id')->unsigned()->default(0); //顾客id
            $table->integer('csr_id')->unsigned()->default(0); //客服id
            $table->smallInteger('log_type')->unsigned()->default(0); //轨迹类型:0=访问,1=被邀请,2=开始对话,3=拒绝会话,4=客服添加,5=关闭页面,6=留言,7=其他
            $table->text('note')->nullable(); //轨迹详情
            $table->text('url')->nullable(); //轨迹额外数据
            $table->text('referrer')->nullable(); //来路
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_trajectory');
    }
}
