<?php

if (defined('ABSPATH')) {
	return;
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$allowed_hosts = array('wdmin.com', 'www.wdmin.com', 'localhost', '127.0.0.1');

if ($host && ! in_array($host, $allowed_hosts, true)) {
	http_response_code(204);
	exit;
}

function feuer_cookie_load_json_file($path) {
	if (! is_readable($path)) {
		return array();
	}

	$decoded = json_decode((string) file_get_contents($path), true);

	return is_array($decoded) ? $decoded : array();
}

function feuer_cookie_detect_version($root_path) {
	$plugin_file = $root_path . DIRECTORY_SEPARATOR . 'feuer-cookie.php';

	if (! is_readable($plugin_file)) {
		return 'unknown';
	}

	$content = (string) file_get_contents($plugin_file);

	if (preg_match("/define\('FEUER_COOKIE_VERSION',\s*'([^']+)'\)/", $content, $matches)) {
		return (string) $matches[1];
	}

	if (preg_match('/Version:\s*([0-9][0-9A-Za-z\.\-_]*)/', $content, $matches)) {
		return (string) $matches[1];
	}

	return 'unknown';
}

$root_path = __DIR__;
$version = feuer_cookie_detect_version($root_path);

$feature_cards = array(
	array(
		'title' => 'Granulare Cookie-Einwilligung',
		'text'  => 'Besucher koennen Dienste nach Zweck und Anbieter freigeben, ablehnen oder spaeter erneut anpassen.',
	),
	array(
		'title' => 'Lokales Blocking externer Inhalte',
		'text'  => 'Karten, Medien, Skripte und externe Ressourcen bleiben bis zur passenden Auswahl technisch gesperrt.',
	),
	array(
		'title' => 'Besucherstatistik im eigenen System',
		'text'  => 'Nach ausdruecklicher Freigabe: Seitenaufrufe, Tagesverlauf, beliebte Seiten, Herkunftsdomains sowie grobe Geraete- und Browsergruppen. Statistikdaten bleiben lokal und enthalten keine IP-Adressen.',
	),
	array(
		'title' => 'IP-Schutz und Sperrliste',
		'text'  => 'Auffaellige IP-Adressen, Wildcards oder CIDR-Bereiche koennen im WordPress-Backend blockiert werden.',
	),
	array(
		'title' => 'Diagnose fuer externe Dienste',
		'text'  => 'Theme, Plugins und Inhalte koennen auf datenschutzrelevante externe Einbindungen geprueft werden.',
	),
	array(
		'title' => 'Eigener Update-Kanal',
		'text'  => 'Release-Pakete und Update-Manifest werden direkt ueber wdmin.com bereitgestellt.',
	),
);

$requirements = array(
	'DSGVO-orientierte Einwilligung vor nicht notwendigen Diensten',
	'TTDSG-konforme Trennung zwischen technisch erforderlichen und optionalen Verarbeitungen',
	'Nachweisbare Consent-Revisionen bei Aenderungen an Texten, Diensten oder Kategorien',
	'Datensparsame lokale Besucherstatistik ohne zwangslaeufige externe Tracking-CDNs',
	'Widerruf und Anpassung der Auswahl ueber ein dauerhaft erreichbares Cookie-Panel',
	'Transparente Benennung von Zwecken, Kategorien, Anbietern und Datenschutzlinks',
);

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Feuer-Cookie | Consent, Statistik und Datenschutz fuer WordPress</title>
	<style>
		:root {
			--ink: #172033;
			--muted: #5d697d;
			--line: rgba(54, 72, 99, 0.16);
			--panel: #ffffff;
			--accent: #b91c1c;
			--accent-dark: #7f1d1d;
			--warm: #f59e0b;
			--soft: #f6f8fb;
		}

		* {
			box-sizing: border-box;
		}

		body {
			background: #eef2f6;
			color: var(--ink);
			font-family: "Segoe UI", Tahoma, sans-serif;
			margin: 0;
		}

		a {
			color: var(--accent-dark);
			font-weight: 700;
			text-decoration: none;
		}

		a:hover {
			text-decoration: underline;
		}

		.feuer-cookie-page {
			margin: 0 auto;
			max-width: 1180px;
			padding: 28px 20px 52px;
		}

		.feuer-cookie-hero {
			background:
				linear-gradient(135deg, rgba(127, 29, 29, 0.94), rgba(185, 28, 28, 0.88)),
				linear-gradient(90deg, #111827, #4b5563);
			border-radius: 18px;
			color: #fff;
			display: grid;
			gap: 28px;
			grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.6fr);
			margin-bottom: 18px;
			overflow: hidden;
			padding: 38px;
		}

		.feuer-cookie-eyebrow {
			color: #fde68a;
			font-size: 0.78rem;
			font-weight: 800;
			letter-spacing: 0.12em;
			margin: 0 0 12px;
			text-transform: uppercase;
		}

		h1 {
			font-size: clamp(2.45rem, 5vw, 4.6rem);
			letter-spacing: 0;
			line-height: 0.98;
			margin: 0;
			max-width: 760px;
		}

		.feuer-cookie-lead {
			color: rgba(255, 255, 255, 0.88);
			font-size: 1.13rem;
			line-height: 1.72;
			margin: 22px 0 0;
			max-width: 760px;
		}

		.feuer-cookie-meta {
			align-self: end;
			background: rgba(255, 255, 255, 0.12);
			border: 1px solid rgba(255, 255, 255, 0.2);
			border-radius: 12px;
			padding: 20px;
		}

		.feuer-cookie-meta p {
			color: rgba(255, 255, 255, 0.9);
			margin: 0;
		}

		.feuer-cookie-meta p + p {
			border-top: 1px solid rgba(255, 255, 255, 0.14);
			margin-top: 12px;
			padding-top: 12px;
		}

		.feuer-cookie-grid {
			display: grid;
			gap: 18px;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			margin-top: 18px;
		}

		.feuer-cookie-panel,
		.feuer-cookie-card {
			background: var(--panel);
			border: 1px solid var(--line);
			border-radius: 8px;
			box-shadow: 0 16px 38px rgba(28, 37, 53, 0.08);
			padding: 24px;
			transition: border-color 180ms ease, box-shadow 180ms ease, transform 180ms ease;
		}

		.feuer-cookie-card:hover {
			border-color: rgba(185, 28, 28, 0.32);
			box-shadow: 0 20px 44px rgba(28, 37, 53, 0.12);
			transform: translateY(-2px);
		}

		.feuer-cookie-panel {
			margin-top: 18px;
		}

		.feuer-cookie-card h2,
		.feuer-cookie-panel h2 {
			font-size: 1.28rem;
			line-height: 1.25;
			margin: 0 0 10px;
		}

		.feuer-cookie-card p,
		.feuer-cookie-panel p,
		.feuer-cookie-panel li {
			color: var(--muted);
			font-size: 1rem;
			line-height: 1.68;
			margin: 0;
		}

		.feuer-cookie-panel p + p {
			margin-top: 12px;
		}

		.feuer-cookie-list {
			display: grid;
			gap: 10px;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			list-style: none;
			margin: 16px 0 0;
			padding: 0;
		}

		.feuer-cookie-list li {
			background: var(--soft);
			border-left: 4px solid var(--warm);
			padding: 12px 14px;
		}

		.feuer-cookie-footer {
			align-items: center;
			display: flex;
			flex-wrap: wrap;
			gap: 12px 20px;
			justify-content: space-between;
			margin-top: 18px;
		}

		.feuer-cookie-footer p {
			color: var(--muted);
			margin: 0;
		}

		@media (max-width: 900px) {
			.feuer-cookie-hero,
			.feuer-cookie-grid,
			.feuer-cookie-list {
				grid-template-columns: 1fr;
			}

			.feuer-cookie-hero {
				padding: 28px;
			}
		}
	</style>
</head>
<body>
	<main class="feuer-cookie-page">
		<section class="feuer-cookie-hero">
			<div>
				<p class="feuer-cookie-eyebrow">WordPress Consent Management</p>
				<h1>Feuer-Cookie fuer transparente Einwilligung, lokale Statistik und kontrollierte externe Dienste.</h1>
				<p class="feuer-cookie-lead">Feuer-Cookie ist ein WordPress-Plugin fuer deutschsprachige Websites, die Einwilligungen, externe Inhalte und datensparsame Besucherstatistik transparent im eigenen System verwalten wollen.</p>
			</div>
			<div class="feuer-cookie-meta">
				<p><strong>Aktuelle Version:</strong><br><?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?></p>
				<p><strong>Entwickler:</strong><br>Walter Faerber</p>
				<p><strong>Website:</strong><br><a href="https://wdmin.com">wdmin.com</a></p>
				<p><strong>Plugin-Seite:</strong><br><a href="https://wdmin.com/plugins/feuer-cookie/">wdmin.com/plugins/feuer-cookie/</a></p>
			</div>
		</section>

		<section class="feuer-cookie-grid" aria-label="Funktionen">
			<?php foreach ($feature_cards as $card) : ?>
				<article class="feuer-cookie-card">
					<h2><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
					<p><?php echo htmlspecialchars($card['text'], ENT_QUOTES, 'UTF-8'); ?></p>
				</article>
			<?php endforeach; ?>
		</section>

		<section class="feuer-cookie-panel">
			<h2>Vorteile fuer Betreiber</h2>
			<p>Das Plugin verbindet Consent-Verwaltung, lokales Service-Blocking, Diagnose, Update-Auslieferung und einwilligungsbasierte Besucherstatistik in einem System. Statistik wird erst nach ausdruecklicher Freigabe erfasst; taegliche Kennzahlen und Rohdaten-Aufbewahrung lassen sich im WordPress-Backend nachvollziehen.</p>
			<p>Feuer-Cookie eignet sich besonders fuer Organisationen, Vereine, kommunale Projekte und Unternehmen, die keine unnoetigen externen Tracking-Abhaengigkeiten einsetzen wollen und trotzdem klare technische Transparenz benoetigen.</p>
		</section>

		<section class="feuer-cookie-panel">
			<h2>Datenschutz und Anforderungen in Deutschland</h2>
			<p>Feuer-Cookie ist auf den Betrieb deutscher Websites ausgerichtet. Die technische Umsetzung orientiert sich an den Anforderungen der DSGVO, des TTDSG und den gaengigen Erwartungen an transparente Einwilligungsprozesse fuer nicht notwendige Dienste.</p>
			<ul class="feuer-cookie-list">
				<?php foreach ($requirements as $requirement) : ?>
					<li><?php echo htmlspecialchars($requirement, ENT_QUOTES, 'UTF-8'); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="feuer-cookie-panel">
			<h2>Autor und Betrieb</h2>
			<p>Feuer-Cookie wird von Walter Faerber entwickelt und ueber <a href="https://wdmin.com">wdmin.com</a> bereitgestellt. Die Software ist proprietaer; Installation und Nutzung sind nur mit ausdruecklicher persoenlicher Genehmigung erlaubt. Die Veroeffentlichung auf GitHub oder der Download eines ZIP-Archivs erteilen keine Nutzungserlaubnis. <a href="LICENSE.md">Vollstaendige Lizenzbedingungen</a>.</p>
			<div class="feuer-cookie-footer">
				<p><strong>Autor:</strong> <a href="https://wdmin.com">Walter Faerber / wdmin.com</a></p>
				<p><strong>Mindestanforderungen:</strong> WordPress 6.9, PHP 8.0</p>
			</div>
		</section>
	</main>
</body>
</html>
