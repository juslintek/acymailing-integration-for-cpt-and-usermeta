<?php
/*
Plugin Name: AcyMailing integration for CPT and Usermeta
Description: Adds editor options for Custom Post Types and Usermeta in AcyMailing
Author: Linas Jusys
Author URI: https://www.github.com/juslintek
License: GPLv3
Version: 1.0
*/

use AcyMailing\Classes\PluginClass;

class AcymIntegrationForCptAndUserMetaRegistration
{

    public static function register(): void
    {
        register_deactivation_hook(__FILE__, [__CLASS__, 'disable']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
        add_action('acym_load_installed_integrations', [__CLASS__, 'load'], 10, 2);
    }

    public static function disable(): void
    {
        $helperFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'acymailing' . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';
        if (!is_plugin_active('acymailing/index.php') || !file_exists(static::getVendorFolder()) || !include static::getHelperFile()) {
            return;
        }

        $pluginClass = new PluginClass();
        $pluginClass->disable('woocommerce');
    }

    private static function getVendorFolder(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'acymailing' . DIRECTORY_SEPARATOR . 'vendor';
    }

    private static function getHelperFile(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'acymailing' . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';
    }

    public static function uninstall(): void
    {
        $helperFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'acymailing' . DIRECTORY_SEPARATOR . 'back' . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'helper.php';
        if (!is_plugin_active('acymailing/index.php') || !file_exists(static::getVendorFolder()) || !include static::getHelperFile()) {
            return;
        }

        $pluginClass = new PluginClass();
        $pluginClass->deleteByFolderName('cpt-and-usermeta');
    }

    public static function load(&$integrations, $acyVersion): void
    {
        if (version_compare($acyVersion, '7.5.11', '>=')) {
            $integrations[] = [
                'path' => __DIR__,
                'className' => 'plgAcymCptandusermeta',
            ];
        }
    }
}

AcymIntegrationForCptAndUserMetaRegistration::register();
