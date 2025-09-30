<?php
/**
 * Plugin Name: WooCommerce Analytics Tracker
 * Plugin URI: https://github.com/seu-usuario/woocommerce-analytics-tracker
 * Description: Sistema completo de analytics para WooCommerce com rastreamento automático de eventos, remarketing e insights avançados.
 * Version: 1.0.0
 * Author: Seu Nome
 * Author URI: https://seusite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-analytics-tracker
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Evitar acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes do plugin
define('WC_ANALYTICS_TRACKER_VERSION', '1.0.0');
define('WC_ANALYTICS_TRACKER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_ANALYTICS_TRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Classe principal do plugin WooCommerce Analytics Tracker
 */
class WooCommerceAnalyticsTracker {
    
    /**
     * Instância única da classe (Singleton)
     */
    private static $instance = null;
    
    /**
     * Configurações do plugin
     */
    private $options;
    
    /**
     * Construtor privado para Singleton
     */
    private function __construct() {
        $this->init_hooks();
        $this->options = get_option('wc_analytics_tracker_settings', []);
    }
    
    /**
     * Obter instância única da classe
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializar hooks do WordPress/WooCommerce
     */
    private function init_hooks() {
        // Hook de ativação
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Hooks de inicialização
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'inject_tracking_script']);
        
        // Hooks do WooCommerce
        add_action('woocommerce_single_product_summary', [$this, 'track_product_view'], 25);
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
        add_action('woocommerce_cart_item_removed', [$this, 'track_remove_from_cart'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'track_checkout_start'], 10, 3);
        add_action('woocommerce_thankyou', [$this, 'track_purchase'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'track_order_status_change'], 10, 4);
        
        // Hooks para busca
        add_action('pre_get_posts', [$this, 'track_search_query']);
        
        // Menu de administração
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        
        // AJAX endpoints
        add_action('wp_ajax_wc_analytics_test_connection', [$this, 'test_supabase_connection']);
        add_action('wp_ajax_wc_analytics_sync_products', [$this, 'sync_products_to_supabase']);
        
        // Webhooks personalizados
        add_action('rest_api_init', [$this, 'register_webhooks']);
    }
    
    /**
     * Inicialização do plugin
     */
    public function init() {
        // Carregar traduções
        load_plugin_textdomain('wc-analytics-tracker', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Verificar se WooCommerce está ativo
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
    }
    
    /**
     * Ativação do plugin
     */
    public function activate() {
        // Criar tabelas ou configurações necessárias
        $this->create_database_tables();
        
        // Configurações padrão
        $default_options = [
            'supabase_url' => '',
            'supabase_anon_key' => '',
            'enable_tracking' => true,
            'enable_debug' => false,
            'track_anonymous_users' => true,
            'track_scroll_depth' => true,
            'track_time_on_page' => true,
            'track_form_interactions' => true
        ];
        
        add_option('wc_analytics_tracker_settings', $default_options);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Desativação do plugin
     */
    public function deactivate() {
        // Cleanup se necessário
        flush_rewrite_rules();
    }
    
    /**
     * Criar tabelas do banco de dados se necessário
     */
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabela para log de eventos (backup local)
        $table_name = $wpdb->prefix . 'wc_analytics_events';
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            user_id bigint(20) UNSIGNED,
            session_id varchar(100),
            product_id bigint(20) UNSIGNED,
            order_id bigint(20) UNSIGNED,
            event_data longtext,
            synced_to_supabase tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY synced_to_supabase (synced_to_supabase)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Enfileirar scripts e estilos
     */
    public function enqueue_scripts() {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        // Enfileirar o tracker JavaScript
        wp_enqueue_script(
            'wc-analytics-tracker',
            WC_ANALYTICS_TRACKER_PLUGIN_URL . 'assets/js/tracker.js',
            ['jquery'],
            WC_ANALYTICS_TRACKER_VERSION,
            true
        );
        
        // Localizar script com configurações
        wp_localize_script('wc-analytics-tracker', 'wcAnalyticsTracker', [
            'supabaseUrl' => $this->get_option('supabase_url'),
            'supabaseKey' => $this->get_option('supabase_anon_key'),
            'debug' => $this->get_option('enable_debug', false),
            'trackAnonymous' => $this->get_option('track_anonymous_users', true),
            'trackScrollDepth' => $this->get_option('track_scroll_depth', true),
            'trackTimeOnPage' => $this->get_option('track_time_on_page', true),
            'trackForms' => $this->get_option('track_form_interactions', true),
            'userId' => get_current_user_id(),
            'sessionId' => $this->get_session_id(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_analytics_nonce')
        ]);
    }
    
    /**
     * Injetar script de tracking no footer
     */
    public function inject_tracking_script() {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Inicializar tracker
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.init(wcAnalyticsTracker.supabaseUrl, {
                    debug: wcAnalyticsTracker.debug,
                    supabaseKey: wcAnalyticsTracker.supabaseKey,
                    userId: wcAnalyticsTracker.userId,
                    sessionId: wcAnalyticsTracker.sessionId
                });
                
                // Tracking automático de página
                EcommerceTracker.trackPageView();
                
                // Tracking de scroll depth se habilitado
                if (wcAnalyticsTracker.trackScrollDepth) {
                    EcommerceTracker.trackScrollDepth();
                }
                
                // Tracking de tempo na página se habilitado
                if (wcAnalyticsTracker.trackTimeOnPage) {
                    EcommerceTracker.trackTimeOnPage();
                }
                
                // Tracking de formulários se habilitado
                if (wcAnalyticsTracker.trackForms) {
                    EcommerceTracker.trackFormInteractions();
                }
            }
        });
        </script>
        <?php
    }
    
    /**
     * Rastrear visualização de produto
     */
    public function track_product_view() {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        global $product;
        
        if (!$product || !is_object($product)) {
            return;
        }
        
        $product_data = [
            'product_id' => $product->get_id(),
            'name' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'categories' => $this->get_product_categories($product),
            'stock_status' => $product->get_stock_status(),
            'sku' => $product->get_sku()
        ];
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.viewProduct(
                    '<?php echo esc_js($product->get_id()); ?>',
                    <?php echo json_encode($product_data); ?>
                );
            }
        });
        </script>
        <?php
        
        // Log local
        $this->log_event('product_view', [
            'product_id' => $product->get_id(),
            'product_data' => $product_data
        ]);
    }
    
    /**
     * Rastrear adição ao carrinho
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $event_data = [
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'quantity' => $quantity,
            'price' => (float) $product->get_price(),
            'total' => (float) $product->get_price() * $quantity,
            'categories' => $this->get_product_categories($product),
            'cart_item_key' => $cart_item_key
        ];
        
        // Usar AJAX para enviar evento
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.addToCart(
                    '<?php echo esc_js($product_id); ?>',
                    <?php echo json_encode($event_data); ?>
                );
            }
        });
        </script>
        <?php
        
        // Log local
        $this->log_event('add_to_cart', $event_data);
    }
    
    /**
     * Rastrear remoção do carrinho
     */
    public function track_remove_from_cart($cart_item_key, $cart) {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        $cart_item = $cart->removed_cart_contents[$cart_item_key];
        
        if (!$cart_item) {
            return;
        }
        
        $event_data = [
            'product_id' => $cart_item['product_id'],
            'variation_id' => $cart_item['variation_id'],
            'quantity' => $cart_item['quantity'],
            'price' => (float) $cart_item['data']->get_price(),
            'cart_item_key' => $cart_item_key
        ];
        
        // Log local
        $this->log_event('remove_from_cart', $event_data);
        
        // Enviar via JavaScript
        wp_add_inline_script('wc-analytics-tracker', "
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.removeFromCart('" . esc_js($cart_item['product_id']) . "', " . json_encode($event_data) . ");
            }
        ");
    }
    
    /**
     * Rastrear início do checkout
     */
    public function track_checkout_start($order_id, $posted_data, $order) {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        $event_data = [
            'order_id' => $order_id,
            'total_value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'items_count' => $order->get_item_count()
        ];
        
        // Log local
        $this->log_event('begin_checkout', $event_data);
        
        // JavaScript para tracking
        wp_add_inline_script('wc-analytics-tracker', "
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.beginCheckout(" . json_encode($event_data) . ");
            }
        ");
    }
    
    /**
     * Rastrear compra finalizada
     */
    public function track_purchase($order_id) {
        if (!$this->is_tracking_enabled() || !$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (float) $item->get_total() / $item->get_quantity(),
                'total' => (float) $item->get_total(),
                'categories' => $product ? $this->get_product_categories($product) : []
            ];
        }
        
        $event_data = [
            'order_id' => $order_id,
            'total_value' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'coupon_code' => implode(', ', $order->get_coupon_codes()),
            'items' => $items,
            'customer_id' => $order->get_customer_id(),
            'billing_email' => $order->get_billing_email()
        ];
        
        // Log local
        $this->log_event('purchase', $event_data);
        
        ?>
        <script type="text/javascript">
        if (typeof EcommerceTracker !== 'undefined') {
            EcommerceTracker.purchase(<?php echo json_encode($event_data); ?>);
        }
        </script>
        <?php
    }
    
    /**
     * Rastrear mudanças de status do pedido
     */
    public function track_order_status_change($order_id, $from_status, $to_status, $order) {
        if (!$this->is_tracking_enabled()) {
            return;
        }
        
        $event_data = [
            'order_id' => $order_id,
            'from_status' => $from_status,
            'to_status' => $to_status,
            'total_value' => (float) $order->get_total()
        ];
        
        // Log local
        $this->log_event('order_status_change', $event_data);
    }
    
    /**
     * Rastrear consultas de busca
     */
    public function track_search_query($query) {
        if (!$this->is_tracking_enabled() || !$query->is_search() || is_admin()) {
            return;
        }
        
        $search_query = get_search_query();
        if (empty($search_query)) {
            return;
        }
        
        $event_data = [
            'search_query' => $search_query,
            'results_count' => $query->found_posts
        ];
        
        // Log local
        $this->log_event('search', $event_data);
        
        // JavaScript para tracking
        wp_add_inline_script('wc-analytics-tracker', "
            if (typeof EcommerceTracker !== 'undefined') {
                EcommerceTracker.search('" . esc_js($search_query) . "', " . intval($query->found_posts) . ");
            }
        ");
    }
    
    /**
     * Adicionar menu de administração
     */
    public function add_admin_menu() {
        add_options_page(
            __('WC Analytics Tracker', 'wc-analytics-tracker'),
            __('Analytics Tracker', 'wc-analytics-tracker'),
            'manage_options',
            'wc-analytics-tracker',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Inicializar configurações de admin
     */
    public function admin_init() {
        register_setting('wc_analytics_tracker_settings', 'wc_analytics_tracker_settings', [$this, 'validate_settings']);
        
        // Seção principal
        add_settings_section(
            'wc_analytics_tracker_main',
            __('Configurações Principais', 'wc-analytics-tracker'),
            [$this, 'main_section_callback'],
            'wc_analytics_tracker'
        );
        
        // Campos de configuração
        $this->add_settings_fields();
    }
    
    /**
     * Adicionar campos de configuração
     */
    private function add_settings_fields() {
        $fields = [
            'supabase_url' => __('URL do Supabase', 'wc-analytics-tracker'),
            'supabase_anon_key' => __('Chave Anônima do Supabase', 'wc-analytics-tracker'),
            'enable_tracking' => __('Habilitar Tracking', 'wc-analytics-tracker'),
            'enable_debug' => __('Modo Debug', 'wc-analytics-tracker'),
            'track_anonymous_users' => __('Rastrear Usuários Anônimos', 'wc-analytics-tracker'),
            'track_scroll_depth' => __('Rastrear Profundidade de Scroll', 'wc-analytics-tracker'),
            'track_time_on_page' => __('Rastrear Tempo na Página', 'wc-analytics-tracker'),
            'track_form_interactions' => __('Rastrear Interações com Formulários', 'wc-analytics-tracker')
        ];
        
        foreach ($fields as $field_id => $field_title) {
            add_settings_field(
                $field_id,
                $field_title,
                [$this, 'field_callback'],
                'wc_analytics_tracker',
                'wc_analytics_tracker_main',
                ['field_id' => $field_id]
            );
        }
    }
    
    /**
     * Página de administração
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Analytics Tracker', 'wc-analytics-tracker'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Configure as credenciais do Supabase para começar a rastrear eventos de e-commerce.', 'wc-analytics-tracker'); ?></p>
            </div>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_analytics_tracker_settings');
                do_settings_sections('wc_analytics_tracker');
                submit_button();
                ?>
            </form>
            
            <div class="card">
                <h2><?php _e('Ações', 'wc-analytics-tracker'); ?></h2>
                <p>
                    <button type="button" class="button button-secondary" id="test-connection">
                        <?php _e('Testar Conexão', 'wc-analytics-tracker'); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="sync-products">
                        <?php _e('Sincronizar Produtos', 'wc-analytics-tracker'); ?>
                    </button>
                </p>
                <div id="action-results"></div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#test-connection').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php _e("Testando...", "wc-analytics-tracker"); ?>');
                
                $.post(ajaxurl, {
                    action: 'wc_analytics_test_connection',
                    nonce: '<?php echo wp_create_nonce("wc_analytics_nonce"); ?>'
                }, function(response) {
                    $('#action-results').html('<div class="notice ' + 
                        (response.success ? 'notice-success' : 'notice-error') + 
                        '"><p>' + response.data + '</p></div>');
                    button.prop('disabled', false).text('<?php _e("Testar Conexão", "wc-analytics-tracker"); ?>');
                });
            });
            
            $('#sync-products').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php _e("Sincronizando...", "wc-analytics-tracker"); ?>');
                
                $.post(ajaxurl, {
                    action: 'wc_analytics_sync_products',
                    nonce: '<?php echo wp_create_nonce("wc_analytics_nonce"); ?>'
                }, function(response) {
                    $('#action-results').html('<div class="notice ' + 
                        (response.success ? 'notice-success' : 'notice-error') + 
                        '"><p>' + response.data + '</p></div>');
                    button.prop('disabled', false).text('<?php _e("Sincronizar Produtos", "wc-analytics-tracker"); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Callback para seção principal
     */
    public function main_section_callback() {
        echo '<p>' . __('Configure as opções de tracking abaixo:', 'wc-analytics-tracker') . '</p>';
    }
    
    /**
     * Callback para campos
     */
    public function field_callback($args) {
        $field_id = $args['field_id'];
        $value = $this->get_option($field_id);
        
        $boolean_fields = ['enable_tracking', 'enable_debug', 'track_anonymous_users', 'track_scroll_depth', 'track_time_on_page', 'track_form_interactions'];
        
        if (in_array($field_id, $boolean_fields)) {
            echo '<input type="checkbox" id="' . $field_id . '" name="wc_analytics_tracker_settings[' . $field_id . ']" value="1" ' . checked(1, $value, false) . ' />';
        } elseif ($field_id === 'supabase_anon_key') {
            echo '<input type="password" id="' . $field_id . '" name="wc_analytics_tracker_settings[' . $field_id . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        } else {
            echo '<input type="text" id="' . $field_id . '" name="wc_analytics_tracker_settings[' . $field_id . ']" value="' . esc_attr($value) . '" class="regular-text" />';
        }
    }
    
    /**
     * Validar configurações
     */
    public function validate_settings($input) {
        $sanitized = [];
        
        $sanitized['supabase_url'] = esc_url_raw($input['supabase_url']);
        $sanitized['supabase_anon_key'] = sanitize_text_field($input['supabase_anon_key']);
        $sanitized['enable_tracking'] = !empty($input['enable_tracking']);
        $sanitized['enable_debug'] = !empty($input['enable_debug']);
        $sanitized['track_anonymous_users'] = !empty($input['track_anonymous_users']);
        $sanitized['track_scroll_depth'] = !empty($input['track_scroll_depth']);
        $sanitized['track_time_on_page'] = !empty($input['track_time_on_page']);
        $sanitized['track_form_interactions'] = !empty($input['track_form_interactions']);
        
        return $sanitized;
    }
    
    /**
     * Testar conexão com Supabase
     */
    public function test_supabase_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_analytics_nonce')) {
            wp_die(__('Erro de segurança.', 'wc-analytics-tracker'));
        }
        
        $supabase_url = $this->get_option('supabase_url');
        $supabase_key = $this->get_option('supabase_anon_key');
        
        if (empty($supabase_url) || empty($supabase_key)) {
            wp_send_json_error(__('URL ou chave do Supabase não configuradas.', 'wc-analytics-tracker'));
        }
        
        $response = wp_remote_get($supabase_url . '/rest/v1/', [
            'headers' => [
                'apikey' => $supabase_key,
                'Authorization' => 'Bearer ' . $supabase_key
            ],
            'timeout' => 10
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(__('Erro na conexão: ', 'wc-analytics-tracker') . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            wp_send_json_success(__('Conexão com Supabase estabelecida com sucesso!', 'wc-analytics-tracker'));
        } else {
            wp_send_json_error(__('Erro na conexão. Código: ', 'wc-analytics-tracker') . $status_code);
        }
    }
    
    /**
     * Sincronizar produtos com Supabase
     */
    public function sync_products_to_supabase() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_analytics_nonce')) {
            wp_die(__('Erro de segurança.', 'wc-analytics-tracker'));
        }
        
        $products = wc_get_products(['limit' => 100, 'status' => 'publish']);
        $synced = 0;
        
        foreach ($products as $product) {
            $product_data = [
                'product_id' => (string) $product->get_id(),
                'external_product_id' => (string) $product->get_id(),
                'name' => $product->get_name(),
                'price' => (float) $product->get_price(),
                'stock_status' => $product->get_stock_status(),
                'categories' => $this->get_product_categories($product),
                'description' => wp_strip_all_tags($product->get_short_description()),
                'image_url' => wp_get_attachment_url($product->get_image_id())
            ];
            
            if ($this->send_to_supabase('products', $product_data, 'upsert')) {
                $synced++;
            }
        }
        
        wp_send_json_success(sprintf(__('%d produtos sincronizados com sucesso!', 'wc-analytics-tracker'), $synced));
    }
    
    /**
     * Registrar webhooks REST API
     */
    public function register_webhooks() {
        register_rest_route('wc-analytics/v1', '/webhook/order', [
            'methods' => 'POST',
            'callback' => [$this, 'webhook_order_handler'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Handler para webhook de pedidos
     */
    public function webhook_order_handler($request) {
        $data = $request->get_json_params();
        
        // Validar assinatura do webhook aqui
        if (!$this->validate_webhook_signature($request)) {
            return new WP_Error('invalid_signature', 'Invalid webhook signature', ['status' => 401]);
        }
        
        // Processar dados do pedido
        $this->process_order_webhook($data);
        
        return new WP_REST_Response(['status' => 'success'], 200);
    }
    
    // Métodos auxiliares
    
    /**
     * Verificar se o tracking está habilitado
     */
    private function is_tracking_enabled() {
        return $this->get_option('enable_tracking', true) && 
               !empty($this->get_option('supabase_url')) && 
               !empty($this->get_option('supabase_anon_key'));
    }
    
    /**
     * Obter opção de configuração
     */
    private function get_option($key, $default = '') {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    
    /**
     * Obter ID da sessão
     */
    private function get_session_id() {
        if (!session_id()) {
            session_start();
        }
        return session_id();
    }
    
    /**
     * Obter categorias do produto
     */
    private function get_product_categories($product) {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }
        }
        
        return $categories;
    }
    
    /**
     * Log de evento local
     */
    private function log_event($event_type, $event_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_analytics_events';
        
        $wpdb->insert($table_name, [
            'event_type' => $event_type,
            'user_id' => get_current_user_id(),
            'session_id' => $this->get_session_id(),
            'product_id' => isset($event_data['product_id']) ? $event_data['product_id'] : null,
            'order_id' => isset($event_data['order_id']) ? $event_data['order_id'] : null,
            'event_data' => json_encode($event_data),
            'created_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Enviar dados para Supabase
     */
    private function send_to_supabase($table, $data, $method = 'insert') {
        $supabase_url = $this->get_option('supabase_url');
        $supabase_key = $this->get_option('supabase_anon_key');
        
        if (empty($supabase_url) || empty($supabase_key)) {
            return false;
        }
        
        $url = $supabase_url . '/rest/v1/' . $table;
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'apikey' => $supabase_key,
                'Authorization' => 'Bearer ' . $supabase_key,
                'Prefer' => $method === 'upsert' ? 'resolution=merge-duplicates' : 'return=minimal'
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ];
        
        $response = wp_remote_request($url, $args);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300;
    }
    
    /**
     * Validar assinatura do webhook
     */
    private function validate_webhook_signature($request) {
        // Implementar validação de assinatura
        $signature = $request->get_header('x-wc-webhook-signature');
        $body = $request->get_body();
        
        // Por enquanto, sempre retorna true
        // Em produção, implementar validação real
        return true;
    }
    
    /**
     * Processar webhook de pedido
     */
    private function process_order_webhook($data) {
        // Implementar processamento do webhook
        $this->log_event('webhook_order', $data);
    }
    
    /**
     * Aviso de WooCommerce ausente
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce Analytics Tracker requer o WooCommerce para funcionar.', 'wc-analytics-tracker'); ?></p>
        </div>
        <?php
    }
}

// Inicializar o plugin
add_action('plugins_loaded', function() {
    WooCommerceAnalyticsTracker::get_instance();
});

// Hook para cleanup na desinstalação
register_uninstall_hook(__FILE__, function() {
    // Remover opções
    delete_option('wc_analytics_tracker_settings');
    
    // Remover tabelas se necessário
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_analytics_events");
});

