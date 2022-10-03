<?php
/*
Plugin Name: Clip Checkout Gateway
Plugin URI: https://github.com/eamexicano/clip-payment-gateway
Description: Utilizar Clip Checkout como compuerta de pago.
Version: 0.0.1
Author: AwitaStudio
Author URI: https://awitastudio.com/
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: clip-payment-gateway
Domain Path: /languages
*/


function clip_load_textdomain() {
  load_plugin_textdomain( 'clip-payment-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'clip_load_textdomain' );

function clip_load_stylesheet() {
  wp_register_style('clip_stylesheet', plugins_url('clip.css',__FILE__ ));
  wp_enqueue_style('clip_stylesheet');
}
add_action( 'init', 'clip_load_stylesheet' );

function clip_add_gateway_class( $gateways ) {
  $methods[] = 'WC_Clip_Gateway';
  return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'clip_add_gateway_class' );

function prepare_token_for_consumption($token) {
  return str_contains($token, "Basic ") ? $token : "Basic " . $token;
}

function choose_icon_based_on_current_language() {
  if (substr( get_locale(), 0, 2 ) === "es") {
    return WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/img/payment_methods_es.png';
  } else {
    return WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/img/payment_methods_en.png';
  }
}

function clip_remove_settings() {
  $option_name = 'woocommerce_clip_payment_gateway_settings';
  delete_option($option_name);
}

function clip_init_gateway_class() {
  class WC_Clip_Gateway extends WC_Payment_Gateway {
    protected $GATEWAY_NAME              = "WC_Clip_Gateway";
    protected $order                     = null;
    protected $authentication_token = null;

    public function __construct() {
        $this->id              = 'clip_payment_gateway';
        $this->icon        =  choose_icon_based_on_current_language();
        $icon = choose_icon_based_on_current_language();
        $this->method_title    = __( 'Clip Checkout', 'clip-payment-gateway');
        $this->has_fields      = false;
        $this->init_form_fields();
        $this->init_settings();
        $this->authentication_token = prepare_token_for_consumption($this->get_option('authentication_token'));
        $this->gateway_timeout = $this->get_option('gateway_timeout');
        $this->description     = $this->get_option('description');
        $this->instructions     = $this->get_option('instructions');
        $this->url = "https://api-gw.payclip.com/checkout/";

        add_action(
          'woocommerce_update_options_payment_gateways_clip_payment_gateway',
          array($this, 'process_admin_options')
        );

        add_action(
          'woocommerce_thankyou_clip_payment_gateway',
          array( $this, 'clip_thankyou_page')
        );

        add_action(
          'woocommerce_email_before_order_table',
          array($this, 'clip_email_instructions')
        );

        add_action(
          'woocommerce_api_wc_clip_gateway',
          array($this, 'clip_webhook_handler')
        );

        register_uninstall_hook(__FILE__, 'clip_remove_settings');
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
            'gateway_timeout' => array(
                'type'        => 'text',
                'title'       => __('Tiempo de espera de la puerta de enlace.', 'clip-payment-gateway'),
                'description' => __('Segundos que espera el plugin la respuesta de Clip para redirigir el usuario a la orden de compra. En caso de exceder el tiempo de espera el usuario tiene que presionar "Realizar pedido" nuevamente.', 'clip-payment-gateway'),
                'default'     => 5
            ),
            'authentication_token' => array(
                'type'        => 'text',
                'title'       => __('Token de Autenticación', 'clip-payment-gateway'),
                'description' => __("Para generar un token de autenticación en Clip revisa el siguiente enlace:<br><a href=\"https://developer.clip.mx/reference/autenticación-1\" target=\"_blank\">https://developer.clip.mx/reference/autenticación-1</a>", 'clip-payment-gateway'),
            ),
            'description' => array(
                'title' => __( 'Descripción', 'clip-payment-gateway'),
                'type' => 'textarea',
                'description' => __( 'Descripción del método de pago que se visualizará al momento de elegir la compuerta de pago para realizar la transacción. Acepta HTML utilizar con cuidado.', 'clip-payment-gateway'),
                'desc_tip' => false,
            ),
            'instructions' => array(
                'title' => __( 'Instrucciones', 'clip-payment-gateway'),
                'type' => 'textarea',
                'description' => __( 'Mensaje que se anexará a la página final de compras y los correos electrónicos. Acepta HTML utilizar con cuidado.', 'clip-payment-gateway'),
                'desc_tip' => false,
            ),
        );
    }

    function clip_thankyou_page ($order_id) {
        $order = new WC_Order( $order_id );
        $instructions = $this->form_fields['instructions'];
        if ($instructions) {
          echo $this->instructions;
        }
    }

    public function clip_email_instructions ( $order, $sent_to_admin = false, $plain_text = false ) {
       $instructions = $this->form_fields['instructions'];
       $status_that_require_instructions = array('on-hold', 'processing', 'completed');
       if ($instructions && in_array($order->get_status(), $status_that_require_instructions)) {
           echo $this->instructions;
       }
    }

    public function clip_admin_options() {
      ?>
        <h3><?php __('Clip Checkout', 'clip-payment-gateway'); ?></h3>
        <p>
          <?php __('Utilizar Clip Checkout como compuerta de pago.', 'clip-payment-gateway'); ?>
        </p>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
      <?php
    }

    protected function clip_create_payment_link() {
        global $woocommerce;
        $authentication_token  = $this->authentication_token;
        $order_id = $this->order->get_id();
        $currency_code = get_woocommerce_currency();
        $nonce = substr(str_shuffle(MD5(microtime())), 0, 12);
        wc_add_order_item_meta($order_id,'ipn_nonce',$nonce);
        $amount           = (float) $this->order->get_total('view');
        $customer_info =  array("customer" =>"" . $this->order->get_billing_first_name() . " " . $this->order->get_billing_last_name() . "");
        $purchase_description = "" . get_bloginfo('url') . " - Pedido con el ID: " . $order_id . "";
        $callback_url_hook = "" . get_bloginfo('url') . "/wc-api/wc_clip_gateway/?nonce=" . $nonce . "&order_id=" . $order_id . "";
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
                        'error' => $callback_url_error,
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
      $payment_request_url = $this->clip_create_payment_link();
      sleep($this->gateway_timeout);

      if ($payment_request_url != false) {
        $woocommerce->cart->empty_cart();
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
      $this->order->add_order_note("No se generó el enlace de compra en Clip Checkout: " . $this->transaction_error_message );
    }

    protected function clip_complete_order() {
        global $woocommerce;

        if ($this->order->get_status() == 'completed') return;
        $woocommerce->cart->empty_cart();
        $this->order->add_order_note("El pago en clip se completó con un ID: " . $this->transaction_id );
    }
  }
}
add_action( 'plugins_loaded', 'clip_init_gateway_class' );