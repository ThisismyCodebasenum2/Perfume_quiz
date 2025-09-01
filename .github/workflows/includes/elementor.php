<?php
namespace PQ;
if (!defined('ABSPATH')) exit;

class Elementor_Widgets {
    public static function init(){
        add_action('elementor/widgets/register', array(__CLASS__,'register_widgets'));
    }
    public static function register_widgets($widgets_manager){
        if (!class_exists('\Elementor\Widget_Base')) return;
        require_once __DIR__.'/elementor/widget-test.php';
        require_once __DIR__.'/elementor/widget-interpret.php';
        require_once __DIR__.'/elementor/widget-profile.php';
        $widgets_manager->register(new \PQ\Elementor\Widget_Test());
        $widgets_manager->register(new \PQ\Elementor\Widget_Interpret());
        $widgets_manager->register(new \PQ\Elementor\Widget_Profile());
    }
}
