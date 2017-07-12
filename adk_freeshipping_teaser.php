<?php
/**
Plugin Name: Advertikon Checkout teaser
Plugin URI:
Version: 1.0.0
Description: Entices customers to spend more money on checkout, by inform them how much more they need to spend to get free shipping
Author: Advertikon
Author URI: shop.advertikon.com.ua
Text Domain: advertikon
Domain Path :
Network :
Lisence: GPLv2 or later
*/

// Make sure we don't expose any info if called directly
if ( ! function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

class AdvertikonCheckoutTeaser {

	/**
	* @var Object $shippingMethod Free shipping method instance
	*/
	protected $shippingMethod = null;

	/**
	* Add additional controls to free shipping settings panel
	*
	* @param Array $shippingMethods List of shipping methods
	* @return Array
	*/
	public function addShippingMethod( $shippingMethods ) {
		foreach( $shippingMethods as &$shipping ){
			if( ! is_object( $shipping ) && $shipping == 'WC_Shipping_Free_Shipping' ) {
				$shipping = new WC_Shipping_Free_Shipping;
			}
			if( 
				is_object( $shipping ) &&
				get_class( $shipping ) == 'WC_Shipping_Free_Shipping' &&
				! in_array( 'advertikon_checkout_teaser_text' , $shipping->form_fields )
			) {
				$fieldsToAdd = array(
					'advertikon_checkout_teaser_text' => array(
							'title' 		=> __( 'Teaser text', 'advertikon' ),
							'type' 			=> 'text',
							'label' 		=> __( 'Teaser text', 'advertikon' ),
							'default' 		=> __( 'In order to get free shipping you need to buy more products in the {total}' , 'advertikon' ),
							'description'	=> __( 'Text {total} will be replaced with real amount, which needed, to enable free shipping' , 'advertikon' ),
							'desc_tip'		=> true,
						),
					);
				$shipping->form_fields = array_merge( $shipping->form_fields , $fieldsToAdd );
				$this->shippingMethod = $shipping;
				break;
			}
		}
		return $shippingMethods;
	}

	/**
	* Get free shipping method instance
	*
	* @return Object
	*/
	protected function getShippingMethod() {
		if( ! $this->shippingMethod ) {
			$shippingMethods = WC()->shipping->get_shipping_methods();
			if( ! $shippingMethods ) {
				WC()->shipping->load_shipping_methods();
				$shippingMethods = WC()->shipping->get_shipping_methods();
			}
			if( isset( $shippingMethods[ 'free_shipping' ] ) ) {
				$this->shippingMethod = $shippingMethods[ 'free_shipping' ];
			}
		}
		return $this->shippingMethod;
	}

	/**
	* Render teaser
	*/
	public function renderTeaser() {
		if( ! $this->getShippingMethod() ) {
			return;
		}
		foreach( WC()->cart->get_shipping_packages() as $package ) {
			if( ! $this->is_available( $package ) ) {
				return;
			}
		}
		$set = get_option( 'woocommerce_free_shipping_settings' , array() );
		$text = isset( $set[ 'advertikon_checkout_teaser_text' ] ) ? $set[ 'advertikon_checkout_teaser_text' ] : '';
		$amount = $this->getExtraTotal();
		if( ! $amount || $amount < 0 ) {
			return;
		}
		//TODO: implement mb_replace
		echo sprintf( '<div style="padding: 5px;text-align: center;background-color: #F34242;color: white;">%s</div>' , preg_replace( '/{total}/' , wc_price( $amount ) , $text ) );
	}

	/**
	 * Check if free shipping is available.
	 *
	 * @param array $package
	 * @return bool
	 */
	protected function is_available( $package ) {
		if ( 'no' == $this->shippingMethod->enabled ) {
			return false;
		}

		if ( 'specific' == $this->shippingMethod->availability ) {
			$ship_to_countries = $this->shippingMethod->countries;
		} else {
			$ship_to_countries = array_keys( WC()->countries->get_shipping_countries() );
		}

		if ( is_array( $ship_to_countries ) && ! in_array( $package['destination']['country'], $ship_to_countries ) ) {
			return false;
		}

		// Enabled logic
		$is_available       = false;
		$has_coupon         = false;
		$has_met_min_amount = false;

		if ( in_array( $this->shippingMethod->requires, array( 'coupon', 'either', 'both' ) ) ) {

			if ( $coupons = WC()->cart->get_coupons() ) {
				foreach ( $coupons as $code => $coupon ) {
					if ( $coupon->is_valid() && $coupon->enable_free_shipping() ) {
						$has_coupon = true;
					}
				}
			}
		}

		if ( in_array( $this->shippingMethod->requires, array( 'min_amount', 'either', 'both' ) ) && isset( WC()->cart->cart_contents_total ) ) {
			if ( WC()->cart->prices_include_tax ) {
				$total = WC()->cart->cart_contents_total + array_sum( WC()->cart->taxes );
			} else {
				$total = WC()->cart->cart_contents_total;
			}

			if ( $total >= $this->shippingMethod->min_amount ) {
				$has_met_min_amount = true;
			}
		}

		switch ( $this->shippingMethod->requires ) {
			case 'min_amount' :
				if ( ! $has_met_min_amount ) {
					$is_available = true;
				}
			break;
			case 'coupon' :
				if ( $has_coupon ) {

				}
			break;
			case 'both' :
				if ( ! $has_met_min_amount && $has_coupon ) {
					$is_available = true;
				}
			break;
			case 'either' :
				if ( ! $has_met_min_amount && ! $has_coupon ) {
					$is_available = true;
				}
			break;
			default :
				$is_available = true;
			break;
		}
		return $is_available;
	}

	/**
	* Get amount to be spend to get free shipping
	*
	* @return Float|null
	*/
	protected function getExtraTotal() {
		$s = get_option( 'woocommerce_free_shipping_settings' , array() );
		$min_amount = isset( $s[ 'min_amount' ] ) ? $s[ 'min_amount' ] : null;
		if( ! is_null( $min_amount ) ) {
			return $min_amount - WC()->cart->cart_contents_total;
		}
		return null;
	}
	
}

$advertikonCheckoutTeaset = new AdvertikonCheckoutTeaser;

//Add content before cart rendering
add_action( 'woocommerce_before_cart_contents' , array( $advertikonCheckoutTeaset , 'renderTeaser' ) );

//Shipping methods initalization
add_action( 'woocommerce_shipping_methods' , array( $advertikonCheckoutTeaset , 'addShippingMethod' ) );

?>
