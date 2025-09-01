<?php
namespace PQ\Elementor;
if (!defined('ABSPATH')) exit;

class Widget_Profile extends \Elementor\Widget_Base {
    public function get_name(){ return 'pq_profile'; }
    public function get_title(){ return 'Perfume Quiz – Profile'; }
    public function get_icon(){ return 'eicon-user-circle-o'; }
    public function get_categories(){ return ['general']; }
    protected function register_controls(){}
    protected function render(){
        echo do_shortcode('[pq_profile]');
    }
}
