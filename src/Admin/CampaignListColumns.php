<?php
/**
 * Adds Start / End / Goal / Donation Product columns to the Campaigns
 * list table at wp-admin/edit.php?post_type=giving_campaign.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.5.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\PostTypes\Campaign;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the columns + their renderers + their sort wiring.
 */
final class CampaignListColumns {

	private const COL_START    = 'gd_start';
	private const COL_END      = 'gd_end';
	private const COL_GOAL     = 'gd_goal';
	private const COL_PRODUCTS = 'gd_donation_products';

	/**
	 * Name of the inline-edit nonce action that Quick Edit submits.
	 */
	private const QUICK_EDIT_NONCE_ACTION = 'inlineeditnonce';

	/**
	 * Hooks the admin filters. Idempotent.
	 */
	public function register(): void {
		$pt = Campaign::POST_TYPE;
		add_filter( "manage_{$pt}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_cell' ), 10, 2 );
		add_filter( "manage_edit-{$pt}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_sort' ) );

		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_fields' ), 10, 2 );
		add_action( "save_post_{$pt}", array( $this, 'save_quick_edit' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_quick_edit_script' ) );
	}

	/**
	 * Inserts the four new columns between Title and Date.
	 *
	 * @param array<string,string> $columns Existing columns keyed by id.
	 * @return array<string,string>
	 */
	public function columns( array $columns ): array {
		$inserted = array(
			self::COL_START    => __( 'Start', 'giving-day-blocks' ),
			self::COL_END      => __( 'End', 'giving-day-blocks' ),
			self::COL_GOAL     => __( 'Goal', 'giving-day-blocks' ),
			self::COL_PRODUCTS => __( 'Donation product', 'giving-day-blocks' ),
		);

		// Splice the new columns in just before "date" if present, otherwise
		// append. Preserves checkbox/title/author ordering as-is.
		if ( isset( $columns['date'] ) ) {
			$out = array();
			foreach ( $columns as $key => $label ) {
				if ( 'date' === $key ) {
					$out += $inserted;
				}
				$out[ $key ] = $label;
			}
			return $out;
		}
		return $columns + $inserted;
	}

	/**
	 * Renders a single cell. WordPress calls this once per row per custom column.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Campaign post ID.
	 */
	public function render_cell( string $column, int $post_id ): void {
		switch ( $column ) {
			case self::COL_START:
				echo esc_html( $this->format_datetime( $post_id, Campaign::META_START_DATETIME ) );
				$this->emit_raw_value( 'gd-start', (string) get_post_meta( $post_id, Campaign::META_START_DATETIME, true ) );
				return;

			case self::COL_END:
				echo esc_html( $this->format_datetime( $post_id, Campaign::META_END_DATETIME ) );
				$this->emit_raw_value( 'gd-end', (string) get_post_meta( $post_id, Campaign::META_END_DATETIME, true ) );
				return;

			case self::COL_GOAL:
				echo esc_html( $this->format_goal( $post_id ) );
				$this->emit_raw_value( 'gd-goal', (string) get_post_meta( $post_id, Campaign::META_GOAL_AMOUNT, true ) );
				return;

			case self::COL_PRODUCTS:
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built with esc_* helpers below.
				echo $this->render_products( $post_id );
				return;
		}
	}

	/**
	 * Emits a hidden span that carries the raw value of a meta field; the
	 * Quick Edit JS reads these and populates the inline-edit form so the
	 * admin sees the current values instead of empty inputs.
	 *
	 * @param string $key   Short identifier used by the JS lookup.
	 * @param string $value Raw meta value (already trusted; from get_post_meta).
	 */
	private function emit_raw_value( string $key, string $value ): void {
		printf(
			'<span class="hidden giving-day-quick-edit-data" data-key="%s">%s</span>',
			esc_attr( $key ),
			esc_html( $value )
		);
	}

	/**
	 * Marks Start, End, and Goal as sortable. Donation product is left as a
	 * free-text list — sorting by an array of IDs isn't meaningful.
	 *
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns[ self::COL_START ] = self::COL_START;
		$columns[ self::COL_END ]   = self::COL_END;
		$columns[ self::COL_GOAL ]  = self::COL_GOAL;
		return $columns;
	}

	/**
	 * Wires the sortable columns into the list-table query.
	 *
	 * @param WP_Query $query
	 */
	public function apply_sort( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( Campaign::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = (string) $query->get( 'orderby' );
		switch ( $orderby ) {
			case self::COL_START:
				$query->set( 'meta_key', Campaign::META_START_DATETIME );
				$query->set( 'orderby', 'meta_value' );
				break;
			case self::COL_END:
				$query->set( 'meta_key', Campaign::META_END_DATETIME );
				$query->set( 'orderby', 'meta_value' );
				break;
			case self::COL_GOAL:
				$query->set( 'meta_key', Campaign::META_GOAL_AMOUNT );
				$query->set( 'orderby', 'meta_value_num' );
				break;
		}
	}

	/**
	 * Formats a datetime meta using the site's date + time formats.
	 *
	 * @param int    $post_id  Campaign post ID.
	 * @param string $meta_key META_START_DATETIME or META_END_DATETIME.
	 */
	private function format_datetime( int $post_id, string $meta_key ): string {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );
		if ( '' === $raw ) {
			return '—';
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return $raw;
		}
		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		return wp_date( $format, $ts );
	}

	/**
	 * Formats the goal amount using the campaign's own currency meta when
	 * set, falling back to the WooCommerce default.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	private function format_goal( int $post_id ): string {
		$goal = (float) get_post_meta( $post_id, Campaign::META_GOAL_AMOUNT, true );
		if ( $goal <= 0 ) {
			return '—';
		}
		$currency = (string) get_post_meta( $post_id, Campaign::META_CURRENCY, true );
		if ( '' === $currency ) {
			$currency = (string) get_option( 'woocommerce_currency', 'USD' );
		}
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $goal, array( 'currency' => $currency ) ) );
		}
		return sprintf( '%s %s', $currency, number_format_i18n( $goal ) );
	}

	/**
	 * Renders the donation-products cell as edit-screen links. When more than
	 * one product is attached we show all of them — Campaigns rarely have
	 * more than 2–3, so a stacked list is more useful than a truncated count.
	 *
	 * @param int $post_id Campaign post ID.
	 */
	private function render_products( int $post_id ): string {
		$ids = get_post_meta( $post_id, Campaign::META_DONATION_PRODUCTS, true );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return '<span aria-hidden="true">—</span>';
		}
		$links = array();
		foreach ( $ids as $id ) {
			$pid = absint( $id );
			if ( $pid <= 0 ) {
				continue;
			}
			$title = get_the_title( $pid );
			if ( '' === $title ) {
				continue;
			}
			if ( current_user_can( 'edit_post', $pid ) ) {
				$links[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( get_edit_post_link( $pid ) ),
					esc_html( $title )
				);
			} else {
				$links[] = esc_html( $title );
			}
		}
		if ( empty( $links ) ) {
			return '<span aria-hidden="true">—</span>';
		}
		return implode( '<br />', $links );
	}

	/**
	 * Renders Start / End / Goal inputs inside Quick Edit. WordPress fires
	 * this hook once per custom column per row, but the markup is only
	 * emitted into the inline-edit row once per column id; values are
	 * populated by `enqueue_quick_edit_script()` from the column cells.
	 *
	 * Donation product is intentionally skipped — it's a multi-value field
	 * and Quick Edit's confined UI is the wrong place to host a product
	 * picker. Editors keep using the full Edit screen for that.
	 *
	 * @param string $column_name Column id WordPress is rendering.
	 * @param string $post_type   Current screen's post type.
	 */
	public function render_quick_edit_fields( string $column_name, string $post_type ): void {
		if ( Campaign::POST_TYPE !== $post_type ) {
			return;
		}
		switch ( $column_name ) {
			case self::COL_START:
				wp_nonce_field( self::QUICK_EDIT_NONCE_ACTION, 'giving_day_quick_edit_nonce' );
				$this->quick_edit_datetime_field( 'gd_start', __( 'Start', 'giving-day-blocks' ) );
				return;

			case self::COL_END:
				$this->quick_edit_datetime_field( 'gd_end', __( 'End', 'giving-day-blocks' ) );
				return;

			case self::COL_GOAL:
				$this->quick_edit_goal_field();
				return;
		}
	}

	/**
	 * Renders a labeled `datetime-local` input wrapped in the Quick Edit
	 * fieldset markup core uses, so the layout matches built-in fields.
	 */
	private function quick_edit_datetime_field( string $name, string $label ): void {
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php echo esc_html( $label ); ?></span>
					<span class="input-text-wrap">
						<input
							type="datetime-local"
							name="<?php echo esc_attr( $name ); ?>"
							class="giving-day-quick-edit-field"
							data-key="<?php echo esc_attr( str_replace( '_', '-', $name ) ); ?>"
							step="1"
							value=""
						/>
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	private function quick_edit_goal_field(): void {
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Goal', 'giving-day-blocks' ); ?></span>
					<span class="input-text-wrap">
						<input
							type="number"
							name="gd_goal"
							class="giving-day-quick-edit-field"
							data-key="gd-goal"
							min="0"
							step="any"
							value=""
						/>
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Persists the Quick Edit values. Skips when this isn't an inline-edit
	 * request, when the nonce is missing/invalid, or when the user can't
	 * edit the post.
	 *
	 * @param int      $post_id Campaign post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_quick_edit( int $post_id, \WP_Post $post ): void {
		// Bail on autosaves and revisions; Quick Edit fires save_post with
		// a normal context but autosave/cron should leave our meta alone.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( Campaign::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handled below.
		$nonce = isset( $_POST['giving_day_quick_edit_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['giving_day_quick_edit_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::QUICK_EDIT_NONCE_ACTION ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( array_key_exists( 'gd_start', $_POST ) ) {
			$this->save_datetime_meta(
				$post_id,
				Campaign::META_START_DATETIME,
				sanitize_text_field( wp_unslash( $_POST['gd_start'] ) )
			);
		}
		if ( array_key_exists( 'gd_end', $_POST ) ) {
			$this->save_datetime_meta(
				$post_id,
				Campaign::META_END_DATETIME,
				sanitize_text_field( wp_unslash( $_POST['gd_end'] ) )
			);
		}
		if ( array_key_exists( 'gd_goal', $_POST ) ) {
			$raw  = sanitize_text_field( wp_unslash( $_POST['gd_goal'] ) );
			$goal = '' === $raw ? '' : (float) $raw;
			update_post_meta( $post_id, Campaign::META_GOAL_AMOUNT, $goal );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Normalizes a datetime-local input back into the `YYYY-MM-DDTHH:MM:SS`
	 * format the rest of the plugin reads from this meta key. Empty input
	 * clears the meta.
	 */
	private function save_datetime_meta( int $post_id, string $meta_key, string $raw ): void {
		if ( '' === $raw ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return;
		}
		update_post_meta( $post_id, $meta_key, gmdate( 'Y-m-d\TH:i:s', $ts ) );
	}

	/**
	 * Loads a small inline script on the Campaigns list screen that copies
	 * each row's hidden meta values into the Quick Edit form when the admin
	 * opens it. Without this hook the inputs would render blank every time.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue_quick_edit_script( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Campaign::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$script = <<<'JS'
( function ( $ ) {
	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}
	const originalEdit = inlineEditPost.edit;
	inlineEditPost.edit = function ( id ) {
		const ret = originalEdit.apply( this, arguments );
		const postId = typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : id;
		if ( ! postId ) {
			return ret;
		}
		const $row = $( '#post-' + postId );
		const $edit = $( '#edit-' + postId );
		if ( ! $row.length || ! $edit.length ) {
			return ret;
		}
		const values = {};
		$row.find( '.giving-day-quick-edit-data' ).each( function () {
			const key = $( this ).data( 'key' );
			if ( key ) {
				values[ key ] = $( this ).text();
			}
		} );
		$edit.find( '.giving-day-quick-edit-field' ).each( function () {
			const key = $( this ).data( 'key' );
			if ( key && values.hasOwnProperty( key ) ) {
				$( this ).val( values[ key ] );
			}
		} );
		return ret;
	};
} )( jQuery );
JS;

		wp_add_inline_script( 'inline-edit-post', $script );
	}
}
