# feuer-cookie

**Lizenz:** proprietaer; Nutzung nur mit ausdruecklicher persoenlicher Genehmigung des Entwicklers. Die Veroeffentlichung auf GitHub oder der Download eines ZIP-Archivs erteilen keine Nutzungserlaubnis. Vollstaendige Bedingungen: [LICENSE.md](LICENSE.md).
Feuer-Cookie ist ein professionelles WordPress-Plugin fuer deutschsprachige Websites, die Cookie-Einwilligungen, externe Inhalte und eine einwilligungsbasierte Reichweitenmessung transparent verwalten wollen. Lokale Statistik startet nur nach ausdrücklicher Freigabe von „Feuer-Cookie Statistik“.

## Datenschutz und Google Tag Manager

Feuer-Cookie arbeitet im Opt-in-Modell: Optionale Dienste und erkannte externe Ressourcen bleiben im Standardmodus gesperrt, bis eine ausdrueckliche Auswahl gespeichert wurde. Die eigenen Consent-, Praeferenz- und Session-Cookies werden ausschliesslich nach einer Entscheidung gesetzt und erhalten `SameSite=Lax`, `Secure` und `HttpOnly`.

Die Einstellung **GTM Consent Mode v2 aktivieren** ist unter **Feuer-Cookie → Einstellungen → Datenschutz** verfügbar. Sie setzt vor dem Laden eines GTM-Containers alle Google-Consent-Typen auf `denied` und übermittelt nach Freigabe des Dienstes **Google Analytics / Tag Manager** die aktualisierte Auswahl. Die v2-Felder `ad_user_data` und `ad_personalization` sind dabei enthalten.

> Hinweis: Cookies mit dem Attribut `Secure` werden vom Browser nur über HTTPS gespeichert. Die Produktionsseite muss daher per HTTPS ausgeliefert werden.

## Darstellung und Consent-Funktionen

Unter **Banner & Button** stehen mehrere Bannerpositionen zur Auswahl, darunter kompakte, seitliche und zentrierte Dialoge. Das nach der Auswahl sichtbare Datenschutz-Icon lässt sich in Form, Farbe, Symbol und Position konfigurieren.

Zusätzlich stehen drei Feuer-Cookie-Bannerstile bereit: eine dunkle untere Leiste, ein kompakter Toast und eine helle Akzentkarte. Die datenschutzkonforme Opt-in-Auswahl mit „Alle akzeptieren“, „Nur notwendige“ und Einstellungen bleibt vollständig erhalten.

Copyright © 2026 Walter Färber, wdmin.com.

Die Statistik unter **Feuer-Cookie → Statistik** zeigt Seitenaufrufe, pseudonyme 30-Minuten-Sitzungen, beliebte Seiten, externe Herkunftsdomains und grobe Geräte-/Browsergruppen. Seitenaufrufe und Sitzungen lassen sich mit dem vorherigen Zeitraum vergleichen; aggregierte Berichte können als CSV exportiert werden. Für Seitenaufrufe speichert sie keine IP-Adressen, vollständigen URLs, Query-Parameter, rohen User-Agents oder Bildschirmgrößen. Länder- und Ortsangaben werden nur übernommen, wenn der Webserver `GEOIP_COUNTRY_CODE` bzw. `GEOIP_CITY` bereitstellt. Erfassung ist standardmäßig aus, bis Besucher die Statistik-Kategorie freigeben; Rohdaten werden nach der konfigurierten Frist gelöscht.

## Optionale Feuer-Analytics-Synchronisierung

Unter **Einstellungen → Speicherung & Betrieb** kann Feuer Analytics ausdrücklich als optionaler Statistik-Dienst aktiviert werden. Erst dann erscheint der Dienst im Consent-Banner und Feuer Analytics darf Besuche nach der individuellen Freigabe zählen. Zwischen den Plugins wird nur die aktuelle Freigabe übertragen, niemals ein Consent-Protokoll, eine IP-Adresse oder ein Besucherprofil. Der gemeinsame Bericht bleibt im Dashboard von Feuer Analytics; Feuer-Cookie zeigt dazu einen Datenschutz-Status und aggregierte Freigaben.

## Updates

Neue Versionen werden über das Manifest auf `wdmin.com` erkannt. WordPress lädt das ZIP ausschließlich per HTTPS; vor der Installation vergleicht Feuer-Cookie dessen SHA-256-Prüfsumme mit dem Manifest. Der Release-Generator erstellt Archiv, Prüfsumme und beide Manifest-Kanäle gemeinsam.
