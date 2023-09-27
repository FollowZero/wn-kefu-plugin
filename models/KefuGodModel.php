<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuGodModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_god';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];
    public $belongsTo = [
        'target' => ['Winter\User\Models\User','key' => 'user_id','otherKey'=>'id'],
    ];

    protected $appends = ['nickname_origin'];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getNicknameOriginAttribute()
    {
        return $this->nickname;
    }

    public function afterCreate()
    {
        $this->nickname = '游客 ' . $this->id;
        $this->save();
    }
}
