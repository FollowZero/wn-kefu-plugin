<?php
Route::group(
    [
        'middleware' => 'api',
        'prefix' => 'api/kefu',
        'namespace' => 'Summer\Kefu\Http\Controllers',
    ],
    function () {
        //上传
        Route::post(
            'file/upfile',
            'FileController@upfile'
        )->name('api.kefu.file.upfile');
    }
);



