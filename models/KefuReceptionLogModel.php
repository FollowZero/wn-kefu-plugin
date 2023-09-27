<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class KefuReceptionLogModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    

    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_reception_log';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];
}
