<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuGod extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_god', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable()->default(0);//绑定用户ID
            $table->string('nickname', 50)->nullable();//昵称
            $table->string('referrer', 255)->nullable();//用户来路
            $table->string('contact', 100)->nullable();//联系方式
            $table->string('note', 255)->nullable();//客服备注
            $table->string('token', 100)->nullable();//Session标识
            $table->string('wechat_openid', 50)->nullable();//微信openid
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_god');
    }
}
