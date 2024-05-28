<?php

/**
 * The file that saves the abandoned carts.
 *
 * @link       https://www.activecampaign.com/
 * @since      1.3.2
 *
 * @package    Activecampaign_For_Woocommerce
 */

use Activecampaign_For_Woocommerce_Logger as Logger;
use Activecampaign_For_Woocommerce_Plugin_Upgrade_Command as Plugin_Upgrade;
use Activecampaign_For_Woocommerce_Abandoned_Cart_Utilities as Abandoned_Cart_Utilities;

/**
 * Save the cart to a table to keep the record in case it gets abandoned
 *
 * @since      1.0.0
 * @package    Activecampaign_For_Woocommerce
 * @subpackage Activecampaign_For_Woocommerce/includes/commands
 * @author     acteamintegrations <team-integrations@activecampaign.com>
 */
class Activecampaign_For_Woocommerce_Save_Abandoned_Cart_Command {

	/**
	 * The custom ActiveCampaign logger
	 *
	 * @var Activecampaign_For_Woocommerce_Logger
	 */
	private $logger;

	/**
	 * Array of data passed from ajax
	 *
	 * @var Array passed_data
	 */
	private $passed_data;

	/**
	 * Abandoned cart utilities class
	 *
	 * @var Activecampaign_For_Woocommerce_Abandoned_Cart_Utilities
	 */
	private $abandoned_cart_util;

	/**
	 * Activecampaign_For_Woocommerce_Update_Cart_Command constructor.
	 *
	 * @param     Logger $logger     The logger interface.
	 */
	public function __construct(
		Logger $logger = null
	) {
		$this->logger              = $this->logger ?: new Logger();
		$this->abandoned_cart_util = new Abandoned_Cart_Utilities();
	}

	/**
	 * Store the last activity time for the current user.
	 * This is the initialization event which triggers on any cart change.
	 */
	public function init() {
		// Store the cart
		$this->store_abandoned_cart_data();
		// Schedule single event for a logged in user if there's a cart
		$this->abandoned_cart_util->schedule_recurring_abandon_cart_task();
	}

	/**
	 * Store the last activity time for the current user.
	 * This is the initialization event which triggers on any cart change.
	 *
	 * @param     array $data     Data passed from ajax to override the name and email fields.
	 *
	 * @return bool
	 */
	public function init_data( $data ) {
		// Schedule single event for a logged in user if there's a cart
		if ( ! empty( $data ) ) {
			$this->passed_data = $data;
			$this->store_abandoned_cart_data();
			$this->abandoned_cart_util->schedule_recurring_abandon_cart_task();
		}

		return false;
	}

	/**
	 * Builds the customer data we need for abandoned cart.
	 *
	 * @return array|string
	 */
	private function build_customer_data() {
		try {
			// Get current customer
			if ( ! empty( wc()->customer->get_id() ) && ! empty( wc()->customer->get_email() ) ) {
				$customer_data               = wc()->customer->get_data();
				$customer_data['id']         = wc()->customer->get_id(); // This is a user id if registered or a UUID if guest
				$customer_data['email']      = wc()->customer->get_email();
				$customer_data['first_name'] = wc()->customer->get_first_name();
				$customer_data['last_name']  = wc()->customer->get_last_name();
			} else {
				// We don't have a real WC customer, get the session customer
				$customer_data = wc()->session->get( 'customer' );

				// Make sure we've set the id
				$customer_data['id'] = wc()->session->get_customer_id();

				// If we have guest data passed in, replace with that
				if ( ! empty( $this->passed_data ) ) {
					$customer_data['email']      = $this->passed_data['customer_email'];
					$customer_data['first_name'] = $this->passed_data['customer_first_name'];
					$customer_data['last_name']  = $this->passed_data['customer_last_name'];
				}

				if ( ! empty( $customer_data['email'] ) ) {
					// Set the customer data for billing
					$customer_data['billing_email'] = $customer_data['email'];
				}

				if ( ! empty( $customer_data['first_name'] ) ) {
					$customer_data['billing_first_name'] = $customer_data['first_name'];
				}

				if ( ! empty( $customer_data['last_name'] ) ) {
					$customer_data['billing_last_name'] = $customer_data['last_name'];
				}
			}

			return $customer_data;
		} catch ( Throwable $t ) {
			$this->logger->error(
				'Abandoned sync: Encountered an error on gathering customer and/or session data for the abandonment sync',
				[
					'message' => $t->getMessage(),
					'trace'   => $this->logger->clean_trace( $t->getTrace() ),
				]
			);
		}
	}

	/**
	 * This adds the cart info to our table.
	 *
	 * @throws Throwable Message.
	 */
	private function store_abandoned_cart_data() {
		$dt           = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$current_user = wp_get_current_user();

		$customer_data = $this->build_customer_data();

		// If the cart is emptied remove the abandoned cart entry and end the function
		if ( wc()->cart->is_empty() ) {
			$this->abandoned_cart_util->delete_abandoned_cart_by_filter( 'customer_id', $customer_data['id'] );

			return;
		}

		// Get the cart
		$cart                  = wc()->cart->get_cart();
		$removed_cart_contents = wc()->cart->removed_cart_contents;
		$cart_totals           = null;

		try {
			// Calculate the latest totals so the cart totals are accurate
			wc()->cart->calculate_totals();
			$cart_totals = wc()->cart->get_totals();
		} catch ( Throwable $t ) {
			$this->logger->error(
				'Abandoned sync: Encountered an error on gathering cart data for the abandonment sync',
				[
					'message' => $t->getMessage(),
					'trace'   => $this->logger->clean_trace( $t->getTrace() ),
				]
			);
		}

		// If we have a customer, do the stuff
		if ( ! empty( $customer_data['email'] ) ) {
			// Step 1 verify we added a table
			$ac_table = new Plugin_upgrade();
			if ( ! $ac_table->verify_table() ) {
				$this->logger->error( 'Save abandoned cart command: Could not verify the abandoned cart table...' );

				return;
			}

			global $wpdb;

			try {
				$stored_id = null;
				if ( ! empty( $customer_data['id'] ) ) {
					$stored_id = $wpdb->get_var(
					// phpcs:disable
						$wpdb->prepare(
							'SELECT id FROM ' . $wpdb->prefix . ACTIVECAMPAIGN_FOR_WOOCOMMERCE_ABANDONED_CART_NAME . ' WHERE customer_id = %s',
							$customer_data['id']
						)
					// phpcs:enable
					);

					if ( $wpdb->last_error ) {
						$this->logger->error(
							'Save abandoned cart command: There was an error selecting the id for a customer abandoned cart record.',
							[
								'wpdb_last_error' => $wpdb->last_error,
								'customer_id'     => $customer_data['id'],
							]
						);
					}
				}
			} catch ( Throwable $t ) {
				$this->logger->warning(
					'Save abandoned cart command: There was an error attempting to save this abandoned cart',
					[
						'message'       => $t->getMessage(),
						'customer_data' => $customer_data,
						'trace'         => $this->logger->clean_trace( $t->getTrace() ),
					]
				);
			}

			try {
				// clean user_pass from user
				unset( $current_user->user_pass );
			} catch ( Throwable $t ) {
				$this->logger->warning(
					'Save abandoned cart command: There was an error checking and clearing the abandoned cart',
					[
						'message'       => $t->getMessage(),
						'customer_data' => $customer_data,
						'trace'         => $this->logger->clean_trace( $t->getTrace() ),
					]
				);
			}

			try {
				$store_data = [
					'synced_to_ac'                   => 0,
					'customer_id'                    => $customer_data['id'],
					'customer_email'                 => $customer_data['email'],
					'customer_first_name'            => $customer_data['first_name'],
					'customer_last_name'             => $customer_data['last_name'],
					'last_access_time'               => $dt->format( 'Y-m-d H:i:s' ),
					'customer_ref_json'              => wp_json_encode( $customer_data, JSON_UNESCAPED_UNICODE ),
					'user_ref_json'                  => wp_json_encode( $current_user, JSON_UNESCAPED_UNICODE ),
					'cart_ref_json'                  => wp_json_encode( $cart, JSON_UNESCAPED_UNICODE ),
					'cart_totals_ref_json'           => wp_json_encode( $cart_totals, JSON_UNESCAPED_UNICODE ),
					'removed_cart_contents_ref_json' => wp_json_encode( $removed_cart_contents, JSON_UNESCAPED_UNICODE ),
				];

				if ( ! empty( $stored_id ) ) {
					$this->send_table_data( $store_data, $stored_id );
				} else {
					$store_data['activecampaignfwc_order_external_uuid'] = $this->abandoned_cart_util->get_or_generate_uuid();
					$this->abandoned_cart_util->store_abandoned_cart_data( $store_data );
				}
			} catch ( Throwable $t ) {
				$this->logger->warning(
					'Save abandoned cart command: There was an error attempting to save this abandoned cart',
					[
						'message'       => $t->getMessage(),
						'customer_data' => $customer_data,
						'trace'         => $this->logger->clean_trace( $t->getTrace() ),
					]
				);
			}
		}
	}

	/**
	 * Send the table data to the database
	 *
	 * @param Array       $data The data.
	 * @param null|string $stored_id The stored id of the customer.
	 */
	private function send_table_data( $data, $stored_id = null ) {
		global $wpdb;
		try {
			if ( ! is_null( $stored_id ) ) {
				$wpdb->update(
					$wpdb->prefix . ACTIVECAMPAIGN_FOR_WOOCOMMERCE_ABANDONED_CART_NAME,
					$data,
					[
						'id' => $stored_id,
					]
				);

			} else {
				$wpdb->insert(
					$wpdb->prefix . ACTIVECAMPAIGN_FOR_WOOCOMMERCE_ABANDONED_CART_NAME,
					$data
				);
			}

			if ( $wpdb->last_error ) {
				$this->logger->error(
					'Save abandoned cart command: There was an error creating/updating an abandoned cart record.',
					[
						'wpdb_last_error' => $wpdb->last_error,
						'data'            => $data,
						'stored_id'       => $stored_id,
					]
				);
			}
		} catch ( Throwable $t ) {
			$this->logger->error(
				'Save abandoned cart command: There was an error attempting to save this abandoned cart',
				[
					'message'       => $t->getMessage(),
					'stored_id'     => $stored_id,
					'customer_data' => $data,
					'trace'         => $this->logger->clean_trace( $t->getTrace() ),
				]
			);
		}
	}
}
