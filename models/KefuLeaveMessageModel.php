<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuLeaveMessageModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_leave_message';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public $belongsTo = [
        'god' => ['Summer\Kefu\Models\KefuGodModel','key' => 'god_id','otherKey'=>'id'],
    ];
}
