# AI-Kontext, gemeinsame Callbacks und gezielte Änderungen

Verwende für normale Aufrufe weiterhin die globalen `phore_ai_*`-Funktionen.
Ihre Signaturen und Rückgabewerte bleiben erhalten. Für zusammenhängende
Arbeitsschritte erhalten sie `options['ai_context']`. Alternativ kannst du
`new AiContext()` erstellen und die entsprechenden Methoden direkt aufrufen.
Beide Wege verwenden dieselben Traits und dieselbe Ausführungslogik.

Die Beispiele setzen `vendor/autoload.php` und konfigurierte Zugangsdaten
voraus. Die jeweils zweite Variante ist eine Alternative, kein zusätzlicher
notwendiger Aufruf. Das ausführbare CLI-Beispiel liegt unter
[`examples/ai-context.php`](../examples/ai-context.php).

## Text erzeugen und vorhandenen Text bearbeiten

Die globale Funktion behält `($prompts, $options)`. Bestehender Text wird über
`options['input']` übergeben:

```php
$generated = phore_ai_text('Schreibe eine kurze Begrüßung.');
$edited = phore_ai_text('Korrigiere nur die Rechtschreibung.', [
    'input' => 'Herzlich wilkommen in unserer Praxis.',
]);
```

Dieselben Aufgaben mit der neuen Methodensignatur:

```php
use Phore\AiHarness\AiContext;

$context = new AiContext();
$generated = $context->text('Schreibe eine kurze Begrüßung.');
$edited = $context->text(
    'Korrigiere nur die Rechtschreibung.',
    input: 'Herzlich wilkommen in unserer Praxis.',
    options: ['debug_log' => true],
);
```

`input: null` bedeutet Generierung. Ein gesetzter String, auch `''`, ist ein
Bearbeitungsziel. Beim Editieren liefert das Modell nur die Änderungen; die
Funktion setzt den vollständigen Ergebnistext lokal zusammen und gibt ihn als
String zurück. Sie gibt nicht die abschließende Bestätigung des Modells zurück.
Es gibt keine zusätzliche `editText()`-Methode.

## Eine oder mehrere Dateien bearbeiten

Die globale Funktion behält ihre bisherigen Argumente einschließlich der
optionalen Klasse für die abschließende Antwort:

```php
$summary = phore_ai_edit_file(
    'Korrigiere die Rechtschreibung in beiden Dateien.',
    ['intro.md', 'contact.md'],
    options: ['debug_log' => true],
);
```

Die direkte Variante verwendet `file()`:

```php
$context = new \Phore\AiHarness\AiContext();
$summary = $context->file(
    'Korrigiere die Rechtschreibung in beiden Dateien.',
    input: ['intro.md', 'contact.md'],
    options: ['debug_log' => true],
);
```

Ein einzelner Pfad ist ebenfalls erlaubt. `input` bezeichnet hier die
Zielpfade, nicht einen optionalen Text. Ohne Zielpfad kann keine Datei
angelegt werden. Fehlende Dateien dürfen in vorhandenen Verzeichnissen
angelegt werden; bestehende Dateien werden eingelesen. Unlesbare Dateien,
Verzeichnisse, doppelte kanonische Zielpfade und nicht als UTF-8-Text
verwendbare Inhalte werden abgelehnt. PDFs, Office-Dateien und andere
Binärformate sind keine Ziele dieses Text-Editors.

Für eine strukturierte Zusammenfassung bleibt der alte dritte Parameter
verfügbar. In der Context-Methode wird er zur Option `output_class`:

```php
final readonly class FileSummary
{
    public function __construct(public string $summary) {}
}

$result = phore_ai_edit_file(
    'Aktualisiere den Titel.', 'intro.md', FileSummary::class,
);

// Alternative:
$result = (new \Phore\AiHarness\AiContext())->file(
    'Aktualisiere den Titel.',
    'intro.md',
    ['output_class' => FileSummary::class],
);
```

Die Klasse beschreibt nur die Rückmeldung, niemals die Dateiinhalte.

## Strukturierte Ergebnisse und Objektänderungen

Eine Klasse bedeutet Generierung, ein vorhandenes Objekt bedeutet Änderung.
Der bestehende JSON-Patch-Vertrag mit Schema-, Hash- und Policy-Prüfungen
bleibt erhalten. Es gibt in `AiContext` nur eine `struct()`-Methode:

```php
final readonly class Article
{
    public function __construct(public string $title) {}
}

$article = phore_ai_struct('Erzeuge einen passenden Titel.', Article::class);
$edited = phore_ai_edit_struct('Kürze den Titel.', $article);
$articles = phore_ai_struct_array('Erzeuge drei Titel.', Article::class);
```

Direkt auf einem gemeinsamen Kontext:

```php
$context = new \Phore\AiHarness\AiContext();
$article = $context->struct('Erzeuge einen passenden Titel.', Article::class);
$edited = $context->struct('Kürze den Titel.', $article);
$articles = $context->structArray('Erzeuge drei Titel.', Article::class);
```

Objektänderungen erzeugen eine neue Instanz. Das ursprüngliche Objekt wird
weder verändert noch automatisch gespeichert. `dry_run`, `return_patch`,
`expected_hash`, `addressing` und die übrigen Optionen sind unter
[`struct-patch.md`](struct-patch.md) beschrieben. Es gibt weiterhin keine
automatische Reparatur einer ungültigen Objekt-Patch-Antwort und keinen
vollständigen Objekt-Rewrite als Fallback. Gemeinsame Context-Callbacks
können vor der Patch-Antwort Rückfragen klären. Direkt in den Prompt-Stack
gegebene Tools bleiben beim Bearbeiten von Objekten abgelehnt.

## Bilder erzeugen

```php
$image = phore_ai_image('Ein schlichtes Symbol für einen Kalender.', [
    'output_format' => 'png',
]);

// Alternative:
$context = new \Phore\AiHarness\AiContext();
$image = $context->image('Ein schlichtes Symbol für einen Kalender.', [
    'output_format' => 'png',
]);

$image->saveToFile(__DIR__ . '/calendar.png');
```

Bilder werden erst durch `saveToFile()` gespeichert. Gemeinsame Callbacks
stehen auch bei der Bildgenerierung zur Verfügung. Bildantworten bleiben
nicht-streamend, auch bei aktiviertem Debug-Logging.

## Zusammenhängende Aufrufe über eine ID oder ein Objekt

`ai_context` akzeptiert genau drei Formen:

| Wert | Verhalten |
| --- | --- |
| Nicht gesetzt oder `null` | Neuer, nicht registrierter Kontext pro Aufruf. |
| Nicht leerer String | Kontext mit dieser ID laden oder beim ersten Aufruf anlegen. |
| `AiContext`-Instanz | Genau dieses Objekt verwenden und fortschreiben. |

Ein String wie `'default'` ist ein selbst gewählter Registry-Schlüssel, keine
Provider-Response-ID und kein impliziter globaler Standard. Leere IDs und
andere Typen sind ungültig.

```php
use Phore\AiHarness\PromptType\FilePrompt;

phore_ai_text([
    'Lies die Projektbeschreibung. Bestätige anschließend nur die Aufnahme.',
    FilePrompt::fromFile(__DIR__ . '/briefing.md'),
], ['ai_context' => 'article-workflow']);

$title = phore_ai_text('Formuliere dazu einen Titel.', [
    'ai_context' => 'article-workflow',
]);
$intro = phore_ai_text('Schreibe jetzt die Einleitung.', [
    'ai_context' => 'article-workflow',
]);
```

Alternativ hält die Anwendung das Objekt selbst:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\FilePrompt;

$context = new AiContext();
$context->text([
    'Lies die Projektbeschreibung und bestätige die Aufnahme.',
    FilePrompt::fromFile(__DIR__ . '/briefing.md'),
]);
$title = $context->text('Formuliere dazu einen Titel.');

// Gemischte Nutzung setzt denselben Kontext fort.
$intro = phore_ai_text('Schreibe jetzt die Einleitung.', [
    'ai_context' => $context,
]);
```

Die Registry lebt nur innerhalb des PHP-Prozesses beziehungsweise Requests.
Sie ist kein persistenter Session-Speicher und wird nicht automatisch zwischen
Worker-Prozessen geteilt. In langlebigen Workern müssen IDs pro Auftrag oder
Benutzer getrennt und nach Abschluss entfernt werden:

```php
use Phore\AiHarness\Context\AiContextRegistry;

$context = AiContextRegistry::get('article-workflow'); // null, falls unbekannt
AiContextRegistry::forget('article-workflow');
AiContextRegistry::clear(); // beispielsweise an einer Job-Grenze
```

Ein bereits gehaltenes Objekt bleibt nach `forget()` gültig. Ein einzelner
Kontext darf nicht gleichzeitig oder reentrant verwendet werden. Für
unabhängige Ausführungspfade wird er vorher geklont.

## Allgemeine Callbacks, beispielsweise Rückfragen

Registriere gemeinsame `CallbackTool`-Instanzen beim Erzeugen des Kontexts
oder mit `addCallback()`. Die Traits erhalten sie bei jedem Aufruf. Sie werden
nicht automatisch beim Registrieren ausgeführt, sondern nur dann, wenn das
Modell das Tool aufruft. Die Anwendung implementiert die eigentliche
Interaktion, beispielsweise über CLI, eine Oberfläche oder einen Dienst.

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\CallbackTool;

$askUser = new CallbackTool(
    static function (string $question): string {
        fwrite(STDERR, $question . PHP_EOL . '> ');
        $answer = fgets(STDIN);
        if ($answer === false) {
            throw new RuntimeException('Keine Benutzerantwort verfügbar.');
        }
        return trim($answer);
    },
    name: 'ask_user_question',
    description: 'Stellt dem Benutzer eine notwendige Rückfrage.',
);

$context = new AiContext(callbacks: [$askUser]);
$text = phore_ai_text('Kläre zuerst den gewünschten Ton und schreibe den Text.', [
    'ai_context' => $context,
]);

// Alternative mit derselben Callback-Registrierung:
$text = $context->text('Kläre zuerst den gewünschten Ton und schreibe den Text.');
```

Alternativ: `$context->addCallback($askUser)` vor dem ersten oder zwischen
zwei Aufrufen. Für eine ID kannst du den Kontext vorab mit
`AiContextRegistry::resolve(['ai_context' => 'default'])` beziehen und dort
registrieren. Ein Aufruf mit dieser ID nutzt anschließend dieselben Tools.

Gemeinsame und aufrufbezogene Callbacks werden zusammengeführt. Gleichnamige,
unterschiedliche Tools werden nicht stillschweigend überschrieben. Dieselbe
Instanz darf im Prompt nochmals vorkommen; sie wird nur einmal registriert.
`write_text` und `write_files` sind für die Edit-Primitive reserviert.

Der Standardprompt erlaubt Rückfragen über bereitgestellte
Klärungs-Callbacks; ohne ein solches Tool bleibt der Ablauf nicht-interaktiv.
Ein eigener Systemprompt darf dem gewünschten Rückfrageablauf nicht
widersprechen. Halte Antworten und Tool-Ausgaben möglichst klein.

Nur `RecoverableToolException` wird als korrigierbarer Tool-Fehler an das
Modell zurückgegeben. Andere Callback-Exceptions, insbesondere fachliche
Abbrüche, werden unverändert an die Anwendung weitergereicht. Eine Rückfrage
zählt wie jeder andere Callback zur gemeinsamen Rundengrenze.

## Checkpoints und Rollback

Benannter Checkpoint mit den weiterhin bevorzugten Funktionen:

```php
use Phore\AiHarness\Context\AiContextRegistry;

$context = AiContextRegistry::resolve(['ai_context' => 'draft']);
phore_ai_text('Merke dir diese Ausgangslage: ...', ['ai_context' => $context]);
$context->setCheckpoint('ausgangslage');

$variantA = phore_ai_text('Schreibe eine sachliche Variante.', [
    'ai_context' => $context,
]);
$context->rollback('ausgangslage');
$variantB = phore_ai_text('Schreibe eine persönlichere Variante.', [
    'ai_context' => $context,
]);
```

Dasselbe direkt über ein neues Objekt:

```php
$context = new \Phore\AiHarness\AiContext();
$context->text('Merke dir diese Ausgangslage: ...');
$context->setCheckpoint('ausgangslage');
$variantA = $context->text('Schreibe eine sachliche Variante.');
$context->rollback('ausgangslage');
$variantB = $context->text('Schreibe eine persönlichere Variante.');
```

Ohne Namen wird ein normaler Checkpoint gesetzt. `rollback()` stellt den
**zuletzt gesetzten** Checkpoint wieder her, unabhängig davon, ob dieser
benannt oder unbenannt war:

```php
$context->setCheckpoint();
$trial = $context->text('Probiere eine andere Gliederung.');
$context->rollback();
$alternative = $context->text('Verwende stattdessen die ursprüngliche Struktur.');
```

Ein erneutes Setzen desselben Namens ersetzt diesen Marker und macht ihn zum
neuesten. Rollback verbraucht keine Marker; wiederholtes `rollback()` springt
wieder zum selben zuletzt gesetzten Checkpoint. Ein unbekannter Name oder ein
Rollback ohne vorhandenen Checkpoint wirft `LogicException`. Ein leerer Name
ist ungültig. Ein Checkpoint vor dem ersten Aufruf kann den leeren
Ausgangskontext wiederherstellen.

**Rollback betrifft ausschließlich den Gesprächszeiger.** Bereits geschriebene
Dateien, gesendete Nachrichten oder andere Callback-Nebenwirkungen werden
nicht rückgängig gemacht. Ebenso bleiben Callback-Registrierungen,
Konfiguration und angefallene Token-/Kostenstatistiken erhalten. Ein
Datei-Rollback oder eine fachliche Transaktion gehört in die Anwendung.

## Unabhängige Zweige durch Klonen

```php
$base = new \Phore\AiHarness\AiContext();
$base->text('Lies den gemeinsamen Ausgangskontext: ...');
$branch = clone $base;

$first = phore_ai_text('Entwickle Variante A.', ['ai_context' => $base]);
$second = phore_ai_text('Entwickle Variante B.', ['ai_context' => $branch]);

// Direkte Alternative für die Fortsetzung der jeweiligen Zweige:
$base->text('Verfeinere nur Variante A.');
$branch->text('Verfeinere nur Variante B.');
```

Der Klon übernimmt den aktuellen Gesprächsstand und die bisherigen
Checkpoint- und Callback-Registrierungen. Anschließende Änderungen an diesen
Registrierungen und am Gesprächszeiger beeinflussen das Original nicht.
Client-Objekt und Callback-Closures werden als externe Abhängigkeiten geteilt,
nicht tief kopiert. Eine Closure, die veränderlichen Anwendungszustand
referenziert, teilt diesen Zustand daher weiterhin. Dateien sind ebenfalls
nicht branch-isoliert.

## Optionen, Modelle und Provider-Kontext

`new AiContext()` nutzt den vorhandenen Standardclient und die
Keystore-Auflösung. Erst ein Methodenaufruf erzeugt einen Provider-Request.
Gemeinsame Defaults können im Konstruktor gesetzt werden:

```php
$context = new \Phore\AiHarness\AiContext([
    'model' => 'gpt-5-mini',
    'reasoning' => ['effort' => 'low'],
    'debug_log' => true,
]);

phore_ai_text('Analysiere genauer.', [
    'ai_context' => $context,
    'reasoning' => ['effort' => 'medium'],
]);

// Alternative:
$context->text('Analysiere genauer.', options: [
    'reasoning' => ['effort' => 'medium'],
]);
```

Die gemeinsamen Optionen bleiben `client`, `model`, `reasoning`, `timeout`,
`connect_timeout` und `debug_log`. Aufrufoptionen überschreiben Defaults nur
für diesen Aufruf. Bei einer neuen Registry-ID werden diese gemeinsamen
Optionen beim ersten Aufruf als Context-Defaults übernommen. `input`,
`output_class` und bildspezifische Einstellungen sind aufrufbezogen.

Der beim ersten Aufruf aufgelöste Client bleibt an diesen Kontext gebunden.
Ein anderer Client beziehungsweise Provider erfordert einen neuen Kontext.
Timeout-Optionen konfigurieren nur neu erzeugte Clients; eine bereits
bereitgestellte Client-Instanz behält ihre Einstellungen. Ein anderes Modell
kann pro Aufruf angegeben werden, soweit der Provider diese Fortsetzung
unterstützt. Ein abgelehnter Modellwechsel wird nicht durch stilles Vergessen
der bisherigen Unterhaltung umgangen.

Intern verkettet der Harness abgeschlossene Antworten über
`previous_response_id`. Folgeaufrufe müssen daher das bisherige Quellmaterial
nicht erneut als HTTP-Payload übertragen. Sie enthalten die neuen Eingaben
und die für den aktuellen Aufruf gültigen Tools und Ausgabevorgaben.
Innerhalb einer Callback-Schleife bleiben Instructions, Tools und
Structured-Output-Schema in allen Runden erhalten.

Aufrufbezogene Systemprompts, Tools und Ausgabeformate werden nicht als
allgemeine dauerhafte Context-Konfiguration übernommen. Wiederkehrende
Systemvorgaben müssen deshalb in den betreffenden Aufrufen wieder angegeben
werden. Gemeinsam registrierte Callbacks sind davon ausgenommen.

## Patch-Vertrag und Teilfehler

Die interne Schreiboperation verwendet für Text `edits` und für Dateien eine
Liste `files` mit jeweils `filename` und `edits`:

```json
{
  "files": [
    {
      "filename": "targetFile1",
      "edits": [
        {"search": "Alter Titel", "replacement": "Neuer Titel"},
        {"search": "Ein alter Satz.", "replacement": "Ein neuer Satz."}
      ]
    }
  ]
}
```

Eine vollständige Ersetzung hat für das jeweilige Ziel genau eine Operation:

```json
{"edits": [{"search": null, "replacement": "Vollständiger neuer Text."}]}
```

Alle Suchtexte werden gegen denselben Originalstand geprüft. Jeder gesetzte
Suchtext muss nicht leer, exakt und eindeutig sein. Auch überlappende
Vorkommen desselben Suchtexts gelten als mehrdeutig. Verschachtelte oder
überlappende Edits sind nicht erlaubt; benachbarte Edits sind erlaubt. Eine
leere `replacement` löscht die Fundstelle. Eine leere `edits`-Liste meldet ein
unverändertes Ergebnis und legt auch keine fehlende Datei an. Full-Rewrite
und gezielte Edits dürfen innerhalb eines Ziels niemals gemischt werden.
Unterschiedliche Dateien dürfen unterschiedliche Modi verwenden.

Das Modell muss bei Mehrdeutigkeit den Suchausschnitt präzisieren. Es gibt
keinen automatischen Rewrite-Fallback. Whitespace, BOM und Zeilenenden werden
nicht für unscharfe Vergleiche normalisiert; außerhalb der ersetzten Bereiche
bleiben die Bytes erhalten.

Bei einem ungültigen Edit wird innerhalb dieser Datei nichts geändert.
Andere gültige Dateien desselben Batchs werden geschrieben und bleiben
bestehen. Das Tool meldet pro Datei `applied`, `unchanged` oder `failed`, bei
Fehlern zusätzlich Ursache und Wiederholbarkeit. Nur fehlgeschlagene,
korrigierbare Dateien müssen erneut vorgeschlagen werden. Bereits erfolgreiche
Ziele werden innerhalb dieses Aufrufs nicht erneut geschrieben.

Ein ungültiger Batch-Umschlag, doppelte Zielangaben oder ein Pfad außerhalb
der erlaubten Ziele wird dagegen vor allen Schreiboperationen abgelehnt.
Änderungen durch externe Writer seit dem Einlesen werden geprüft und nicht
als korrigierbarer Suchfehler behandelt. Nicht korrigierbare Dateifehler dürfen
nicht erneut angewendet werden.

Vor dem Austausch wird der vollständige Kandidat in eine temporäre Datei im
selben Verzeichnis geschrieben, die Bytezahl geprüft und der Dateimodus
übernommen. Anschließend erfolgt der Rename. Dies vermeidet partielle
Inhalte beim regulären Dateiaustausch, ersetzt aber keine gemeinsame Sperre
gegen nicht kooperierende externe Writer. Inode, Eigentümer, ACLs und andere
Dateisystem-Metadaten sind keine zugesicherte Transaktionssemantik.

Nach maximal **fünf Callback-Runden insgesamt** endet der Ablauf. Dazu zählen
Schreibversuche, Korrekturen und gemeinsame Rückfragen; mehrere Callback-Calls
in einer Modellantwort gehören zu derselben Runde. Ein zusätzlicher Request
kann nach der fünften Runde noch die abschließende Antwort liefern, es wird
aber kein sechster Callback-Durchlauf ausgeführt. Die Grenze gilt pro
Funktions-/Methodenaufruf, nicht für die Lebensdauer des Kontexts.

Bei offenen Datei-Fehlern oder ausgeschöpftem Callback-Limit liefert
`FileEditException::$files` die bisherigen Ergebnisse. Erfolgreiche Dateien
bleiben geschrieben. Andere fachliche Callback- oder Transportfehler behalten
ihre bisherigen Exception-Typen; auch sie können bereits ausgeführte
Seiteneffekte nicht rückgängig machen. Ein neuer Aufruf darf diese Arbeit
nicht blind wiederholen. Syntaxprüfung, Lint und fachliche Validierung sind
separate Anwendungsschritte.

## Cached-Tokens und Kosten

`debug_log: true` gibt im abschließenden `RunStatistics`-Eintrag auch
`tokens_cached` aus. Eigene Logger erhalten denselben Wert über
`$event->statistics?->tokens_cached`. Er summiert die vom Provider gemeldeten
`usage.input_tokens_details.cached_tokens` des Aufrufs. Ein gemeldeter Wert
`0` ist von fehlenden Angaben (`null`, in der Konsole `n/a`) verschieden.

```php
phore_ai_text('Fasse den bisherigen Kontext zusammen.', [
    'ai_context' => 'article-workflow',
    'debug_log' => true,
]);

$usage = get_ai_usage_stats();
printf("Cached input: %d\n", $usage['cachedInputTokens']);
echo $usage['totalCostUsd'] === null
    ? "Estimated total: unknown\n"
    : sprintf("Estimated total USD: %.8f\n", $usage['totalCostUsd']);
```

Die direkte Variante ist
`$context->text('Fasse zusammen.', options: ['debug_log' => true])`.
Die bereits vorhandene globale Statistik und Kostenschätzung berücksichtigen
Cached-Input mit dem hinterlegten Modellpreis. `knownCostUsd` enthält den
preislich bekannten Teilbetrag; `totalCostUsd` ist bei unvollständigen Angaben
`null`. Die Preise sind eine datierte Schätzung, keine Abfrage der tatsächlichen
Abrechnung. Cached-Tokens sind **Teil der Input-Tokens**, keine zusätzlichen
Tokens; sie dürfen nicht ein zweites Mal zu den Gesamttokens addiert werden.

Response-Verkettung und Prompt-Caching sind getrennte Mechanismen. Ein
Context- oder Checkpoint-Zeiger garantiert keinen Cache-Hit und verlängert
keine Cache-Laufzeit. Auch bei `previous_response_id` bleibt die Vorgeschichte
Modellkontext und kann als Input abgerechnet werden. Ein Cache spart
Provider-Verarbeitung und gegebenenfalls Kosten, nicht Kontextfensterplatz.
Bei Modellwechseln darf kein modellübergreifender Cache-Hit vorausgesetzt
werden. Cache-Warming ist bewusst nicht implementiert.

Die Verfügbarkeit gespeicherter Provider-Antworten und die Lebensdauer des
Prompt-Caches sind ebenfalls verschieden. Abgelaufene oder nicht zugreifbare
Response-IDs werden als Provider-Fehler sichtbar; der Harness startet nicht
heimlich eine inhaltsleere neue Unterhaltung. Er führt hier keine automatische
Kontextzusammenfassung oder dauerhafte lokale Gesprächsspeicherung ein.

Provider-Referenzen:
[Conversation state](https://developers.openai.com/api/docs/guides/conversation-state),
[Prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching).

## Aufbau und Kompatibilität

`AiContext` verwaltet nur Kontextzustand, Konfiguration, gemeinsame Callbacks
und die Ausführungskoordination. Die Operationen liegen in separaten Dateien
unter `src/Context/Traits/`: `TextTrait`, `FileTrait`, `StructTrait`,
`StructArrayTrait` und `ImageTrait`. Die globalen Funktionen lösen lediglich
den Kontext auf und reichen ihre bisherigen Argumente weiter.

Die globale API erhält in diesem Schritt keinen Breaking Change. Änderungen
an ihrem Aufrufvertrag sind für einen späteren Schritt reserviert. Der
interne Modell-Callback `write_files` verwendet jetzt das strukturierte
`files`-/`edits`-Format statt paralleler `filenames`-/`contents`-Listen.
Anwendungen, die interne Tool-Payloads in eigenen Mocks fest verdrahten,
müssen diese Mocks entsprechend aktualisieren. Unlesbare Dateien werden aus
Sicherheitsgründen nicht mehr wie neue, leere Dateien behandelt.
