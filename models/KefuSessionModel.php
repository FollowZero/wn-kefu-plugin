<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuSessionModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_session';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];

    protected $appends = ['csrname','godname'];

    public $belongsTo = [
        'god' => ['Summer\Kefu\Models\KefuGodModel','key' => 'god_id','otherKey'=>'id'],
        'csr' => ['Summer\Kefu\Models\KefuCsrModel','key' => 'csr_id','otherKey'=>'id'],
    ];
    public $hasMany = [
        'records' => ['Summer\Kefu\Models\KefuRecordModel','key'=>'session_id','otherKey'=>'id'],
        'god_record_count' => ['Summer\Kefu\Models\KefuRecordModel','key'=>'session_id','otherKey'=>'id','conditions'=>'sender_identity = 1','count'=>true],
        'csr_record_count' => ['Summer\Kefu\Models\KefuRecordModel','key'=>'session_id','otherKey'=>'id','conditions'=>'sender_identity = 0','count'=>true],
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getCsrnameAttribute()
    {
        return $this->csr->nickname??'';
    }
    public function getGodnameAttribute()
    {
        return $this->god->nickname;
    }

}
