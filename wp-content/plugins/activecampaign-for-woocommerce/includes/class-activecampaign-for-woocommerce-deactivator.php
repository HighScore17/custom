<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://www.activecampaign.com/
 * @since      1.0.0
 *
 * @package    Activecampaign_For_Woocommerce
 * @subpackage Activecampaign_For_Woocommerce/includes
 */

use Activecampaign_For_Woocommerce_Logger as Logger;

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Activecampaign_For_Woocommerce
 * @subpackage Activecampaign_For_Woocommerce/includes
 * @author     acteamintegrations <team-integrations@activecampaign.com>
 */
class Activecampaign_For_Woocommerce_Deactivator {

	/**
	 * The custom ActiveCampaign logger
	 *
	 * @var Activecampaign_For_Woocommerce_Logger
	 */
	private $logger;

	/**
	 * Deactivation script.
	 *
	 * @since    1.0.0
	 */
	public function deactivate() {
		$this->logger = new Logger();
		// Should we clean the table out on deactivation?
		$this->logger->info( 'Deactivation running...' );

		if ( wp_next_scheduled( 'activecampaign_for_woocommerce_cart_updated_recurring_event' ) ) {
			$this->logger->info( 'Clearing our scheduled event...' );

			wp_clear_scheduled_hook( 'activecampaign_for_woocommerce_cart_updated_recurring_event' );

			if ( function_exists( 'wp_get_scheduled_event' ) ) {
				$this->logger->info(
					'Verify that the scheduled event was removed...',
					[
						'activecampaign_for_woocommerce_cart_updated_recurring_event' => wp_get_scheduled_event( 'activecampaign_for_woocommerce_cart_updated_recurring_event' ),
					]
				);
			}
		} else {
			$this->logger->info( 'No event scheduled. Nothing to deactivate.' );
		}

		$this->logger->info( 'ActiveCampaign for WooCommerce Deactivated.' );
	}

}
