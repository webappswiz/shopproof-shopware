# 0.5.0
- Die Produktsicherheit steht als eine Zeile unter dem Button „In den Warenkorb“ („Produktsicherheit (Hersteller: Acme GmbH)“), die sich zu den Angaben öffnet. Der ganze Block unter der Beschreibung bleibt wählbar (Einstellung „Wo es erscheint“).
- Gefahrenkennzeichnung für Chemikalien (CLP Artikel 48a): je Produkt Piktogramme und Signalwort wählen und die Codes der Gefahren- und Sicherheitshinweise eintragen. Die Produktseite zeigt die amtlichen Texte in der Sprache der Kunden, immer sichtbar über der Zeile zur Produktsicherheit. Die Shopproof-Seite listet Codes, die eigene Worte brauchen oder unbekannt sind.
- Registrierungsnummern und Rücknahme: Ihre WEEE-, Batterie-, Verpackungs-, IDU- oder Textilnummern in den Einstellungen eintragen. Sie erscheinen als kleine Zeile in der Fußzeile, und als Elektrogerät oder mit Batterien markierte Produkte erklären, wie Altgeräte und Altbatterien zurückgegeben werden.
- Bestellbestätigung und Versand-E-Mails enden mit dem Widerrufslink: „Vertrag widerrufen. Sie haben es sich anders überlegt? Sie können innerhalb von 14 Tagen widerrufen.“, verlinkt auf die Seite des Shopware-Widerrufsbuttons. Neue Einstellung für die Widerrufsfrist (14 bis 365 Tage).
- Nachweisprotokoll: ein datiertes, per Hash verkettetes Protokoll dessen, was der Shop gezeigt hat (täglicher Stand) und welche Widerrufe eingegangen sind, ohne Kundennamen, E-Mail-Adressen oder Bestellnummern. Auf der Shopproof-Seite die Kette prüfen (sie findet einen geänderten Eintrag, einen entfernten neuesten oder ältesten Eintrag und ein geleertes Protokoll) und als CSV herunterladen. Standardmäßig 2 Jahre aufbewahrt.
- Das Haltbarkeitslabel erscheint jetzt als schmaler Streifen, der das ganze Label öffnet, wie im WooCommerce-Plugin und in der Shopify-App. Produkte mit „Ganzes Label“ behalten es. Zeigt ein Produkt das eigene Haltbarkeitslabel von Shopware (ab 6.7.14), lässt Shopproof sein Label weg, es gibt also nie ein zweites.
- Regelliste 0.10.0, darin die zweite geprüfte niederländische Widerrufsformulierung („hier de overeenkomst herroepen“ mit „herroeping bevestigen“) aus 0.9.1. Die übrigen Neuerungen in 0.10.0 sind Pro-Recherche und nicht in dieser Liste.
- Fehlerbehebung: Auf älteren Shopware-6.7-Versionen zeigte die Storefront Textschlüssel wie „shopproof.safety.heading“ statt der Worte, und die nationale Formulierung des Widerrufsbuttons griff nicht. Die Texte liegen jetzt auch für die üblichen Sprachregionen bei (en-GB, de-DE, de-AT und weitere).
- 0.4.5 wurde nie veröffentlicht; seine Änderungen sind Teil dieser Version. Sein Änderungsprotokoll nannte fälschlich die Regeln 0.9.1.

# 0.4.4
- Regelliste 0.9.0: neue EU-Regel zur Gefahrenkennzeichnung von online verkauften Chemikalien (CLP Artikel 48a).
- Produktsicherheit: Hersteller und verantwortliche Person in der EU kommen jeweils aus einer Quelle, der Name eines Herstellers erscheint also nicht mehr mit der Standardanschrift und -E-Mail des Shops. Die Standardwerte gelten für Produkte ohne Shopware-Hersteller und für den Hersteller mit dem Standardnamen. Ein Hersteller ohne Angaben zeigt nur seinen Namen, und die Shopproof-Seite listet solche Hersteller. Ein Produkt, das einen anderen Hersteller nennt, übernimmt nicht mehr die Anschrift des Shopware-Herstellers.
- Widerrufsanträge, die nicht gespeichert werden konnten, werden gezählt, und die Shopproof-Seite zeigt einen roten Hinweis, bis Sie sie als geprüft markieren. Die Einrichtungsprüfung bestätigt, dass die Tabelle für die Anträge vorhanden ist.
- Widerrufsanträge werden ihrer Bestellung auch zugeordnet, wenn der Kunde "#", Leerzeichen oder "Order" oder "Bestellung" vor die Nummer schreibt.
- Die Einrichtungsprüfung warnt, solange der EU-Hinweis zur gesetzlichen Gewährleistung aus ist (für Waren ab 27. September 2026 vorgeschrieben).
- Die Regelliste lässt außerdem Regeln weg, die auf eine Prüfung warten, eine niedrige Sicherheit haben oder nicht verifiziert sind, sowie Regeln ohne https-Quellenlink.

# 0.4.3
- Shopproof enthält jetzt eine Kopie der Regelliste und kontaktiert bei Installation, Aktivierung oder beim Öffnen der Shopproof-Seite keinen Server.
- Neue Einstellung „Regel-Updates einmal täglich herunterladen“ (Karte Regel-Updates), standardmäßig aus. Das tägliche Regel-Update läuft nur, wenn Sie sie einschalten.
- Neue Schaltfläche „Jetzt nach Regel-Updates suchen“ auf der Shopproof-Seite für einen einmaligen Download.
- Die Beschreibung sagt jetzt, dass die Regelliste enthalten ist und tägliche Updates optional sind.

