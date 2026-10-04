# AI-Kontext, vorbereitete Prompts und gezielte Änderungen

Verwende für normale Aufrufe weiterhin die globalen `phore_ai_*`-Funktionen.
Ihre Signaturen und Rückgabewerte bleiben erhalten. Für zusammenhängende
Arbeitsschritte erhalten sie `options['ai_context']`. Alternativ kannst du
`new AiContext()` erstellen und die entsprechenden Methoden direkt aufrufen.
Beide Wege verwenden dieselben Traits und dieselbe Ausführungslogik.

Die Beispiele setzen `vendor/autoload.php` und konfigurierte Zugangsdaten
voraus. Der Einstieg beginnt bewusst mit den globalen Helpern unter
[`examples/01-basic-functions.php`](../examples/01-basic-functions.php).
[`examples/02-context.php`](../examples/02-context.php) führt danach den
expliziten `AiContext` mit Checkpoint, Rollback und State-Export/Import ein.
Das fokussierte `do()`-Beispiel steht unter
[`examples/04-do.php`](../examples/04-do.php).

## Arbeitsschritte ohne Textausgabe

`do()` führt einen Arbeitsschritt im selben Conversation-Kontext aus, behält
Tools, Reasoning, Logging und den neuen Response-Cursor, verwirft aber die
eigentliche Textantwort. Dadurch können mehrere Vorarbeiten direkt vor einer
späteren Ausgabe stehen:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\ToolType\WebAccessTool;

$context = new AiContext(
    prompts: [new WebAccessTool()],
    options: ['model' => 'gpt-5-mini'],
);

$context->do('Recherchiere die aktuellen Fakten zu Thema X.');
$context->do('Prüfe die gefundenen Fakten auf Widersprüche.');
$result = $context->text('Fasse die geprüften Fakten kurz zusammen.');
```

Die globale Kurzform ist `phore_ai_do($prompts, $throw = false, $options = [])`;
mit `options['ai_context']` teilt sie denselben Conversation-Cursor wie die
anderen Helper.

Standardmäßig liefert `do()` `true` bei fachlichem Erfolg und `false` bei
einem fachlichen Misserfolg. Technische Fehler, ungültige Task-Contracts und
Callback-Exceptions bleiben normale Exceptions. Mit `throw: true` wird ein
fachlicher Misserfolg als `DoException` ausgegeben; alternativ kann eine
Unterklasse angegeben werden. `DoException` besitzt dafür einen finalen
Constructor, sodass jede Unterklasse automatisch denselben Datenvertrag erbt:

```php
use Phore\AiHarness\DoException;

final class VerificationFailed extends DoException
{
}

$context->do('Verifiziere die Quelle.', throw: true);
$context->do('Verifiziere die Quelle.', throw: VerificationFailed::class);
```

Die Exception enthält die vom Modell strukturiert gelieferten Felder
`message`, `details` und `data`. Größere Begründungen oder relevante
Textausschnitte gehören in `details`; `data` enthält kurze diagnostische
Strings. Auf der niedrigeren `PhoreAi`-Fassade wird der Prompt wie gewohnt
vorher mit `with()` gesetzt:

```php
$ok = (new \Phore\AiHarness\PhoreAi())
    ->with(new \Phore\AiHarness\PromptType\TextPrompt(
        'Prüfe die Quelle.',
        allowInstructions: true,
    ))
    ->do();
```

## Einfache Entscheidungen und Klassifizierungen

Für Entscheidungen, bei denen kein DTO benötigt wird, stellt `AiContext`
`choice()`, `choices()`, `yesNo()`, `rank()` und `score()` bereit. Der
Prompt steht aus Konsistenzgründen immer zuerst. Er ist nullable, aber nicht
optional: `null` fordert ausdrücklich den Standard-Prompt der Methode an.

```php
$tag = $context->choice('Welcher Tag passt am besten?', ['news', 'guide', 'review']);

$tag = $context->choice(null, [
    'news' => 'Aktuelle Nachricht oder neue Entwicklung.',
    'guide' => 'Konkrete Anleitung.',
    'review' => 'Bewertung oder Vergleich.',
]);

$tags = $context->choices(null, ['news', 'guide', 'review'], min: 1, max: 2);
$ready = $context->yesNo(null, allowNull: true);
$ranking = $context->rank(null, ['news', 'guide', 'review']);
$score = $context->score(null);
```

Die öffentliche Methodensignatur ist:

```php
public function choice(?string $prompt, array $choices, bool $allowNull = false, AiOptions|array|string|null $options = null): string|int|null;
public function choices(?string $prompt, array $choices, int $min = 0, ?int $max = null, bool $allowNull = false, AiOptions|array|string|null $options = null): ?array;
public function yesNo(?string $prompt, bool $allowNull = false, AiOptions|array|string|null $options = null): ?bool;
public function rank(?string $prompt, array $choices, bool $allowNull = false, AiOptions|array|string|null $options = null): ?array;
public function score(?string $prompt, bool $allowNull = false, AiOptions|array|string|null $options = null): ?float;
```

### Choice-Werte und optionale Beschreibungen

Die kurze Form ist eine normale Liste. Jeder Eintrag ist gleichzeitig Name und
Rückgabewert:

```php
$tag = $context->choice(null, ['news', 'guide', 'review']);
```

Wenn der Name allein nicht erklärt, wann eine Auswahl passt, kann eine Map
verwendet werden. Der Array-Key ist der erlaubte String-/Integer-Wert, der
Array-Wert ist die optionale Beschreibung:

```php
$priority = $context->choice(null, [
    10 => 'Sofort bearbeiten; blockiert einen laufenden Prozess.',
    20 => 'Normal priorisieren; kein akuter Blocker.',
]);
```

Beschreibungen werden als Quelldaten eingebettet, nicht als zusätzliche
Instruktionen. Das Modell liefert intern nur Indizes; der Harness mappt sie
lokal auf die erlaubten Werte zurück. Dadurch können keine anderen Strings oder
Integer als Rückgabewert durchrutschen. Doppelte Listeneinträge, leere
String-Werte und ungültige Beschreibungen werden vor dem Provider-Aufruf
abgelehnt.

`choices()` erlaubt mehrere eindeutige Werte. `min` ist standardmäßig `0`,
`max: null` bedeutet Anzahl der vorhandenen Choices. `min > max` oder ein
`max` oberhalb der Anzahl Choices ist ungültig. Die Rückgabe bleibt in der vom
Modell bevorzugten Reihenfolge.

Bei `choice()` kann das Array unter `options['selected']` einen aktuell
gesetzten String-/Integer-Wert enthalten. Bei `choices()` ist `selected` eine
Liste. Das ist Kontext für die Neubewertung; es erzwingt nicht, dass die
bisherige Auswahl erhalten bleibt.

### Standard-Prompts und Model-Kurzform

Bei `prompt: null` werden folgende kurzen Arbeitsanweisungen erzeugt:

- `choice()`: `Choose exactly one option that best matches the current context.`
- `choices()`: z. B. `Choose between 1 and 2 options that best match the current context.`; bei identischem Minimum/Maximum wird `Choose exactly N ...` verwendet.
- `yesNo()`: `Answer the current question from the conversation with yes or no.`; mit `allowNull: true` kommt `null` für nicht zuverlässig entscheidbare Fälle hinzu.
- `rank()`: `Rank all options from best match to worst match for the current context.`
- `score()`: `Score how well the current context matches the task on a scale from 0.0 to 1.0.`

Die üblichen per-call Optionen können als Array oder `AiOptions` übergeben
werden. Für den häufigsten Fall reicht auch direkt der Modellname:

```php
$tag = $context->choice(null, ['news', 'guide'], 'gpt-5-mini');
```

Alle fünf Simple-Type-Methoden verwenden `allowNull = false`. Meldet das Modell
explizit, dass der vorhandene Kontext für eine zuverlässige Bestimmung nicht
ausreicht, wird mit `allowNull: true` `null` zurückgegeben. Beim Default `false`
wird stattdessen `TaskErrorException` geworfen; ungültige Providerwerte bleiben
normale Validierungsfehler. Ein bestimmtes leeres Ergebnis von `choices()` ist
weiterhin `[]` und nicht `null`. `rank()` enthält jeden Wert genau einmal und
`score()` wird lokal auf `0.0..1.0` geprüft.

Das ausführbare Gegenüberstellungsbeispiel steht unter
[`examples/03-simple-types.php`](../examples/03-simple-types.php).

Die gleichen Operationen gibt es als `phore_ai_choice()`,
`phore_ai_choices()`, `phore_ai_yes_no()`, `phore_ai_rank()` und
`phore_ai_score()`. Mit demselben `options['ai_context']`-Key setzen diese
Helper innerhalb des PHP-Prozesses denselben Conversation-Cursor fort. Für
Checkpoint/Rollback sowie `exportState()`/`importState()` wird die
Objekt-API verwendet; siehe
[`examples/01-basic-functions.php`](../examples/01-basic-functions.php).

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
vollständigen Objekt-Rewrite als Fallback. Vorbereitete Context-Tools können
vor der Patch-Antwort Rückfragen klären. Nur zusätzlich für diesen einzelnen
Objekt-Patch übergebene Tools bleiben abgelehnt.

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

Bilder werden erst durch `saveToFile()` gespeichert. Vorbereitete Context-Tools
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

$context = new AiContext(prompts: [
    'Nutze die Projektbeschreibung als Grundlage für alle folgenden Aufgaben.',
    FilePrompt::fromFile(__DIR__ . '/briefing.md'),
]);
$title = $context->text('Formuliere dazu einen Titel.');

// Gemischte Nutzung setzt denselben Kontext fort.
$intro = phore_ai_text('Schreibe jetzt die Einleitung.', [
    'ai_context' => $context,
]);
```

Vorbereitete Daten-Prompts werden beim ersten Root-Request eingebracht und
liegen danach in der Response-Historie. `SystemPrompt` und `ToolType` werden
dagegen bei jedem Request erneut angehängt, damit Instructions und Tools aktiv
bleiben. Ein Rollback auf den leeren Ausgangspunkt lädt die vorbereiteten Daten
beim nächsten Request erneut.

Die Registry lebt nur innerhalb des PHP-Prozesses beziehungsweise Requests
und wird nicht automatisch zwischen Worker-Prozessen geteilt. Für Session- oder
Request-Grenzen kann ein Context seinen fortsetzbaren Cursor explizit als JSON
exportieren und später in einen neu mit demselben Prompt-/Tool-Setup aufgebauten
Context importieren. Der vollständige Ablauf einschließlich Checkpoint und
State-Export steht in [`examples/02-context.php`](../examples/02-context.php).

In langlebigen Workern müssen Registry-IDs pro Auftrag oder Benutzer getrennt
und nach Abschluss entfernt werden:

```php
use Phore\AiHarness\Context\AiContextRegistry;

$context = AiContextRegistry::get('article-workflow'); // null, falls unbekannt
AiContextRegistry::forget('article-workflow');
AiContextRegistry::clear(); // beispielsweise an einer Job-Grenze
```

Ein bereits gehaltenes Objekt bleibt nach `forget()` gültig. Ein einzelner
Kontext darf nicht gleichzeitig oder reentrant verwendet werden. Für
unabhängige Ausführungspfade wird er vorher geklont.

## Vorbereitete Prompts und Tools

Der Constructor nimmt unter `prompts` dieselben Strings, `PromptType`- und
`ToolType`-Objekte wie die globalen `phore_ai_*`-Funktionen. Ein
`CallbackTool` ist deshalb kein Sonderfall und benötigt keine eigene
Registrierungs-API.

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\PromptType\PromptFile;
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\WebAccessTool;

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
);

$context = new AiContext(prompts: [
    new PromptFile(__DIR__ . '/SKILL.md'),
    new WebAccessTool(),
    $askUser,
]);

$text = $context->text('Kläre fehlende Angaben bei Bedarf und schreibe den Text.');
```

Context-Tools stehen bei `do()`, `text()`, `file()`, `struct()`, `structArray()`,
`image()`, `choice()`, `choices()`, `yesNo()`, `rank()` und `score()` zur Verfügung. Bei Objekt-Patches bleiben nur Tools gesperrt, die
zusätzlich ausschließlich an diesen einzelnen `struct()`-Aufruf übergeben
werden. Gleichnamige unterschiedliche `CallbackTool`-Instanzen werden
abgelehnt. `write_text` und `write_files` sind als Context-Tool-Namen
reserviert, weil die Edit-Primitive sie intern verwenden.

Der Standardprompt erlaubt Rückfragen über bereitgestellte Klärungs-Tools.
`RecoverableToolException` bleibt der korrigierbare Tool-Fehler; andere
Callback-Exceptions werden an die Anwendung weitergereicht.
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
Dateien, gesendete Nachrichten oder andere Tool-Nebenwirkungen werden
nicht rückgängig gemacht. Ebenso bleiben vorbereiteter Prompt-/Tool-Stack,
Konfiguration und angefallene Token-/Kostenstatistiken erhalten. Ein
Datei-Rollback oder eine fachliche Transaktion gehört in die Anwendung.

## State zwischen Sessions exportieren und importieren

`exportState()` serialisiert nur den fortsetzbaren Conversation-Cursor und
die Checkpoints plus Metadaten (`version`, `exportedAt`, `provider`,
`setupHash`). Prompts, Tools, Client und Modell bleiben im Anwendungscode und
werden beim nächsten Request wie gewohnt neu konstruiert.

```php
$state = $context->exportState();
$_SESSION['ai_context_state'] = $state;

$next = new \Phore\AiHarness\AiContext(
    prompts: $samePromptsAndTools,
    options: ['model' => 'gpt-5-mini'],
);
$next->importState($_SESSION['ai_context_state']);
```

Der Setup-Hash wird aus den vorbereiteten Prompt-/Tool-Verträgen gebildet.
Ändert sich dieses Setup oder der Provider, wirft `importState()` standardmäßig
`ResumeStateException`. Optional kann bewusst blank neu gestartet werden:

```php
$next->importState(
    $state,
    new \Phore\AiHarness\ResumeOptions(
        onMismatch: \Phore\AiHarness\ResumeOptions::ON_MISMATCH_RESTART,
    ),
);
```

Für `CallbackTool`-Closures, die über Session-Grenzen resumiert werden sollen,
ist ein expliziter stabiler Tool-Name sinnvoll; automatisch aus einer Closure
abgeleitete Namen sind nicht als persistente Identität gedacht.

Der JSON-State enthält keinen vollständigen Gesprächsinhalt, sondern nur
Provider-IDs. Deren Lebensdauer ist providerabhängig. OpenAI dokumentiert für
gespeicherte Responses standardmäßig mindestens ungefähr 30 Tage
Application-State-Retention. Danach beziehungsweise wenn der Provider den
Response-Cursor nicht mehr kennt, schlägt erst der nächste AI-Aufruf mit der
normalen Provider-Exception fehl; `importState()` macht keinen Remote-Preflight.

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

Der Klon übernimmt den aktuellen Gesprächsstand, den vorbereiteten
Prompt-/Tool-Stack und die Checkpoints. Prompt- und Tool-Objekte sowie der
Client werden dabei nicht tief kopiert. Eine Closure, die veränderlichen
Anwendungszustand
referenziert, teilt diesen Zustand daher weiterhin. Dateien sind ebenfalls
nicht branch-isoliert.

## Optionen, Modelle und Provider-Kontext

`new AiContext()` nutzt den vorhandenen Standardclient und die
Keystore-Auflösung. Gemeinsame Defaults können als Array oder als `AiOptions`
gesetzt werden:

```php
use Phore\AiHarness\AiContext;
use Phore\AiHarness\AiOptions;

$config = AiOptions::fromArray([
    'model' => 'gpt-5-mini',
    'reasoning' => ['effort' => 'low'],
    'debug_log' => true,
]);

$context = new AiContext(options: $config);
assert(AiOptions::fromArray($config) === $config);

$other = new AiContext(options: [
    'model' => 'gpt-5-mini',
    'debug_log' => true,
]);
```

`AiOptions::fromArray()` akzeptiert ein Array oder eine vorhandene
`AiOptions`-Instanz. Eine Instanz wird unverändert zurückgegeben. Arrays
werden validiert; unbekannte Keys wie `modle` führen zu
`InvalidArgumentException`. Unterstützt werden `client`, `model`, `reasoning`,
`timeout`, `connect_timeout` und `debug_log`.

Aufrufoptionen überschreiben diese Defaults nur für den jeweiligen Aufruf.
`input`, `output_class` sowie bild- und patch-spezifische Einstellungen bleiben
aufrufbezogen. Der beim ersten Aufruf aufgelöste Client bleibt an den Kontext
gebunden. Ein anderes Modell kann pro Aufruf angegeben werden, soweit der
Provider die Fortsetzung akzeptiert.

Intern verkettet der Harness abgeschlossene Antworten über
`previous_response_id`. Vorbereitete Daten-Prompts liegen danach in der
Conversation-History; vorbereitete `SystemPrompt`- und `ToolType`-Items werden
bei jedem Request erneut angehängt. Response-Verkettung ist weiterhin nicht
gleichbedeutend mit Prompt-Caching.
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

`AiContext` verwaltet nur Kontextzustand, vorbereitete Prompts/Tools,
Konfiguration und die Ausführungskoordination. Die Operationen liegen in
separaten Dateien
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


## Context direkt an Domain-Objekte binden

`AiContextTrait` bindet genau einen `AiContext` an ein beliebiges
Domain-Objekt. Dadurch stehen dieselben High-Level-Operationen mit dem Prefix
`ai_` direkt am Objekt zur Verfügung:

```php
final readonly class Mail
{
    use AiContextTrait;

    public function __construct(AiContext $context)
    {
        $this->ai_set_context($context);
    }
}

$answer = $mail->ai_text('Was ist die aktuelle offene Frage?');
$ready = $mail->ai_yes_no('Kann diese Mail sicher beantwortet werden?');
$action = $mail->ai_choice('Welche Aktion passt?', ['reply', 'forward']);
```

Der Context wird genau einmal gebunden. Das verhindert, dass ein Domain-Objekt
nach ersten AI-Aufrufen unbemerkt auf einen anderen Conversation-Cursor
wechselt. Ohne explizite Bindung erstellt der erste `ai_*`-Aufruf lazy einen
leeren `AiContext`. Alternativ initialisiert `ai_prepare()` einen Context mit
Prepared Prompts, Tools und Defaults.

Das Trait delegiert `do`, `text`, `file`, `struct`, `structArray`,
`image`, `choice`, `choices`, `yesNo`, `rank` und `score`.
Zusätzlich stehen `ai_get_content_by_id()` für die exakte Content-Auflösung
und `ai_query_content()` für AI-gestützte Content-Selektion zur Verfügung.
`ai_query_content()` liefert ein `AiContentResultSet`, das erneut abgefragt
oder mit `withContext()` auf einen frischen beziehungsweise bestehenden
Conversation-Branch gesetzt werden kann.

Außerdem stehen `ai_set_checkpoint()`, `ai_rollback()`,
`ai_export_state()`, `ai_import_state()` und
`ai_get_response_id()` zur Verfügung. Der eigentliche Context ist über
`ai_get_context()` erreichbar.

Das Trait speichert nur die Referenz auf den Context. Daher kann es auch in
readonly Domain-Objekten verwendet werden: Die Property-Referenz bleibt
readonly, während der gebundene `AiContext` seinen internen Conversation-State
weiterentwickelt.
