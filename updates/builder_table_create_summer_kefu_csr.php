<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuCsr extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_csr', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('admin_id')->nullable()->default(0); //绑定管理员
            $table->string('nickname', 50)->nullable();//昵称
            $table->smallInteger('ceiling')->nullable()->unsigned()->default(1); //接待上限
            $table->smallInteger('reception_count')->nullable()->unsigned()->default(0); //当前接待量
            $table->dateTime('last_reception_at')->nullable(); //上次接待时间
            $table->smallInteger('keep_alive')->nullable()->unsigned()->default(0); //是否保持在线
            $table->text('welcome_msg')->nullable(); //欢迎语
            $table->smallInteger('status')->nullable()->unsigned()->default(0); //状态:0=离线,1=繁忙,2=离开,3=在线
        });
    }

    public function down()
    {
        Schema::dropIfExists('summer_kefu_csr');
    }
}
