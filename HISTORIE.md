# Feuer-Cookie Historie

## Version 1.25.0 - 04.09.2026

Consent-Design-, GTM- und Besucheranalyse-Release.

### Enthalten

- GTM Consent Mode v2 mit standardmaessig verweigertem optionalem Google-Speicher und Aktualisierung nach einer ausdruecklichen Auswahl
- Eigene Consent-Cookies werden mit `SameSite=Lax`, `Secure` und `HttpOnly` gesetzt
- Neue Banner-Varianten: seitliche Dialoge und kompakter zentrierter Dialog
- Erweiterte Cookie-Schaltflaeche mit zusaetzlichen Formen, Farbe und frei waehlbarer Bildschirmposition
- Besuchsstatistik fuer Woche, Monat, Quartal und Halbjahr
- Farbige Laenderuebersicht sowie Auswertungen nach Staedten, Geraeten, Browsern, Betriebssystemen und Bildschirmgroessen
- Seiten-, aktive Aufenthalts- und Gesamtzeit-Auswertung nach Statistikfreigabe
- Neue Akquisitionsauswertung fuer Suchmaschinen und externe Verweisquellen; direkte und interne Aufrufe werden ausgeschlossen
- Plugin-Version auf `1.25.0` erhoeht

## Version 1.24.2 - 03.07.2026

Kompatibilitaetsfix fuer Update-Pruefungen aus alten Plugin-Versionen.

### Enthalten

- `download_url` im Release-Manifest zeigt wieder auf die oeffentliche Plugin-Beschreibung, damit alte Updater einen gueltigen Update-Eintrag erzeugen koennen
- Direkte ZIP-Paket-URLs bleiben weiter unveroeffentlicht
- Neuer Updater trennt Beschreibung (`download_url`) und echtes Paketfeld (`package_url`)
- Auto-Updates werden deaktiviert, solange kein echtes Paketfeld veroeffentlicht wird
- Plugin-Version auf `1.24.2` erhoeht

## Version 1.24.1 - 02.07.2026

Admin-Design- und Download-Schutz-Update.

### Enthalten

- Admin-Bereiche visuell staerker getrennt: Panels, Funktionskarten, Tabellen und Formulare erhalten klarere Abstaende
- Einheitlichere Tabellen- und Formularzellen fuer bessere Lesbarkeit in Besucher-, Status-, Diagnose-, SEO- und Einstellungsbereichen
- Ploetzliche UI-Wechsel reduziert durch sanfte Hover-, Fokus- und Panel-Animationen
- Oeffentliche `index.php` zeigt keine direkten Update- oder Download-Endpunkte mehr
- Plugin-Updater veroeffentlicht keine direkte Paket-URL mehr in der WordPress-Plugin-Info
- Release-Build schreibt eine `.htaccess`-Sperre fuer ZIP-Dateien in den Release-Bereich
- Plugin-Version auf `1.24.1` erhoeht

## Version 1.24.0 - 30.06.2026

OpenSEO-inspiriertes SEO-Audit-Update fuer lokale Inhaltsoptimierung.

### Enthalten

- Neuer Menuepunkt `Feuer-Cookie -> SEO-Audit`
- Lokaler On-Page-Audit fuer veroeffentlichte Seiten und Beitraege
- Pruefung von SEO-Titel, Meta-Beschreibung, Canonical, Noindex-Signalen, Texttiefe und internen Links
- Traffic-Priorisierung ueber Feuer-Cookie-Seitenaufrufe der letzten 30 Tage
- Unterstuetzung gaengiger SEO-Metafelder von Yoast, Rank Math und AIOSEO
- Plugin-Version auf `1.24.0` erhoeht

## Version 1.23.2 - 29.06.2026

Layout-Korrektur fuer Besucherfilter und Status-Tabellen.

### Enthalten

- Filter im Besucherbereich nutzen jetzt eine eigene kompakte Grid-Struktur statt der allgemeinen Zweispalten-Ansicht
- Filterfelder fuer Pfad, Geraet und Browser ueberlagern sich nicht mehr
- Tabellen mit Ereignissen erhalten einen horizontalen Scroll-Container
- User-Agent-Spalten werden einzeilig gekuerzt und per Tooltip vollstaendig lesbar gehalten
- Realtime-Tabelle im Status-Bereich nutzt eine kompaktere Ereignis-Tabelle und mehr Breite

## Version 1.23.1 - 29.06.2026

WordPress-Dashboard-Widget fuer schnelle Besucher- und Consent-Kontrolle.

### Enthalten

- Neues Dashboard-Widget auf der WordPress-Startseite im Backend
- Auffaellige Kopfzeile mit Feuer-Cookie-Status, Icons und Besucherzahl des aktuellen Tages
- Widget-Tabs fuer `Heute`, `7 Tage`, `Cookie` und `Status`
- Tageswerte fuer Seitenaufrufe, Besucher, Cookie-Auswahlen und Top-Seiten
- 7-Tage-Werte fuer Aufrufe, Besucher und Engagement-Ereignisse
- Cookie-Auswertung fuer optionale Freigaben, nur erforderliche Dienste und Freigabequote
- Status-Tab mit Tabellenstatus, Realtime-Sitzungen, Admin-Bypass und Demo-Modus
- Schnelllinks aus dem Widget zu Uebersicht, Besucherbereich und Status-Seite

## Version 1.23.0 - 29.06.2026

Diagnose-, Export- und Besucheranalyse-Update fuer den produktiven Betrieb.

### Enthalten

- Neuer Status-Bereich mit Health Check fuer Statistik-Tabellen, letzte Ereignisse, AJAX-Endpunkt, Admin-Bypass und Demo-Modus
- Realtime-Kennzahlen fuer aktive Sitzungen der letzten 5 und 15 Minuten sowie letzte Consent- und Page-View-Ereignisse
- Diagnosehinweise, warum Besucherstatistiken leer bleiben koennen
- Frontend Request Scan der Startseite auf externe `src`, `href` und `action`-Requests
- Besucherbereich mit Filtern nach Zeitraum, Consent-Typ, IP, Pfad, Geraet, Browser und Robots
- CSV- und JSON-Export der gefilterten Besucherereignisse
- Separate Listen fuer Besucher mit optionaler Freigabe und Besucher mit nur erforderlichen Diensten
- Consent Audit Trail fuer aktuelle Cookie-Entscheidungen
- Preview-Link fuer Administratoren, um den Consent-Flow trotz Admin-Bypass wie ein Besucher zu testen
- Getrennte Aufbewahrungsfristen fuer Rohereignisse, Consent Audit und aggregierte Tagesstatistik
- Basis-Seitenaufrufe werden direkt nach gespeicherter Cookie-Auswahl geschrieben und nach dem Redirect nicht doppelt gezaehlt
- `Auswahl speichern` ohne optionale Dienste wird als `Nur erforderlich` klassifiziert

## Version 1.22.2 - 22.06.2026

Ueberarbeitung der oeffentlichen Plugin-Praesentationsseite.

### Enthalten

- Technische Projektumfang-Angaben wie Codezeilen, Dateitypen, Zeichen und Bytes von der `index.php` entfernt
- Professionelle Beschreibung von Zweck, Funktionen, Vorteilen und Einsatzbereichen des Plugins ergaenzt
- Datenschutzbezug fuer Deutschland mit DSGVO-, TTDSG- und Consent-Anforderungen beschrieben
- Autor, Entwickler-Website `wdmin.com`, Plugin-Seite, Update-Endpunkt und Mindestanforderungen klar ausgewiesen

## Version 1.22.1 - 22.06.2026

Korrektur fuer leere Besucherstatistiken und neue Consent-Client-Auswertung.

### Enthalten

- Basis-Seitenaufrufe werden nach jeder gespeicherten Cookie-Auswahl protokolliert, auch bei Auswahl nur erforderlicher Dienste
- Erweiterte Aufenthaltszeit-Messung bleibt weiterhin an die ausdrueckliche Statistik-Freigabe gebunden
- Geraete- und Browser-Rankings nutzen vorhandene User-Agent-Daten als Fallback, wenn neue Statistikspalten noch leer sind
- Neue Dashboard-Auswertung fuer Geraete und Browser, die eine Cookie-Auswahl bestaetigt haben
- Stadt- und Land-Auswertung bleibt auf CDN-, Proxy- oder Server-Geo-Header angewiesen und zeigt sonst bewusst unbekannte Werte

## Version 1.22.0 - 19.05.2026

Erweiterte Besucherstatistik mit Tagesansicht und Client-Auswertung.

### Enthalten

- Tagesdiagramm fuer die letzten 30 Tage mit Auswahl eines konkreten Tages
- Top-Seiten fuer den ausgewaehlten Tag und bestehende Seitenrankings im Dashboard
- Speicherung und Auswertung von Stadt, Land, Geraetetyp, Browser und Betriebssystem fuer neue Ereignisse
- Rankings fuer Regionen, Geraete, Browser, Betriebssysteme und haeufig besuchte IP-Adressen
- Aufenthaltszeit pro Seite wird im Dashboard als summierte aktive Gesamtzeit dargestellt
- Automatisches Schema-Upgrade ergaenzt, damit neue Statistikfelder bei Plugin-Updates angelegt werden

## Version 1.21.1 - 14.05.2026

Robot-Bypass fuer saubere Indexierung trotz IP-Sperrliste.

### Enthalten

- Bekannte Suchmaschinen und Robots umgehen nun auch die Frontend-Gate-Pruefung vor der IP-Sperrliste
- Consent-Banner, Cookie-Button und externe Content-Blocker bleiben fuer Robots weiterhin deaktiviert
- Indexierung bleibt damit auch dann moeglich, wenn normale Besucher ueber IP-Regeln eingeschraenkt werden

## Version 1.21.0 - 24.04.2026

Release- und Analyse-Update fuer GitHub, wdmin.com und den wiedereroeffnenden Cookie-Button.

### Enthalten

- Plugin-Metadaten auf Walter Faerber und `wdmin.com/plugins/feuer-cookie/` umgestellt
- Developer-hosted Update-Mechanismus ueber `release/latest/update.json` und WordPress-Updater integriert
- Neues Build-Skript fuer Release-Pakete, Projektmetriken, Checksummen und Latest-Kanal ergaenzt
- Frontend-Analytics um durchschnittliche aktive Aufenthaltszeit pro Seite erweitert
- Neue Dashboard-Charts fuer IP-Rankings und aktive Aufenthaltszeit ergaenzt
- Floating Cookie-Button jetzt mit waehlbaren Formen, Farben und Icons
- Developer-Landing-`index.php` mit Projektbeschreibung und Live-Metriken ergaenzt

## Version 1.20 - 05.04.2026

Fix fuer den Einstellungen-Button in blockierten Datenschutz-Bereichen.

### Enthalten

- Klick auf `Einstellungen` in Karten- und Service-Overlays oeffnet das Cookie-Panel jetzt wieder korrekt
- Reopen-Panel wird nicht mehr durch denselben Ausloese-Klick sofort wieder geschlossen

## Version 1.19 - 05.04.2026

Layout-Anpassung fuer geblockte Karten- und Inhaltsbereiche.

### Enthalten

- Hinweistext, Meta-Informationen und Freigabe-Buttons liegen jetzt direkt innerhalb der abgedunkelten Datenschutz-Flaeche
- Blockierte Karten wirken damit wie ein zusammenhaengendes Overlay statt wie ein Textblock unterhalb der Sperrzone
- Kontrast der Buttons und Texte innerhalb des Overlays fuer bessere Lesbarkeit angepasst

## Version 1.18 - 05.04.2026

Korrektur fuer Karten-Gating, Admin-Tests und die Sichtbarkeit blockierter Bereiche.

### Enthalten

- Service-Gates geben eingebettete Karten nun korrekt frei, wenn `admin_bypass` aktiv ist oder das Blocking abgeschaltet wurde
- `ff_cookie_has_consent('leaflet_osm')` verhaelt sich damit fuer Rendering-Zwecke konsistent zum restlichen Frontend-Blocking
- Datenschutz-Placeholder fuer blockierte Bereiche deutlich dunkler ueberblendet, damit die Sperrung visuell klarer erkennbar ist

## Version 1.17 - 05.04.2026

Update fuer geblockte Inhaltsbereiche und die dauerhafte Wiedereroeffnung der Consent-Einstellungen.

### Enthalten

- Blockierte Karten, Iframes und Service-Zonen werden jetzt als unscharfe Datenschutz-Flaeche mit Hinweistext dargestellt
- Placeholder behalten besser ihre Hoehe, indem aus Embed- und Shortcode-Angaben sinnvolle Min-Height-Werte uebernommen werden
- Cookie-Symbol fuer die spaetere Aenderung der Auswahl wird jetzt dauerhaft auf freigegebenen Seiten angezeigt
- Frontend-Hinweis in der Admin-Konfiguration angepasst, damit das Cookie-Symbol nicht versehentlich abgeschaltet werden kann
- Sichtbarkeit und Position des Cookie-Symbols fuer kleine Displays weiter verbessert

## Version 1.16 - 05.04.2026

Analyse- und Stabilitaets-Update fuer Banner-UX, Diagnostics und Plugin-Metadaten.

### Enthalten

- Plugin-Header und Beschreibung auf den aktuellen Funktionsumfang angepasst
- Diagnostics so erweitert, dass Kommentar-URLs ignoriert und Snapshot-Ordner `versions/` beziehungsweise `release/` nicht mehr mitgescannt werden
- Banner um konfigurierbare Hintergrund-Abdunkelung erweitert, Standardwert 40 Prozent
- Neue Banner-Layouts fuer oben, unten, Mitte und kompakte Varianten ergaenzt
- Schwebenden Text-Button fuer den Widerruf durch ein Cookie-Icon mit Panel ersetzt
- Accessibility im Frontend verbessert, unter anderem durch `aria-expanded`, Dialog-Markup und Escape-Schliessen

## Version 1.15 - 30.03.2026

Durchgehende Umbenennung des alten Plugin-Namespace auf `feuer-cookie` beziehungsweise `feuer_cookie`.

### Enthalten

- Hauptdatei in `feuer-cookie.php` umbenannt
- Klassen-Dateien auf `class-feuer-cookie-*.php` umgestellt
- PHP-Konstanten und Helper auf `FEUER_COOKIE_*` und `feuer_cookie_*` umgestellt
- WordPress-Hooks, Shortcodes, Asset-Handles, Admin-Slugs und Datenattribute auf das neue Namensschema umgestellt
- JavaScript-Globals auf `FeuerCookieAdmin` und `FeuerCookieData` umgestellt
- Snapshot-Skript auf die neue Hauptdatei und Versionskonstante angepasst

## Version 1.3 - 28.03.2026

Umstellung von der vorgeschalteten Consent-Seite auf einen DSGVO-orientierten Consent-Banner mit servicebasiertem Re-Consent.

### Enthalten

- Plugin-Version auf `1.3` erhöht
- Consent-Flow von Full-Page-Gate auf Banner mit Einstellungen und schwebendem Widerruf umgestellt
- Kategorien auf `Notwendig`, `Statistik`, `Externe Medien`, `Karten` und `Marketing` ausgerichtet
- Service-Registry über Filter (`feuer_cookie_registered_services` / `feuer_cookie_services`) ergänzt
- Helper `ff_cookie_has_consent()` und Shortcode `[feuer_cookie_service_gate]` ergänzt
- Placeholder mit Button für „Diesen Dienst erlauben“ ergänzt
- Consent-Speicherung um Zeitstempel, Revisionswert, Config-Hash und Local-Storage-Spiegel erweitert
- Automatischen Re-Consent bei Änderungen an Bannertext, Revision, Kategorien oder Diensten ergänzt
- Einstellungen um Cookie-Name, Blocking-Modus, Versionsjournal und JSON-Export erweitert

## Version 1.2 - 22.03.2026

Umstellung auf granulare Einwilligung nach Zweck und Empfänger sowie Ausbau der Theme-Makros.

### Enthalten

- Plugin-Version auf `1.2` erhöht
- Consent-Modell von binärer Zustimmung auf granulare Auswahl umgestellt
- Neue Kategorien für:
  - technisch erforderliche Dienste
  - Statistik
  - Karten und Geodienste
  - Medien und Einbettungen
  - externe Ressourcen
- Eigene Empfängersteuerung je erkanntem Dienst
- Lokale Statistik an die ausdrückliche Freigabe von `feuer_cookie_statistics` gekoppelt
- Consent-Gate und Widerruf-Panel mit gleicher Empfänger-Matrix ausgestattet
- Frontend-JavaScript vollständig auf `necessary`, `save_selection` und `accept_all` umgestellt
- Zusätzliche Theme-Helfer ergänzt:
  - `feuer_cookie_has_category_consent()`
  - `feuer_cookie_has_vendor_consent()`
- Einstellungsseite um Tabelle für Kategorien und Empfänger erweitert
- Frontend-Stile für die neue Auswahlmatrix neu aufgebaut
- Dokumentation auf Version `1.2` und deutsche Rechtsquellenbasis aktualisiert

## Version 1.1 - 22.03.2026

Überarbeitung von Oberfläche, Texten und lokaler Ressourcenführung.

### Enthalten

- Alle sichtbaren deutschen Texte auf echte Schreibweise mit Ä Ö Ü ß umgestellt
- Plugin-Version auf `1.1` erhöht
- Adminbereich optisch auf einen schlichteren WordPress-Stil umgestellt
- Einfache Seitenüberschrift statt hervorgehobener Hero-Box im Backend
- Consent-, Ablehnungs- und Sperrseiten auf lokale Plugin-Ressourcen umgestellt
- Entfernung von `wp_head()` und `wp_footer()` in den isolierten Gate-Templates, um fremde Einbindungen auf diesen Seiten zu minimieren
- Lokale Makros für Header und Footer ergänzt:
  - `feuer_cookie_header_macro()`
  - `feuer_cookie_footer_macro()`
  - `[feuer_cookie_header_macro]`
  - `[feuer_cookie_footer_macro]`
- Dokumentation überarbeitet und um Hinweise zur lokalen Ressourcenführung ergänzt

## Version 1.0 - 22.03.2026

Erstveröffentlichung von Feuer-Cookie.

### Enthalten

- WordPress-Plugin-Grundstruktur mit Plugin-Header
- Consent-Vorschaltseite mit drei Layoutvarianten
- Ablehnungsseite und Sperrseite für IP-Blockierungen
- Speicherung von Consent-Entscheidungen per Cookie und Revisions-Cookie
- Besuchslogging mit eigener Ereignistabelle
- Tagesstatistik für Aufrufe, Sitzungen, Zustimmungen, Ablehnungen und Blockierungen
- IP-Sperrliste mit Unterstützung für exakte IPs, Wildcards und CIDR
- Adminbereiche für:
  - Übersicht
  - Besucher
  - IP-Sperrliste
  - Diagnose
  - Einstellungen
  - Dokumentation
- Autodiagnose für aktive Themes, Plugins und Inhalte
- Deutsche Dokumentation mit Quellenbasis und Betriebshinweisen
