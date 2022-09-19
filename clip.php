<?php
/*
Plugin Name: Clip Checkout Gateway
Plugin URI: https://awitastudio.com/
Description: Utilizar Clip Checkout as compuerta de pago.
Version: 0.0.1
Author: AwitaStudio
Author URI: https://awitastudio.com/
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: clip-payment-gateway
Domain Path: /languages
*/

add_action( 'init', 'clip_load_textdomain' );
function clip_load_textdomain() {
  load_plugin_textdomain( 'clip-payment-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_filter( 'woocommerce_payment_gateways', 'clip_add_gateway_class' );
function clip_add_gateway_class( $gateways ) {
  $methods[] = 'WC_Clip_Gateway';
  return $methods;
}

add_action( 'plugins_loaded', 'clip_init_gateway_class' );
function clip_init_gateway_class() {
  class WC_Clip_Gateway extends WC_Payment_Gateway {
    protected $GATEWAY_NAME              = "WC_Clip_Gateway";
    protected $order                     = null;
    protected $authentication_token = null;

    public function __construct() {
        $this->id              = 'clip_payment_gateway';
        $this->icon            = WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/img/clip.png';
        $this->method_title    = __( 'Clip Checkout', 'clip-payment-gateway');
        $this->has_fields      = false;
        $this->init_form_fields();
        $this->init_settings();
        $this->account_owner = $this->get_option('account_owner');
        $this->authentication_token = $this->get_option('authentication_token');
        $this->title           = $this->get_option('title');
        $this->gateway_timeout = $this->get_option('gateway_timeout');
        $this->description     = $this->get_option('description');
        $this->instructions     = $this->get_option('instructions');
        $this->url = "https://api-gw.payclip.com/checkout/";

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id ,
            array($this, 'process_admin_options')
        );
        add_action(
            'woocommerce_thankyou_' . $this->id,
            array( $this, 'clip_thankyou_page')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'clip_email_reference')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'clip_email_instructions')
        );

        add_action(
            'woocommerce_api_' . strtolower(get_class($this)),
            array($this, 'clip_webhook_handler')
        );
    }

    public function clip_webhook_handler () {
      header('HTTP/1.1 200 OK');
      $body          = @file_get_contents('php://input');
      $data         = json_decode($body, true);
      $order_id = isset($_REQUEST['order_id']) ? $_REQUEST['order_id'] : null;
      $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : null;
      $order_ipn_nonce = wc_get_order_item_meta($order_id,'ipn_nonce');

      if (is_null($order_id)) return;
      if (is_null($nonce)) return;       
      if ($order_ipn_nonce !=$nonce) return;
       
      $order = wc_get_order( $order_id );
      $order->payment_complete();               
      wp_redirect($order->get_checkout_order_received_url());
    } 

    public function init_form_fields () {
        $this->form_fields = array(
            'enabled' => array(
                'type'        => 'checkbox',
                'title'       => __('Habilitar / Deshabilitar', 'clip-payment-gateway'),
                'label'       => __('Habilitar pago con Clip Checkout', 'clip-payment-gateway'),
                'default'     => 'yes'
            ),
            'account_owner' => array(
              'type'        => 'text',
              'title'       => __('Nombre de la tienda', 'clip-payment-gateway'),
              'description' => __('Se visualizará en el pie de página de los correos', 'clip-payment-gateway')
            ),
            'title' => array(
                'type'        => 'text',
                'title'       => __('Título', 'clip-payment-gateway'),
                'description' => __('Mensaje que el usuario visualizará al elegir la compuerta de pago.', 'clip-payment-gateway'),
                'default'     => __('Pagar con Clip', 'clip-payment-gateway')
            ),
            'gateway_timeout' => array(
                'type'        => 'text',
                'title'       => __('Tiempo de espera de la puerta de enlace.', 'clip-payment-gateway'),
                'description' => __('Segundos que espera el plugin la respuesta de Clip para redirigir el usuario a la orden de compra. En caso de exceder el tiempo de espera el usuario tiene que presionar "Realizar pedido" nuevamente.', 'clip-payment-gateway'),
                'default'     => 5
            ),            
            'authentication_token' => array(
                'type'        => 'text',
                'title'       => __('Token de Autenticación', 'clip-payment-gateway'),
                'description' => __('Para generar un token de autenticación en Clip revisa el siguiente enlace:<br><a href="https://developer.clip.mx/reference/autenticación-1" target="_blank">https://developer.clip.mx/reference/autenticación-1</a><br>Pega el token completo después de \"Basic \" (hay un espacio en blanco después de la palabra) sin comillas. Ej:<br> Basic YWJhNWJkNjQtOTYwOC00N2E4LWIwMzUtNWU2NDkzOTBjZTViOmY2NmI0MzVkLTFmYTEtNDk5NC0wMmI2LTBiYTYzMmJhMThiZA==', 'clip-payment-gateway'),
            ),
            'description' => array(
                'title' => __( 'Descripción', 'clip-payment-gateway'),
                'type' => 'textarea',
                'description' => __( 'Descripción del método de pago que se visualizará al momento de elegir la compuerta de pago para realizar la transacción. Acepta HTML utilizar con cuidado.', 'clip-payment-gateway'),
                'default' =>__( 'Al presionar "Realizar el pedido" se va a generar un enlace de pago en Clip. Te vamos a redirigir a Clip para que realices el pago. Una vez que realices el pago, vas a regresar a este sitio.', 'clip-payment-gateway'),
                'desc_tip' => false,
            ),
            'instructions' => array(
                'title' => __( 'Instrucciones', 'clip-payment-gateway'),
                'type' => 'textarea',
                'description' => __( 'Mensaje que se anexará a la página final de compras y los correos electrónicos. Acepta HTML utilizar con cuidado.', 'clip-payment-gateway'),
                'default' =>__( 'Gracias por utilizar Clip Checkout como compuerta de pago.', 'clip-payment-gateway'),
                'desc_tip' => false,
            ),
        );
    }

    function clip_thankyou_page ($order_id) {
        $order = new WC_Order( $order_id );
        echo '<p>'.esc_html($this->account_owner).'</p>';        
        echo '<p>' . __('Orden de compra procesada por Clip Checkout', 'clip-payment-gateway') . '</p>';
    }

    function clip_email_reference ($order) {
      if('customer_processing_order' == $email->id ){       
         echo '<p>'.esc_html($this->account_owner).'</p>';        
         echo '<p>' . __('Orden de compra procesada por Clip Checkout', 'clip-payment-gateway') . '</p>';
       }
    }

    public function clip_email_instructions ( $order, $sent_to_admin = false, $plain_text = false ) {
       $instructions = $this->form_fields['instructions'];
       if ( $instructions && 'on-hold' === $order->get_status() ) {
           echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
       }
    }

    public function clip_admin_options() {
      ?>
        <h3>
           <?php __('Clip Checkout', 'clip-payment-gateway'); ?>
        </h3>

        <p>
          <?php __('Utilizar Clip Checkout como compuerta de pago.', 'clip-payment-gateway'); ?>
        </p>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>        
      <?php
    }

    public function payment_fields() {
      ?>
        <span class='payment-errors required'></span>
        <?php echo $this->settings['description']; ?>
      <?php 
    }

    protected function clip_create_payment_link() {
        global $woocommerce;
        $authentication_token  = $this->authentication_token;
        $order_id = $this->order->get_id();
        $currency_code = "MXN"; //=> get_woocommerce_currency();
        $nonce = substr(str_shuffle(MD5(microtime())), 0, 12);
        wc_add_order_item_meta($order_id,'ipn_nonce',$nonce);
        $amount           = (float) $this->order->get_total('view');
        $customer_info =  array("customer" =>"" . $this->order->get_billing_first_name() . " " . $this->order->get_billing_last_name() . "");
        $purchase_description = "" . get_bloginfo('url') . " - Pedido con el ID: " . $order_id . "";
        $callback_url_hook = "" . get_bloginfo('url') . "/wc-api/"  . strtolower(get_class($this)) . "/?nonce=" . $nonce . "&order_id=" . $order_id . "";
        $callback_url_error = $this->order->get_checkout_payment_url( false );
        $callback_url_default = $this->order->get_checkout_order_received_url();

        try {
            $args = array(
                'method' => 'POST',
                'headers' => array(
                    'Accept' => 'application/vnd.com.payclip.v2+json',
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->authentication_token,
                ),
                'body' => json_encode(array(
                    'amount' => $amount,
                    'currency' => $currency_code,
                    'purchase_description' => $purchase_description,
                    'redirection_url' => array(
                        'success' => $callback_url_hook,
                        'error' => $callback_url_error, //=> A la 5a vez se redirecciona callback_url_error.
                        'default' => $callback_url_default,
                    ),
                    'metadata' => $customer_info
                ))
            );

            $response =  wp_remote_post($this->url, $args);
            $response_code = wp_remote_retrieve_response_code( $response );
            $body     =  wp_remote_retrieve_body( $response );
            $data = json_decode($body, true);

            if ($response_code == 200 && isset($data['payment_request_url'])) {
                $this->order->update_status('on-hold', 'Redirigiendo a Clip Checkout: ' . $data['payment_request_url'] . '');
                return $data['payment_request_url'];
            } else {
              
              try {
                wc_delete_order_item_meta($order_id,'ipn_nonce');
              } catch (Exception $e) {
                //=> no-op
              }
              wc_add_notice(__("Hubo un error al generar la orden de pago. Presiona 'Realizar el pedido' nuevamente." , 'clip-payment-gateway'), $notice_type = 'error');
              return false;
            }
        } catch(Exception $e) {
            $description = $e->getMessage();
            wc_add_notice(__('Error: ', 'clip-payment-gateway') . $description , $notice_type = 'error');
            return false;
        }
    }

    public function process_payment($order_id) {
      global $woocommerce;
      $this->order        = new WC_Order($order_id);

      $logger = wc_get_logger();
      $context = array( 'source' => 'clipmx' );
      $logger->notice("-- process_payment --", $context);

      $payment_request_url = $this->clip_create_payment_link();
      sleep($this->gateway_timeout); // Wait for Clip to create the payment link
            
      $logger->notice("-- payment_request_url --", $context);
      $logger->notice(wc_print_r( $payment_request_url, true ), $context);

      if ($payment_request_url != false) {
        $woocommerce->cart->empty_cart();
        $logger->notice("-- process_payment --" . $order_id . " - ", $context);
        // Redirect to Clip Checkout
        return array(
          'result'   => 'success',
          'redirect' => $payment_request_url,
        );
      } else {
        $this->clip_mark_as_failed_payment();
        wc_add_notice(__('No se generó el enlace de compra en Clip Checkout.', 'clip-payment-gateway'), $notice_type = 'error');
        return;
      }
    }

    protected function clip_mark_as_failed_payment() {
      $this->order->update_status('failed', __( 'No se generó el enlace de compra en Clip Checkout.', 'clip-payment-gateway'));
      $this->order->add_order_note(
          sprintf(
              "%s No se generó el enlace de compra en Clip Checkout: '%s'",
              $this->GATEWAY_NAME,
              $this->transaction_error_message
          )
      );
    }

    protected function clip_complete_order() {
        global $woocommerce;

        if ($this->order->get_status() == 'completed') return;        
        $woocommerce->cart->empty_cart();
        $this->order->add_order_note(
            sprintf(
                "%s El pago en clip se completó con un ID: '%s'",
                $this->GATEWAY_NAME,
                $this->transaction_id
            )
        );
    }
  }
}
