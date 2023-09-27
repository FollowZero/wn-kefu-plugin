<?php namespace Summer\Kefu\Models;

use Model;

/**
 * Model
 */
class Settings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'kefu_settings';
    public $settingsFields = 'fields.yaml';

    /**
     * @var string The database table used by the model.
     */
    public $table = 'summer_kefu_csr';

    public $attachMany = ['sliders' => 'System\Models\File'];
    public $attachOne = [
        'invite_box_img' => 'System\Models\File',
        'ringing' => 'System\Models\File',
    ];

    /**
     * @var array Attribute names to encode and decode using JSON.
     */
    public $jsonable = [];

}
