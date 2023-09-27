<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuTrajectoryModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_trajectory';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];

    protected $appends = ['createtime'];

    public function getCreatetimeAttribute()
    {
        return $this->created_at->timestamp;
    }
}
