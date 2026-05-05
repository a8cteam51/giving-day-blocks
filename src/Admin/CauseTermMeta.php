<?php
/**
 * Term-edit-screen UI for the Cause Area image meta field.
 *
 * @package Team51\GivingDay\Admin
 * @since   0.3.0
 */

namespace Team51\GivingDay\Admin;

use Team51\GivingDay\Taxonomies\Cause;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Cause Area image" media-uploader field on the giving_cause
 * Add-Term and Edit-Term screens, persists the chosen attachment ID, and
 * enqueues a small media-frame helper script.
 *
 * The field surface uses the public-facing wording ("Cause Area image") to
 * match the front-end block; the underlying taxonomy and slug remain
 * `giving_cause`.
 */
final class CauseTermMeta {

	private const HANDLE = 'giving-day-blocks-cause-term-meta';
	private const NONCE  = 'giving_day_save_cause_image';

	/**
	 * Registers the term-screen UI hooks.
	 */
	public function register(): void {
		$taxonomy = Cause::TAXONOMY;
		add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_form_field' ) );
		add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_form_field' ) );
		add_action( "created_{$taxonomy}", array( $this, 'save_term_meta' ) );
		add_action( "edited_{$taxonomy}", array( $this, 'save_term_meta' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Renders the field on the "Add new Cause" screen.
	 */
	public function render_add_form_field(): void {
		wp_nonce_field( self::NONCE, 'giving_day_cause_image_nonce' );
		?>
		<div class="form-field giving-day-cause-image-field">
			<label for="giving-day-cause-image-id">
				<?php esc_html_e( 'Cause Area image', 'giving-day-blocks' ); ?>
			</label>
			<div class="giving-day-cause-image-preview" data-empty="1" style="margin-bottom:0.5em;max-width:240px;">
				<span class="giving-day-cause-image-placeholder" style="display:inline-block;padding:0.5em;border:1px dashed #c3c4c7;color:#646970;">
					<?php esc_html_e( 'No image set.', 'giving-day-blocks' ); ?>
				</span>
			</div>
			<p>
				<button type="button" class="button giving-day-cause-image-choose">
					<?php esc_html_e( 'Choose image', 'giving-day-blocks' ); ?>
				</button>
				<button type="button" class="button-link giving-day-cause-image-remove" hidden>
					<?php esc_html_e( 'Remove image', 'giving-day-blocks' ); ?>
				</button>
			</p>
			<input
				type="hidden"
				name="<?php echo esc_attr( Cause::META_IMAGE_ID ); ?>"
				id="giving-day-cause-image-id"
				value=""
			/>
			<p class="description">
				<?php esc_html_e( 'Shown as the card thumbnail when this Cause Area appears in the Cause Areas Browser block.', 'giving-day-blocks' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the field on the "Edit Cause" screen.
	 *
	 * @param WP_Term $term The term being edited.
	 */
	public function render_edit_form_field( WP_Term $term ): void {
		$attachment_id = Cause::get_image_id( (int) $term->term_id );
		$thumb_url     = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		wp_nonce_field( self::NONCE, 'giving_day_cause_image_nonce' );
		?>
		<tr class="form-field giving-day-cause-image-field">
			<th scope="row">
				<label for="giving-day-cause-image-id">
					<?php esc_html_e( 'Cause Area image', 'giving-day-blocks' ); ?>
				</label>
			</th>
			<td>
				<div class="giving-day-cause-image-preview" data-empty="<?php echo $thumb_url ? '0' : '1'; ?>" style="margin-bottom:0.5em;max-width:240px;">
					<?php if ( $thumb_url ) : ?>
						<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="max-width:100%;height:auto;display:block;" />
					<?php else : ?>
						<span class="giving-day-cause-image-placeholder" style="display:inline-block;padding:0.5em;border:1px dashed #c3c4c7;color:#646970;">
							<?php esc_html_e( 'No image set.', 'giving-day-blocks' ); ?>
						</span>
					<?php endif; ?>
				</div>
				<p>
					<button type="button" class="button giving-day-cause-image-choose">
						<?php
						echo $thumb_url
							? esc_html__( 'Replace image', 'giving-day-blocks' )
							: esc_html__( 'Choose image', 'giving-day-blocks' );
						?>
					</button>
					<button type="button" class="button-link giving-day-cause-image-remove" <?php echo $thumb_url ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Remove image', 'giving-day-blocks' ); ?>
					</button>
				</p>
				<input
					type="hidden"
					name="<?php echo esc_attr( Cause::META_IMAGE_ID ); ?>"
					id="giving-day-cause-image-id"
					value="<?php echo esc_attr( (string) $attachment_id ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'Shown as the card thumbnail when this Cause Area appears in the Cause Areas Browser block.', 'giving-day-blocks' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persists the chosen attachment ID on term create / update.
	 *
	 * @param int $term_id The term being saved.
	 */
	public function save_term_meta( int $term_id ): void {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		if ( ! isset( $_POST['giving_day_cause_image_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['giving_day_cause_image_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! isset( $_POST[ Cause::META_IMAGE_ID ] ) ) {
			return;
		}

		$attachment_id = absint( wp_unslash( $_POST[ Cause::META_IMAGE_ID ] ) );

		if ( $attachment_id > 0 ) {
			update_term_meta( $term_id, Cause::META_IMAGE_ID, $attachment_id );
		} else {
			delete_term_meta( $term_id, Cause::META_IMAGE_ID );
		}
	}

	/**
	 * Enqueues the media-frame helper on Cause taxonomy edit screens only.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Cause::TAXONOMY !== $screen->taxonomy ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			self::HANDLE,
			GIVING_DAY_BLOCKS_URL . 'assets/admin-cause-term/index.js',
			array( 'wp-i18n', 'media-editor' ),
			GIVING_DAY_BLOCKS_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'giving-day-blocks' );
	}
}
