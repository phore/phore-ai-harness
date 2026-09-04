# Proposal: Edit Multiple

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–10: Proposal angelegt |

## § 1 Kurzfassung

`phore_ai_edit_multiple()` soll einen Arbeitsauftrag mit beliebig vielen lokalen Dateien ausführen. Dateien werden beim Aufruf ausdrücklich als nur lesbar oder beschreibbar klassifiziert. Der Request darf mehrere Dateien gesammelt lesen und mehrere beschreibbare Dateien mit genau einem Batch-Aufruf aktualisieren. Zusätzlich liefert die Funktion wie `phore_ai_edit_file()` entweder Text oder strukturierte Meta-Informationen zurück.

Empfohlen wird eine eigene Funktion statt weiterer Überladung von `phore_ai_edit_file()`: Der bestehende Helper bleibt der einfache Fall für direkt eingebettete, beschreibbare Zieldateien; `phore_ai_edit_multiple()` übernimmt gemischte Rollen, bedarfsgesteuertes Lesen und Kontextbudgets. Intern sollen beide Funktionen dieselbe Ausführungs- und Schreiblogik verwenden.

## § 2 Ziele und Nicht-Ziele

Ziele sind eine eindeutige Trennung von Read-only- und Write-Dateien, ein einziger Modell-Request für zusammenhängende Änderungen, Batch-Aufrufe für Lesen und Schreiben, eine harte serverseitige Dateifreigabe, optionale strukturierte Ergebnisdaten und ein kontrollierbares Kontextbudget. Das Modell darf keine nicht freigegebenen Pfade lesen oder schreiben und keine Read-only-Datei über das Write-Tool verändern.

Nicht Ziel der ersten Version sind freie Verzeichniszugriffe, Shell-Zugriff, unbegrenztes Repository-Browsing, binäre Dateiänderungen, garantierte atomare Transaktionen über mehrere Dateisysteme oder automatische Aufteilung eines fachlich zusammenhängenden Auftrags auf mehrere Modell-Requests.

## § 3 Vorgeschlagene öffentliche API

```php
/**
 * @template T of object
 * @param string|PromptType|ToolType|array<int, string|PromptType|ToolType> $prompts
 * @param string|list<string> $readFiles
 * @param string|list<string> $writeFiles
 * @param class-string<T>|null $className
 * @param array{
 *     context_mode?: 'auto'|'eager'|'lazy',
 *     max_context_bytes?: int,
 *     max_file_bytes?: int,
 *     client?: OpenAiClient|string|null,
 *     model?: string,
 *     timeout?: int,
 *     connect_timeout?: int
 * } $options
 * @return ($className is class-string<T> ? T : string)
 */
function phore_ai_edit_multiple(
    string|PromptType|ToolType|array $prompts,
    string|array $readFiles = [],
    string|array $writeFiles = [],
    ?string $className = null,
    array $options = [],
): object|string;
```

Ein Pfad darf nur einmal vorkommen. Steht derselbe kanonische Pfad in beiden Parametern, ist der Aufruf ungültig, damit die Rolle nicht mehrdeutig ist. Mindestens eine Write-Datei ist erforderlich; reine Analyse bleibt Aufgabe von `phore_ai_text()` beziehungsweise `phore_ai_struct()` mit passenden Datei-Prompts.

Beispiel:

```php
$result = phore_ai_edit_multiple(
    prompts: 'Übertrage die Regeln aus dem Styleguide auf beide Templates.',
    readFiles: [__DIR__ . '/style-guide.md', __DIR__ . '/schema.json'],
    writeFiles: [__DIR__ . '/mail.html', __DIR__ . '/mail.txt'],
    className: EditResult::class,
    options: ['context_mode' => 'auto'],
);
```

## § 4 Dateimodell und Manifest

Vor dem Request werden alle Pfade kanonisiert, dedupliziert und in ein unveränderliches Manifest überführt. Jeder Eintrag enthält mindestens eine stabile Request-ID, den für das Modell sichtbaren Dateinamen, die Rolle `read` oder `write`, MIME-Type, Byte-Länge und einen SHA-256-Hash des eingelesenen Zustands. Nicht vorhandene Write-Dateien sind mit leerem Inhalt, Länge `0` und einem Kennzeichen `exists: false` zulässig; nicht vorhandene Read-only-Dateien führen dagegen zu einem Fehler, weil eine fehlende Quelle nicht sinnvoll als leere Information interpretiert werden sollte.

Das Modell verwendet in Tools vorzugsweise die kurze Request-ID statt frei erzeugter Pfade. Der Dateiname bleibt im Manifest und in jeder Tool-Antwort sichtbar. Dadurch sinkt die Argumentgröße, und Unterschiede zwischen relativen, absoluten und kanonischen Pfaden können nicht zu unbeabsichtigten Zugriffen führen.

## § 5 Read-Tool im Batch-Modus

Im Lazy- und Auto-Modus erhält das Modell ein Tool `read_files`. Ein Aufruf kann eine oder mehrere freigegebene Datei-IDs enthalten und liefert pro Datei ID, Dateiname, Inhalt, MIME-Type, Byte-Länge und Hash zurück. Read-only- und Write-Dateien dürfen gelesen werden; die Trennung betrifft ausschließlich das Schreiben.

Vorgeschlagenes logisches Tool-Schema:

```json
{
  "ids": ["r1", "w1"],
  "ranges": [
    {"id": "r1", "start_line": 1, "end_line": 250}
  ]
}
```

`ids` fordert vollständige Dateien an. `ranges` ist optional und erlaubt gezielte Ausschnitte; eine ID darf innerhalb eines Aufrufs nur einmal verwendet werden. Die konkrete PHP-Callback-Signatur muss Typen verwenden, die `phore/schema` sicher in ein striktes OpenAI-Tool-Schema übersetzen kann, beispielsweise parallele Listen oder dedizierte DTOs statt verschachtelter PHPDoc-Array-Shapes.

## § 6 Write-Tool im Batch-Modus

Das Tool `write_files` akzeptiert in genau einem Aufruf eine oder mehrere freigegebene Write-Datei-IDs und den jeweils vollständigen resultierenden Inhalt. Read-only-IDs und unbekannte IDs werden vor jedem Dateizugriff abgewiesen. Alle Argumente, Duplikate, Zielrollen und aktuellen Dateihashes werden vollständig validiert, bevor der erste Schreibvorgang beginnt.

Vorgeschlagenes logisches Tool-Schema:

```json
{
  "ids": ["w1", "w2"],
  "contents": ["vollständiger Inhalt 1", "vollständiger Inhalt 2"]
}
```

Die Listen müssen dieselbe Länge besitzen. Für jede bestehende Write-Datei wird vor dem Schreiben geprüft, ob ihr Hash noch dem Manifest entspricht; bei zwischenzeitlicher Änderung bricht der gesamte Batch vor dem ersten Write mit einem Konflikt ab. Danach werden Inhalte zunächst in temporäre Dateien im jeweiligen Zielverzeichnis geschrieben und erst nach erfolgreicher Vorbereitung ersetzt. Eine echte dateisystemübergreifende Transaktion ist nicht möglich und wird nicht zugesichert; Fehler und bereits erfolgte Ersetzungen müssen in der Exception nachvollziehbar sein.

## § 7 Ergebnis und Meta-Informationen

Ohne `$className` liefert die Funktion den abschließenden Modelltext. Mit `$className` nutzt sie denselben Structured-Output- und Hydration-Mechanismus wie `phore_ai_edit_file()` und gibt eine Instanz dieser Klasse zurück. Die strukturierte Rückgabe ist unabhängig vom technischen Ergebnis des Write-Tools und kann fachliche Meta-Informationen wie Zusammenfassung, Warnungen, betroffene Bereiche oder Folgearbeiten enthalten.

Das Write-Tool selbst gibt nur kompakte technische Daten zurück: Datei-ID, Dateiname, geschriebene Bytes und neuen SHA-256-Hash. Es gibt keinen vollständigen Inhalt zurück, weil dieser bereits im Tool-Argument und im lokalen Dateisystem vorhanden ist. Die Funktion akzeptiert ein Modellresultat nur, nachdem mindestens ein erfolgreicher `write_files`-Aufruf erfolgt ist.

Beispiel für fachliche Meta-Daten:

```php
final readonly class EditResult
{
    /** @param list<string> $changedFiles */
    public function __construct(
        public string $summary,
        public array $changedFiles,
        public array $warnings,
    ) {}
}
```

## § 8 Kontextoptimierung

### § 8.1 Eager, Lazy und Auto

`eager` bettet alle Datei-Inhalte direkt in den initialen Prompt ein und eignet sich für wenige kleine Dateien. `lazy` übergibt zunächst nur das Manifest und lässt benötigte Inhalte gesammelt über `read_files` abrufen. `auto` wird als Standard empfohlen: kleine Write-Dateien werden eingebettet, damit das Modell ihren Ausgangszustand sicher kennt; Read-only-Dateien und große Write-Dateien werden zunächst nur im Manifest angeboten.

### § 8.2 Harte Budgets

`max_context_bytes` begrenzt die Summe eingebetteter Inhalte, `max_file_bytes` die direkte Einbettung einer einzelnen Datei. Ein Überschreiten führt nicht zu stiller Trunkierung. Im Auto-Modus wechselt die betroffene Datei auf Lazy Reading; im Eager-Modus wird vor dem API-Aufruf eine klare Exception ausgelöst. Die Byte-Grenzen sind nur eine robuste Näherung für Tokens und können später durch einen optionalen modellabhängigen Token-Schätzer ergänzt werden.

### § 8.3 Ausschnitte und Suche

Für große Textdateien reduziert zeilenweises Range-Reading den Kontext deutlich. Als spätere Erweiterung kann ein lokal ausgeführtes `search_files`-Tool Treffer mit Dateiname, Zeilenbereich und kleinem Kontextfenster liefern; anschließend liest das Modell nur relevante Bereiche. Suche und Chunking dürfen niemals Schreibrechte erweitern und sollten Ergebnisse nach Datei-ID deduplizieren.

### § 8.4 Wiederverwendung und Caching

Manifest und unveränderte Read-only-Inhalte sollten in stabiler Reihenfolge vor wechselnden Arbeitsanweisungen stehen, damit Provider-Prompt-Caching greifen kann. Hashes erlauben zusätzlich einen lokalen, requestübergreifenden Cache für bereits erzeugte Kurzindizes oder Zusammenfassungen. Solche abgeleiteten Inhalte müssen als Zusammenfassung gekennzeichnet sein und dürfen den Originalinhalt nicht ersetzen, wenn exakte Details für die Änderung erforderlich sind.

### § 8.5 Ausgabevolumen

Vollständige neue Inhalte erscheinen ausschließlich in den Argumenten des einmaligen `write_files`-Aufrufs und werden weder in dessen Antwort noch in der abschließenden Text- oder Struct-Ausgabe wiederholt. Für sehr große Änderungen kann ein späterer Patch-Modus Token sparen, sollte aber erst nach zuverlässiger Patch-Validierung, Konflikterkennung und Fallback auf vollständigen Inhalt eingeführt werden.

## § 9 Ausführungsablauf und Fehlerfälle

1. Parameter normalisieren, Pfade kanonisieren, Rollen und Optionen validieren.
2. Manifest mit Hashes erstellen und Kontextmodus pro Datei bestimmen.
3. Initialen Request mit Arbeitsauftrag, Manifest, eingebetteten Dateien sowie `read_files` und `write_files` erzeugen.
4. Null oder mehr Batch-Reads ausführen; Zahl der Tool-Runden und gelesene Gesamtbytes begrenzen.
5. Genau einen Batch-Write für alle in diesem Auftrag geänderten Write-Dateien ausführen.
6. Text oder Structured Output erzeugen und erst nach erfolgreichem Write zurückgeben.

Nicht lesbare Read-only-Dateien, Rollenüberschneidungen, unbekannte IDs, ungültige Ranges, Budgetüberschreitungen im Eager-Modus, Hash-Konflikte und teilweise fehlgeschlagene Writes werden mit spezifischen Exceptions gemeldet. Wenn keine inhaltliche Änderung erforderlich ist, benötigt das Protokoll entweder einen expliziten leeren bestätigenden `write_files`-Aufruf oder ein separates `complete_without_changes`-Signal; empfohlen wird das separate Signal, damit „kein Write notwendig“ von „Modell hat das Write vergessen“ unterscheidbar bleibt.

## § 10 Entscheidung und Umsetzungsschritte

Empfohlen wird die Umsetzung als eigener Helper `phore_ai_edit_multiple()` mit gemeinsamem internem Executor für `phore_ai_edit_file()`. Der Standardmodus ist `auto`, Write-Dateien werden soweit budgetkonform vorab eingebettet, Read-only-Dateien zunächst nur als Manifest angeboten, und Lesen sowie Schreiben erfolgen batchfähig. Diese Aufteilung hält den einfachen Helper klein, ohne die leistungsfähigere API mit impliziten Sonderfällen zu belasten.

Die Umsetzung sollte in vier getrennt prüfbaren Schritten erfolgen: erstens Manifest, Rollenvalidierung und DTOs; zweitens Batch-Read mit Budgets und Ranges; drittens Batch-Write mit Hash-Konfliktschutz und vorbereiteten temporären Dateien; viertens Integration von Text-/Struct-Ergebnis, Dokumentation und End-to-End-Tests. Patch-Modus, lokale Suche, Token-Schätzung und Summary-Caching bleiben optionale Folgeausbaustufen.
