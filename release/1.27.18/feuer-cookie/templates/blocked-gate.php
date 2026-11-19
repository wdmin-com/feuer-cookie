<?php
/**
 * IP-Sperrseiten-Template.
 *
 * @var Feuer_Cookie_Plugin $plugin
 * @var array<string,mixed> $settings
 */

$site_name = get_bloginfo('name');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html($settings['blocked_title']); ?></title>
	<?php $plugin->print_gate_head_assets(); ?>
</head>
<body class="feuer-cookie-shell feuer-cookie-mode-blocked feuer-cookie-template-<?php echo esc_attr($settings['consent_template']); ?>">
	<div class="feuer-cookie-scene">
		<div class="feuer-cookie-stage">
			<section class="feuer-cookie-card-shell">
				<aside class="feuer-cookie-side">
					<div class="feuer-cookie-logo">
						<?php if (! empty($settings['logo_url'])) : ?>
							<img src="<?php echo esc_url($settings['logo_url']); ?>" alt="<?php echo esc_attr($site_name); ?>">
						<?php endif; ?>
						<span class="feuer-cookie-chip">IP-Sperre aktiv</span>
					</div>
					<div>
						<h1 class="feuer-cookie-heading"><?php echo esc_html($settings['blocked_title']); ?></h1>
						<p class="feuer-cookie-subtitle">
							Feuer-Cookie hat diese Anfrage aus Sicherheitsgründen blockiert.
						</p>
					</div>
					<div class="feuer-cookie-links">
						<?php if (! empty($settings['support_url'])) : ?>
							<a href="<?php echo esc_url($settings['support_url']); ?>">Kontakt aufnehmen</a>
						<?php endif; ?>
						<?php if (! empty($settings['imprint_url'])) : ?>
							<a href="<?php echo esc_url($settings['imprint_url']); ?>">Impressum</a>
						<?php endif; ?>
					</div>
				</aside>

				<div class="feuer-cookie-body">
					<div class="feuer-cookie-box">
						<h2>Sicherheitsstopp</h2>
						<div class="feuer-cookie-richtext">
							<?php echo wp_kses_post($settings['blocked_text']); ?>
						</div>
					</div>

					<?php if (! empty($settings['support_url'])) : ?>
						<div class="feuer-cookie-actions">
							<a class="feuer-cookie-button feuer-cookie-button--primary" href="<?php echo esc_url($settings['support_url']); ?>">Support kontaktieren</a>
						</div>
					<?php endif; ?>
				</div>
			</section>
		</div>
	</div>
	<?php $plugin->print_gate_footer_assets(); ?>
</body>
</html>
