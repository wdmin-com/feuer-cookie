# feuer-cookie
Feuer-Cookie ist ein professionelles WordPress-Plugin fuer deutschsprachige Websites, die Cookie-Einwilligungen, externe Inhalte, Besucheranalyse und IP-Schutz transparent und technisch sauber im eigenen System verwalten wollen.

## Datenschutz und Google Tag Manager

Feuer-Cookie arbeitet im Opt-in-Modell: Optionale Dienste und erkannte externe Ressourcen bleiben im Standardmodus gesperrt, bis eine ausdrueckliche Auswahl gespeichert wurde. Die eigenen Consent-, Praeferenz- und Session-Cookies werden ausschliesslich nach einer Entscheidung gesetzt und erhalten `SameSite=Lax`, `Secure` und `HttpOnly`.

Die Einstellung **GTM Consent Mode v2 aktivieren** ist unter **Feuer-Cookie → Einstellungen → Datenschutz** verfügbar. Sie setzt vor dem Laden eines GTM-Containers alle Google-Consent-Typen auf `denied` und übermittelt nach Freigabe des Dienstes **Google Analytics / Tag Manager** die aktualisierte Auswahl. Die v2-Felder `ad_user_data` und `ad_personalization` sind dabei enthalten.

> Hinweis: Cookies mit dem Attribut `Secure` werden vom Browser nur über HTTPS gespeichert. Die Produktionsseite muss daher per HTTPS ausgeliefert werden.

## Darstellung und Besucherstatistik

Unter **Banner & Button** stehen mehrere Bannerpositionen zur Auswahl, darunter kompakte, seitliche und zentrierte Dialoge. Das nach der Auswahl sichtbare Datenschutz-Icon lässt sich in Form, Farbe, Symbol und Position konfigurieren.

Zusätzlich stehen drei CookieKit-inspirierte Presets bereit: eine dunkle untere Leiste, ein kompakter Toast und eine helle Akzentkarte. Sie übernehmen nur das visuelle Prinzip der Referenzen; die datenschutzkonforme Opt-in-Auswahl mit „Alle akzeptieren“, „Nur notwendige“ und Einstellungen bleibt vollständig erhalten.

Die Übersicht zeigt Besuchszahlen für Woche, Monat, Quartal und Halbjahr, häufig besuchte Seiten, Aufenthalts- und aktive Zeit, Länder und Städte, Gerätetypen sowie Browser und Betriebssysteme. Zusätzlich werden externe Verweise nach Domain und Suchmaschinen getrennt ausgewiesen; interne und direkte Aufrufe erscheinen dort nicht. Bildschirm- und Viewportgrößen werden ausschließlich mit der aktiv freigegebenen Statistik erhoben. Länder mit Besuchern werden in einer farbintensiven Übersicht dargestellt; für geografische Daten sind Geo-Header eines CDN/Proxys wie Cloudflare erforderlich.
