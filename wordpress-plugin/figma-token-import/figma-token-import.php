<?php
/**
 * Plugin Name: Figma Token Import
 * Plugin URI:  https://example.com/figma-token-import
 * Description: Import color and typography design tokens exported from the companion Figma plugin into the active Elementor kit. Offline and copy-paste based — paste the JSON, preview it, then confirm to write Global Colors and Global Fonts. No network calls, no REST routes.
 * Version:     1.0.0
 * Author:      Figma → Elementor
 * License:     GPL-2.0-or-later
 * Text Domain: figma-token-import
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

class Figma_Token_Import {

	const MENU_SLUG    = 'figma-token-import';
	const NONCE_ACTION = 'figma_token_import_action';
	const NONCE_NAME   = 'figma_token_import_nonce';
	const ADMIN_ACTION = 'figma_token_import';

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_import' ) );
	}

	/**
	 * Load translations from /languages (e.g. figma-token-import-fa_IR.mo).
	 * Hooked to `init` so it runs before the admin page and admin-post handler.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'figma-token-import',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Admin menu                                                          */
	/* ------------------------------------------------------------------ */

	public function add_menu() {
		add_options_page(
			__( 'Figma Token Import', 'figma-token-import' ),
			__( 'Figma Token Import', 'figma-token-import' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sanitizers                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise a color to #rrggbb, or return '' if it cannot be parsed.
	 * Accepts #rgb, rgb, #rrggbb, rrggbb, and rgb()/rgba() functional form.
	 */
	private function sanitize_hex( $value ) {
		$value = strtolower( trim( (string) $value ) );

		// Functional rgb()/rgba() form -> hex.
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $value, $m ) ) {
			$r = max( 0, min( 255, (int) $m[1] ) );
			$g = max( 0, min( 255, (int) $m[2] ) );
			$b = max( 0, min( 255, (int) $m[3] ) );
			return sprintf( '#%02x%02x%02x', $r, $g, $b );
		}

		$value = ltrim( $value, '#' );

		// Expand 3-digit shorthand.
		if ( preg_match( '/^[0-9a-f]{3}$/', $value ) ) {
			$value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
		}

		if ( preg_match( '/^[0-9a-f]{6}$/', $value ) ) {
			return '#' . $value;
		}

		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Notices (transient-backed, survive the post/redirect)               */
	/* ------------------------------------------------------------------ */

	private function notice_key() {
		return 'figma_token_import_notice_' . get_current_user_id();
	}

	private function set_notice( $type, $message ) {
		set_transient( $this->notice_key(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	private function redirect_back( $type, $message ) {
		$this->set_notice( $type, $message );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Environment helpers                                                 */
	/* ------------------------------------------------------------------ */

	private function elementor_active() {
		return did_action( 'elementor/loaded' );
	}

	private function active_kit_id() {
		$kit_id = get_option( 'elementor_active_kit' );
		return $kit_id ? absint( $kit_id ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* Screen 1 (paste) + Screen 2 (preview, rendered client-side)         */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'figma-token-import' ) );
		}

		// Show and clear any pending notice.
		$notice = get_transient( $this->notice_key() );
		if ( $notice ) {
			delete_transient( $this->notice_key() );
			$class = ( 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
			printf(
				'<div class="notice %s is-dismissible"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( $notice['message'] )
			);
		}

		$elementor_ok = $this->elementor_active();
		$kit_id       = $this->active_kit_id();
		?>
		<div class="wrap figma-token-import">
			<h1><?php esc_html_e( 'Figma Token Import', 'figma-token-import' ); ?></h1>

			<?php if ( ! $elementor_ok ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'Elementor is not active. Activate Elementor before importing tokens.', 'figma-token-import' ); ?>
				</p></div>
			<?php elseif ( ! $kit_id ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'No active Elementor kit was found. Open Elementor → Site Settings once to create a kit, then try again.', 'figma-token-import' ); ?>
				</p></div>
			<?php endif; ?>

			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Paste the JSON exported from the Figma plugin, click Preview to review the colors and fonts, then Confirm & Import to write them into the active Elementor kit. Nothing is saved until you confirm.', 'figma-token-import' ); ?>
			</p>

			<form id="figma-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<h2><?php esc_html_e( 'Paste the JSON exported from the Figma plugin', 'figma-token-import' ); ?></h2>
				<textarea id="figma-json" name="figma_json" rows="12" spellcheck="false" dir="ltr"
					style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;text-align:left;"
					placeholder='{ "version": 1, "systemColors": { … }, "customColors": [ … ], "typography": { … } }'></textarea>

				<p>
					<button type="button" id="figma-preview-btn" class="button button-primary"><?php esc_html_e( 'Preview', 'figma-token-import' ); ?></button>
				</p>

				<div id="figma-error" class="notice notice-error" style="display:none;"><p></p></div>

				<div id="figma-preview-area" style="display:none;"></div>

				<div id="figma-confirm-bar" style="display:none;margin-top:20px;">
					<button type="submit" id="figma-confirm-btn" class="button button-primary button-hero"<?php echo ( $elementor_ok && $kit_id ) ? '' : ' disabled'; ?>><?php esc_html_e( 'Confirm & Import', 'figma-token-import' ); ?></button>
					<button type="button" id="figma-cancel-btn" class="button button-hero"><?php esc_html_e( 'Cancel', 'figma-token-import' ); ?></button>
				</div>
			</form>

			<p class="description" style="max-width:820px;margin-top:24px;">
				<strong><?php esc_html_e( 'Note about fonts:', 'figma-token-import' ); ?></strong>
				<?php esc_html_e( 'Only the font family names are written into Elementor. Custom Persian fonts such as IRANYekanX or Shazde are not Google Fonts — upload the actual font files separately (Elementor → Custom Fonts, or a font plugin) so the site can display them.', 'figma-token-import' ); ?>
			</p>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'This importer targets the Elementor Classic / Editor V3 kit structure (system_colors, custom_colors, system_typography). If the Editor V4 (atomic) experiment is enabled, the kit structure differs and the results may not appear as expected.', 'figma-token-import' ); ?>
			</p>
		</div>

		<?php
		// Every JS-facing string is translated here in PHP and handed to the
		// inline script below. That way a single .mo file (no separate JS
		// translation JSON) localises both the PHP and the JavaScript UI.
		$i18n = array(
			'invalidJson'   => __( 'Invalid JSON — could not parse:', 'figma-token-import' ),
			'notObject'     => __( 'The JSON must be an object matching the Figma export contract.', 'figma-token-import' ),
			'systemColors'  => __( 'System colors', 'figma-token-import' ),
			'customColors'  => __( 'Custom colors', 'figma-token-import' ),
			/* translators: %d is the number of custom colors. */
			'colorsCount'   => __( '%d colors', 'figma-token-import' ),
			'typography'    => __( 'Typography', 'figma-token-import' ),
			'slotPrimary'   => __( 'Primary', 'figma-token-import' ),
			'slotSecondary' => __( 'Secondary', 'figma-token-import' ),
			'slotText'      => __( 'Text', 'figma-token-import' ),
			'slotAccent'    => __( 'Accent', 'figma-token-import' ),
			/* translators: keep the <code> tags around the font names. */
			'familyNote'    => __( 'You can correct the family names below before importing (for example the body variable says <code>IRANYekanX</code> but text styles may use <code>IRANYekanXFaNum</code>).', 'figma-token-import' ),
			'titleFamily'   => __( 'Title family', 'figma-token-import' ),
			'bodyFamily'    => __( 'Body family', 'figma-token-import' ),
			'sampleTitle'   => __( 'The quick brown fox — 0123456789', 'figma-token-import' ),
			'sampleBody'    => __( 'The quick brown fox jumps over the lazy dog. یک نمونه متن فارسی برای پیش‌نمایش قلم بدنه.', 'figma-token-import' ),
			'slotsHeading'  => __( 'How the four Elementor typography slots will be filled', 'figma-token-import' ),
			'colSlot'       => __( 'Slot', 'figma-token-import' ),
			'colFamily'     => __( 'Family', 'figma-token-import' ),
			'colWeight'     => __( 'Weight', 'figma-token-import' ),
			/* translators: %1$d system colors, %2$d custom colors, %3$d typography slots, %4$d kit ID. */
			'summary'       => __( 'This will write %1$d system colors, %2$d custom colors, and %3$d typography slots to Elementor kit #%4$d.', 'figma-token-import' ),
			'blocked'       => __( 'Elementor or its active kit is unavailable, so importing is disabled. The preview above is still accurate.', 'figma-token-import' ),
		);
		?>

		<script>
		( function () {
			var KIT_ID       = <?php echo (int) $kit_id; ?>;
			var I18N         = <?php echo wp_json_encode( $i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ); ?>;
			var previewBtn   = document.getElementById( 'figma-preview-btn' );
			var textarea     = document.getElementById( 'figma-json' );
			var previewArea  = document.getElementById( 'figma-preview-area' );
			var confirmBar   = document.getElementById( 'figma-confirm-bar' );
			var confirmBtn   = document.getElementById( 'figma-confirm-btn' );
			var cancelBtn    = document.getElementById( 'figma-cancel-btn' );
			var errorBox     = document.getElementById( 'figma-error' );
			var kitBlocked   = <?php echo ( $elementor_ok && $kit_id ) ? 'false' : 'true'; ?>;

			function esc( s ) {
				var d = document.createElement( 'div' );
				d.textContent = ( s === undefined || s === null ) ? '' : String( s );
				return d.innerHTML;
			}

			// Mirror of the PHP hex sanitizer so the preview matches what is written.
			function normalizeHex( v ) {
				if ( v === undefined || v === null ) { return ''; }
				v = String( v ).trim().toLowerCase();
				var fn = v.match( /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/ );
				if ( fn ) {
					return '#' + [ fn[1], fn[2], fn[3] ].map( function ( n ) {
						n = Math.max( 0, Math.min( 255, parseInt( n, 10 ) ) ).toString( 16 );
						return n.length === 1 ? '0' + n : n;
					} ).join( '' );
				}
				v = v.replace( /^#/, '' );
				if ( /^[0-9a-f]{3}$/.test( v ) ) { v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2]; }
				if ( /^[0-9a-f]{6}$/.test( v ) ) { return '#' + v; }
				return '';
			}

			function showError( msg ) {
				errorBox.querySelector( 'p' ).textContent = msg;
				errorBox.style.display = 'block';
				previewArea.style.display = 'none';
				confirmBar.style.display = 'none';
			}

			function hideError() {
				errorBox.style.display = 'none';
			}

			function textColorFor( hex ) {
				var h = hex.replace( '#', '' );
				var r = parseInt( h.substr( 0, 2 ), 16 );
				var g = parseInt( h.substr( 2, 2 ), 16 );
				var b = parseInt( h.substr( 4, 2 ), 16 );
				var lum = ( 0.299 * r + 0.587 * g + 0.114 * b ) / 255;
				return lum > 0.6 ? '#1e1e1e' : '#ffffff';
			}

			previewBtn.addEventListener( 'click', function () {
				hideError();
				var data;
				try {
					data = JSON.parse( textarea.value );
				} catch ( e ) {
					showError( I18N.invalidJson + ' ' + e.message );
					return;
				}
				if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) {
					showError( I18N.notObject );
					return;
				}
				renderPreview( data );
			} );

			cancelBtn.addEventListener( 'click', function () {
				previewArea.style.display = 'none';
				confirmBar.style.display = 'none';
				hideError();
				textarea.focus();
			} );

			function renderPreview( data ) {
				var sys      = ( data.systemColors && typeof data.systemColors === 'object' ) ? data.systemColors : {};
				var custom   = Array.isArray( data.customColors ) ? data.customColors : [];
				var typo     = ( data.typography && typeof data.typography === 'object' ) ? data.typography : {};
				var titleFam = typo.titleFamily ? String( typo.titleFamily ) : '';
				var bodyFam  = typo.bodyFamily ? String( typo.bodyFamily ) : '';

				var html = '';

				/* ----- System colors ----- */
				var sysSlots = [
					{ id: 'primary',   label: I18N.slotPrimary },
					{ id: 'secondary', label: I18N.slotSecondary },
					{ id: 'text',      label: I18N.slotText },
					{ id: 'accent',    label: I18N.slotAccent }
				];
				var sysValidCount = 0;
				html += '<h2>' + esc( I18N.systemColors ) + '</h2><div class="fti-swatches fti-system">';
				sysSlots.forEach( function ( slot ) {
					var hex = normalizeHex( sys[ slot.id ] );
					if ( hex ) { sysValidCount++; }
					var shown = hex || '—';
					var bg = hex || '#f0f0f0';
					var fg = hex ? textColorFor( hex ) : '#a0a0a0';
					html += '<div class="fti-swatch"><div class="fti-chip" style="background:' + esc( bg ) + ';color:' + fg + ';">' + esc( shown ) + '</div>' +
						'<div class="fti-name">' + esc( slot.label ) + '</div></div>';
				} );
				html += '</div>';

				/* ----- Custom colors ----- */
				var customValid = [];
				custom.forEach( function ( c ) {
					if ( ! c || typeof c !== 'object' ) { return; }
					var hex = normalizeHex( c.hex );
					var name = ( c.name === undefined || c.name === null ) ? '' : String( c.name );
					if ( hex && name ) { customValid.push( { name: name, hex: hex } ); }
				} );
				html += '<h2 style="margin-top:24px;">' + esc( I18N.customColors ) +
					' <span class="fti-count">' + esc( I18N.colorsCount.replace( '%d', customValid.length ) ) + '</span></h2>';
				html += '<div class="fti-swatches fti-custom">';
				customValid.forEach( function ( c ) {
					var fg = textColorFor( c.hex );
					html += '<div class="fti-swatch"><div class="fti-chip" style="background:' + esc( c.hex ) + ';color:' + fg + ';">' + esc( c.hex ) + '</div>' +
						'<div class="fti-name" title="' + esc( c.name ) + '">' + esc( c.name ) + '</div></div>';
				} );
				html += '</div>';

				/* ----- Typography ----- */
				html += '<h2 style="margin-top:24px;">' + esc( I18N.typography ) + '</h2>';
				// familyNote intentionally carries <code> tags from the translation.
				html += '<p class="description">' + I18N.familyNote + '</p>';
				html += '<table class="form-table" role="presentation"><tbody>' +
					'<tr><th scope="row"><label for="fti-title-family">' + esc( I18N.titleFamily ) + '</label></th>' +
					'<td><input type="text" id="fti-title-family" name="title_family" class="regular-text" value="' + esc( titleFam ) + '"></td></tr>' +
					'<tr><th scope="row"><label for="fti-body-family">' + esc( I18N.bodyFamily ) + '</label></th>' +
					'<td><input type="text" id="fti-body-family" name="body_family" class="regular-text" value="' + esc( bodyFam ) + '"></td></tr>' +
					'</tbody></table>';

				html += '<div class="fti-type-samples">' +
					'<div class="fti-sample fti-sample-title">' + esc( I18N.sampleTitle ) + '</div>' +
					'<div class="fti-sample fti-sample-body">' + esc( I18N.sampleBody ) + '</div>' +
					'</div>';

				html += '<h3 style="margin-top:20px;">' + esc( I18N.slotsHeading ) + '</h3>';
				html += '<table class="widefat striped fti-typo-map" style="max-width:640px;"><thead><tr>' +
					'<th>' + esc( I18N.colSlot ) + '</th><th>' + esc( I18N.colFamily ) + '</th><th>' + esc( I18N.colWeight ) + '</th></tr></thead><tbody>' +
					'<tr><td>' + esc( I18N.slotPrimary ) + '</td><td class="fti-map-title">' + esc( titleFam || '—' ) + '</td><td>700</td></tr>' +
					'<tr><td>' + esc( I18N.slotSecondary ) + '</td><td class="fti-map-title">' + esc( titleFam || '—' ) + '</td><td>600</td></tr>' +
					'<tr><td>' + esc( I18N.slotText ) + '</td><td class="fti-map-body">' + esc( bodyFam || '—' ) + '</td><td>400</td></tr>' +
					'<tr><td>' + esc( I18N.slotAccent ) + '</td><td class="fti-map-body">' + esc( bodyFam || '—' ) + '</td><td>500</td></tr>' +
					'</tbody></table>';

				/* ----- Summary ----- */
				// Only families that are non-empty are written (mirrors the server),
				// so each present family contributes its two slots.
				var typoCount = ( titleFam ? 2 : 0 ) + ( bodyFam ? 2 : 0 );
				var summaryText = I18N.summary
					.replace( '%1$d', sysValidCount )
					.replace( '%2$d', customValid.length )
					.replace( '%3$d', typoCount )
					.replace( '%4$d', KIT_ID );
				html += '<p class="fti-summary" style="margin-top:20px;font-weight:600;">' + esc( summaryText ) + '</p>';

				if ( kitBlocked ) {
					html += '<p class="notice notice-warning" style="padding:8px 12px;">' + esc( I18N.blocked ) + '</p>';
				}

				previewArea.innerHTML = html;
				previewArea.style.display = 'block';
				confirmBar.style.display = 'block';

				// Live font preview + keep the mapping table in sync with edits.
				var titleInput = document.getElementById( 'fti-title-family' );
				var bodyInput  = document.getElementById( 'fti-body-family' );
				var titleSample = previewArea.querySelector( '.fti-sample-title' );
				var bodySample  = previewArea.querySelector( '.fti-sample-body' );
				var mapTitles   = previewArea.querySelectorAll( '.fti-map-title' );
				var mapBodies   = previewArea.querySelectorAll( '.fti-map-body' );

				function applyFonts() {
					var t = titleInput.value.trim();
					var b = bodyInput.value.trim();
					titleSample.style.fontFamily = t ? ( '"' + t + '", sans-serif' ) : 'sans-serif';
					bodySample.style.fontFamily  = b ? ( '"' + b + '", sans-serif' ) : 'sans-serif';
					mapTitles.forEach( function ( el ) { el.textContent = t || '—'; } );
					mapBodies.forEach( function ( el ) { el.textContent = b || '—'; } );
				}
				titleInput.addEventListener( 'input', applyFonts );
				bodyInput.addEventListener( 'input', applyFonts );
				applyFonts();

				confirmBar.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}
		} )();
		</script>

		<style>
			.figma-token-import .fti-swatches {
				display: grid;
				gap: 10px;
				margin-top: 10px;
			}
			.figma-token-import .fti-system { grid-template-columns: repeat(4, minmax(120px, 1fr)); max-width: 640px; }
			.figma-token-import .fti-custom { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
			.figma-token-import .fti-swatch {
				border: 1px solid #dcdcde;
				border-radius: 6px;
				overflow: hidden;
				background: #fff;
			}
			.figma-token-import .fti-chip {
				height: 54px;
				display: flex;
				align-items: center;
				justify-content: center;
				font-family: Menlo, Consolas, monospace;
				font-size: 11px;
				direction: ltr;
			}
			.figma-token-import .fti-name {
				padding: 6px 8px;
				font-size: 12px;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				direction: ltr;
				text-align: left;
			}
			.figma-token-import .fti-count {
				font-size: 12px;
				font-weight: 400;
				color: #646970;
				background: #f0f0f1;
				padding: 2px 8px;
				border-radius: 10px;
				vertical-align: middle;
			}
			.figma-token-import .fti-type-samples {
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 16px;
				margin-top: 12px;
				background: #fff;
			}
			.figma-token-import .fti-sample-title { font-size: 30px; line-height: 1.3; font-weight: 700; }
			.figma-token-import .fti-sample-body { font-size: 16px; line-height: 1.6; margin-top: 10px; }
		</style>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Confirm & Import (server write)                                     */
	/* ------------------------------------------------------------------ */

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'figma-token-import' ) );
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'figma-token-import' ) );
		}

		if ( ! $this->elementor_active() ) {
			$this->redirect_back( 'error', __( 'Elementor is not active. Nothing was imported.', 'figma-token-import' ) );
		}

		$kit_id = $this->active_kit_id();
		if ( ! $kit_id ) {
			$this->redirect_back( 'error', __( 'No active Elementor kit was found. Nothing was imported.', 'figma-token-import' ) );
		}

		$raw  = isset( $_POST['figma_json'] ) ? wp_unslash( $_POST['figma_json'] ) : '';
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$this->redirect_back( 'error', __( 'The pasted JSON could not be parsed. Nothing was imported.', 'figma-token-import' ) );
		}

		// Optional per-import family overrides from the preview screen.
		$title_override = isset( $_POST['title_family'] ) ? sanitize_text_field( wp_unslash( $_POST['title_family'] ) ) : '';
		$body_override  = isset( $_POST['body_family'] ) ? sanitize_text_field( wp_unslash( $_POST['body_family'] ) ) : '';

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		/* ---- System colors: four fixed slots, fixed order ------------- */
		$sys_in = ( isset( $data['systemColors'] ) && is_array( $data['systemColors'] ) ) ? $data['systemColors'] : array();
		$sys_map = array(
			'primary'   => 'Primary',
			'secondary' => 'Secondary',
			'text'      => 'Text',
			'accent'    => 'Accent',
		);
		$system_colors = array();
		foreach ( $sys_map as $id => $title ) {
			$hex = isset( $sys_in[ $id ] ) ? $this->sanitize_hex( $sys_in[ $id ] ) : '';
			if ( '' === $hex ) {
				continue;
			}
			$system_colors[] = array(
				'_id'   => $id,
				'title' => $title,
				'color' => $hex,
			);
		}
		$settings['system_colors'] = $system_colors;

		/* ---- Custom colors: merge by title, reuse existing ids -------- */
		$existing = ( isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] ) ) ? $settings['custom_colors'] : array();
		$by_title = array();       // title => index into $merged
		$merged   = array();
		foreach ( $existing as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['title'] ) ) {
				continue;
			}
			$by_title[ $item['title'] ] = count( $merged );
			$merged[] = $item;
		}

		$incoming = ( isset( $data['customColors'] ) && is_array( $data['customColors'] ) ) ? $data['customColors'] : array();
		foreach ( $incoming as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$name = isset( $c['name'] ) ? sanitize_text_field( $c['name'] ) : '';
			$hex  = isset( $c['hex'] ) ? $this->sanitize_hex( $c['hex'] ) : '';
			if ( '' === $name || '' === $hex ) {
				continue;
			}
			if ( isset( $by_title[ $name ] ) ) {
				$idx = $by_title[ $name ];
				$merged[ $idx ]['color'] = $hex;
				$merged[ $idx ]['title'] = $name;
				if ( empty( $merged[ $idx ]['_id'] ) ) {
					$merged[ $idx ]['_id'] = substr( md5( 'figma_' . $name ), 0, 7 );
				}
			} else {
				$by_title[ $name ] = count( $merged );
				$merged[] = array(
					'_id'   => substr( md5( 'figma_' . $name ), 0, 7 ),
					'title' => $name,
					'color' => $hex,
				);
			}
		}
		$settings['custom_colors'] = array_values( $merged );

		/* ---- System typography: four fixed slots ---------------------- */
		$typo         = ( isset( $data['typography'] ) && is_array( $data['typography'] ) ) ? $data['typography'] : array();
		$title_family = ( '' !== $title_override )
			? $title_override
			: ( isset( $typo['titleFamily'] ) ? sanitize_text_field( $typo['titleFamily'] ) : '' );
		$body_family  = ( '' !== $body_override )
			? $body_override
			: ( isset( $typo['bodyFamily'] ) ? sanitize_text_field( $typo['bodyFamily'] ) : '' );

		// Merge into any existing system_typography by _id. Only slots whose
		// family is non-empty are activated as 'custom' (mirrors the color
		// paths, which skip empty values) — this avoids writing an
		// activated-but-empty global font, and leaves untouched slots as
		// Elementor's defaults.
		$existing_typo = ( isset( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ) ? $settings['system_typography'] : array();
		$typo_by_id    = array();
		$typo_merged   = array();
		foreach ( $existing_typo as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['_id'] ) ) {
				continue;
			}
			$typo_by_id[ $item['_id'] ] = count( $typo_merged );
			$typo_merged[] = $item;
		}

		$typo_slots = array(
			array( 'primary', 'Primary', $title_family, '700' ),
			array( 'secondary', 'Secondary', $title_family, '600' ),
			array( 'text', 'Text', $body_family, '400' ),
			array( 'accent', 'Accent', $body_family, '500' ),
		);
		$typo_written = 0;
		foreach ( $typo_slots as $slot ) {
			list( $id, $title, $family, $weight ) = $slot;
			if ( '' === $family ) {
				continue; // nothing to write for this slot — leave existing/default in place
			}
			$built = $this->typo_slot( $id, $title, $family, $weight );
			if ( isset( $typo_by_id[ $id ] ) ) {
				$typo_merged[ $typo_by_id[ $id ] ] = $built;
			} else {
				$typo_by_id[ $id ] = count( $typo_merged );
				$typo_merged[] = $built;
			}
			$typo_written++;
		}
		$settings['system_typography'] = array_values( $typo_merged );

		/* ---- Persist + rebuild CSS ------------------------------------ */
		update_post_meta( $kit_id, '_elementor_page_settings', $settings );

		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		$message = sprintf(
			/* translators: 1: system color count, 2: custom color count, 3: typography slot count, 4: kit ID. */
			__( 'Import complete: %1$d system colors, %2$d custom colors, and %3$d typography slots written to Elementor kit #%4$d. Elementor CSS was rebuilt.', 'figma-token-import' ),
			count( $system_colors ),
			count( $settings['custom_colors'] ),
			$typo_written,
			$kit_id
		);
		$this->redirect_back( 'success', $message );
	}

	private function typo_slot( $id, $title, $family, $weight ) {
		return array(
			'_id'                     => $id,
			'title'                   => $title,
			'typography_typography'   => 'custom',
			'typography_font_family'  => $family,
			'typography_font_weight'  => $weight,
		);
	}
}

new Figma_Token_Import();
