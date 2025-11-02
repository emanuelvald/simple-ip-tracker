<?php
/**
 * Plugin Name: IP Visitor Tracker Simple
 * Description: Rastrea IPs de visitantes y detecta fraude en campañas PPC
 * Version: 3.0.0
 * Author: Diseño Web Córdoba
 */

if (!defined('ABSPATH')) exit;

class Simple_IP_Tracker {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ip_visitor_tracker';
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        add_action('wp_footer', array($this, 'track_visit'));
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        add_action('wp_ajax_ipvt_get_visits', array($this, 'ajax_get_visits'));
        add_action('wp_ajax_ipvt_export_csv', array($this, 'ajax_export_csv'));
    }
    
    public function activate() {
        global $wpdb;
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            visit_date datetime NOT NULL,
            page_url text NOT NULL,
            referrer text,
            user_agent text,
            utm_source varchar(255),
            utm_medium varchar(255),
            utm_campaign varchar(255),
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY visit_date (visit_date)
        ) DEFAULT CHARSET=utf8mb4;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function track_visit() {
        // Solo rastrear si NO es admin de WordPress
        if (current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        
        $ip = $this->get_ip();
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        $referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : '';
        $utm_medium = isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : '';
        $utm_campaign = isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '';
        
        $wpdb->insert(
            $this->table_name,
            array(
                'ip_address' => $ip,
                'visit_date' => current_time('mysql'),
                'page_url' => $current_url,
                'referrer' => $referrer,
                'user_agent' => $user_agent,
                'utm_source' => $utm_source,
                'utm_medium' => $utm_medium,
                'utm_campaign' => $utm_campaign
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    private function get_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    public function add_menu() {
        add_menu_page(
            'IP Tracker',
            'IP Tracker',
            'manage_options',
            'simple-ip-tracker',
            array($this, 'admin_page'),
            'dashicons-visibility',
            30
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_simple-ip-tracker') return;
        
        wp_enqueue_style('ipvt-admin', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '3.0');
        wp_enqueue_script('ipvt-admin', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '3.0', true);
        
        wp_localize_script('ipvt-admin', 'ipvtData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ipvt_nonce')
        ));
    }
    
    public function admin_page() {
        global $wpdb;
        
        $total_visits = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM {$this->table_name}");
        $today_visits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(visit_date) = %s",
            current_time('Y-m-d')
        ));
        
        $suspicious_ips = $wpdb->get_results("
            SELECT ip_address, COUNT(*) as visit_count
            FROM {$this->table_name}
            WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY ip_address
            HAVING visit_count > 5
            ORDER BY visit_count DESC
            LIMIT 10
        ");
        
        include plugin_dir_path(__FILE__) . 'template-admin.php';
    }
    
    public function ajax_get_visits() {
        check_ajax_referer('ipvt_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        global $wpdb;
        
        $search_ip = isset($_POST['search_ip']) ? sanitize_text_field($_POST['search_ip']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        $where = array('1=1');
        $values = array();
        
        if ($search_ip) {
            $where[] = "ip_address LIKE %s";
            $values[] = '%' . $wpdb->esc_like($search_ip) . '%';
        }
        
        if ($date_from) {
            $where[] = "DATE(visit_date) >= %s";
            $values[] = $date_from;
        }
        
        if ($date_to) {
            $where[] = "DATE(visit_date) <= %s";
            $values[] = $date_to;
        }
        
        $where_sql = implode(' AND ', $where);
        
        if (count($values) > 0) {
            $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}", $values));
            
            $values[] = $per_page;
            $values[] = $offset;
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY visit_date DESC LIMIT %d OFFSET %d",
                $values
            ));
        } else {
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY visit_date DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        }
        
        wp_send_json_success(array(
            'visits' => $results,
            'total' => $total,
            'page' => $page,
            'total_pages' => ceil($total / $per_page)
        ));
    }
    
    public function ajax_export_csv() {
        check_ajax_referer('ipvt_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }
        
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY visit_date DESC", ARRAY_A);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ip-tracker-' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, array('IP', 'Fecha', 'Página', 'Referrer', 'UTM Source', 'UTM Medium', 'UTM Campaign'));
        
        foreach ($results as $row) {
            fputcsv($output, array(
                $row['ip_address'],
                $row['visit_date'],
                $row['page_url'],
                $row['referrer'],
                $row['utm_source'],
                $row['utm_medium'],
                $row['utm_campaign']
            ));
        }
        
        fclose($output);
        exit;
    }
}

new Simple_IP_Tracker();
