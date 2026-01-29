<?php

/**
 * Plugin Name: Frete Barato v1.0.12
 * Description: Somos um HUB de transporte que integra seu e-commerce com as melhores transportadoras do Brasil.
 */

if (! defined('WPINC')) {

    die;
}

/*
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    function fretebarato_frete_shipping_method()
    {
        if (! class_exists('FreteBarato_Frete_Shipping_Method')) {
            class FreteBarato_Frete_Shipping_Method extends WC_Shipping_Method
            {
                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                public function __construct()
                {
                    $this->id                 = 'fretebarato_frete';
                    $this->method_title       = __('Frete Barato', 'fretebarato_frete');
                    $this->method_description = __('Cálculo de frete', 'fretebarato_frete');

                    $this->availability = 'including';
                    $this->countries = array(
                        'BR'
                    );

                    $this->init();

                    $this->enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'yes';
                    $this->title = isset($this->settings['title']) ? $this->settings['title'] : __('Frete Barato Shipping', 'fretebarato_frete');
                    $this->email = isset($this->settings['email']) ? $this->settings['email'] : __('', 'fretebarato_frete');
                }

                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init()
                {
                    // Load the settings API
                    $this->init_form_fields();
                    $this->init_settings();

                    // Save settings in admin if you have any defined
                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                }

                /**
                 * Define settings field for this shipping
                 * @return void 
                 */
                function init_form_fields()
                {

                    $this->form_fields = array(

                        'enabled' => array(
                            'title' => __('Habilitar', 'fretebarato_frete'),
                            'type' => 'checkbox',
                            'description' => __('Habilite esse módulo de frete.', 'fretebarato_frete'),
                            'default' => 'yes'
                        ),

                        'rcf' => array(
                            'title' => __('Customer ID', 'fretebarato_frete'),
                            'type' => 'text',
                            'description' => __('Digite aqui o seu Customer ID da API Frete Barato.', 'fretebarato_frete'),
                            'default' => ''
                        ),

                        'link' => array(
                            'title' => __('Link de rastreio', 'fretebarato_frete'),
                            'type' => 'text',
                            'description' => __('Insira aqui seu link de rastreio.', 'fretebarato_frete'),
                            'default' => ''
                        ),

                    );
                }

                /**
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function calculate_shipping($package = array())
                {
                    // Validar se há configuração do Customer ID
                    $customer_code = isset($this->settings['rcf']) ? $this->settings['rcf'] : '';
                    
                    if (empty($customer_code)) {
                        return;
                    }

                    // Obter dados do carrinho
                    $cart = WC()->cart;
                    if (!$cart) {
                        return;
                    }

                    $amount = $cart->get_cart_contents_total();
                    $weight = $cart->get_cart_contents_weight();
                    $zipcode = isset($package['destination']['postcode']) ? $package['destination']['postcode'] : '';
                    
                    if (empty($zipcode)) {
                        return;
                    }

                    $skus = array();


                    foreach ($cart->get_cart() as $cart_item) {
                        $product = $cart_item['data'];
                        
                        $_produto = (object)array();
                        $_produto->unidade_peso = get_option('woocommerce_weight_unit', 'kg');
                        $_produto->unidade_dimensao = get_option('woocommerce_dimension_unit', 'cm');
                        $_produto->name = $product->get_name();
                        $_produto->quantity = $cart_item['quantity'];
                        $_produto->price_unity = $product->get_price();
                        $_produto->weight = $product->get_weight() ? $product->get_weight() : 0;
                        $_produto->length = $product->get_length() ? $product->get_length() : 0;
                        $_produto->width = $product->get_width() ? $product->get_width() : 0;
                        $_produto->height = $product->get_height() ? $product->get_height() : 0;

                        // Formatar dimensões de forma mais robusta
                        $length = $_produto->length;
                        $width = $_produto->width;
                        $height = $_produto->height;
                        $_produto->dimensoes = $length . "x" . $width . "x" . $height;

                        $skus[] = $_produto;
                    }

                    $API = API_request_openlog($amount, $weight, $customer_code, $zipcode, $skus);

                    // Validar resposta da API antes de processar
                    if (isset($API->quotes) && is_array($API->quotes)) {
                        foreach ($API->quotes as $shipping) {
                            if (isset($shipping->quote_id) && isset($shipping->name) && isset($shipping->price)) {
                                $days = isset($shipping->days) ? (int)$shipping->days : 0;
                                $label = $shipping->name;
                                
                                if ($days > 0) {
                                    $label .= ' - Prazo: (' . $days . ($days <= 1 ? ' dia útil' : ' dias úteis') . ')';
                                }
                                
                                $this->add_rate(array(
                                    'id' => $shipping->quote_id,
                                    'label' => $label,
                                    'cost' => floatval($shipping->price)
                                ));
                            }
                        }
                    }
                }
            }
        }
    }

    add_action('woocommerce_shipping_init', 'fretebarato_frete_shipping_method');

    function add_fretebarato_frete_shipping_method($methods)
    {
        $methods[] = 'FreteBarato_Frete_Shipping_Method';
        return $methods;
    }

    add_filter('woocommerce_shipping_methods', 'add_fretebarato_frete_shipping_method');

    function fretebarato_frete_validate_order($posted)
    {
        // Esta função está preparada para validações futuras
        // Por enquanto, não há validações específicas necessárias
        // A validação básica já é feita pelo WooCommerce
    }

    function API_request_openlog($amount, $weight, $customer_code, $zipcode, $skus)
    {
        // Inicializar resposta padrão
        $oCollection = (object)array('quotes' => array());

        // Validar parâmetros obrigatórios
        if (empty($customer_code) || empty($zipcode)) {
            return $oCollection;
        }

        // Sanitizar customer_code para URL
        $customer_code = sanitize_text_field($customer_code);
        $url = "https://admin.fretebarato.com/woocommerce/price/v1/json/" . urlencode($customer_code);

        // Preparar dados para envio
        $post_data = array(
            'amount' => floatval($amount),
            'peso' => floatval($weight),
            'customer_code' => $customer_code,
            'zipcode' => sanitize_text_field($zipcode),
            'skus' => $skus
        );

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($post_data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            )
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        // Processar resposta apenas se HTTP 200 e sem erros de cURL
        if ($httpcode === 200 && $response !== false && empty($curl_error)) {
            $decoded_response = json_decode($response);
            if (json_last_error() === JSON_ERROR_NONE && is_object($decoded_response)) {
                $oCollection = $decoded_response;
            }
        }

        return $oCollection;
    }


    add_action('woocommerce_order_details_after_order_table', 'custom_order_details_after_order_table', 10, 1);
    function custom_order_details_after_order_table($order)
    {
        // Validar se $order é um objeto válido
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        // Obter ID do pedido usando método compatível com WooCommerce 3.0+
        // Usar get_id() para evitar acesso direto à propriedade (depreciado desde WC 3.0)
        if (method_exists($order, 'get_id')) {
            $order_id = $order->get_id();
        } else {
            // Fallback para versões antigas (antes de WC 3.0)
            $order_id = isset($order->id) ? $order->id : 0;
        }
        
        if (empty($order_id)) {
            return;
        }
        
        // Obter métodos de envio do pedido
        $shipping_methods = $order->get_shipping_methods();
        
        if (empty($shipping_methods)) {
            return;
        }

        // Verificar se algum método de envio é do Frete Barato
        $is_fretebarato = false;
        foreach ($shipping_methods as $shipping_method) {
            if (isset($shipping_method['method_id']) && $shipping_method['method_id'] === 'fretebarato_frete') {
                $is_fretebarato = true;
                break;
            }
        }

        if (!$is_fretebarato) {
            return;
        }

        // Obter configurações do método de envio usando get_option (padrão WooCommerce)
        $shipping_method_settings = get_option('woocommerce_fretebarato_frete_settings', array());
        $tracking_link = isset($shipping_method_settings['link']) ? trim($shipping_method_settings['link']) : '';

        if (empty($tracking_link)) {
            return;
        }

        // Construir link de rastreio
        $link = esc_url($tracking_link . $order_id);
        $link_text = __('Clique aqui para rastrear sua entrega.', 'fretebarato_frete');

        echo '<p><b><a class="author-link" href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html($link_text) . '</a></b></p>';
    }
}
