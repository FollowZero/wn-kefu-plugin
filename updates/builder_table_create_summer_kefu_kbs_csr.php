<?php namespace Summer\Kefu\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class BuilderTableCreateSummerKefuKbsCsr extends Migration
{
    public function up()
    {
        Schema::create('summer_kefu_kbs_csr', function($table)
        {
            $table->engine = 'InnoDB';
            $table->integer('kbs_id')->unsigned();
            $table->integer('csr_id')->unsigned();
            $table->primary(['kbs_id','csr_id']);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('summer_kefu_kbs_csr');
    }
}
