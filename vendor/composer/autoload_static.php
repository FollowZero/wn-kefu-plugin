<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit952a7077c4f136f220a83573d2ff96b1
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'Workerman\\' => 10,
        ),
        'P' => 
        array (
            'PHPSocketIO\\' => 12,
        ),
        'C' => 
        array (
            'Channel\\' => 8,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Workerman\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/workerman',
        ),
        'PHPSocketIO\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/phpsocket.io/src',
        ),
        'Channel\\' => 
        array (
            0 => __DIR__ . '/..' . '/workerman/channel/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit952a7077c4f136f220a83573d2ff96b1::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit952a7077c4f136f220a83573d2ff96b1::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit952a7077c4f136f220a83573d2ff96b1::$classMap;

        }, null, ClassLoader::class);
    }
}