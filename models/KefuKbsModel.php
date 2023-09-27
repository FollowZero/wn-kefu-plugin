<?php namespace Summer\Kefu\Models;

use Model;
use Config;

/**
 * Model
 */
class KefuKbsModel extends Model
{
    use \Winter\Storm\Database\Traits\Validation;


    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_kbs';

    /**
     * @var array Validation rules
     */
    public $rules = [
    ];

    public $belongsToMany = [
        'csrs' => [
            'Summer\Kefu\Models\KefuCsrModel',
            'table'    => 'summer_kefu_kbs_csr',
            'key'      => 'kbs_id',
            'otherKey' => 'csr_id'
        ]
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

    public function getStatusOptions(){
        return Config::get('summer.kefu::kbs_status');
    }
}
