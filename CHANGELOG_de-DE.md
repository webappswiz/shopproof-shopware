# 0.4.2
- Die Beschreibung nennt jetzt den täglichen Download der öffentlichen Shopproof-Regeldatei.
- Administration: Die Hilfsklasse für Screenreader nutzt nicht mehr die veraltete CSS-Eigenschaft clip. Keine sichtbare Änderung.

# 0.4.1
- Neuer Erlebniswelten-Block "Shopproof Produktinformationen" (Kategorie Commerce) für eigene Produktseiten-Layouts. Die Standard-Produktseite braucht nichts: Die Blöcke erscheinen wie bisher in der Kaufbox und unter der Beschreibung.
- Jeder Block erscheint nur einmal pro Seite, egal wo Theme oder Layout ihn platzieren.
- Die Einstellungsseite listet jetzt jedes Produktseiten-Layout und zeigt, ob es einen Platz für die Shopproof-Blöcke hat, mit Link zum Layout.
- Widerrufsanfragen werden jetzt auch unter Shopware 6.7.9 bis 6.7.11 gespeichert.
- Eine Änderung am Hersteller aktualisiert jetzt seine Produktseiten im Seiten-Cache, damit neue Sicherheitsangaben sofort erscheinen.
- Benötigt Shopware 6.7.2 oder neuer (die älteste getestete Version).

# 0.4.0
- Ergänzt den Widerrufsbutton von Shopware (ab Shopware 6.7.9). Jeder Antrag darüber wird in der Datenbank des Shops gespeichert und mit seiner Bestellung verknüpft, wenn Bestellnummer und E-Mail passen, damit Sie den Eingang belegen können. Die Anträge stehen unter Einstellungen > Erweiterungen > Shopproof, mit Suche und Löschen.
- Der Button im Footer und der Absenden-Button des Formulars tragen die gesetzliche Beschriftung des jeweiligen Landes, auf Englisch, Deutsch, Französisch, Italienisch und Niederländisch.
- Eine Einrichtungsprüfung zeigt, ob der Button eingeschaltet ist, auf eine Seite mit dem Widerrufsformular führt und ob der Flow dem Kunden eine Bestätigung schickt.

# 0.3.0
- EU-Haltbarkeitslabel (GARAN, Verordnung (EU) 2025/1960) auf Produktseiten für Produkte mit einer Haltbarkeitsgarantie des Herstellers von 3 Jahren oder mehr. Die Jahre werden je Produkt eingetragen; Marke und Modell kommen sonst vom Shopware-Hersteller und seiner Produktnummer. Ganzes Label oder ein kleiner Streifen, der es öffnet. Die offizielle Grafik und die Schrift Inter liefert der Shop selbst aus, ohne JavaScript.

# 0.2.0
- Informationen zur Produktsicherheit (GPSR) auf Produktseiten: Hersteller, verantwortliche Person in der EU, Produktkennzeichnung und Warnhinweise. Jedes Feld kommt vom Produkt, dann vom Shopware-Hersteller, dann von den Standardwerten in den Einstellungen. Serverseitig gerendert, ohne JavaScript, im Stil des Themes.
- Offizieller EU-Hinweis zur gesetzlichen Gewährleistung auf Produktseiten (standardmäßig aus), in der Sprache der Seite, mit Textfassung. Die Bilder liefert der Shop selbst aus.

# 0.1.0
- Erste Version: tägliches Laden der Shopproof-Regeldatei (die letzte gute Kopie bleibt erhalten) und eine Übersicht der Regeln für die Länder, in die Sie verkaufen, unter Einstellungen > Erweiterungen > Shopproof. Regeln, die noch geprüft werden, sind ausgeblendet. Technische Hilfe, keine Rechtsberatung.
