# Contao Backend Event Search

Schnelle Volltextsuche über die Events **aller Kalender**, direkt auf der **Startseite des Contao-Backends**.
Die Suche basiert auf [Loupe](https://github.com/loupe-php/loupe) (SQLite, Tippfehlertoleranz, Präfixsuche,
Relevanz-Ranking).

## Berechtigung

Die Suche sehen **Administratoren** sowie Benutzer, die die Berechtigung **Event-Suche** erhalten haben. Sie lässt sich
im Benutzer (Rechtevergabe «Erweitert» oder «Benutzerdefiniert») oder in einer Benutzergruppe setzen, jeweils in der
Legende «Event-Suche» unterhalb der Kalender-Rechte. Contao führt Benutzer- und Gruppenrechte wie gewohnt zusammen.

Zusätzlich gilt für Nicht-Admins:

- Der Benutzer braucht Zugriff auf das Backend-Modul der Kalender (`backend_module`, Standard: `calendar`).
- Gefunden werden nur Events, die der Benutzer auch im Backend sehen darf. Geprüft wird mit denselben
  Data-Container-Votern wie in den Backend-Listen (`contao_dc.tl_calendar` und `contao_dc.tl_calendar_events`). Damit
  gelten automatisch die **Kalender-Rechte** (`calendars`) des Benutzers und seiner Gruppen und die Regeln anderer
  Bundles, z. B. die **Kalender-Container** des Event-Tools (`calendar_containers`). Das gilt auch für die Suche nach der
  ID.
- Die Kalender werden schon im Suchindex gefiltert (Anzahl Treffer und Limit stimmen). Danach wird jeder Treffer
  nochmals auf Event-Ebene geprüft.
- Ob ein Benutzer ein gefundenes Event bearbeiten oder die Teilnehmerliste öffnen darf, entscheiden weiterhin Contao und
  das Event-Tool.

In eigenem Code lässt sich die Berechtigung so prüfen:

```php
use Markocupic\ContaoBackendEventSearch\Security\BackendEventSearchPermissions;

$security->isGranted(BackendEventSearchPermissions::USER_CAN_SEARCH_EVENTS);
```

Nach der Installation bzw. dem Update `contao:migrate` ausführen: Es legt die Spalte `backendEventSearchp` in `tl_user`
und `tl_user_group` an.

Ein Klick auf einen Treffer öffnet das **Event zum Bearbeiten**. Daneben stehen zwei Operationen:

| Icon           | Operation                                                                             |
|----------------|---------------------------------------------------------------------------------------|
| `children.svg` | Eventauflistung des Kalenders (`table=tl_calendar_events&id=<Kalender-ID>`)           |
| `mgroup.svg`   | Teilnehmerliste (`tl_calendar_events_member`), nur wenn das Event-Tool installiert ist |

Ist die Eingabe eine Ganzzahl, wird zusätzlich nach der **ID** des Events gesucht. Ein Treffer über die ID steht immer
zuoberst und ist mit `ID: …` markiert. Für IDs gilt die Mindestlänge von 2 Zeichen nicht.

Unter dem Titel stehen die Freigabestufe (z. B. `FS: 3`, der Titel der Stufe erscheint als Tooltip; nur mit Event-Tool),
das Datum, der Kalender und die Leiter.

## Voraussetzungen

- Contao 5.3 oder Contao 6
- PHP 8.2+ mit `pdo_sqlite` (SQLite >= 3.35.0)
- Optional: [markocupic/sac-event-tool-bundle](https://github.com/markocupic/sac-event-tool-bundle) für die Suche nach
  Leitern (`tl_calendar_events_instructor`). Ohne Event-Tool wird das Feld `instructors` einfach ignoriert.

## Konfiguration

Ohne Konfiguration werden der **Titel** und die **Leiter** indexiert. Die indexierten Spalten von `tl_calendar_events`
lassen sich in der `config/config.yaml` der Installation festlegen:

```yaml
markocupic_contao_backend_event_search:
    # Spalten von tl_calendar_events. "instructors" ist ein virtuelles Feld mit den Namen
    # aller Leiter (tl_user.name via tl_calendar_events_instructor), Hauptleiter zuerst.
    # Die Reihenfolge bestimmt die Gewichtung im Relevanz-Ranking: Felder weiter oben zählen mehr.
    searchable_fields:
        - title
        - instructors
        - teaser
        - location

    # Sprachen für Tokenizer und Stemming (Standard: [de])
    languages: [de, fr]

    # Maximale Anzahl Treffer auf der Startseite (Standard: 25)
    hits_per_page: 25

    # Backend-Modul, auf das die Links zeigen (Standard: calendar)
    backend_module: calendar

    # Verzeichnis des Suchindex (Standard: %kernel.project_dir%/var/backend_event_search)
    index_dir: '%kernel.project_dir%/var/backend_event_search'
```

- Serialisierte Felder (z. B. Mehrfachauswahlen) werden flachgeklopft, HTML wird entfernt.
- Felder, die es in `tl_calendar_events` nicht gibt, werden ignoriert. Der Reindex-Befehl gibt dazu eine Warnung aus.
- Nicht verwendbar, weil immer im Index: `id`, `pid`, `startDate`, `calendarTitle`.
- Treffer in zusätzlichen Feldern (z. B. `teaser`) werden in der Trefferliste als kurzer Ausschnitt mit Feldname angezeigt.
- Nach einer Änderung der Konfiguration wird der Index bei der nächsten Suche automatisch neu aufgebaut.

## Suchindex

Der Index liegt standardmässig unter `var/backend_event_search` und wird so aktuell gehalten:

| Auslöser                                           | Aktion                                     |
|----------------------------------------------------|--------------------------------------------|
| Event speichern, kopieren, löschen                 | Event wird im Index aktualisiert/entfernt  |
| Kalender speichern (Titel) oder löschen            | Events des Kalenders werden aktualisiert   |
| Backend-Benutzer speichern (Name)                  | Events, die er leitet, werden aktualisiert |
| Cronjob (stündlich)                                | Index wird komplett neu aufgebaut          |
| Erste Suche bei leerem Index / geänderter Config   | Index wird komplett neu aufgebaut          |

Manuell neu aufbauen:

```bash
php vendor/bin/contao-console markocupic:backend-event-search:reindex
```

Der Neuaufbau passiert in einem temporären Verzeichnis, die Suche funktioniert währenddessen weiter.
Das Verzeichnis `var/backend_event_search` gehört nicht ins Deployment (es wird bei Bedarf neu erstellt).

## Funktionsweise für Entwickler

| Baustein                                                 | Aufgabe                                                                                              |
|----------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| `Search\EventDocumentProvider`                           | Liest die Events (inkl. Kalendertitel und Leiter) aus der Datenbank und baut daraus Loupe-Dokumente. |
| `Search\EventSearchIndex`                                | Kapselt Loupe: Suchen, Neuaufbau, einzelne Events aktualisieren oder löschen.                        |
| `Search\Highlighter`                                     | Escaped die Treffer und markiert die Fundstellen mit `<mark>`.                                       |
| `EventListener\AddSearchToWelcomeScreenListener`         | `parseBackendTemplate`-Hook: setzt das Suchfeld oben in `be_welcome` ein. Kein Template-Override.    |
| `EventListener\DataContainer\UpdateSearchIndexListener`  | DCA-Callbacks für `tl_calendar_events`, `tl_calendar` und `tl_user`.                                 |
| `Controller\BackendEventSearchController`                | `GET /contao/backend-event-search?q=…`, liefert die Treffer als JSON (nur mit Berechtigung).         |
| `Security\EventSearchAccess`                             | Prüft die Berechtigung, liefert die erlaubten Kalender und filtert die Treffer auf Event-Ebene.      |
| `Event\BuildSearchHitOperationsEvent`                    | Erweiterungspunkt: andere Bundles ersetzen die Operationen eines Treffers durch eigenes Markup.      |
| `Cron\ReindexEventsCron`, `Command\ReindexEventsCommand` | Kompletter Neuaufbau des Index.                                                                      |
| `public/backend-event-search.js`                         | Suche beim Tippen (Debounce), Tastaturnavigation (↑/↓, Esc), letzte Suche bleibt erhalten.           |

## Operationen anderer Bundles

Ohne weitere Bundles stehen neben jedem Treffer zwei Operationen: die Eventauflistung des Kalenders und, falls es
`tl_calendar_events_member` gibt, die Teilnehmerliste.

Andere Bundles können diese Operationen pro Treffer ersetzen. Dazu hören sie auf das Event
`Markocupic\ContaoBackendEventSearch\Event\BuildSearchHitOperationsEvent` und setzen eigenes Markup. Das Markup wird
unverändert eingefügt, das Bundle muss also selbst escapen (z. B. mit einem Twig-Template). Stimulus-Controller im
Markup werden automatisch verbunden.

```php
use Markocupic\ContaoBackendEventSearch\Event\BuildSearchHitOperationsEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
class SearchHitOperationsListener
{
    public function __invoke(BuildSearchHitOperationsEvent $event): void
    {
        // $event->getEventId(), $event->getDocument(), $event->getBackendModule()
        $event->setOperationsHtml($this->twig->render('…', [/* … */]));

        // Optional: link of the event title (null = no link, e.g. without permission)
        $event->setEditUrl($editUrl, $editTitle);
    }
}
```

Das SAC Event-Tool ([markocupic/sac-event-tool-bundle](https://github.com/markocupic/sac-event-tool-bundle)) nutzt das,
um dieselben Operationen, Icons und Berechtigungen wie im Dashboard «Ihre nächsten Events» anzuzeigen, inklusive des
aufklappbaren Menüs auf kleinen Bildschirmen.
