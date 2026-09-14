<?php
/**
 * Read-only admin pages for recipes and the component ledger (Host mode).
 *
 * The desktop app authors recipes; wp-admin only shows what the hub holds,
 * lets an administrator acknowledge an unresolved ledger row, and can run
 * the derived recompute by hand.
 *
 * @package Hashy_AU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hashy_AU_Recipes_Admin {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_wcss_ledger_ack', array( $this, 'handle_ack' ) );
		add_action( 'admin_post_wcss_recipes_recompute', array( $this, 'handle_recompute' ) );
	}

	public function register_menu(): void {
		add_submenu_page( 'wcss', 'Recipes', 'Recipes', 'manage_woocommerce', 'wcss-recipes', array( $this, 'render_recipes_page' ) );
		add_submenu_page( 'wcss', 'Component ledger', 'Component ledger', 'manage_woocommerce', 'wcss-component-ledger', array( $this, 'render_ledger_page' ) );
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		if ( 'host' !== Hashy_AU_Settings::instance()->get_mode() ) {
			wp_die( 'Host mode only', 400 );
		}
	}

	public function handle_ack(): void {
		$this->guard();
		check_admin_referer( 'wcss_ledger_ack' );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( $id > 0 ) {
			Hashy_AU_Recipes_API::acknowledge( $id, get_current_user_id() );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'wcss-component-ledger', 'wcss_msg' => 'acked' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_recompute(): void {
		$this->guard();
		check_admin_referer( 'wcss_recipes_recompute' );
		$result = Hashy_AU_Recipes::instance()->recompute_all();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'wcss-recipes',
					'wcss_msg' => 'recomputed',
					'changed'  => (int) $result['changed'],
					'queued'   => (int) $result['queued'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_recipes_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$set  = Hashy_AU_Recipes::instance()->get_set();
		$mode = Hashy_AU_Settings::instance()->get_mode();
		echo '<div class="wrap"><h1>Recipes</h1>';
		if ( 'host' !== $mode ) {
			echo '<p>Recipes live on the Host. This site is an Agent.</p></div>';
			return;
		}
		if ( isset( $_GET['wcss_msg'] ) && 'recomputed' === sanitize_key( (string) wp_unslash( $_GET['wcss_msg'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			printf( '<div class="notice notice-success"><p>Derived figures recomputed: %d changed, %d queued for cron.</p></div>', isset( $_GET['changed'] ) ? (int) $_GET['changed'] : 0, isset( $_GET['queued'] ) ? (int) $_GET['queued'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<p>What arrows are made of, as pushed from the Solkarra Desktop App. Recipes are authored there, never here; a pushed set replaces this one whole.</p>';
		if ( '' === (string) $set['hash'] ) {
			echo '<p><em>No recipe set has been pushed yet.</em></p></div>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		printf( '<tr><th style="width:180px">Set hash</th><td><code>%s</code></td></tr>', esc_html( (string) $set['hash'] ) );
		printf( '<tr><th>Pushed</th><td>%s UTC by user #%d (%s)</td></tr>', esc_html( (string) $set['updated_at'] ), (int) $set['updated_by'], esc_html( (string) $set['source'] ) );
		$alias_bits = array();
		foreach ( (array) $set['aliases'] as $role => $names ) {
			$alias_bits[] = esc_html( (string) $role ) . ': ' . esc_html( implode( ', ', (array) $names ) );
		}
		printf( '<tr><th>Role aliases</th><td>%s</td></tr>', implode( '<br>', $alias_bits ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</tbody></table><br>';

		echo '<table class="widefat striped"><thead><tr><th>Assembled</th><th>Component</th><th>Per unit</th><th>Matched on</th><th>Fixed</th><th>Mode</th><th>Derives</th><th>Note</th></tr></thead><tbody>';
		foreach ( (array) $set['recipes'] as $row ) {
			$component = (string) ( $row['component_sku'] ?? '' );
			if ( isset( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
				$parts = array();
				foreach ( (array) $row['value_map']['map'] as $value => $parent ) {
					$parts[] = $value . ' &rarr; ' . $parent;
				}
				$component = 'by ' . (string) $row['value_map']['role'] . ': ' . implode( ', ', $parts );
				if ( ! empty( $row['value_map']['default_parent'] ) ) {
					$component .= ' (default ' . (string) $row['value_map']['default_parent'] . ')';
				}
			}
			$fixed = array();
			foreach ( (array) ( $row['fixed'] ?? array() ) as $role => $value ) {
				$fixed[] = $role . ' = ' . $value;
			}
			foreach ( (array) ( $row['default_attrs'] ?? array() ) as $role => $value ) {
				$fixed[] = $role . ' defaults ' . $value;
			}
			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['assembled_sku'] ),
				esc_html( $component ),
				(int) $row['qty_per'],
				esc_html( implode( ', ', (array) ( $row['match'] ?? array() ) ) ),
				esc_html( implode( '; ', $fixed ) ),
				esc_html( (string) $row['mode'] ),
				! empty( $row['derives'] ) ? 'yes' : 'no',
				esc_html( (string) ( $row['note'] ?? '' ) )
			);
		}
		echo '</tbody></table>';

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=wcss_recipes_recompute' ), 'wcss_recipes_recompute' );
		printf( '<p><a class="button" href="%s">Recompute derived figures now</a> <span class="description">Sets every live, deriving arrow to what its shafts allow and pushes the changes.</span></p>', esc_url( $url ) );
		echo '</div>';
	}

	public function render_ledger_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		echo '<div class="wrap"><h1>Component ledger</h1>';
		if ( 'host' !== Hashy_AU_Settings::instance()->get_mode() ) {
			echo '<p>The ledger lives on the Host. This site is an Agent.</p></div>';
			return;
		}
		if ( ! Hashy_AU_Ledger::table_exists() ) {
			echo '<p>The ledger table has not been created yet; reload once.</p></div>';
			return;
		}
		$unresolved_only = isset( $_GET['unresolved'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['unresolved'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter on a read-only page.
		if ( isset( $_GET['wcss_msg'] ) && 'acked' === sanitize_key( (string) wp_unslash( $_GET['wcss_msg'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>Row acknowledged.</p></div>';
		}
		$count = Hashy_AU_Ledger::count_unresolved();
		printf( '<p>Every arrow line that reached the Host, and what it consumed. <strong>%d</strong> unresolved row(s) not yet acknowledged.</p>', (int) $count );
		printf(
			'<p><a class="button%s" href="%s">All rows</a> <a class="button%s" href="%s">Unresolved only</a></p>',
			$unresolved_only ? '' : ' button-primary',
			esc_url( admin_url( 'admin.php?page=wcss-component-ledger' ) ),
			$unresolved_only ? ' button-primary' : '',
			esc_url( admin_url( 'admin.php?page=wcss-component-ledger&unresolved=1' ) )
		);
		$rows = Hashy_AU_Ledger::recent( 200, $unresolved_only );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>When (UTC)</th><th>Site</th><th>Order</th><th>Arrow</th><th>Qty</th><th>Component</th><th>Took</th><th>Matched</th><th>Mode</th><th>Applied</th><th>Reversed</th><th></th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="13"><em>Nothing yet.</em></td></tr>';
		}
		foreach ( $rows as $row ) {
			$site   = (string) wp_parse_url( (string) $row['site'], PHP_URL_HOST );
			$status = (string) $row['matched'];
			if ( in_array( $status, array( 'unresolved', 'ambiguous' ), true ) ) {
				$status .= ' (' . (string) $row['reason'] . ')';
				if ( ! empty( $row['wanted'] ) ) {
					$status .= ' wanted ' . wp_json_encode( $row['wanted'] );
				}
			}
			$ack = '';
			if ( in_array( (string) $row['matched'], array( 'unresolved', 'ambiguous' ), true ) ) {
				$ack = empty( $row['acknowledged_at'] )
					? '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcss_ledger_ack&id=' . (int) $row['id'] ), 'wcss_ledger_ack' ) ) . '">Acknowledge</a>'
					: '<span class="description">acknowledged</span>';
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>#%d / %s</td><td><code>%s</code></td><td>%d</td><td><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				(int) $row['id'],
				esc_html( (string) $row['created_at'] ),
				esc_html( $site ),
				(int) $row['order_id'],
				esc_html( (string) $row['line_id'] ),
				esc_html( (string) $row['arrow_sku'] ),
				(int) $row['line_qty'],
				esc_html( (string) $row['component_sku'] ),
				(int) $row['qty'],
				esc_html( $status ),
				esc_html( (string) $row['mode'] ),
				! empty( $row['applied'] ) ? 'yes' : 'no',
				(int) $row['reversed_qty'],
				$ack // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			);
		}
		echo '</tbody></table></div>';
	}
}
