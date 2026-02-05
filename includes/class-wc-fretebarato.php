<?php
/**
 * WC_Fretebarato class.
 */
class WC_Fretebarato extends WC_Shipping_Method {

    protected $zip_origin;
    protected $debug;
    protected $display_date;
    protected $additional_time;
    protected $customer_id;
    protected $log;
    public $quoteByProduct = false;

    /**
     * @var string
     */
    protected $urlShipQuoteBase = 'https://admin.fretebarato.com/woocommerce/price/v1/json/';

	/**
	 * Initialize the Frete Barato shipping method.
	 *
	 * @return void
	 */
	public function __construct($instance_id = 0 ) {
        $this->id           = 'fretebarato';
        $this->instance_id 	= absint( $instance_id );
		$this->method_title = __( 'Frete Barato', 'woo-shipping-gateway' );

        $this->supports              = array(
            'shipping-zones',
            'instance-settings'
        );

		$this->init();
	}

	/**
	 * Convert class to string.
	 *
	 * @return string Class ID.
	 */
	public function __toString()
	{
	    return 'WC_Fretebarato::' . $this->id . '::' . $this->instance_id . '::' . $this->method_title;
	}

	/**
	 * Initializes the method.
	 *
	 * @return void
	 */
	public function init() {
		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables.
		$this->enabled            = $this->get_option('enabled');
		$this->title              = $this->get_option('title');
		$this->zip_origin         = $this->get_option('zip_origin');
		$this->debug              = $this->get_option('debug');
        $this->display_date       = $this->get_option('display_date');
        $this->additional_time    = $this->get_option('additional_time');
        $this->customer_id        = $this->get_option('customer_id');

		// Active logs.
		if ( 'yes' == $this->debug ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$this->log = new WC_Logger();
			} else {
				$this->log = $this->woocommerce_method()->logger();
			}
		}

		// Actions.
        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Backwards compatibility with version prior to 2.1.
	 *
	 * @return object Returns the main instance of WooCommerce class.
	 */
	protected function woocommerce_method() {
		if ( function_exists( 'WC' ) ) {
			return WC();
		} else {
			global $woocommerce;
			return $woocommerce;
		}
	}

	/**
	 * Admin options fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled' => array(
				'title'            => __( 'Ativar/Desativar', 'woo-shipping-gateway' ),
				'type'             => 'checkbox',
				'label'            => __( 'Ativar este método de envio', 'woo-shipping-gateway' ),
				'default'          => 'yes'
			),
			'title' => array(
				'title'            => __( 'Título', 'woo-shipping-gateway' ),
				'type'             => 'text',
				'description'      => __( 'Este é o título que o cliente verá durante o checkout.', 'woo-shipping-gateway' ),
				'desc_tip'         => true,
				'default'          => __( 'Frete Barato', 'woo-shipping-gateway' )
			),
            'zip_origin' => array(
                'title'            => __( 'CEP de Origem', 'woo-shipping-gateway' ),
                'type'             => 'text',
                'description'      => __( 'CEP de onde as requisições serão enviadas (CEP da loja).', 'woo-shipping-gateway' ),
                'desc_tip'         => true
            ),
            'shipping_class_id'  => array(
                'title'       => __( 'Classe de Envio', 'woo-shipping-gateway' ),
                'type'        => 'select',
                'description' => __( 'Selecione uma classe de envio para usar com este método.', 'woo-shipping-gateway' ),
                'desc_tip'    => true,
                'default'     => '',
                'options'     => $this->get_shipping_classes(),
            ),
            'simulator' => array(
                'title' => __('Simulador de Frete', 'woo-shipping-gateway'),
                'type' => 'checkbox',
                'label' => __('Ativar', 'woo-shipping-gateway'),
                'description' => __('Exibir simulador de frete na página do produto.', 'woo-shipping-gateway'),
                'desc_tip' => true,
                'default' => 'yes'
            ),
            'display_date' => array(
                'title'            => __( 'Prazo de Entrega', 'woo-shipping-gateway' ),
                'type'             => 'checkbox',
                'label'            => __( 'Exibir prazo estimado', 'woo-shipping-gateway' ),
                'description'      => __( 'Exibir o prazo estimado de entrega junto com o nome da transportadora.', 'woo-shipping-gateway' ),
                'desc_tip'         => true,
                'default'          => 'yes'
            ),
            'additional_time' => array(
                'title'            => __( 'Dias Adicionais', 'woo-shipping-gateway' ),
                'type'             => 'text',
                'description'      => __( 'Adicione dias extras ao prazo de entrega estimado pela transportadora.', 'woo-shipping-gateway' ),
                'desc_tip'         => true,
                'default'          => '0',
                'placeholder'      => '0'
            ),
            'customer_id' => array(
                'title'            => __( 'Customer ID', 'woo-shipping-gateway' ),
                'type'             => 'text',
                'description'      => __( 'Seu Customer ID da Frete Barato. Este código é fornecido quando você se cadastra na plataforma.', 'woo-shipping-gateway' ),
                'desc_tip'         => true,
                'required'         => true
            ),
			'testing' => array(
				'title'            => __( 'Testes', 'woo-shipping-gateway' ),
				'type'             => 'title'
			),
			'debug' => array(
				'title'            => __( 'Log de Depuração', 'woo-shipping-gateway' ),
				'type'             => 'checkbox',
				'label'            => __( 'Ativar logs', 'woo-shipping-gateway' ),
				'default'          => 'no',
				'description'      => sprintf( __( 'Registrar eventos da Frete Barato, como requisições à API, dentro de %s.', 'woo-shipping-gateway' ), '<code>woocommerce/logs/fretebarato-' . sanitize_file_name( wp_hash( 'fretebarato' ) ) . '.txt</code>' )
			)
		);

        $this->form_fields = $this->instance_form_fields;
	}

	/**
	 * Frete Barato options page.
	 *
	 * @return void
	 */
    public function admin_options()
    {
        $html = '<h3>' . esc_html($this->method_title) . '</h3>';
        $html .= '<p>' . __( esc_html('Frete Barato é um método de entrega brasileiro que oferece cotações de frete de diversas transportadoras.'), 'woo-shipping-gateway' ) . '</p>';
        $html .= '<table class="form-table">';
        echo $html;
        $this->generate_settings_html();
        $html = '</table>';
        echo $html;
	}

	/**
	 * Checks if the method is available.
	 *
	 * @param array $package Order package.
	 *
	 * @return bool
	 */
	public function is_available( $package ) {
		$is_available = true;

		if ( 'no' == $this->enabled ) {
			$is_available = false;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}

	/**
	 * Replace comma by dot.
	 *
	 * @param  mixed $value Value to fix.
	 *
	 * @return mixed
	 */
	private function fix_format( $value ) {
		$value = str_replace( ',', '.', $value );

		return $value;
	}

	/**
	 * Fix Zip Code format.
	 *
	 * @param mixed $zip Zip Code.
	 *
	 * @return int
	 */
	protected function fix_zip_code( $zip ) {
		$fixed = preg_replace( '([^0-9])', '', $zip );

		return $fixed;
	}

	/**
	 * Get fee.
	 *
	 * @param  mixed $fee
	 * @param  mixed $total
	 *
	 * @return float
	 */
	public function get_fee( $fee, $total ) {
		if ( strstr( $fee, '%' ) ) {
			$fee = ( $total / 100 ) * str_replace( '%', '', $fee );
		}

		return $fee;
	}

	/**
	 * Calculates the shipping rate.
	 *
	 * @param array $package Order package.
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = [] ) {
		$rates  = [];
        $errors = [];

        // Validar se Customer ID está configurado
        if (empty($this->customer_id)) {
            if ( 'yes' == $this->debug ) {
                $this->log->add( $this->id, 'ERRO: Customer ID não configurado');
            }
            return;
        }

        $shipping_values = $this->fretebarato_calculate($package);

        if (!$this->has_shipping_class($package)) {
            return;
        }

        if ( ! empty( $shipping_values ) ) {
            foreach ( $shipping_values as $quote_id => $quote ) {

                // Validar campos obrigatórios
                if (!isset($quote->price) || !isset($quote->name)) {
                    continue;
                }

                // O name já vem formatado do requestJson (ucfirst aplicado)
                $label = ucfirst($quote->name);
                $days = isset($quote->days) ? (int)$quote->days : 0;
                
                // Adicionar prazo ao label se display_date estiver habilitado
                if ( 'yes' === $this->display_date && $days > 0 ) {
                    $label = $this->estimating_delivery( $label, $days, $this->additional_time );
                }
                
                $cost = (float) $quote->price;
                $service = isset($quote->service) ? $quote->service : $quote->name;

                $rates[] = array(
                    'id' => $quote_id,
                    'method_id' => $service,
                    'label' => $label,
                    'cost' => $cost,
                    'meta_data' => array('FRETEBARATO_ID' => 'FB_ID_' . $service, 
                                         'FRETEBARATO_SERVICE' => 'FB_SER_' . $service, 
                                         'FRETEBARATO_NAME' => $label, 
                                         'FRETEBARATO_DAYS' => isset($quote->days) ? $quote->days : 0));
            }

            foreach ( $rates as $rate ) {
                $this->add_rate( $rate );
            }
        }
	}

    /**
     * Estimating Delivery.
     *
     * @param string $label
     * @param string $date
     * @param int    $additional_time
     *
     * @return string
     */
    protected function estimating_delivery( $label, $date, $additional_time = 0 ) {
        $name = $label;
        $additional_time = intval( $additional_time );

        if ( $additional_time > 0 ) {
            $date += intval( $additional_time );
        }

        if ( $date > 0 ) {
            $name .= ' (' . sprintf( _n( 'Delivery in %d working day', 'Delivery in %d working days', $date, 'woo-shipping-gateway' ),  $date ) . ')';
        }

        return $name;
    }

    /***
     * Getting the coupom
     */
    protected function get_coupom($package) {
        $coupom = "";
        if (in_array( "applied_coupons", array_keys( $package ) ) && count($package["applied_coupons"]) > 0) {
            $coupom = $package["applied_coupons"][0];
        }
        return $coupom;
    }

    /**
     * Calculate shipping at frete barato
     * @param array $package
     * @return array
     */
    protected function fretebarato_calculate( $package ){

        $values = array();

        try {
            // Validar Customer ID obrigatório
            if (empty( $this->customer_id )) {
                if ( 'yes' == $this->debug ) {
                    $this->log->add( $this->id,"ERRO: Customer ID não configurado");
                }
                return $values;
            }

            $RecipientCEP = $package['destination']['postcode'];
            $RecipientCountry = $package['destination']['country'];

            // Checks if services and zipcode is empty.
            if (empty( $RecipientCEP ) && $RecipientCountry =='BR') {
                if ( 'yes' == $this->debug ) {
                    $this->log->add( $this->id,"ERRO: CEP destino não informado");
                }
                return $values;
            }

            if (empty( $this->zip_origin )) {
                if ( 'yes' == $this->debug ) {
                    $this->log->add( $this->id,"ERRO: CEP origem não configurado");
                }
                return $values;
            }

            $coupom = $this->get_coupom($package);

            // Inicializar variáveis para cálculo total
            $amount = 0;
            $weight = 0;
            $skus = array();

            // Processar produtos do pacote
            foreach ( $package['contents'] as $item_id => $item_data ) {
                $product = $item_data['data'];
                $qty = $item_data['quantity'];
                
                if (!is_numeric($qty)) {
                    $this->log('there is a package configuration mistake in store, numeric expected, but string found '.$qty);
                    $qty = 0;
                }

                if ( 'yes' == $this->debug ) {
                    $this->log->add( $this->id, 'Product: ' . print_r($product, true));
                }

                if ( $qty > 0 && $product->needs_shipping() ) {
                    // Obter dimensões e peso do produto
                    if ( version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
                        $_height = $product->get_height() ? $product->get_height() : 0;
                        $_width  = $product->get_width() ? $product->get_width() : 0;
                        $_length = $product->get_length() ? $product->get_length() : 0;
                        $_weight = $product->get_weight() ? $product->get_weight() : 0;
                    }
                    else if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
                        $_height = isset($product->height) ? $product->height : 0;
                        $_width  = isset($product->width) ? $product->width : 0;
                        $_length = isset($product->length) ? $product->length : 0;
                        $_weight = isset($product->weight) ? $product->weight : 0;
                    } else {
                        $_height = isset($product->height) ? $product->height : 0;
                        $_width  = isset($product->width) ? $product->width : 0;
                        $_length = isset($product->length) ? $product->length : 0;
                        $_weight = isset($product->weight) ? $product->weight : 0;
                    }

                    // Obter preço do produto
                    $price = $product->get_price();
                    if (!is_numeric($price)) {
                        $this->log('there is a package price configuration mistake in store, numeric expected, but string found '.$price);
                        $price = 0.0;
                    }

                    // Calcular totais
                    $amount += $price * $qty;
                    $weight += $_weight * $qty;

                    // Criar objeto produto no formato do index.php
                    $_produto = (object)array();
                    $_produto->unidade_peso = get_option('woocommerce_weight_unit', 'kg');
                    $_produto->unidade_dimensao = get_option('woocommerce_dimension_unit', 'cm');
                    $_produto->name = $product->get_name();
                    $_produto->quantity = $qty;
                    $_produto->price_unity = $price;
                    $_produto->weight = $_weight ? $_weight : 0;
                    $_produto->length = $_length ? $_length : 0;
                    $_produto->width = $_width ? $_width : 0;
                    $_produto->height = $_height ? $_height : 0;

                    // Formatar dimensões
                    $length = $_produto->length;
                    $width = $_produto->width;
                    $height = $_produto->height;
                    $_produto->dimensoes = $length . "x" . $width . "x" . $height;

                    $skus[] = $_produto;
                }
            }

            if ( 'yes' == $this->debug ) {
                $this->log->add( $this->id, 'CEP ' . $package['destination']['postcode'] );
                $this->log->add( $this->id, 'Amount: ' . $amount );
                $this->log->add( $this->id, 'Weight: ' . $weight );
            }

            // Se não usar cotação por produto, usar valor total do carrinho
            if(!$this->quoteByProduct) {
                $amount = WC()->cart->get_cart_contents_total();
                $weight = WC()->cart->get_cart_contents_weight();
            }

            // Preparar parâmetros para requisição JSON com nomenclaturas do endpoint
            $serviceParam = array(
                'coupom' => $coupom,
                'platform_name' => 'WOOCOMMERCE', // Identificar que está foi uma chamada do woocommerce
                'version' => WOOCOMMERCE_VERSION, // Identificar que está foi uma chamada do woocommerce
                'zipcode_origin' => $this->zip_origin,
                'zipcode' => $RecipientCEP,
                'document' => '',
                'amount' => $amount,
                'skus' => $skus,
                'country' => $RecipientCountry
            );
            $values = $this->requestJson($serviceParam, $values);
        } catch (Exception $e) {
            $this->log(print_r($e->getMessage(), true));
        }

        return $values;

    }

    /**
     * @return array
     */
    protected function get_shipping_classes() {
        $shipping_classes = WC()->shipping->get_shipping_classes();
        $options          = array(
            '-1' => __( 'Qualquer Classe de Envio', 'woo-shipping-gateway' ),
            '0'  => __( 'Sem Classe de Envio', 'woo-shipping-gateway' ),
        );

        if (!empty($shipping_classes)) {
            $options += wp_list_pluck($shipping_classes, 'name', 'term_id');
        }

        return $options;
    }

    /**
     * @param  array $package
     * @return bool
     */
    protected function has_shipping_class($package) {
        $same_class = true;
        $class_id = $this->get_option('shipping_class_id');

        if ($class_id === '' || (int) $class_id === -1) {
            return $same_class;
        }

        $class_id = (int) $class_id;

        foreach ($package['contents'] as $item) {
            $product  = $item['data'];
            $quantity = $item['quantity'];

            if (($quantity > 0 && $product->needs_shipping()) && $class_id !== (int)$product->get_shipping_class_id()) {
                $same_class = false;
                break;
            }
        }

        return $same_class;
    }

    /**
     * Log message
     *
     * @param string $mensage
     * @return void
     */
    protected function log($menssage) {
        if ( 'yes' == $this->debug ) {
            $this->log->add( $this->id, $menssage);
        }
    }

    /**
     * Request Json
     *
     * @param array $serviceParam
     * @param array $values
     * @return array
     */
    protected function requestJson(array $serviceParam, array $values)
    {
        // Construir URL dinâmica com Customer ID
        $urlShipQuote = $this->urlShipQuoteBase . urlencode($this->customer_id);
        
        $this->log('Requesting the Frete Barato API...');
        $this->log(print_r($serviceParam, true));
        $this->log('URL: ' . $urlShipQuote);

        $paramsRequest = [
            'body' => wp_json_encode($serviceParam),
            'headers' => [
                "Content-Type" => "application/json"
            ]
        ];

        $curlResponse = wp_remote_post($urlShipQuote, $paramsRequest);

        if ( is_wp_error( $curlResponse ) ) {
            $this->log('WP_Error: ' . $curlResponse->get_error_message());
            return $values;
        }

        // Pega os headers da resposta
        $headers = wp_remote_retrieve_headers($curlResponse);
        // Verifica o Content-Type
        if (isset($headers['content-type']) && !str_contains($headers["content-type"], "application/json")) {
            $this->log('WP_Error: O Content-Type retornado não é application/json, mas sim: ' . $headers['content-type']);
            return $values;
        }

        $this->log('Curl response: ' . $curlResponse['body']);

        $response = json_decode($curlResponse['body']);
        
        // Verificar se a resposta contém o array de cotações
        if ( !isset( $response->quotes ) || !is_array($response->quotes) ) {
            if ( 'yes' == $this->debug ) {
                $this->log('ERRO: Resposta da API não contém quotes ou não é um array');
            }
            return $values;
        }

        $quotesArray = $response->quotes;

        if(empty($quotesArray)) {
            if ( 'yes' == $this->debug ) {
                $this->log('AVISO: Array de quotes está vazio');
            }
            return $values;
        }

        // Processar cada cotação retornada
        foreach ($quotesArray as $quote) {
            // Validar campos obrigatórios
            if (!isset($quote->quote_id) || !isset($quote->name) || !isset($quote->price)) {
                if ( 'yes' == $this->debug ) {
                    $this->log('AVISO: Quote sem campos obrigatórios (quote_id, name ou price)');
                }
                continue;
            }

            // Formatar nome da transportadora com ucfirst (primeira letra maiúscula, resto minúscula)
            $quote->name = ucfirst(strtolower($quote->name));
            
            // Formatar service também se existir
            if (isset($quote->service)) {
                $quote->service = ucfirst(strtolower($quote->service));
            }

            $quote_id = (string) $quote->quote_id;
            if ( 'yes' == $this->debug ) {
                $this->log('Processando quote [' . $quote->name . ']: ' . print_r( $quote, true ));
            }
            
            // Usar quote_id como chave do array
            $values[ $quote_id ] = $quote;
        }
        return $values;
    }
}
