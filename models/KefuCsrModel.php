<?php namespace Summer\Kefu\Models;

use Model;
use Config;
/**
 * Model
 */
class KefuCsrModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_csr';

    /**
     * @var array Validation rules
     */
    public $rules = [
        'admin_id' => 'unique:summer_kefu_csr',
    ];

    public $belongsTo = [
        'target' => ['Backend\Models\User','key' => 'admin_id','otherKey'=>'id'],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    protected $appends = ['sum_reception_count','sum_message_count'];

    public function getSumReceptionCountAttribute()
    {
        return KefuReceptionLogModel::where('csr_id',$this->id)->count();
    }
    public function getSumMessageCountAttribute()
    {
        return KefuRecordModel::where('sender_identity',0)->where('csr_id',$this->id)->count();
    }

    public function getStatusTextAttribute()
    {
        return Config::get('summer.kefu::csr_status.'.$this->status);
    }
}
