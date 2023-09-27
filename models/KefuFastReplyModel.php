<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuFastReplyModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;

    use \Winter\Storm\Database\Traits\SoftDelete;

    protected $dates = ['deleted_at'];


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_fast_reply';

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
        'target' => ['Backend\Models\User','key' => 'admin_id','otherKey'=>'id'],
    ];
}
