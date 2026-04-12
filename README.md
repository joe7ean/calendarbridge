# Calendar Bridge for Nextcloud

Ein externer iCal-Feed, ein interner Relay-Kalender, automatisch für Gruppen freigegeben.

**App-ID:** `calendarbridge`  
**Kategorie:** Organization / Groupware  
**Lizenz:** AGPL-3.0

---

## Was macht diese App?

Vereine, Teams und Organisationen teilen oft Kalenderinformationen über öffentliche iCal-Links (z.B. von einer anderen Nextcloud-Instanz, einem Sportportal oder einem Vereinsmanagementsystem). Ohne diese App müsste jedes Mitglied diesen Link manuell in seinem Kalender einpflegen.

**Calendar Bridge** löst das zentral:

1. Ein Admin abonniert einen externen iCal-Feed einmalig in der App.
2. Die App holt den Feed regelmäßig im Hintergrund (Cron-Job).
3. Die Events werden in einen internen "Relay-Kalender" eines Service-Users geschrieben.
4. Dieser Kalender wird automatisch **read-only** an Nextcloud-Gruppen freigegeben.
5. Alle Gruppenmitglieder sehen die Events sofort in ihrem Nextcloud-Kalender — ohne eigene Konfiguration.

---

## Features

- Externe iCal/WebCal-Feeds abonnieren und intern spiegeln
- Automatische Synchronisation via Nextcloud-Cron (konfigurierbar: 15 min bis 24 h)
- Read-only Freigabe an beliebige Nextcloud-Gruppen
- Mehrere Zielgruppen pro Feed möglich
- Optionaler HTTP Basic-Auth-Support für geschützte Feeds
- ETag/Last-Modified-Support: kein unnötiger Traffic wenn sich der Feed nicht geändert hat
- Diff-basierter Sync: nur geänderte Events werden geschrieben
- Admin-Benachrichtigung bei dauerhaften Fetch-Fehlern (z.B. geänderter Link)
- Privacy by Design: ATTENDEE/ORGANIZER-Felder (E-Mail-Adressen) werden standardmäßig vor dem Speichern entfernt
- DSGVO-konform: alle Daten bleiben auf der eigenen Nextcloud-Instanz

---

## Voraussetzungen

- Nextcloud 29 oder neuer
- PHP 8.1+
- Nextcloud-Cron muss konfiguriert sein (empfohlen: systemd-Timer oder crontab, nicht Ajax-Cron)
- Die `dav`-App muss aktiv sein (Standardmäßig der Fall)

---

## Installation

### Über den App Store (empfohlen)

In Nextcloud: **Apps → Organization → Calendar Bridge → Installieren**

### Manuell

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/your-org/calendarbridge.git
cd calendarbridge
composer install --no-dev
npm ci && npm run build
```

Anschließend in Nextcloud unter **Apps** aktivieren.

---

## Konfiguration

### 1. Service-User einrichten

Siehe den ausführlichen Abschnitt [Service-User einrichten](#service-user-einrichten) weiter unten.

### 2. Globale App-Einstellungen

Unter **Einstellungen → Administration → Calendar Bridge**:

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Standard-Service-User | — | NC-User, dem Relay-Kalender gehören |
| Standard-Sync-Intervall | 1 Stunde | Wie oft Feeds abgerufen werden |
| Minimales Sync-Intervall | 15 Minuten | Untergrenze (schützt externe Server) |
| Max. Feed-Größe | 10 MB | Sicherheitslimit für Downloads |
| Nur HTTPS erlauben | Ja | HTTP-Feeds ablehnen (empfohlen) |
| ATTENDEE-Felder entfernen | Ja | E-Mail-Adressen nicht speichern |
| HTTP-Timeout | 30 Sekunden | |

### 3. Feed anlegen

Im Admin-Panel → **Feed hinzufügen**:

- **Feed-URL**: `https://example.org/remote.php/dav/public-calendars/TOKEN/` oder `webcal://...`
- **Anzeigename**: Wird als Kalenderbezeichnung für Gruppenmiitglieder angezeigt
- **Farbe**: Kalenderfarbe (Hex)
- **Zielgruppen**: Multiselect — welche NC-Gruppen Zugriff erhalten
- **Sync-Intervall**: Presets: 15 min / 30 min / 1 h / 2 h / 6 h / 12 h / 24 h
- **HTTP-Authentifizierung**: Optional, für zugangsbeschränkte Feeds
- **ATTENDEE-Felder entfernen**: Pro-Feed-Override der globalen Einstellung

---

## Test & Manueller Sync

### "Verbindung testen" (Dry Run)

Beim Anlegen oder Bearbeiten eines Feeds kann die URL vor dem Speichern getestet werden. Die App fetcht die URL und zeigt an:

- Anzahl gefundener Events
- Zeitraum (erstes / letztes Event)
- Kalender-Anzeigename aus dem iCal-Feed
- Erkannte Encoding-Probleme oder fehlerhafte iCal-Struktur

Es wird dabei **nichts** in die Datenbank oder den Kalender geschrieben. Limit: 5 Tests pro Minute pro Admin.

### "Jetzt synchronisieren"

Für bestehende Feeds gibt es einen "Jetzt synchronisieren"-Button. Nützlich:

- Nach Erstanlage (nicht bis zum nächsten Cron-Lauf warten)
- Nach Korrektur einer fehlerhaften Feed-URL
- Zur manuellen Überprüfung ob neue Events angekommen sind

Die Antwort enthält: Anzahl hinzugefügter / aktualisierter / gelöschter Events und die Dauer.

**Rate-Limiting:**

- Max. 3 manuelle Syncs innerhalb von 5 Minuten pro Admin (über alle Feeds)
- Pro Feed: min. 5 Minuten Abstand zwischen zwei manuellen Syncs
- Bei Überschreitung: 429-Fehler mit Countdown ("Nächster Sync in 3 Minuten")

---

## Sync-Verhalten

### Intervall-Empfehlungen

| Anwendungsfall | Empfehlung |
|---|---|
| Vereinskalender, selten geändert | 6–24 h |
| Wettkampfplan, wöchentlich aktualisiert | 1–2 h |
| Veranstaltungskalender, häufig aktualisiert | 30 min – 1 h |
| Echtzeit-kritisch | Nicht geeignet — iCal ist kein Push-Protokoll |

> **Hinweis:** Sehr kurze Intervalle (< 30 min) können die ToS des externen Kalenderanbieters verletzen. Die App erzwingt ein Minimum von 15 Minuten.

### Caching

Die App nutzt HTTP-ETag und Last-Modified-Header. Wenn der externe Feed unverändert ist, antwortet der Server mit `304 Not Modified` — kein Download, kein Re-Parse, kein DB-Schreibzugriff.

### Diff-Sync

Jeder VEVENT wird anhand seiner `UID` identifiziert. Beim Sync:

1. Bestehende UIDs im Relay-Kalender laden
2. UIDs im externen Feed mit `LAST-MODIFIED` vergleichen
3. Nur neue/geänderte Events schreiben
4. Aus dem Feed entfernte Events werden im Relay-Kalender gelöscht

---

## Fehlerbehandlung

### Was passiert wenn sich ein iCal-Link ändert?

Wenn ein externer Feed-Link ungültig wird (HTTP 404, falsche Content-Type, Auth-Fehler):

- Bestehende Events im Relay-Kalender bleiben **unverändert** (kein Löschen bei Fehlern)
- Der Fehlerzähler wird erhöht
- Nach 3 aufeinanderfolgenden Fehlern: **Admin-Benachrichtigung** über das Nextcloud-Benachrichtigungssystem (Glocke-Icon)
- Die Fehlermeldung ist im Admin-Panel sichtbar (Zeitstempel + HTTP-Statuscode)
- Nach 10 aufeinanderfolgenden Fehlern: Feed wird automatisch pausiert

**Lösung:** Admin öffnet den betroffenen Feed im Admin-Panel, aktualisiert die URL und klickt "Jetzt synchronisieren".

---

## Duplikate & Portabilität

### Darf dieselbe URL mehrfach eingetragen werden?

- **Gleicher Service-User + gleiche URL**: Nein — wird durch Datenbankconstraint verhindert.
- **Verschiedene Service-User, gleiche URL**: Technisch möglich. Sinnvoll wenn verschiedene Gruppen unterschiedliche Kalender-Setups benötigen. Die App zeigt im UI eine Warnung.
- **Ein Feed, mehrere Gruppen**: Empfohlener Weg — ein Feed-Eintrag, mehrere Zielgruppen.

### Portabilität / Service-User wechseln

Da Relay-Kalender immer aus dem Feed neu aufgebaut werden, ist ein "Umzug" einfach:

1. Neuen Feed-Eintrag mit dem neuen Service-User anlegen
2. Ersten Sync abwarten (Events werden automatisch neu aufgebaut)
3. Alten Feed deaktivieren und löschen

> **Wichtig:** Wenn ein Service-User-Account aus Nextcloud gelöscht wird, werden alle zugehörigen Relay-Kalender gelöscht. Betroffene Feeds zeigen im Admin-Panel einen Fehler.

---

## Sicherheit

### SSRF-Schutz

Die App nutzt ausschließlich `OCP\Http\Client\IClientService` von Nextcloud, das private IP-Ranges und Loopback-Adressen automatisch blockiert. Zusätzlich wird `OCP\Security\IRemoteHostValidator` für eine explizite Vorabprüfung verwendet. HTTP (ohne TLS) ist standardmäßig deaktiviert.

### Credential-Sicherheit

HTTP Basic-Auth-Passwörter werden mit `OCP\Security\ICrypto` verschlüsselt gespeichert und nie in API-Responses zurückgegeben.

### Zugriffskontrolle

Die App-Konfiguration ist ausschließlich für Nextcloud-Administratoren zugänglich (`#[AuthorizedAdminSetting]`). Gruppenmiitglieder haben nur lesenden Zugriff auf die geteilten Relay-Kalender und können diese nicht verändern.

---

## Datenschutz / DSGVO

- **Datensparsamkeit**: ATTENDEE- und ORGANIZER-Felder (die E-Mail-Adressen enthalten können) werden standardmäßig vor dem Speichern entfernt.
- **Lokale Verarbeitung**: Alle Daten verbleiben auf der eigenen Nextcloud-Instanz. Keine Drittanbieter.
- **Löschkonzept**: Wird ein Feed gelöscht, wird der zugehörige Relay-Kalender inklusive aller Events sofort entfernt.
- **Keine Analyse**: Event-Inhalte werden ausschließlich zur Synchronisation verarbeitet, nicht analysiert oder ausgewertet.
- **Logging**: Logs enthalten nur technische Metadaten (Feed-ID, HTTP-Statuscode, Anzahl geänderter Events) — keine Event-Inhalte oder persönlichen Daten.

---

## Entwicklung

### Voraussetzungen

- Node.js 20+
- PHP 8.1+
- Composer
- Laufende Nextcloud-Instanz (lokal oder Docker)

### Setup

```bash
git clone https://github.com/your-org/calendarbridge.git
cd calendarbridge

# PHP-Abhängigkeiten
composer install

# JS-Abhängigkeiten + Build
npm ci
npm run dev        # Entwicklungs-Build
npm run watch      # Watch-Modus
npm run build      # Produktions-Build
```

### Tests

```bash
composer test      # PHPUnit Backend-Tests
npm run test       # Jest Frontend-Tests
```

### App-Struktur

```
calendarbridge/
├── appinfo/                   # App-Metadaten, Routing
├── lib/
│   ├── AppInfo/               # DI-Container-Bootstrap
│   ├── BackgroundJob/         # Cron-Jobs (SyncJob)
│   ├── Controller/            # HTTP-Controller (admin-only)
│   ├── Db/                    # Entity + Mapper
│   ├── Migration/             # Datenbankmigrationen
│   └── Service/               # Business-Logik
├── src/                       # Vue 3 Frontend-Quellen
├── templates/                 # PHP-Templates
└── tests/                     # Backend- und Frontend-Tests
```

Für eine detaillierte Architekturübersicht und Implementierungsnotizen siehe [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## Service-User einrichten

Calendar Bridge benötigt einen Nextcloud-User, dem die Relay-Kalender gehören. Dieser User ist kein echtes Login-Konto — er fungiert ausschließlich als "Kalender-Eigentümer" im System.

### Warum ein dedizierter Account?

Wenn ein vorhandener Admin-Account (z.B. `ncAdmin`) als Service-User verwendet wird, erscheinen die Relay-Kalender in dessen Nextcloud-Kalender-App mit **vollen Verwaltungsoptionen** — inklusive "Freigabe aufheben". Das ist verwirrend, weil Calendar Bridge die Freigabe beim nächsten Sync ohnehin wiederherstellt.

Mit einem dedizierten Account, in den sich niemand einloggt, entfällt dieses Problem vollständig.

### Account anlegen

```bash
# 1. User anlegen — Passwort wird nur für die initiale Erstellung benötigt
php occ user:add \
  --display-name="Calendar Bridge Service" \
  --password-from-env \
  calbridge-service
# → SMTP-Benachrichtigung wird nicht verschickt, kein E-Mail-Attribut gesetzt

# Oder interaktiv (occ fragt nach Passwort):
php occ user:add --display-name="Calendar Bridge Service" calbridge-service
```

> **Wichtig:** Kein `occ user:disable`! Ein deaktivierter User kann zwar keinen Login durchführen, aber auch keine CalDAV-Kalender verwalten — die Sync-Operationen würden fehlschlagen.

### Login-Zugang sperren (ohne Account zu deaktivieren)

Der Account soll aktiv bleiben, aber kein Passwort-Login erlaubt sein:

```bash
# Passwort auf einen langen zufälligen Wert setzen den niemand kennt
php occ user:resetpassword --password-from-env calbridge-service
# RNDPW=$(openssl rand -base64 48) gilt als Wert für RNDPW-Variable
```

Oder direkt in der Datenbank — der Hash eines nie herausgegebenen Passworts:

```bash
# Zufälliges Passwort generieren und sofort verwerfen
RNDPW=$(openssl rand -base64 48)
OC_PASS="$RNDPW" php occ user:resetpassword --password-from-env calbridge-service
unset RNDPW OC_PASS
```

Damit ist kein Brute-Force-Angriff auf den Account möglich, und niemand kann sich einloggen — die CalDAV-API-Zugriffe der App laufen aber weiterhin korrekt (intern, ohne Login).

### Optionale Härtung

```bash
# Aus allen Gruppen entfernen (kein Zugriff auf Gruppeninhalte)
# — normalerweise nicht nötig, da der Account keine Gruppenrechte braucht

# Nur die nötigsten Quotas setzen
php occ user:setting calbridge-service files quota "0 B"
# → Kein Datei-Upload möglich (Kalender nutzen keinen Dateispeicher)
```

### In der App eintragen

Im Admin-Panel unter **Einstellungen → Administration → Calendar Bridge → Globale Einstellungen**:

- **Standard-Service-User** → `calbridge-service` auswählen

Beim Anlegen einzelner Feeds kann pro Feed auch ein abweichender Service-User gewählt werden.

### Was passiert wenn der Account gelöscht wird?

Nextcloud löscht beim Entfernen eines Users automatisch alle zugehörigen Kalender. Betroffene Feeds zeigen im Admin-Panel einen Fehler und lassen sich nicht mehr synchronisieren. Vor dem Löschen des Accounts sollten alle Feeds auf einen anderen Service-User umgezogen werden:

1. Feeds bearbeiten → anderen Service-User auswählen → speichern
2. Manuellen Sync auslösen (Relay-Kalender wird unter neuem User neu erstellt)
3. Erst dann den alten Account löschen

---

## Support & Feedback

- **Bugs & Feature-Requests**: [GitHub Issues](https://github.com/your-org/calendarbridge/issues)
- **Fragen**: [Nextcloud Help Forum](https://help.nextcloud.com)
- **Sicherheitslücken**: Bitte **nicht** öffentlich melden — stattdessen per E-Mail an security@your-org.example

---

## Lizenz

[AGPL-3.0](LICENSE) — wie alle Nextcloud-Apps.
