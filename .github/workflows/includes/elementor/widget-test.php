<?php
namespace PQ\Elementor;
if (!defined('ABSPATH')) exit;

class Widget_Test extends \Elementor\Widget_Base {
    public function get_name(){ return 'pq_test'; }
    public function get_title(){ return 'Perfume Quiz – Test'; }
    public function get_icon(){ return 'eicon-form-horizontal'; }
    public function get_categories(){ return ['general']; }

    protected function register_controls(){
        $this->start_controls_section('content', ['label'=>'Settings']);
        $this->add_control('test_id', [
            'label'=>'Test ID', 'type'=>\Elementor\Controls_Manager::NUMBER, 'default'=>0, 'min'=>0
        ]);
        $this->end_controls_section();
    }
    protected function render(){
        $id = intval($this->get_settings_for_display('test_id'));
        echo do_shortcode('[pq_test id="'.$id.'"]');
    }
}
