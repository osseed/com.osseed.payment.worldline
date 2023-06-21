<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit28433e423705be752f516ca2a2f42228
{
    public static $prefixLengthsPsr4 = array (
        'R' => 
        array (
            'Root\\ComOsseedPaymentWorldline\\' => 31,
        ),
        'O' => 
        array (
            'OnlinePayments\\Sdk\\' => 19,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Root\\ComOsseedPaymentWorldline\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
        'OnlinePayments\\Sdk\\' => 
        array (
            0 => __DIR__ . '/..' . '/wl-online-payments-direct/sdk-php/src/OnlinePayments/Sdk',
            1 => __DIR__ . '/..' . '/wl-online-payments-direct/sdk-php/lib/OnlinePayments/Sdk',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit28433e423705be752f516ca2a2f42228::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit28433e423705be752f516ca2a2f42228::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit28433e423705be752f516ca2a2f42228::$classMap;

        }, null, ClassLoader::class);
    }
}
