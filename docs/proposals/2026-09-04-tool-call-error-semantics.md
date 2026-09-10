# Proposal: Fehlersemantik für Function-Tool-Aufrufe

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–7: Proposal angelegt |

## § 1 Kurzfassung

Function-Tool-Aufrufe unterscheiden künftig explizit zwischen recoverable Tool-Feedback und laufabbrechenden Fehlern. Nur eine bewusst geworfene `RecoverableToolException` wird als strukturiertes `function_call_output` an das Modell zurückgegeben; jede andere Exception beendet den Agent-Lauf und wird unverändert an die aufrufende Programmschicht durchgereicht. Ein optional hinzugefügtes `TaskErrorTool` erlaubt dem Modell weiterhin, einen nicht erfüllbaren Auftrag selbst als `TaskErrorException` abzubrechen.

## § 2 Ziele und Nicht-Ziele

Ziel ist eine fail-closed Fehlergrenze: Entwickler müssen ausdrücklich markieren, wenn das Modell nach einem Tool-Fehler weiterarbeiten oder einen korrigierten Aufruf versuchen darf. Gleichzeitig müssen Programmierfehler, Infrastrukturfehler und vom Modell erklärte Abbruchbedingungen zuverlässig außerhalb des Agenten behandelbar bleiben. Nicht Ziel ist eine automatische Klassifizierung beliebiger Exceptions, ein automatisches Wiederholen desselben Tool-Aufrufs oder eine neue allgemeine Retry-Policy für HTTP-Requests.

## § 3 Fehlerklassen

### § 3.1 Laufabbrechende Tool-Fehler

Alle normalen PHP-Exceptions und `Throwable`-Implementierungen behalten ihre native Semantik. Sie werden an der Callback-Grenze nicht gefangen, beenden dadurch die Auflösung weiterer Function Calls und gelangen unverändert zur aufrufenden Programmschicht. Das betrifft insbesondere unerwartete Datei-, Datenbank-, Netzwerk-, Validierungs- und Programmierfehler.

### § 3.2 Recoverable Tool-Feedback

Ein Callback darf `RecoverableToolException` mit einer für das Modell bestimmten, sicheren Fehlermeldung werfen. Der Harness fängt ausschließlich diesen Typ und liefert ein JSON-Objekt mit `ok: false`, dem Fehlertyp `recoverable_tool_error`, der Nachricht, `retryable: true` und einer Handlungsanweisung als `function_call_output` zurück. Das Modell kann anschließend Eingaben korrigieren, ein anderes Tool wählen oder eine fachlich passende Antwort formulieren; es gibt keine automatische Wiederholung durch den Harness.

### § 3.3 Vom Modell ausgelöster Abbruch

Soll das Modell selbst feststellen dürfen, dass ein Auftrag wegen widersprüchlicher Anweisungen, fehlender Informationen oder fehlender Fähigkeiten nicht erfüllbar ist, fügt der Aufrufer `TaskErrorTool` zum Request hinzu. Der Tool-Aufruf wirft `TaskErrorException` mit den vom Modell formulierten strukturierten Angaben. Da diese Exception nicht recoverable ist, wird sie unverändert durchgereicht und beendet den gesamten Lauf.

## § 4 Öffentliche API

Die neue öffentliche Klasse `Phore\AiHarness\ToolType\RecoverableToolException` erweitert `RuntimeException` und kann direkt in jedem `CallbackTool` verwendet werden. Eine zusätzliche boolesche Option am Tool wird nicht eingeführt, weil die Exception am tatsächlichen Fehlerort präziser ausdrückt, ob genau dieser Fehler recoverable ist. `TaskErrorTool` bleibt die vorhandene opt-in Logikkomponente für agentenseitig ausgelöste Abbrüche.

```php
use Phore\AiHarness\ToolType\CallbackTool;
use Phore\AiHarness\ToolType\RecoverableToolException;
use Phore\AiHarness\ToolType\TaskErrorTool;

$readFile = new CallbackTool(static function (string $path): string {
    if (!is_file($path)) {
        throw new RecoverableToolException('File not found. Check the path and retry.');
    }

    return (string) file_get_contents($path);
}, name: 'read_file');

$agent = (new PhoreAi())->with($prompt, $readFile, new TaskErrorTool());
```

## § 5 Ablauf

Nach einem Function Call validiert der Harness wie bisher die JSON-Argumente und ruft den registrierten Callback auf. Ein reguläres Ergebnis wird als Tool-Output gesendet. Eine `RecoverableToolException` wird in das definierte Fehlerobjekt übersetzt und der Chat wird mit derselben Responses-Konversation fortgesetzt. Jede andere Exception verlässt unmittelbar den Aufruf-Stack; dadurch werden keine weiteren Tool-Calls der Response ausgeführt und keine weitere Modell-Anfrage gestartet.

## § 6 Kompatibilität und Sicherheit

Das Verhalten bestehender Callback-Tools bleibt für erfolgreiche Rückgaben und normale Exceptions unverändert. Der neue recoverable Pfad ist rein opt-in. Nur die ausdrücklich gesetzte Exception-Nachricht wird an das Modell übermittelt; Klassenname, Stack Trace und vorherige Exceptions werden nicht offengelegt. Anwendungen sollen deshalb ausschließlich modellgeeignete, nicht vertrauliche Texte in `RecoverableToolException` verwenden.

## § 7 Tests und Akzeptanzkriterien

Unit-Tests müssen bestätigen, dass `RecoverableToolException` in das definierte JSON-Tool-Output übersetzt wird, eine gewöhnliche `RuntimeException` unverändert propagiert und eine durch `TaskErrorTool` erzeugte `TaskErrorException` den Lauf ebenfalls abbricht. Akzeptiert ist die Änderung, wenn die bestehende PHPUnit-Suite unverändert grün bleibt und die neue API ohne zusätzliche Dependency autoloadbar ist.
