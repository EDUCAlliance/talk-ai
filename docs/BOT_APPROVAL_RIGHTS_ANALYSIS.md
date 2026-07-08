# Bot Approval and Rights Analysis

Stand: 2026-04-10

Diese Analyse beschreibt den aktuellen Ist-Zustand der Bot-Rechte- und Approval-Logik in `educai`.

Die Analyse basiert auf:

- Code in `lib/Service`, `lib/Controller`, `lib/Db`, `src/components`, `src/views`
- Laufzeitprüfung gegen die lokale Docker-Instanz `master-nextcloud-1`
- Datenbankschema und aktuelle Daten in `oc_educai_bots`
- Testlauf im Container

## Kurzfazit

Die Rechte- und Approval-Logik ist aktuell **nicht konsistent**.

Die drei wichtigsten Probleme sind:

1. **Neue `pending`-Bots sind vor Approval bereits benutzbar**, weil `pending` im Access-Pfad fast wie `approved` behandelt wird.
2. **Ein Wechsel von `personal` auf `teams`/`groups`/`global` funktioniert für normale Nutzer nicht sauber**, weil der Bot-Status beim Update nicht aus `personal` herausgeführt wird.
3. **Wer approven darf, wird bei Pending-Änderungen anhand des aktuell live gespeicherten Scopes geprüft, nicht anhand des beantragten Ziel-Scopes**. Dadurch können falsche Approver Scope-Eskalationen freigeben.

Wenn man nur auf die UI schaut, wirkt der Flow plausibel. Im Backend gibt es aber mehrere Stellen, an denen `visibility`, `approval_status` und `pending_changes` nicht sauber zusammengeführt werden.

## Datenmodell

Relevante Felder in `oc_educai_bots`:

- `visibility`: `personal`, `groups`, `teams`, `global`
- `approval_status`: `draft`, `pending`, `approved`, `personal`
- `allowed_groups`: JSON-Liste von Group-IDs
- `allowed_teams`: JSON-Liste von Team-IDs
- `pending_changes`: JSON für noch nicht freigegebene Änderungen
- `submitted_at`, `approved_by`, `approved_at`
- `testing_enabled_by`
- Approval-Fragebogenfelder:
  - `approval_reason`
  - `bot_capabilities`
  - `rag_source_description`
  - `testing_description`
  - `rejection_reason`

Code:

- `lib/Db/Bot.php`
- `lib/Migration/Version022000Date20251127000000.php`
- `lib/Migration/Version022300Date20251210100000.php`
- `lib/Migration/Version022400Date20251211000000.php`

Wichtig: `visibility` und `approval_status` sind zwei getrennte Dimensionen. Genau daraus entstehen mehrere Inkonsistenzen.

## Rollenmodell

Die Rechteermittlung sitzt primär in `lib/Service/PermissionService.php`.

Aktuelle Rollen:

- **Nextcloud-Admin**
  - `isAdmin()`
  - darf alles direkt veröffentlichen
  - darf global approven
- **Group-Admin / Subadmin**
  - `isGroupAdmin()`, `getAdminGroups()`
  - darf nur Scopes verwalten, für die er alle betroffenen Gruppen administriert
- **Team-Moderator/Admin/Owner**
  - `getAdminTeams()`
  - Circles-Level `>= 4` gilt als administrativ
  - darf nur Scopes verwalten, für die er alle betroffenen Teams administriert
- **Bot-Owner**
  - darf eigenen Bot immer editieren und löschen
  - darf eigene Shared-Bots aber nicht selbst approven
- **Normaler Nutzer**
  - darf Personal-Bots direkt anlegen
  - Shared-Bots nur indirekt via Draft/Approval

Die Kernmethoden sind:

- `hasApprovalRights()`
- `canApproveBot()`
- `canPublishBotToScope()`
- `canEditBot()`
- `canDeleteBot()`

## Wie Create aktuell funktioniert

Code: `lib/Service/BotService.php:createBot()`

### 1. Ziel-Scope wird bestimmt

`visibility` wird normalisiert:

- explizit gesetztes `visibility` gewinnt
- sonst `global`, wenn `isPublic = true`
- sonst `groups`

### 2. Status wird gesetzt

Aktuelle Logik:

- `visibility === personal`
  - `approval_status = personal`
- sonst: wenn `canPublishBotToScope(...) === true`
  - `approval_status = approved`
  - `approved_by = creator`
  - `approved_at = now`
- sonst
  - `approval_status = draft`

### 3. Konsequenz pro Rolle

- Admin erstellt `global` direkt live
- passender Group-Admin erstellt passende Group-Bots direkt live
- passender Team-Admin erstellt passende Team-Bots direkt live
- normaler Nutzer:
  - `personal` direkt
  - `groups`/`teams`/`global` nur als `draft`

Das ist für neue Bots grundsätzlich nachvollziehbar.

## Wie Submit aktuell funktioniert

Code: `lib/Service/BotService.php:submitForApproval()`

Der Submit-Flow ist aktuell sehr strikt:

- nur der Owner darf submitten
- nur Bots mit `approval_status = draft` dürfen submitted werden
- beim Submit werden Fragebogenfelder gespeichert
- danach:
  - `approval_status = pending`
  - `submitted_at = now`

Wichtig:

- `approved`-Bots werden **nicht** über `submitForApproval()` erneut eingereicht
- Änderungen an bereits freigegebenen Shared-Bots gehen über `updateBot()` direkt in `pending_changes`
- `personal`-Bots können **nicht** submitted werden

## Wie Update aktuell funktioniert

Code: `lib/Service/BotService.php:updateBot()`

`updateBot()` hat zwei Modi:

1. **Direktes Überschreiben der Live-Daten**
2. **Speichern als `pending_changes`**

### Wann landet ein Update in `pending_changes`?

`storePending` wird gesetzt, wenn:

- der Bot bereits `pending_changes` hat
- oder der Bot `approved` ist und
  - der User den Ziel-Scope nicht direkt publishen darf
  - oder der Owner seinen eigenen Shared-Bot ändert (`selfApprovalBlocked`)

Das ist die aktuelle 4-Augen-Logik für bereits freigegebene Shared-Bots.

### Was passiert bei `storePending = true`?

- Live-Bot bleibt unverändert
- Änderungen werden in `pending_changes` gespeichert
- `approval_status = pending`
- `submitted_at = now`

### Was passiert bei direktem Update?

Dann werden die Felder direkt auf dem Bot überschrieben.

Wichtig: In diesem Pfad wird der `approval_status` **nur in einem Sonderfall** aktiv angepasst:

- non-admin + Ziel `global` + vorher nicht `approved`
  - dann `draft`

Für alle anderen Direkt-Updates bleibt der bisherige Status faktisch bestehen.

Genau das ist die Ursache für den `personal -> shared`-Bug.

## Wie Approval aktuell funktioniert

Code:

- `lib/Service/BotService.php:approveBot()`
- `lib/Service/BotService.php:rejectBot()`
- `lib/Service/BotService.php:getPendingApprovals()`
- `lib/Service/PermissionService.php:canApproveBot()`

### Approve

`approveBot()`:

- verbietet Self-Approval bei Shared-Bots
- prüft `canApproveBot()`
- erlaubt nur `approval_status = pending`
- wendet bei vorhandenen `pending_changes` diese auf den Live-Bot an
- synchronisiert ggf. Tools aus `pending_changes`
- setzt danach:
  - `approval_status = approved`
  - `approved_by = approver`
  - `approved_at = now`

### Reject

`rejectBot()`:

- verbietet Self-Review bei Shared-Bots
- prüft `canApproveBot()`
- erlaubt nur `pending`
- bei bereits früher freigegebenem Bot mit `pending_changes`:
  - `pending_changes` werden verworfen
  - Bot geht zurück auf `approved`
- bei neuem noch nie freigegebenem Bot:
  - Bot geht zurück auf `draft`

### Pending Queue

`getPendingApprovals()`:

- lädt alle Bots mit `approval_status = pending`
- filtert dann pro Bot mit `canApproveBot()`

Die Queue ist also **scope-abhängig gefiltert**.

### Was der Approver in der Queue aktuell wirklich sieht

Fuer neue Bots ohne `pending_changes` ist die Queue halbwegs plausibel.

Fuer Updates an bereits freigegebenen Bots ist der Review aber unvollstaendig:

- `getPendingApprovals()` liefert zwar `pending_changes` mit aus
- die Approval-UI in `src/views/PersonalBots.vue` nutzt fuer Name, Sichtbarkeit und Vorschau aber primaer die Live-Felder
- `previewBot(bot)` zeigt `bot.system_prompt`, nicht den Pending-Prompt
- Scope-Aenderungen werden in der Approval-Karte nicht als beantragter Zielzustand aufbereitet

Das heisst:

- der Approver sieht bei `has_pending_changes = true` im UI nicht sauber die beantragte neue Version
- die Freigabe erfolgt damit teilweise ohne vernuenftige Sicht auf die eigentliche Aenderung

## Wie Zugriff auf Bots aktuell funktioniert

Code:

- `lib/Service/BotService.php:userCanAccessBot()`
- `lib/Service/BotService.php:getAvailableBotsForUser()`
- `lib/Service/BotService.php:getAvailableBotsForUserEnriched()`
- `lib/Reference/BotReferenceProvider.php`
- `lib/Webhook/TalkHandler.php`
- `src/components/BotPickerElement.vue`
- `src/components/PublicBotList.vue`

### Aktuelle Access-Regeln

`userCanAccessBot()` arbeitet so:

1. Owner darf immer
2. `draft` ist für andere gesperrt
3. `personal` ist für andere gesperrt
4. `pending` **und** `approved` laufen beide durch dieselben Sichtbarkeitschecks
5. danach gilt:
   - `global`: jeder
   - `teams`: Mitglieder der erlaubten Teams
   - `groups`: Mitglieder der erlaubten Gruppen

### Kritische Konsequenz

Für **neue Bots**, die aus `draft` nach `pending` submitted wurden, heißt das:

- sobald der Status `pending` ist,
- sind sie für alle Nutzer im Ziel-Scope bereits sichtbar und nutzbar

Das betrifft nicht nur die Public-Liste, sondern auch:

- Smart Picker / Bot Picker
- Mention-Resolution
- Talk-Webhook-Verarbeitung

Das ist aus Approval-Sicht sehr wahrscheinlich falsch.

## Spezieller Fall: Personal-Bot auf Team/Group/Global upgraden

Das ist aktuell der problematischste fachliche Sonderfall.

### Erwartetes Verhalten

Wenn ein Nutzer einen `personal`-Bot später für `teams`, `groups` oder `global` freigeben will, müsste einer der folgenden Pfade passieren:

- direkt `approved`, wenn der Nutzer den Ziel-Scope selbst administrieren darf
- sonst `draft` oder `pending`, damit der neue Shared-Scope approvbar wird

### Tatsächliches Verhalten im Code

Ausgangspunkt:

- `approval_status = personal`
- `visibility = personal`

Dann Update auf z.B. `visibility = teams`.

In `updateBot()` passiert:

- `originalStatus === personal`
- `storePending === false`, weil `storePending` nur an `approved` hängt
- `forceDraft === false`, weil das nur für non-admin + `global` gilt
- Felder werden direkt überschrieben
- **`approval_status` bleibt `personal`**

Das heißt:

- der Bot hat danach z.B. `visibility = teams`
- aber weiterhin `approval_status = personal`

### Praktische Folge

Das System hängt in einem inkonsistenten Zwischenzustand:

- für andere Nutzer bleibt der Bot gesperrt, weil `userCanAccessBot()` `personal` früh abblockt
- der Owner kann `submitForApproval()` nicht verwenden, weil diese Methode `personal` explizit ablehnt:
  - "Personal bots do not require approval. Change visibility first."
- die Sichtbarkeit ist aber bereits geändert

### Ergebnis

**Der Upgrade-Pfad `personal -> teams/groups/global` funktioniert für normale Nutzer aktuell nicht sauber.**

Das bestätigt den Verdacht aus der Anfrage.

## Wer kann aktuell was approven?

Die fachliche Antwort ist leider: **nicht sauber derjenige, der den Ziel-Scope verantwortet, sondern oft derjenige, der den aktuell live gespeicherten Scope verantwortet.**

### Warum?

`canApproveBot()` prüft immer den aktuell am Bot gespeicherten Scope:

- `visibility`
- `allowed_groups`
- `allowed_teams`

`pending_changes` werden in dieser Entscheidung **nicht** berücksichtigt.

### Beispiel 1: Group A -> Group B

Ausgangslage:

- Bot ist freigegeben für Group A
- Owner ändert ihn auf Group B
- Änderungen landen in `pending_changes`

Aktuelles Verhalten:

- approven darf weiter ein Admin von Group A
- ein Admin von Group B sieht den Bot evtl. gar nicht in seiner Queue

### Beispiel 2: Team A -> Team B

Gleicher Effekt:

- aktuelle Approver ergeben sich aus Team A
- nicht aus dem beantragten Ziel-Team B

### Beispiel 3: Group-Bot -> Global

Das ist besonders kritisch:

- ein Group-Admin des aktuellen Live-Scopes kann über `canApproveBot()` eventuell eine Änderung approven, deren Ziel `global` ist
- eigentlich dürfte `global` nur von Nextcloud-Admins freigegeben werden

### Fazit

**Die Approval-Berechtigung für Pending-Änderungen basiert aktuell auf dem alten Live-Scope, nicht auf dem beantragten Ziel-Scope.**

Das ist fachlich und sicherheitstechnisch die wichtigste Schwäche im Review-Prozess.

## Freigabeprozess Schritt fuer Schritt

### Neuer Shared-Bot durch normalen Nutzer

1. Create
2. Status wird `draft`
3. Owner öffnet "Submit for Approval"
4. Fragebogen wird gespeichert
5. Status wird `pending`
6. Approver sieht Bot in Queue, **wenn `canApproveBot()` true ist**
7. Approver kann:
   - `Enable Test`
   - `Approve`
   - `Reject`

Problem:

- der Bot ist in `pending` schon zugreifbar, wenn `userCanAccessBot()` den Scope erlaubt

### Update eines bereits freigegebenen Shared-Bots

1. Owner editiert Bot
2. Änderungen landen in `pending_changes`
3. Live-Bot bleibt mit alten Feldern aktiv
4. Status wird `pending`
5. Approver sieht Queue
6. `Approve` uebernimmt `pending_changes`
7. `Reject` verwirft `pending_changes`

Das Versioning an sich ist sinnvoll. Das Problem ist die falsche Scope-Berechnung für Reviewer und Access.

### Personal -> Shared

1. Owner ändert `visibility`
2. Status bleibt `personal`
3. Bot ist nicht mehr rein fachlich personal, aber technisch im Personal-Status gefangen
4. Submit ist blockiert

Dieser Flow ist derzeit kaputt.

## Testing-Flow fuer Approver

Code:

- `lib/Service/BotService.php:enableTesting()`
- Feld `testing_enabled_by`
- Laufzeitpfad: `lib/Webhook/TalkHandler.php` -> `lib/Service/BotService.php:processMessage()`

Aktueller Ablauf:

- Approver klickt "Test Bot"
- `testing_enabled_by` wird gesetzt

Aber:

- dieses Feld wird im Access-Pfad **nirgends ausgewertet**
- weder `userCanAccessBot()`
- noch Picker/Public-Liste
- noch Webhook/Talk-Handler

### Fazit

`Enable Test` ist aktuell **kein echter Zugriffs- oder Freigabemechanismus**, sondern nur gespeicherte Metadaten.

Wenn die fachliche Idee war "nur der Reviewer darf den Pending-Bot testweise nutzen", dann ist das aktuell nicht umgesetzt.

Zusatzproblem bei versionierten Updates:

- wenn ein bereits freigegebener Bot geaendert wurde, liegen die neuen Werte nur in `pending_changes`
- der Talk-Laufzeitpfad verarbeitet aber den Live-Bot und ignoriert `pending_changes`
- ein Approver testet damit bei Pending-Updates in Talk weiterhin die alte Live-Version

Fazit:

- neue Pending-Bots sind eher zu offen
- Pending-Updates bestehender Bots sind fuer den Approver dagegen nicht real testbar

## Frontend- und UX-Inkonsistenzen

### 1. Frontend ignoriert `visibilities` aus dem Permissions-Endpoint

`GET /api/v1/permissions` liefert:

- `permissions`
- `visibilities`

Im Frontend wird aber praktisch nur `permissions` genutzt. Das Formular rendert die Optionen statisch:

- `personal`
- `global`
- `groups`
- `teams`

Folgen:

- `global` wird auch Nicht-Admins direkt angeboten
- die eigentliche Einschränkung passiert erst im Backend
- das ist an sich tolerierbar, aber in Kombination mit dem Pending-Access-Bug gefährlich

### 2. Gruppen-/Team-Auswahl ist sehr offen

`SettingsController::groups()` versucht breit Gruppen zu listen.

Je nach Nextcloud-Konfiguration kann das bedeuten:

- Nutzer sehen mehr Gruppen als ihre eigenen
- sie können Bots für fremde Gruppen vorbereiten

Das ist nicht zwingend falsch, aber aus Governance-Sicht auffällig.

### 3. Pending-Tooling wird im Formular nicht sauber geladen

Bei `pending_changes` werden Textfelder, Visibility etc. aus Pending geladen.

Die Tool-Auswahl wird aber separat via `/bots/{id}/tools` geladen und damit aus dem Live-Bot, nicht aus `pending_changes['tools']`.

Folge:

- bei Pending-Änderungen kann das Formular suggerieren, man bearbeite die Pending-Version
- die Tool-Selektion spiegelt aber eher die Live-Version

Das ist kein Kernproblem des Rechte-Systems, aber ein realer Inkonsistenzpunkt.

## Lokale Laufzeitvalidierung

Validiert auf der lokalen Docker-Instanz:

- Container `master-nextcloud-1` läuft
- App `educai` ist aktiviert
- Version laut `occ app:list`: `2.30.0`
- App `circles` ist aktiviert
- App-Pfad im Container: `/var/www/html/apps-extra/educai`

Datenbankschema in `oc_educai_bots` enthält alle relevanten Approval-Felder:

- `visibility`
- `allowed_groups`
- `allowed_teams`
- `approval_status`
- `submitted_at`
- `approved_by`
- `approved_at`
- `testing_enabled_by`
- `pending_changes`

Zum Zeitpunkt der Analyse gab es in der lokalen DB keine aktiven `pending`-Bots, daher wurde die fachliche Fehlfunktion primär per Codepfad-Analyse und nicht per UI-Reproduktion belegt.

## Testlage

Im Container laufen aktuell diese relevanten Tests erfolgreich:

- `tests/unit/Service/PermissionServiceTest.php`
- `tests/unit/Service/BotServiceTest.php`

Lokal im Workspace scheitert `phpunit` gegen den uebergeordneten Nextcloud-Bootstrap mit `Not installed`; im laufenden Container funktionieren die Tests.

### Was aktuell getestet ist

- `canApproveBot()` blockiert Self-Approval und verlangt passenden Scope
- `canPublishBotToScope()` fuer Teams
- Owner-Edit eines freigegebenen Shared-Bots erzeugt `pending_changes`
- erneutes Editieren einer bereits versionierten Pending-Version bleibt im Pending-Overlay
- Self-Approval wird abgewiesen

### Was aktuell nicht getestet ist

- `personal -> groups/teams/global`
- Zugriff auf `pending`-Bots durch normale Nutzer
- `testing_enabled_by` als Test-Gate
- Approval von Scope-Aenderungen anhand des Ziel-Scopes
- Wechsel `groups -> global`
- Wechsel `team A -> team B`

Die gravierendsten fachlichen Fehler sind damit aktuell **nicht** von Tests abgedeckt.

## Bewertung: Funktioniert das aktuell?

### Was funktioniert

- Rollenmodell fuer Admin / Group-Admin / Team-Moderator ist grundsätzlich angelegt
- neue Shared-Bots koennen fuer normale Nutzer als `draft` angelegt und submitted werden
- freigegebene Shared-Bots koennen versioniert geaendert werden, ohne die Live-Version sofort zu ueberschreiben
- Self-Approval fuer Shared-Bots ist blockiert

### Was nicht sauber funktioniert

- `personal -> shared` ist faktisch kaputt
- neue `pending`-Bots sind vor Approval bereits nutzbar
- `Enable Test` steuert keinen realen Testzugriff
- Approval-Zuständigkeit bei Scope-Wechseln basiert auf dem alten Scope

## Konkrete Problemstellen

### Problem A: `personal -> shared` bleibt im Status `personal`

Ort:

- `lib/Service/BotService.php:updateBot()`

Wirkung:

- Shared-Upgrade kann weder direkt live gehen noch sauber in Draft/Pending wechseln

Priorität:

- hoch

### Problem B: `pending` wird im Access-Pfad wie `approved` behandelt

Ort:

- `lib/Service/BotService.php:userCanAccessBot()`
- `lib/Service/BotService.php:getAvailableBotsForUserEnriched()`
- `lib/Webhook/TalkHandler.php`
- `lib/Reference/BotReferenceProvider.php`

Wirkung:

- neue Bots sind vor Freigabe bereits sichtbar und benutzbar

Priorität:

- sehr hoch

### Problem C: Approver wird nach altem statt nach beantragtem Scope ermittelt

Ort:

- `lib/Service/PermissionService.php:canApproveBot()`
- `lib/Service/BotService.php:getPendingApprovals()`

Wirkung:

- falsche Personen koennen Scope-Aenderungen freigeben

Priorität:

- sehr hoch

### Problem D: `testing_enabled_by` ist aktuell wirkungslos

Ort:

- gesetzt in `enableTesting()`
- aber im Access-Pfad nirgends gelesen

Wirkung:

- Reviewer-Testprozess ist fachlich nicht implementiert

Priorität:

- hoch

### Problem E: Approver prueft bei Pending-Updates oft die falsche Version

Ort:

- `src/views/PersonalBots.vue`
- `lib/Webhook/TalkHandler.php`
- `lib/Service/BotService.php:processMessage()`

Wirkung:

- Queue-Preview zeigt fuer Updates eher Live- als Pending-Daten
- Talk-Test laeuft bei versionierten Updates gegen die Live-Version

Priorität:

- hoch

## Empfohlene technische Korrekturen

### 1. Einheitliches Konzept fuer "effective review target"

Es braucht eine zentrale Methode, die fuer Approval-Entscheidungen den **Zielzustand** bestimmt:

- bei `pending_changes`: Ziel aus `pending_changes`
- sonst aktueller Bot-Zustand

Diese Zielzustandslogik muss dann verwendet werden in:

- `canApproveBot()`
- `getPendingApprovals()`
- ggf. Reviewer-UI

### 2. `personal -> shared` muss Status neu berechnen

Beim Wechsel weg von `personal` sollte `updateBot()` den Status aktiv neu bestimmen:

- direkt `approved`, wenn `canPublishBotToScope(...)`
- sonst `draft` oder `pending`

Der Status darf in diesem Fall nicht einfach `personal` bleiben.

### 3. Pending-Zugriff unterscheiden zwischen "neuer Bot" und "Update eines bereits live freigegebenen Bots"

Mindestens zwei fachliche Fälle muessen getrennt werden:

- `pending` fuer neuen Bot
  - nur Owner und ggf. `testing_enabled_by` duerfen zugreifen
- `pending` fuer Update eines bereits freigegebenen Bots
  - Live-Version bleibt fuer bisherige Zielgruppe nutzbar

Das kann man modellieren ueber:

- neues Statusmodell
- oder Zusatzregel: `pending` + `approved_at === null` ist nicht public

### 4. `testing_enabled_by` wirklich erzwingen

Wenn Review-Test gewollt ist, muss `userCanAccessBot()` fuer neue Pending-Bots so etwas pruefen:

- Owner immer
- `testing_enabled_by` darf testen
- sonst niemand

### 5. Frontend auf `visibilities` aus dem Backend umstellen

Dann zeigt das UI nur Optionen an, die fachlich ueberhaupt vorgesehen sind.

## Empfehlung fuer weitere Arbeit

Wenn diese Logik repariert werden soll, wuerde ich als Reihenfolge empfehlen:

1. `userCanAccessBot()` fuer `pending` korrigieren
2. `canApproveBot()` auf Ziel-Scope umstellen
3. `personal -> shared` in `updateBot()` reparieren
4. Tests fuer genau diese vier Flows ergänzen:
   - personal -> team
   - neuer pending group-bot darf vor approval nicht sichtbar sein
   - approved group-bot -> global darf nicht durch group-admin approvbar sein
   - `enableTesting()` darf nur reviewer-spezifischen Zugriff freischalten

## Schlussbewertung

Die aktuelle Implementation hat eine gute Grundidee:

- getrennte Scopes
- Rollenbasierte Direktfreigabe
- Draft/Approval
- Versionierung ueber `pending_changes`

Aber die Kombination aus `visibility`, `approval_status` und `pending_changes` ist derzeit nicht konsequent zu Ende modelliert.

Der auffälligste Business-Bug ist der von dir vermutete Fall:

- **Personal-Bot erstellen**
- **später auf Team/Gruppen/Global wechseln**

Dieser Flow ist aktuell im Backend nicht sauber abgebildet und bleibt in einem inkonsistenten Status hängen.

Zusätzlich gibt es einen noch kritischeren Governance-Fehler:

- **neue Pending-Bots koennen schon vor Freigabe fuer den Ziel-Scope erreichbar sein**
- **und Scope-Eskalationen koennen vom falschen Approver freigegeben werden**

Damit ist die aktuelle Rechte- und Approval-Verwaltung fachlich noch nicht verlässlich genug.
