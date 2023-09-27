<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuRecord extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_record', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('session_id')->unsigned()->default(0); // 会话id
            // 原来是用身份+ID 去区分。试试用顾客ID和客服代表ID
            $table->smallInteger('sender_identity')->unsigned()->default(0); // 发送人身份:0=客服,1=用户
            $table->integer('sender_id')->unsigned()->default(0); // 发送人ID
            $table->integer('god_id')->unsigned()->default(0); // 顾客ID
            $table->integer('csr_id')->unsigned()->default(0); // 客服代表ID
            $table->integer('message_type')->nullable(); // 消息类型:0=富文本,1=图片,2=文件,3=系统消息,4=商品卡片,5=订单卡片
            $table->text('message')->nullable(); // 消息
            $table->smallInteger('status')->unsigned()->default(0); //状态:0=未读,1=已读
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_record');
    }
}
