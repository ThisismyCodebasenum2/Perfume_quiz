
<?php
namespace PQ\Elementor;
if (!defined('ABSPATH')) exit;

class Widget_Interpret extends \Elementor\Widget_Base {
    public function get_name(){ return 'pq_interpret'; }
    public function get_title(){ return 'Perfume Quiz – Interpret'; }
    public function get_icon(){ return 'eicon-text'; }
    public function get_categories(){ return ['general']; }

    protected function register_controls(){
        $this->start_controls_section('content', ['label'=>'Settings']);
        $this->add_control('test_id', ['label'=>'Test ID (اختیاری)','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'min'=>0]);
        $this->add_control('attempt', ['label'=>'Attempt ID (اختیاری)','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'min'=>0]);
        $this->end_controls_section();
    }
    protected function render(){
        $s = $this->get_settings_for_display();
        $atts = [];
        if (!empty($s['test_id']))  $atts[] = 'id="'.intval($s['test_id']).'"';
        if (!empty($s['attempt']))  $atts[] = 'attempt="'.intval($s['attempt']).'"';
        echo do_shortcode('[pq_interpret '.implode(' ', $atts).']');
    }
}
