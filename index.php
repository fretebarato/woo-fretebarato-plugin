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
                            'title' => __('Código da regra de cálculo de frete', 'fretebarato_frete'),
                            'type' => 'text',
                            'description' => __('Digite aqui o seu código da regra de cálculo de frete.', 'fretebarato_frete'),
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
                    //CART
                    global $woocommerce;
                    $FreteBarato_Frete_Shipping_Method = new FreteBarato_Frete_Shipping_Method();
                    $amount = $woocommerce->cart->cart_contents_total;
                    $weight = $woocommerce->cart->cart_contents_weight;
                    $zipcode = $package['destination']['postcode'];
                    $customer_code = $FreteBarato_Frete_Shipping_Method->settings['rcf'];
                    $skus = array();


                    foreach (WC()->cart->get_cart() as $cart_item) {
                        $_produto = (object)array();
                        $_produto->unidade_peso = get_option('woocommerce_weight_unit');
                        $_produto->unidade_dimensao = get_option('woocommerce_dimension_unit');
                        $_produto->name = $cart_item['data']->get_title();
                        $_produto->quantity = $cart_item['quantity'];
                        $_produto->price_unity = $cart_item['data']->get_price();
                        $_produto->weight = $cart_item['data']->get_weight();
                        $_produto->length = $cart_item['data']->get_length();
                        $_produto->width = $cart_item['data']->get_width();
                        $_produto->height = $cart_item['data']->get_height();

                        $dimensoes = $cart_item['data']->get_dimensions();
                        list($comprimento, $largura, $altura) = explode("&times;", $dimensoes);
                        $_produto->dimensoes = $comprimento . "x" . $largura . "x" . str_replace(" cm", "", $altura);

                        array_push($skus, $_produto);
                    }

                    $API = API_request_openlog($amount, $weight, $customer_code, $zipcode, $skus);

                    foreach ($API->quotes as $shipping) {
                        $this->add_rate(array(
                            'id' => $shipping->quote_id,
                            'label' => $shipping->name . ' - ' . 'Prazo: (' . $shipping->days . ($shipping->days <= 1 ? ' dia útil' : ' dias úteis') . ')',
                            'cost' => $shipping->price
                        ));
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

        $packages = WC()->shipping->get_packages();

        $chosen_methods = WC()->session->get('chosen_shipping_methods');

        if (is_array($chosen_methods) && in_array('fretebarato_frete', $chosen_methods)) {

            foreach ($packages as $i => $package) {

                if ($chosen_methods[$i] != "fretebarato_frete") {

                    continue;
                }

                $FreteBarato_Frete_Shipping_Method = new FreteBarato_Frete_Shipping_Method();
            }
        }
    }

    function API_request_openlog($amount, $weight, $customer_code, $zipcode, $skus)
    {
        $oCollection = json_decode(json_encode(array('quotes' => [])));

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://admin.fretebarato.com/woocommerce/price/v1/json/" . $customer_code,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode(array(
                'amount' => $amount,
                'peso' => $weight,
                'customer_code' => $customer_code,
                'zipcode' => $zipcode,
                'skus' => $skus
            ))
        ));

        $response = curl_exec($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        switch ($httpcode) {
            case 200:
                $oCollection = json_decode($response);
                break;
        }

        return $oCollection;
    }


    add_action('woocommerce_order_details_after_order_table', 'custom_order_details_after_order_table', 10, 1);
    function custom_order_details_after_order_table($order)
    {

        $user_link = get_post_meta($order->id, '_user_link', true);

        $FreteBarato_Frete_Shipping_Method = new FreteBarato_Frete_Shipping_Method();
        $link = $FreteBarato_Frete_Shipping_Method->settings['link'] . $order->id;
        $shipping_method = @array_shift($order->get_shipping_methods());
        $shipping_method_id = $shipping_method['method_id'];

        if ($shipping_method_id == "fretebarato_frete") {
            echo '<p><b><a class="author-link" href="' . $link . '">' . __('Clique aqui para rastrear sua entrega. ') . '</a></b><p>';
        }
    }
}
