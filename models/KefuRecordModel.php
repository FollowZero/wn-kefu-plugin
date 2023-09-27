<?php namespace Summer\Kefu\Models;

use Carbon\Carbon;
use Config;
use Model;

/**
 * Model
 */
class KefuRecordModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_record';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];
    public $attachOne = [
        'msgfile' => 'System\Models\File',
    ];
    public $belongsTo = [
        'god' => ['Summer\Kefu\Models\KefuGodModel','key' => 'god_id','otherKey'=>'id'],
        'csr' => ['Summer\Kefu\Models\KefuCsrModel','key' => 'csr_id','otherKey'=>'id'],
    ];
    protected $appends = ['createtime'];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getCreatetimeAttribute()
    {
        return $this->created_at?$this->created_at->timestamp:'';
    }

    public function getMessageTypeOptions(){
        return Config::get('summer.kefu::message_type');
    }

}
