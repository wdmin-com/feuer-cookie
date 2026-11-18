# Feuer-Cookie

> **Hinweis für Version 1.27.5:** Lokale Reichweitenmessung ist optional und beginnt ausschließlich nach ausdrücklicher Freigabe von „Feuer-Cookie Statistik“. Ohne diese Freigabe werden keine Seitenaufrufe erfasst.

Feuer-Cookie ist ein WordPress-Plugin für Zugriffskontrolle, granulare Consent-Steuerung, Dienst-Blocking und eine Autodiagnose von Themes, Plugins und Inhalten. Das Plugin wurde für deutschsprachige Websites konzipiert und stellt alle Oberflächen, Texte und Projektunterlagen vollständig auf Deutsch bereit.

## Zielbild

Feuer-Cookie setzt nicht nur ein Consent-Banner ein, sondern stellt vor dem Besuch der Website eine echte Auswahl nach Zweck und Empfänger bereit. Besucher können damit selbst entscheiden,

- ob nur technisch erforderliche Dienste aktiv bleiben,
- ob sie Feuer Analytics als optionalen Statistik-Dienst freigeben,
- welche Karten-, Medien- oder externen Ressourcen freigegeben werden,
- welche konkret erkannten Empfänger Daten erhalten dürfen.

Bei der Auswahl `Nur erforderliche Dienste` bleibt die Website nutzbar; optionale Empfänger bleiben dabei deaktiviert.

## Funktionsumfang

- Consent-Banner mit gleichwertigen Buttons für:
  - `Alle akzeptieren`
  - `Nur notwendige`
  - `Einstellungen`
- Konfigurierbare Banner-Positionen für unten, oben, Mitte und kompakte Varianten
- Einstellbare Hintergrund-Abdunkelung hinter dem Consent-Banner
- Granulare Auswahl nach Kategorien und einzelnen Diensten
- Service-Placeholder als unscharfe Datenschutz-Flaeche mit Freigabe eines einzelnen Dienstes
- Hinweistext und Freigabe-Buttons direkt innerhalb der abgedunkelten Datenschutz-Flaeche
- Cookie-Icon zum nachtraeglichen Widerruf oder zur Aenderung der Auswahl
- Floating Cookie-Button mit mehreren Formen, Farben und Icons
- `Einstellungen` in blockierten Bereichen oeffnet das Cookie-Panel zuverlaessig erneut
- Karten- und Service-Gates verhalten sich auch bei aktiviertem Admin-Bypass konsistent zum restlichen Frontend-Blocking
- Automatischer Re-Consent bei Änderungen an Text, Revision, Kategorien oder Diensten
- JSON-Export der Konfiguration und Journal der Consent-Versionen
- Status-Bereich für Consent, Dienst-Blocking und Kompatibilitätsdiagnose
- Optionale Feuer-Analytics-Synchronisierung; Analytics erhält nur die Freigabe des aktuellen Aufrufs
- Eigene Statistikübersicht mit Seitenaufrufen, pseudonymen Sitzungen, Top-Seiten, Herkunftsdiensten sowie groben Geräte- und Browsergruppen
- Vergleich der Seitenaufrufe und Sitzungen mit dem vorherigen gleich langen Zeitraum sowie CSV-Export aggregierter Berichte
- Lokale Besuchsmessung nur nach ausdrücklicher Freigabe der Statistik-Kategorie; keine IP-Adressen oder vollständigen URLs in Statistikereignissen
- Consent Audit Trail fuer nachvollziehbare Cookie-Entscheidungen
- Preview-Link fuer Administratoren, um den Consent-Flow trotz Admin-Bypass wie ein Besucher zu testen
- Aufbewahrungsfrist für datensparsame Consent-Nachweise
- Manifest-basierte Plugin-Updates über `wdmin.com/plugins/feuer-cookie/release/latest/update.json`, HTTPS und SHA-256-Prüfung
- Build-Skript für Release-Pakete, Checksummen und Projektmetriken
- Weiterhin verfügbare isolierte Layoutvarianten:
  - `Feuerwand`
  - `Kontrollkarte`
  - `Sicherheitsleiste`
- Consent-Protokoll, optionale Statistikereignisse und IP-Sperrliste in getrennten Verarbeitungszwecken
- Statistikberichte über Seitenpfade, 30-Minuten-Sitzungen, Herkunftsdomains und grobe Geräte-/Browsergruppen
- Keine IP-Adressen, Query-Parameter, vollständigen URLs oder rohen Browser-Kennungen in freiwilligen Statistikereignissen
- Automatische Löschung der Statistik-Rohdaten nach konfigurierbarer Frist
- Sperrseite für geblockte IP-Adressen
- Autodiagnose für aktive Themes, Plugins und bis zu 50 veröffentlichte Seiten/Beiträge
- Lokale Makros für Header und Footer ohne externe Quellen
- Lokale CSS- und JavaScript-Ressourcen für Consent-Seiten und Widerruf

## Installation

1. Den Plugin-Ordner `feuer-cookie` in `wp-content/plugins/` ablegen.
2. Das Plugin im WordPress-Backend aktivieren.
3. Unter `Feuer-Cookie -> Einstellungen` Logo, Links und Rechtstexte pflegen.
4. Unter `Feuer-Cookie -> Diagnose` einen ersten Scan ausführen.
5. Datenschutzerklärung, Impressum und optional eine Ausweichseite hinterlegen.
6. Nach Änderungen an Theme oder Plugins die Diagnose erneut starten.

## Einstellungen

### Marke und Links

- Logo-URL für die Vorschaltseiten
- Einwilligungs-Revision
- Datenschutz-URL
- Impressum-URL
- Alternative Zielseite / Ausweichseite
- Support-/Kontakt-URL

### Consent-Oberfläche

- Auswahl eines Consent-Templates
- Titel und Volltext für:
  - Consent-Banner
  - nur erforderliche Nutzung
  - Sperrseite
- Individuelle Beschriftung der Buttons
- Übersicht der erkannten Kategorien und Dienste

### Speicherung und Betrieb

- Aufbewahrung der Rohlogs in Tagen
- Laufzeit des Consent-Cookies
- Optionale Speicherung vollständiger IP-Adressen
- Admin-Bypass für eingeloggte Administratoren
- Widerruf-Button im Frontend

## Kategorien und Dienste

Feuer-Cookie verwaltet die Einwilligung getrennt nach Kategorien und einzelnen Diensten. Standardmäßig sind nur technisch erforderliche Verarbeitungen aktiv.

### Standardkategorien

- `necessary` – technisch erforderliche Dienste
- `statistics` – lokale Statistik und Reichweitenmessung
- `external_media` – externe Inhalte, Medien und sonstige Drittressourcen
- `maps` – Karten und Geodienste
- `marketing` – Tracking-, Marketing- und Tag-Management-Dienste

### Typische vordefinierte Empfänger

- Feuer-Cookie Kernfunktionen
- Feuer-Cookie Sicherheitsprotokolle
- Feuer-Cookie Statistik
- Leaflet / OpenStreetMap
- Google Analytics / Tag Manager
- Google Fonts
- YouTube
- Vimeo
- Bootstrap / CDN
- unpkg CDN
- Font Awesome

Zusätzlich erkannte Browser-Ressourcen können nach Prüfung im Cookie-Dialog als Dienste erscheinen. Serverseitige API-Aufrufe werden nicht als Cookie-Dienste für Besucher übernommen. Der statische Diagnosescan belegt eine Fundstelle im Quellcode, aber nicht, dass ein Dienst zur Laufzeit geladen wird.

## Statistik und Logs

Feuer-Cookie speichert Consent-Nachweise und – nur nach ausdrücklicher Freigabe von „Feuer-Cookie Statistik“ – reduzierte Seitenaufrufe in der Tabelle `wp_feu-cookie_events`.

### Besuchsereignisse

- `page_view`
- `consent_necessary`
- `consent_custom`
- `consent_accept_all`
- `blocked_ip`

Wichtig: Seitenaufrufe werden nur dann protokolliert, wenn der Empfänger `feuer_cookie_statistics` freigegeben wurde. Gespeichert werden der Seitenpfad ohne Query-Parameter, eine zufällige gehashte 30-Minuten-Sitzungskennung, die externe Herkunftsdomain und grobe Clientgruppen. IP-Adressen, vollständige URLs und rohe User-Agents bleiben leer. Die Aufbewahrung ist unter „Einstellungen → Datenschutz“ zwischen 30 und 365 Tagen konfigurierbar.

Consent- und Sperrlistenereignisse werden nach der konfigurierten Consent-Audit-Frist gelöscht. Seitenaufrufe, Engagement-Ereignisse und tägliche aggregierte Statistik werden nach der Statistik-Aufbewahrungsfrist gelöscht.

### IP-Handling

Consent-Nachweise speichern standardmäßig eine maskierte IP im Rohlog, zum Beispiel `203.0.113.0`, und einen HMAC-Hash. Reduzierte Seitenaufrufe nach Statistik-Einwilligung speichern keine IP-Adresse. Die Client-IP wird standardmäßig ausschließlich aus `REMOTE_ADDR` ermittelt; vom Besucher mitsendbare Forwarded-Header werden ignoriert.

Wenn WordPress hinter einem eigenen Reverse-Proxy läuft, können dessen exakte IP-Adressen explizit als vertrauenswürdig freigegeben werden. Der Proxy muss `X-Forwarded-For` korrekt überschreiben bzw. ergänzen:

```php
add_filter('feuer_cookie_trusted_proxy_ips', function ($trusted) {
	$trusted[] = '127.0.0.1'; // Durch die tatsächliche Proxy-IP ersetzen.
	return $trusted;
});
```

Länder- und Ortsangaben werden nur aus serverseitig gesetzten `GEOIP_COUNTRY_CODE`- und `GEOIP_CITY`-Variablen gelesen. Vom Browser übermittelte Proxy- oder CDN-Header werden dafür nicht verwendet.

## IP-Sperrliste

Unter `Feuer-Cookie -> IP-Sperrliste` können folgende Muster gespeichert werden:

- exakte IP-Adressen
- Wildcards, zum Beispiel `203.0.113.*`
- CIDR-Blöcke, zum Beispiel `203.0.113.0/24`

## Autodiagnose

Die Diagnose prüft:

- das aktive Theme
- optional das Parent Theme
- alle aktiven Plugins
- veröffentlichte Inhalte

Gesucht werden externe URLs und typische Drittanbieter-Dienste. Die Ausgabe zeigt Dienst, Domain, Fundort und eine Handlungsempfehlung. Die Diagnose lädt selbst keine fremden Bibliotheken nach.

## SEO-Audit

Unter `Feuer-Cookie -> SEO-Audit` kann ein lokaler On-Page-Check gestartet werden. Der Audit erfasst zuerst Projekt- und Seitensignale und priorisiert dann konkrete naechste Schritte.

Geprueft werden:

- SEO-Titel aus WordPress oder gaengigen SEO-Plugin-Metafeldern
- Meta-Beschreibung aus WordPress, Yoast, Rank Math oder AIOSEO
- Canonical-Ziel und auffaellige Abweichungen
- Noindex-Signale gaengiger SEO-Plugins
- indexierbare Textmenge, interne Links und Bilder
- lokale Seitenaufrufe der letzten 30 Tage aus Feuer-Cookie

Der Audit ruft keine externen SEO-APIs auf. Seiten mit Traffic und schwachen Basis-Signalen werden bewusst hoeher priorisiert, weil Optimierungen dort schneller Wirkung zeigen.

## Makros und Hilfsfunktionen

Für Theme-Dateien oder Builder-Bereiche stellt Feuer-Cookie lokale Makros und Prüf-Helfer bereit.

### PHP-Makros

- `<?php if ( function_exists('feuer_cookie_header_macro') ) { feuer_cookie_header_macro(); } ?>`
- `<?php if ( function_exists('feuer_cookie_footer_macro') ) { feuer_cookie_footer_macro(); } ?>`

### Shortcodes

- `[feuer_cookie_header_macro]`
- `[feuer_cookie_footer_macro]`
- `[feuer_cookie_service_gate service="leaflet_osm"]...[/feuer_cookie_service_gate]`

### Consent-Helfer für Themes

- `feuer_cookie_has_category_consent('statistics')`
- `ff_cookie_has_consent('leaflet_osm')`
- `feuer_cookie_render_service_gate('leaflet_osm', $html)`

Damit können Template-Dateien eigene Inhalte oder Einbindungen abhängig von der Auswahl des Besuchers steuern.

## Minimierung externer Quellen

Feuer-Cookie selbst lädt keine externen JavaScript- oder CSS-Dateien. Alle plugin-eigenen Ressourcen liegen im Plugin-Ordner.

### Hinweise zur Einbindung

- Bootstrap wird nicht vom Plugin geladen.
- Wenn das aktive Theme Bootstrap bereits lokal oder intern liefert, nutzt Feuer-Cookie dies nicht zusätzlich.
- Die Consent-Seiten werden mit lokalen Plugin-Dateien ausgeliefert, um zusätzliche Anfragen an Theme- oder Fremdquellen zu minimieren.
- Externe Skripte, Styles und erkannte Iframes bleiben ohne passende Freigabe blockiert.
- Für reguläre freigegebene Seiten nutzt das Plugin nur lokale Plugin-Skripte und den lokalen WordPress-AJAX-Endpunkt.

## Rechtliche Leitplanken

Feuer-Cookie versucht, deutsche Datenschutz-Anforderungen technisch sauber umzusetzen. Die konkrete Rechtssicherheit hängt immer von der realen Konfiguration, den eingesetzten Diensten und den veröffentlichten Rechtstexten der Website ab. Eine anwaltliche Prüfung bleibt sinnvoll.

### Wichtige Punkte für den Betrieb

- Optionalen Empfängern sollte erst nach einer ausdrücklichen Auswahl Zugriff gewährt werden.
- Datenschutzerklärung und Impressum sollten erreichbar bleiben.
- Externe Dienste sollten vor Einwilligung nicht geladen werden.
- Bei wesentlichen Änderungen an Zwecken, Empfängern oder Rechtstexten muss die `Einwilligungs-Revision` erhöht werden.
- Die Speicherung vollständiger IP-Adressen sollte nur aktiviert werden, wenn der Zweck dies wirklich erfordert.

### Quellenbasis für die Voreinstellungen

Die technische und textliche Ausrichtung von Version 1.2 basiert auf einer Prüfung am 22.03.2026 unter anderem anhand dieser primären oder offiziellen Quellen:

- BfDI, Cookies und andere Tracking-Technologien: <https://www.bfdi.bund.de/DE/Buerger/Inhalte/Telemedien/Cookies.html>
- BfDI, LogFile-Analyse: <https://www.bfdi.bund.de/DE/Buerger/Inhalte/Telemedien/LogFile_Analyse.html>
- Datenschutzkonferenz, Orientierungshilfe für Anbieter:innen von digitalen Diensten (OH Digitale Dienste), Version 1.2, Stand November 2024: <https://www.datenschutzkonferenz-online.de/media/oh/OH_Digitale_Dienste.pdf>
- OpenStreetMap Foundation, Privacy Policy: <https://osmfoundation.org/wiki/Privacy_Policy>
- OpenStreetMap Operations, Tile Usage Policy: <https://operations.osmfoundation.org/policies/tiles/>

## Versionspflege

- Plugin-Version: technische Release-Version
- Einwilligungs-Revision: juristisch-inhaltliche Revision für bereits erteilte Zustimmungen

Wenn Sie Rechtstexte, Datenkategorien oder externe Dienste wesentlich ändern, sollten beide Werte bewusst gepflegt werden.
