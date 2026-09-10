# Proposal: Edit Multiple

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–10: Proposal angelegt |
| 2026-09-04 | dermatthes | §§ 3, 6, 8.5, 9–10: Patch-Modus, Format, Prompt und Fallback ergänzt |

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
 *     write_mode?: 'auto'|'replace'|'patch',
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

## § 6 Schreibwerkzeuge im Batch-Modus

Der Harness bietet zwei kontrollierte Schreibmechanismen: vollständigen Ersatz über `write_files` und für lokale Änderungen an großen bestehenden Textdateien einen Patch-Modus. `write_mode` steuert die Auswahl: `replace` stellt nur `write_files` bereit, `patch` nur den Patch-Modus und `auto` beide Werkzeuge. Im Auto-Modus wählt das Modell pro Datei genau einen Mechanismus; beide Mechanismen dürfen für dieselbe Datei innerhalb eines Auftrags nicht gemischt werden.

### § 6.1 Vollständiger Batch-Replace

Das Tool `write_files` akzeptiert in genau einem Aufruf eine oder mehrere freigegebene Write-Datei-IDs und den jeweils vollständigen resultierenden Inhalt. Read-only-IDs und unbekannte IDs werden vor jedem Dateizugriff abgewiesen. Alle Argumente, Duplikate, Zielrollen und aktuellen Dateihashes werden vollständig validiert, bevor der erste Schreibvorgang beginnt.

Vorgeschlagenes logisches Tool-Schema:

```json
{
  "ids": ["w1", "w2"],
  "contents": ["vollständiger Inhalt 1", "vollständiger Inhalt 2"]
}
```

Die Listen müssen dieselbe Länge besitzen. Für jede bestehende Write-Datei wird vor dem Schreiben geprüft, ob ihr Hash noch dem Manifest entspricht; bei zwischenzeitlicher Änderung bricht der gesamte Batch vor dem ersten Write mit einem Konflikt ab. Danach werden Inhalte zunächst in temporäre Dateien im jeweiligen Zielverzeichnis geschrieben und erst nach erfolgreicher Vorbereitung ersetzt. Eine echte dateisystemübergreifende Transaktion ist nicht möglich und wird nicht zugesichert; Fehler und bereits erfolgte Ersetzungen müssen in der Exception nachvollziehbar sein.

### § 6.2 Patch-Modus für große Dateien

Ein Patch-Modus ist sinnvoll, wenn eine große bestehende Textdatei nur lokal geändert wird: Das Modell gibt dann nur entfernte und hinzugefügte Zeilen mit kleinem unverändertem Kontext aus, statt den vollständigen neuen Dateiinhalt erneut zu übertragen. Er ist dagegen ungeeignet für binäre Dateien, neue Dateien, fast vollständige Umschreibungen oder Änderungen, deren Ausgangskontext nicht exakt gelesen wurde. `write_files` bleibt deshalb der robuste Standard und der Fallback für kleine Dateien sowie breite Änderungen.

Für OpenAI-Responses-Modelle wird vorrangig das native Tool `{"type":"apply_patch"}` empfohlen. Laut [OpenAI-Dokumentation](https://developers.openai.com/api/docs/guides/tools-apply-patch) erzeugt es `apply_patch_call`-Objekte mit genau einer Operation `create_file`, `update_file` oder `delete_file`; bei `update_file` enthält `operation.diff` einen hunk-basierten Diff ohne Dateiheader. Für diesen Helper werden nur `update_file` auf bestehenden freigegebenen Write-Zielen zugelassen; Erzeugen erfolgt über `write_files`, Löschen und Umbenennen bleiben außerhalb des Scopes.

Beispiel einer erwarteten nativen Operation, wobei `path` die kurze Manifest-ID und keinen frei erzeugten Dateisystempfad enthält:

```json
{
  "type": "apply_patch_call",
  "operation": {
    "type": "update_file",
    "path": "w1",
    "diff": "@@ function renderTitle()\n-    return oldTitle;\n+    return newTitle;"
  }
}
```

Der Harness löst `w1` erst nach erfolgreicher Rollen-, Hash- und Kontextprüfung auf den kanonischen Zielpfad auf, wendet den Patch ohne Shell-Ausführung auf eine temporäre Kopie an und ersetzt die Originaldatei erst nach vollständigem Erfolg. Ein Hunk ohne passenden Kontext, eine unbekannte oder Read-only-ID, ein Hash-Konflikt, Pfadwechsel, Erzeugen, Löschen, binäre Inhalte und Teilanwendung führen fail-closed zu einem fehlgeschlagenen Tool-Ergebnis; die Originaldatei bleibt unverändert.

### § 6.3 Alternativformat und Modell-Prompt

Wenn der verwendete Provider das native Responses-Tool nicht unterstützt, wird als Fallback ein Freeform-Tool `apply_patch` mit der von OpenAI Codex veröffentlichten [Lark-Grammatik](https://github.com/openai/codex/blob/main/codex-rs/core/assets/tools/apply_patch.lark) empfohlen. Codex beschreibt dieses Tool ausdrücklich als [für GPT-5-Modelle geeignet](https://github.com/openai/codex/blob/main/codex-rs/core/src/tools/handlers/apply_patch_spec.rs). Sein Format kapselt `*** Update File: w1` und einen oder mehrere `@@`-Hunks zwischen `*** Begin Patch` und `*** End Patch`; es wird als rohe Freeform-Eingabe und nicht als JSON-String übergeben. Das klassische Git-Unified-Diff ist als eigenes Modellformat weniger passend, weil Dateiheader und korrekte Zeilenzähler zusätzliche Fehlerquellen schaffen; seine Sicherheitssemantik bleibt jedoch Vorbild: [`git apply`](https://git-scm.com/docs/git-apply) erwartet Kontextzeilen, verwirft standardmäßig den gesamten Patch, wenn ein Hunk nicht passt, und weist Pfade außerhalb des Arbeitsbereichs ab.

Vorgeschlagene zusätzliche System-Instruktion:

```text
Edit only write targets listed in the immutable manifest.
For each changed file, use exactly one write mechanism:
- use write_files for new, small, or broadly rewritten files;
- use apply_patch for localized changes to large existing text files.
Before patching, read the exact current lines around every edit.
Use only the manifest ID as the patch path and include unchanged context in every hunk.
Never create, delete, rename, or patch read-only or unknown targets.
Do not mix write_files and apply_patch for the same file.
If a patch fails, read the current range again and retry once with smaller hunks or more context.
If it still fails and the full file fits the configured budget, fall back to write_files; otherwise return a clear error.
```

Native `apply_patch` und das Freeform-Fallback werden niemals gleichzeitig angeboten. Nach jedem Patch-Aufruf meldet der Harness Status, Datei-ID, angewendete Hunk-Zahl, neue Byte-Länge und SHA-256-Hash oder bei Fehlern einen knappen Grund sowie den ersten nicht passenden Kontext zurück, damit das Modell gezielt nachlesen und höchstens einmal korrigieren kann.

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

Im Replace-Modus erscheinen vollständige neue Inhalte ausschließlich in den Argumenten des einmaligen `write_files`-Aufrufs und werden weder in dessen Antwort noch in der abschließenden Text- oder Struct-Ausgabe wiederholt. Im Patch-Modus werden für große bestehende Textdateien nur die betroffenen Hunks mit unverändertem Kontext übertragen. Die Auswahl richtet sich nicht allein nach der Dateigröße: Bei einer fast vollständigen Umschreibung ist ein Replace kleiner und robuster, während bei wenigen lokalen Änderungen ein Patch deutlich weniger Output-Tokens benötigt. Das technische Tool-Ergebnis bleibt in beiden Fällen kompakt.

## § 9 Ausführungsablauf und Fehlerfälle

1. Parameter normalisieren, Pfade kanonisieren, Rollen, `context_mode` und `write_mode` validieren.
2. Manifest mit Hashes erstellen und Kontextmodus pro Datei bestimmen.
3. Initialen Request mit Arbeitsauftrag, Manifest, eingebetteten Dateien, `read_files` und den durch `write_mode` erlaubten Schreibwerkzeugen erzeugen.
4. Null oder mehr Batch-Reads ausführen; Zahl der Tool-Runden und gelesene Gesamtbytes begrenzen.
5. Im Replace-Modus genau einen Batch-Write ausführen; im Patch-Modus eine logisch zusammengehörige Patch-Phase mit höchstens einem erfolgreichen Patch pro Datei ausführen; im Auto-Modus den Mechanismus je Datei festlegen und nicht mischen.
6. Jeden Replace oder Patch vollständig gegen Rollen, Manifest-Hash, erlaubte Operationen und aktuellen Dateistand prüfen, zunächst temporär anwenden und erst nach vollständigem Erfolg ersetzen.
7. Text oder Structured Output erzeugen und erst nach erfolgreicher Änderung oder `complete_without_changes` zurückgeben.

Nicht lesbare Read-only-Dateien, Rollenüberschneidungen, unbekannte IDs, ungültige Ranges, Budgetüberschreitungen im Eager-Modus, Hash-Konflikte, nicht passende Patch-Hunks, unerlaubte Patch-Operationen und teilweise fehlgeschlagene Writes werden mit spezifischen Exceptions beziehungsweise fehlgeschlagenen Tool-Ergebnissen gemeldet. Ein Patch-Fehler darf genau einen kontrollierten Read-und-Retry-Zyklus auslösen; anschließend ist nur ein budgetkonformer vollständiger Replace zulässig, andernfalls bricht der Auftrag klar ab. Wenn keine inhaltliche Änderung erforderlich ist, wird `complete_without_changes` verwendet, damit „kein Write notwendig“ von „Modell hat das Write vergessen“ unterscheidbar bleibt.

## § 10 Entscheidung und Umsetzungsschritte

Empfohlen wird die Umsetzung als eigener Helper `phore_ai_edit_multiple()` mit gemeinsamem internem Executor für `phore_ai_edit_file()`. Der Standardmodus ist `context_mode: auto` und `write_mode: auto`: Write-Dateien werden soweit budgetkonform vorab eingebettet, Read-only-Dateien zunächst nur als Manifest angeboten, vollständige Änderungen werden batchfähig geschrieben und lokale Änderungen an großen bestehenden Textdateien bevorzugt gepatcht. Wenn der aktuelle OpenAI-Transport das native Responses-`apply_patch` unterstützt, wird dieses Format verwendet; andernfalls kann dieselbe Semantik über genau ein grammatikgebundenes Codex-Freeform-Tool bereitgestellt werden.

Die Umsetzung sollte in fünf getrennt prüfbaren Schritten erfolgen: erstens Manifest, Rollenvalidierung und DTOs; zweitens Batch-Read mit Budgets und Ranges; drittens Batch-Replace mit Hash-Konfliktschutz und vorbereiteten temporären Dateien; viertens Integration von Text-/Struct-Ergebnis und `complete_without_changes`; fünftens nativer Patch-Transport beziehungsweise Freeform-Fallback mit Parser, fail-closed Validierung, Retry-Grenze, Prompt, Unit- und End-to-End-Tests. Lokale Suche, Token-Schätzung und Summary-Caching bleiben optionale Folgeausbaustufen.
