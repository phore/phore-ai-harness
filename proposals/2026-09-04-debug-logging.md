# Proposal: Optionales Debug-Logging für den AI Harness

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–9: Proposal angelegt |

## § 1 Kurzfassung

Der AI Harness erhält optionales, standardmäßig deaktiviertes Debug-Logging. Wird es für eine `PhoreAi`-Instanz oder über das Options-Array einer `phore_ai_*`-Funktion aktiviert, verwendet der Harness für alle von der OpenAI Responses API unterstützten Text- und Structured-Output-Aufrufe den vorhandenen Streaming-Pfad. Modelltext, Callback-Tool-Aufrufe, recoverable Fehler und eine aggregierte Abschlussstatistik werden mit einem eindeutigen Modellpräfix ausgegeben. Ohne aktiviertes Logging bleiben Ausführung und Ausgabe vollständig kompatibel zum bisherigen Verhalten.

## § 2 Ist-Zustand und Problem

`OpenAiClient::streamResponse()` und die Verarbeitung von Server-Sent Events existieren bereits, während `PhoreAi::run()`, `PhoreAi::runCastedArray()` und die Callback-Folgeaufrufe ausschließlich `createResponse()` verwenden. Callback-Tools werden in `resolveCallbackToolCalls()` in höchstens fünf Runden ausgeführt; Fehler aus JSON-Decoding, PHP-Argumentbindung, Typprüfung oder Callback-Ausführung beenden den Lauf derzeit sofort. `AiResponse::getUsage()` liefert nur die Usage der jeweiligen Response, sodass mehrere Responses eines Tool-Laufs nicht zu einer Gesamtsicht zusammengeführt werden. Ein gemeinsamer Logger und eine Laufstatistik fehlen.

## § 3 Ziele und Nicht-Ziele

Ziele sind eine opt-in Debug-Ausgabe ohne Änderung des Standardverhaltens, Streaming-Ausgabe mit lesbarem Modellpräfix, Sichtbarkeit jedes Callback-Tool-Aufrufs und seines Ergebnisses, rote Warnungen für recoverable Tool-Fehler, kontrollierte erneute Modellaufrufe zur Korrektur fehlerhafter Argumente sowie eine Abschlussstatistik über Fehler, Retries, Requests, Tool-Aufrufe, Tokens und Laufzeiten. Nicht Bestandteil sind persistente Logdateien, verteiltes Tracing, automatische Retries bei beliebigen HTTP-Fehlern, Änderungen an der fachlichen Tool-Schnittstelle oder die Ausgabe von Secrets und vollständigen Stacktraces.

## § 4 Öffentliche Konfiguration

### § 4.1 PhoreAi-Fassade

`PhoreAi` erhält einen optionalen Logger und eine immutable Konfigurationsmethode:

```php
use Phore\AiHarness\Logging\ConsoleLogger;
use Phore\AiHarness\PhoreAi;

$ai = (new PhoreAi())
    ->withLogger(new ConsoleLogger())
    ->withModel('gpt-5-mini');
```

Der Logger aktiviert den Debug-Modus für diese Instanz. Der bestehende Konstruktor bleibt kompatibel; eine eventuelle Logger-Übergabe wird nur als neuer optionaler letzter Parameter ergänzt. `withLogger()` folgt dem bestehenden Clone-Muster von `withModel()`, `with()` und `withOutput()`.

### § 4.2 Globale Funktionen

Alle Options-Arrays von `phore_ai_text()`, `phore_ai_struct()`, `phore_ai_struct_array()`, `phore_ai_image()` und `phore_ai_file()` erhalten den Schlüssel `debug_log`:

```php
$result = phore_ai_text('Erkläre den Status.', [
    'model' => 'gpt-5-mini',
    'debug_log' => true,
]);
```

`debug_log => true` verwendet den eingebauten `ConsoleLogger` auf `STDERR`, damit der eigentliche Rückgabewert beziehungsweise eine reguläre Ausgabe auf `STDOUT` nicht beschädigt wird. Alternativ akzeptiert `debug_log` eine Instanz des Harness-eigenen `LoggerInterface`. Fehlt der Schlüssel oder ist er `false`, wird kein Logger angelegt und der nicht-streamende Pfad bleibt aktiv. Ungültige Werte führen vor dem API-Aufruf zu einer `InvalidArgumentException`.

### § 4.3 Logger-Vertrag

Unter `Phore\AiHarness\Logging` wird ein kleiner, dependency-freier `LoggerInterface` eingeführt, der strukturierte Harness-Ereignisse entgegennimmt. Der Vertrag deckt mindestens Debug-Zeilen, Warnungen und die Abschlussstatistik ab. `ConsoleLogger` übernimmt Zeilenpufferung, Präfixe und ANSI-Farben. Dadurch entsteht keine neue Composer-Runtime-Abhängigkeit; Anwendungen können über einen Adapter weiterhin PSR-3 oder einen eigenen Logger anbinden.

## § 5 Ausgabeformat und Streaming

### § 5.1 Modelltext

Text-Deltas aus `response.output_text.delta` werden zeilenweise gepuffert. Jede sichtbare Zeile beginnt mit dem tatsächlich konfigurierten Modellnamen in eckigen Klammern, zum Beispiel:

```text
[gpt-5-mini] Erste Zeile der Modellantwort
[gpt-5-mini] Zweite Zeile der Modellantwort
```

Unvollständige letzte Zeilen werden beim Ende der Response oder des Laufs abgeschlossen. Chunk-Grenzen der SSE-Verbindung dürfen weder zusätzliche Zeilen noch wiederholte Präfixe erzeugen. Das Logging verändert den zusammengesetzten Rückgabewert nicht.

### § 5.2 Tool-Aufrufe

Jeder erkannte Callback-Tool-Aufruf wird vor der Ausführung mit Modell, Tool-Name, `call_id` und redigierten Argumenten geloggt. Nach der Ausführung folgen Status und Dauer; große Ergebnisse werden begrenzt oder nur mit Größe und Kurzvorschau ausgegeben. Authorization-Header, API-Keys und als sensibel erkannte Werte werden niemals geloggt.

Beispiel:

```text
[gpt-5-mini] TOOL get_weather call_id=call_123 args={"city":"Berlin"}
[gpt-5-mini] TOOL get_weather call_id=call_123 ok duration=12ms
```

### § 5.3 Warnungen

Recoverable Tool-Fehler werden als `WARNING` mit Modell, Tool, Fehlerklasse, kurzer Meldung und Retry-Zähler ausgegeben. `ConsoleLogger` stellt die gesamte Warnzeile auf einem farbfähigen Terminal rot dar und respektiert `NO_COLOR` sowie nicht-interaktive Ausgaben. Bei eigenen Loggern wird die Warnstufe strukturiert übergeben.

## § 6 Fehler- und Retry-Semantik

Als recoverable gelten Fehler beim Decodieren oder Validieren der Tool-Argumente, bei der PHP-Argumentbindung sowie fachliche Exceptions eines regulären `CallbackTool`. Sie werden nicht als erfolgreicher Tool-Output behandelt, sondern als maschinenlesbares `function_call_output` mit `ok: false`, Fehlerart und einer kurzen Korrekturanweisung an das Modell zurückgegeben. Das Modell kann daraufhin einen korrigierten Tool-Aufruf senden. Jeder fehlgeschlagene Aufruf erhöht `errors`; jeder daraus resultierende zusätzliche Modell-Request erhöht `retries`.

Die bestehende Obergrenze von fünf Callback-Runden bleibt als harte Gesamtgrenze erhalten und wird als benannte Konstante dokumentiert. Ist die Grenze erreicht, wird eine letzte rote Warnung ausgegeben und eine aussagekräftige Exception geworfen, statt eine möglicherweise unvollständige Response zurückzugeben. `TaskErrorException`, Transportfehler, Authentifizierungsfehler und nicht-recoverable interne Fehler werden geloggt und unverändert weitergeworfen; sie lösen keinen automatischen Retry aus.

## § 7 Laufstatistik

Für jeden öffentlichen `run*`-Aufruf wird ein neuer interner Laufkontext erzeugt. Er aggregiert alle initialen und durch Tools oder Fehler ausgelösten Folge-Responses. Die Abschlussstatistik wird in einem `finally`-Pfad genau einmal ausgegeben, auch wenn der Lauf mit einer Exception endet, und enthält mindestens:

- `status`: `ok` oder `failed`
- `requests`: Anzahl der API-Requests
- `tool_calls`: Anzahl ausgeführter Callback-Tool-Aufrufe
- `errors`: Anzahl der aufgetretenen Fehler
- `retries`: Anzahl der wegen recoverable Tool-Fehler zusätzlich ausgeführten Modell-Requests
- `tokens_in`, `tokens_out`, `tokens_total`: Summe aus allen verfügbaren Response-Usage-Daten
- `duration_total`, `duration_api`, `duration_tools`: monotone Laufzeiten für Gesamtlauf, API und Tool-Ausführung

Beispiel:

```text
[gpt-5-mini] STATS status=ok requests=3 tool_calls=2 errors=1 retries=1 tokens_in=1840 tokens_out=327 tokens_total=2167 duration_total=2.41s duration_api=2.32s duration_tools=0.09s
```

Responses ohne Usage-Daten werden bei den Tokens als null beziehungsweise nicht verfügbar berücksichtigt und nicht als null Tokens fehlinterpretiert. Bildgenerierung bleibt nicht-streamend, solange der Provider dafür keine kompatiblen Text-SSE-Ereignisse liefert, erzeugt bei aktiviertem Logging aber weiterhin Lifecycle-, Fehler- und Statistik-Einträge.

## § 8 Vorgesehene Änderungen

### § 8.1 Neue Logging-Komponenten

Unter `src/Logging/` werden `LoggerInterface`, `ConsoleLogger` und kleine unveränderliche Ereignis-/Statistiktypen angelegt. Die Formatierung und ANSI-Behandlung liegen ausschließlich im Console-Logger; `PhoreAi` erzeugt strukturierte Ereignisse und enthält keine Terminal-Steuercodes.

### § 8.2 Orchestrierung

`src/PhoreAi.php` bündelt den Request-Versand in einer privaten Methode, die abhängig vom Logger `createResponse()` oder `streamResponse()` aufruft, Usage und Zeit erfasst und sowohl den initialen Request als auch alle Callback-Folge-Requests gleich behandelt. Die Callback-Ausführung protokolliert Beginn, Ergebnis und Dauer, wandelt recoverable Exceptions in Fehler-Outputs um und erzwingt die Retry-Grenze.

### § 8.3 Options-Weitergabe und Dokumentation

`src/Helper/Toolkit.php` validiert `debug_log` und setzt den Logger auf der erzeugten Fassade. Die Array-Shapes und Options-Dokumentation in `src/functions.php`, `.ai-usage-info.md` und `README.md` werden für alle globalen Funktionen konsistent ergänzt. Bestehende Optionen und Signaturen bleiben gültig.

### § 8.4 Tests

Neue Unit-Tests prüfen Zeilenpufferung und Modellpräfix, rote Warnungen mit und ohne `NO_COLOR`, redigierte Tool-Argumente, Auswahl des Streaming-Pfads, unveränderten Rückgabewert, Logging aller Callback-Runden, Korrektur eines invaliden Tool-Aufrufs, Abbruch nach der Retry-Grenze sowie die Aggregation von Tokens und Laufzeiten. Bestehende Stream-, Facade- und Funktionstests werden nur dort erweitert, wo die neue Option oder Orchestrierung berührt wird; externe API-Aufrufe sind für die Unit-Tests nicht erforderlich.

## § 9 Akzeptanzkriterien und Umsetzungsschritte

Das Proposal gilt als umgesetzt, wenn Logging ohne Option vollständig deaktiviert und rückwärtskompatibel bleibt, `debug_log => true` bei allen `phore_ai_*`-Funktionen funktioniert, ein eigener Logger injiziert werden kann, jeder geloggte Textzeile der Modellname vorangestellt ist, Callback-Tool-Aufrufe samt Dauer sichtbar sind, recoverable Validierungs-/Argumentfehler rot gewarnt und innerhalb der Grenze zur Korrektur an das Modell zurückgegeben werden, nicht-recoverable Fehler nicht wiederholt werden und genau eine aggregierte Abschlussstatistik mit Fehlern, Retries, Tokens und Zeiten erscheint.

Die spätere Implementierung erfolgt in getrennten, reviewbaren Schritten: zuerst Logger-Vertrag, Console-Formatierung und Statistiktyp; danach einheitliche Request-Orchestrierung und Streaming-Umschaltung; anschließend recoverable Tool-Fehler und Retry-Zählung; zuletzt Options-Weitergabe, Dokumentation und vollständige Unit-Tests. Diese Proposal-PR enthält absichtlich keinen Produktivcode.
