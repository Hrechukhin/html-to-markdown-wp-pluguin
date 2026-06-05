<?php
/**
 * Admin settings + export UI.
 *
 * @package HtmlToMarkdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2MD_Admin_Page {

	const SLUG = 'html-to-markdown';

	/**
	 * Register the admin menu entry.
	 */
	public function register_menu() {
		add_management_page(
			__( 'HTML → Markdown', 'html-to-markdown' ),
			__( 'HTML → Markdown', 'html-to-markdown' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings group + fields.
	 */
	public function register_settings() {
		register_setting(
			'h2md_settings_group',
			H2MD_OPTION,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize the settings on save.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		$defaults = h2md_default_settings();
		$input    = is_array( $input ) ? $input : array();

		return array(
			'sitemap_url'      => esc_url_raw( trim( $input['sitemap_url'] ?? '' ) ),
			'export_dir'       => $this->sanitize_dir( $input['export_dir'] ?? $defaults['export_dir'], $defaults['export_dir'] ),
			'batch_size'       => max( 1, min( 50, absint( $input['batch_size'] ?? $defaults['batch_size'] ) ) ),
			'base_url'         => esc_url_raw( trim( $input['base_url'] ?? '' ) ),
			'auth_user'        => sanitize_text_field( $input['auth_user'] ?? '' ),
			'auth_pass'        => (string) ( $input['auth_pass'] ?? '' ),
			'content_selector' => sanitize_text_field( $input['content_selector'] ?? 'body' ) ?: 'body',
		);
	}

	/**
	 * Keep export dir within wp-content/uploads.
	 *
	 * @param string $dir      Requested dir.
	 * @param string $fallback Default dir.
	 * @return string
	 */
	private function sanitize_dir( $dir, $fallback ) {
		$dir = wp_normalize_path( trim( $dir ) );
		if ( '' === $dir ) {
			return $fallback;
		}

		$uploads = wp_normalize_path( wp_upload_dir()['basedir'] );
		// Force the dir under uploads to avoid arbitrary filesystem writes.
		if ( 0 !== strpos( $dir, $uploads ) ) {
			return $fallback;
		}

		return untrailingslashit( $dir );
	}

	/**
	 * Enqueue admin JS/CSS only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'h2md-admin', H2MD_URL . 'assets/admin.css', array(), H2MD_VERSION );
		wp_enqueue_script( 'h2md-admin', H2MD_URL . 'assets/admin.js', array( 'jquery' ), H2MD_VERSION, true );

		wp_localize_script(
			'h2md-admin',
			'H2MD',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'h2md_ajax' ),
				'i18n'    => array(
					'building'  => __( 'Збираю карту сайту…', 'html-to-markdown' ),
					'found'     => __( 'Знайдено сторінок:', 'html-to-markdown' ),
					'exporting' => __( 'Експортую…', 'html-to-markdown' ),
					'done'      => __( 'Готово! Експортовано сторінок:', 'html-to-markdown' ),
					'error'     => __( 'Помилка:', 'html-to-markdown' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = h2md_get_settings();
		?>
		<div class="wrap h2md-wrap">
			<h1><?php esc_html_e( 'HTML → Markdown Site Export', 'html-to-markdown' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Обходить карту сайту, завантажує зрендерений HTML кожної сторінки та зберігає її в Markdown із SEO-даними та посиланнями на зображення.', 'html-to-markdown' ); ?>
			</p>

			<h2><?php esc_html_e( 'Налаштування', 'html-to-markdown' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'h2md_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="h2md_sitemap_url"><?php esc_html_e( 'URL сайтмапу', 'html-to-markdown' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="h2md_sitemap_url" name="<?php echo esc_attr( H2MD_OPTION ); ?>[sitemap_url]" value="<?php echo esc_attr( $s['sitemap_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Залиште порожнім для авто-визначення (wp-sitemap.xml / sitemap_index.xml).', 'html-to-markdown' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="h2md_export_dir"><?php esc_html_e( 'Папка експорту', 'html-to-markdown' ); ?></label></th>
						<td>
							<input type="text" class="large-text code" id="h2md_export_dir" name="<?php echo esc_attr( H2MD_OPTION ); ?>[export_dir]" value="<?php echo esc_attr( $s['export_dir'] ); ?>">
							<p class="description"><?php esc_html_e( 'Має бути всередині wp-content/uploads.', 'html-to-markdown' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="h2md_batch_size"><?php esc_html_e( 'Розмір батчу', 'html-to-markdown' ); ?></label></th>
						<td><input type="number" min="1" max="50" id="h2md_batch_size" name="<?php echo esc_attr( H2MD_OPTION ); ?>[batch_size]" value="<?php echo esc_attr( $s['batch_size'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="h2md_content_selector"><?php esc_html_e( 'Селектор контенту', 'html-to-markdown' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="h2md_content_selector" name="<?php echo esc_attr( H2MD_OPTION ); ?>[content_selector]" value="<?php echo esc_attr( $s['content_selector'] ); ?>">
							<p class="description"><?php esc_html_e( 'За замовчуванням body. Можна звузити: main, #content, .entry-content.', 'html-to-markdown' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="h2md_base_url"><?php esc_html_e( 'Base URL override', 'html-to-markdown' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="h2md_base_url" name="<?php echo esc_attr( H2MD_OPTION ); ?>[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>" placeholder="<?php echo esc_attr( home_url() ); ?>">
							<p class="description"><?php esc_html_e( 'Лишіть порожнім, якщо loopback працює. Корисно для нестандартних локальних хостів.', 'html-to-markdown' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Basic Auth (опц.)', 'html-to-markdown' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( H2MD_OPTION ); ?>[auth_user]" value="<?php echo esc_attr( $s['auth_user'] ); ?>" placeholder="<?php esc_attr_e( 'логін', 'html-to-markdown' ); ?>">
							<input type="password" class="regular-text" name="<?php echo esc_attr( H2MD_OPTION ); ?>[auth_pass]" value="<?php echo esc_attr( $s['auth_pass'] ); ?>" placeholder="<?php esc_attr_e( 'пароль', 'html-to-markdown' ); ?>">
							<p class="description"><?php esc_html_e( 'Для захищеного паролем staging-середовища.', 'html-to-markdown' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Зберегти налаштування', 'html-to-markdown' ) ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Експорт', 'html-to-markdown' ); ?></h2>
			<p>
				<button type="button" class="button button-secondary" id="h2md-build"><?php esc_html_e( '1. Зібрати карту сайту', 'html-to-markdown' ); ?></button>
				<button type="button" class="button button-primary" id="h2md-export" disabled><?php esc_html_e( '2. Експортувати', 'html-to-markdown' ); ?></button>
			</p>

			<div id="h2md-status" class="h2md-status" aria-live="polite"></div>

			<div id="h2md-progress-wrap" class="h2md-progress-wrap" style="display:none;">
				<div class="h2md-progress-bar"><span id="h2md-progress-fill"></span></div>
				<div id="h2md-progress-text"></div>
			</div>

			<div id="h2md-log" class="h2md-log" style="display:none;"></div>
		</div>
		<?php
	}
}
