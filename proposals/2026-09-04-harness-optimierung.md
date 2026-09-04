# Proposal: Gesamtoptimierung des AI Harness

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–10: Proposal angelegt |

## § 1 Kurzfassung

Der AI Harness besitzt bereits eine kompakte Responses-API-Abstraktion, typisierte Prompt- und Tool-Objekte, Structured Output, Streaming, parallele Requests und eine bewusst defensive Standard-Instruktion gegen Prompt Injection. Vor einem Ausbau fehlen jedoch belastbare Grundlagen: Die CI kann wegen PHP 8.4 gegenüber der Anforderung PHP 8.5 keine Tests ausführen, `AiRequest::$temperature` wird nicht serialisiert, die Callback-Schleife kann nach fünf Runden oder bei unbekannten Tool-Aufrufen still mit einer unvollständigen Antwort enden, zentrale Request-Optionen sind nur über unvalidiertes `extraBody` erreichbar, und Diagnose-, Preis- sowie Zustandsdaten sind global oder statisch gekoppelt.

Empfohlen wird keine große Neuentwicklung in einem Schritt, sondern eine Reihenfolge aus vier kleinen Paketen: zuerst grüne und reproduzierbare CI; danach fail-closed Tool-Orchestrierung und Sicherheitsgrenzen; anschließend eine typisierte Request-/Result-API; zuletzt effizienzorientierte Funktionen wie Prompt-Caching, vereinheitlichtes Streaming/Observability und kontrollierte Datei-Patches. Die bestehenden Pull Requests #1 bis #4 werden dabei weiterverwendet, aber vor dem Merge aufeinander abgestimmt.

## § 2 Analysierter Stand

### § 2.1 Repository und Qualitätssicherung

Das Repository ist jung, besitzt noch keine Releases oder Tags und enthält keine offenen Issues außerhalb der vier Pull Requests. `README.md` enthält weiterhin den Text des Projekt-Templates statt einer Paketdokumentation. `composer.json` hat eine leere Beschreibung, verlangt `phore/schema:dev-main`, setzt `minimum-stability: dev` und bevorzugt Source-Installationen. Gleichzeitig wird kein `LICENSE`-Dokument mitgeliefert, obwohl MIT deklariert ist. Der einzige Workflow läuft nur auf `push`, verwendet `php:8.4-cli` und kann die Paketanforderung `php >=8.5` nicht erfüllen. Eingecheckte `.phpunit.cache`-Dateien sind generierter Zustand und kein Testnachweis.

### § 2.2 Öffentliche API und Modellkommunikation

`PhoreAi` bietet eine verständliche Fassade, aber `with()` ersetzt alle bisherigen Prompts und Tools statt sie zu ergänzen; der Name lässt dieses Verhalten nicht erkennen. `run()`, `runCastedArray()` und `runImage()` duplizieren Teile der Request-Orchestrierung. `AiRequest` kennt bereits `maxOutputTokens`, `temperature`, `toolChoice`, `parallelToolCalls` und `extraBody`; `temperature` fehlt jedoch in `toArray()`. Wichtige Responses-Optionen wie Reasoning-Effort, Text-Verbosity, Service-Tier, Truncation, Speicherung, Safety-Identifier und Prompt-Cache-Steuerung besitzen keinen validierten Vertrag.

Die aktuelle Tool-Schleife führt mehrere Callback-Aufrufe einer Response nacheinander aus, ignoriert unbekannte Function-Tools, beendet die Fortsetzung bei fehlender Response-ID ohne Fehler und liefert nach fünf Runden die letzte Response zurück, selbst wenn sie erneut Tool-Aufrufe enthält. Dadurch kann ein Aufrufer einen scheinbar erfolgreichen, aber fachlich unvollständigen String erhalten. Die [OpenAI-Dokumentation zu Function Calling](https://developers.openai.com/api/docs/guides/function-calling) empfiehlt Strict Mode und beschreibt explizit parallele Tool-Aufrufe; diese Semantik sollte im Harness sichtbar und deterministisch behandelt werden.

### § 2.3 Sicherheit und Datenschutz

Der Default-Systemprompt trennt Anweisungen von Datei-, Bild- und Audioinhalten bereits sinnvoll. Außerhalb des Prompts fehlen jedoch technische Grenzen: `OpenAiClient` kann einen beliebigen `baseUrl` mit einem Authorization-Header kombinieren, zusätzliche Header werden nicht auf Zeilenumbrüche oder sensible Namen geprüft, `McpTool` erlaubt Remote-Server ohne sichere Standardfreigabe oder zwingende Tool-Allowlist, und komplette Requests/Responses bleiben über globale statische `$last`-Felder erreichbar. Datei-Prompts besitzen keine zentrale Größenbegrenzung und übertragen standardmäßig den vollständigen lokalen Pfad an das Modell.

Die offizielle [OpenAI-Sicherheitsempfehlung](https://developers.openai.com/api/docs/guides/safety-best-practices) nennt begrenzte Ein- und Ausgaben sowie datenschutzschonende `safety_identifier`-Werte als wichtige Schutzmaßnahmen. Diese Kontrollen sollten als typisierte Optionen und Policies umgesetzt werden, nicht nur als Dokumentationshinweis.

## § 3 Bewertung der offenen Pull Requests

| PR | Bewertung | Vor Merge erforderlich |
|---|---|---|
| [#3 CI/PHP 8.5](https://github.com/phore/phore-ai-harness/pull/3) | Richtige höchste Priorität, behebt als Proposal aber noch keinen Workflow. | Den beschriebenen Minimal-Fix unmittelbar implementieren; Workflow auch auf `pull_request` ausführen und Rechte minimieren. |
| [#2 Tool-Fehler](https://github.com/phore/phore-ai-harness/pull/2) | Gute fail-closed Grundlage: nur `RecoverableToolException` darf modellseitige Korrektur erlauben. | Nach grüner CI testen; zusätzlich unbekannte Tools, ungültige Argumente, fehlende Response-ID und Iterationslimit mit typisierten Fehlern absichern. |
| [#4 Batch-Dateiedit](https://github.com/phore/phore-ai-harness/pull/4) | Spart Requests und beschränkt Schreibziele, führt aber einen harten API-Bruch und nicht-atomare Mehrdateischreibvorgänge ein. | `phore_ai_file()` zunächst deprecaten statt entfernen; Create und Edit trennen; alle Ziele vorprüfen; Größenlimit, Symlink-/Pfad-Policy und transaktionales Staging ergänzen; stille Lesefehler nicht als leere Datei tarnen. |
| [#1 Debug-Logging](https://github.com/phore/phore-ai-harness/pull/1) | Sinnvolle Observability, überschneidet sich aber mit #2 und verwendet den alten Dateifunktionsnamen aus #4. | Auf #2 aufbauen; nur explizit recoverable Fehler fortsetzen; sensible Inhalte standardmäßig nicht loggen; Transportwahl und Logging entkoppeln; nach #4 rebasen. |

Alle vier Branches basieren aktuell auf demselben `main`-Commit. #2 und #4 ändern beide `.ai-usage-info.md`; #1 referenziert `phore_ai_file()`, das #4 entfernen will. Die empfohlene Reihenfolge lautet daher: CI-Fix aus #3, dann #2, danach Überarbeitung und Merge von #4, anschließend Rebase und Präzisierung von #1.

## § 4 Priorisierte Zielarchitektur

### § 4.1 P0: Korrektheit vor neuen Features

Jeder öffentliche Lauf muss genau einen terminalen Zustand liefern: vollständig abgeschlossen oder mit einer typisierten Exception abgebrochen. Unbekannte Tool-Namen, doppelte Tool-Namen, nicht decodierbare Argumente, fehlende `response.id`, unvollständige API-Statuswerte und ein erreichtes Tool-Rundenlimit dürfen nicht still ignoriert werden. Das Limit wird konfigurierbar, besitzt einen konservativen Default und zählt API-Runden sowie Tool-Aufrufe getrennt. `temperature` wird entweder korrekt serialisiert und getestet oder aus dem Value Object entfernt, falls es für die unterstützten Modelle bewusst nicht angeboten werden soll.

### § 4.2 P1: Ein Orchestrator und ein Ergebnisobjekt

`PhoreAi` soll Request-Aufbau, Versand, Streaming, Tool-Auflösung und Usage-Aggregation über genau einen internen Orchestrator führen. Alle `run*`-Methoden verwenden denselben Ablauf. Ein unveränderliches `AiRunResult` enthält mindestens Text, Response-ID, Status, Usage, Request-ID aus den Response-Headern, Tool-Runden und optional strukturierte Events. Die vorhandenen Convenience-Funktionen dürfen weiterhin String, DTO oder Bild zurückgeben; eine zusätzliche Result-Variante macht Diagnose und Conversation-Fortsetzung ohne globale `$last`-Felder möglich.

### § 4.3 P2: Typisierte, providerfähige Konfiguration

Ein `AiRequestOptions`-Objekt validiert unterstützte Felder und wird von Fassade und globalen Funktionen gleichermaßen benutzt. Der erste Umfang umfasst `max_output_tokens`, `reasoning.effort`, `reasoning.summary`, `text.verbosity`, `tool_choice`, `parallel_tool_calls`, `max_tool_calls`, `store`, `truncation`, `service_tier`, `prompt_cache_key`, `prompt_cache_options` und `safety_identifier`. Ein expliziter Escape Hatch für unbekannte Provider-Felder bleibt möglich, darf jedoch reservierte Kernfelder nicht rekursiv oder unbemerkt überschreiben.

Der Harness ist heute faktisch OpenAI-spezifisch. Die Provider-Parameter in `ToolType::available()` und `type()` schaffen deshalb noch keinen echten Multi-Provider-Vertrag. Bis ein zweiter Provider implementiert ist, bleibt die öffentliche Sprache ehrlich OpenAI-spezifisch; Provider-Normalisierung wird zentralisiert statt in mehreren Tool-Klassen dupliziert.

### § 4.4 P3: Effizienz und Observability

Stabile Instructions und Tool-Definitionen werden vor dynamische Nutzdaten gelegt. Optionaler `prompt_cache_key` und Cache-Modus werden dokumentiert und die vorhandenen `cachedInputTokens` in Run-Statistiken sichtbar gemacht. Laut [OpenAI Prompt Caching](https://developers.openai.com/api/docs/guides/prompt-caching) erhöhen stabile Präfixe und stabile Tool-Definitionen die Wiederverwendung; Cache-Keys unterstützen die Zuordnung verwandter Requests.

Streaming wird nicht nur durch Debug-Logging aktiviert. Stattdessen erzeugt der Orchestrator einheitliche, strukturierte Events, die wahlweise an einen Stream-Callback, Logger oder No-op-Sink gehen. Ausgaben erhalten konfigurierbare Längenlimits; `max_output_tokens` und `text.verbosity` werden in den High-Level-APIs zugänglich. Die [OpenAI-Latenzempfehlungen](https://developers.openai.com/api/docs/guides/latency-optimization) heben kleinere Modelle, weniger Ausgabetokens, weniger sequentielle Requests und Parallelisierung als zentrale Hebel hervor.

## § 5 Fehlende Funktionen

### § 5.1 Lauf- und Conversation-Kontrolle

Es fehlen eine öffentliche Fortsetzungs-API für `previous_response_id`, ein Abbruch-/Cancellation-Signal für lange oder gestreamte Requests, eine Retry-Policy für temporäre Transport-, 429- und 5xx-Fehler mit Beachtung von `Retry-After`, sowie ein expliziter Status für `incomplete`, `failed` und `cancelled`. Transport-Retries dürfen Tool-Callbacks niemals implizit erneut ausführen.

### § 5.2 Tool-Policies

Jedes Callback-Tool benötigt neben Name und Schema optional eine Policy mit Timeout, maximaler Outputgröße, Nebenwirkungsstufe und Parallelisierbarkeit. Schreibende oder externe Tools sind standardmäßig nicht parallel. Tool-Ergebnisse verwenden einen stabilen Envelope mit `ok`, `data` beziehungsweise `error`, `retryable` und optional gekürzter Vorschau. Der vollständige Output bleibt in der Anwendung und wird nur innerhalb eines konfigurierten Limits an das Modell zurückgesendet.

### § 5.3 Dateiänderungen

Für kleine Textdateien bleibt Full-Replace sinnvoll. Für größere Dateien fehlt ein Patch-Modus mit Vorbedingung auf den ursprünglichen Content-Hash, Pfad-Allowlist, Maximalgröße, Dry-Run und atomarem Commit aller Änderungen. Neue Dateien werden nur in einem ausdrücklich gewählten Create-Modus erlaubt. Binärdateien, nicht lesbare Dateien und Symlinks werden nicht still als leerer Text behandelt. Optional können providerseitige Predicted-Output-Funktionen eingesetzt werden, wenn Modell und Endpoint sie tatsächlich unterstützen.

### § 5.4 Evals und Reproduzierbarkeit

Neben Unit- und E2E-Tests fehlen fixture-basierte Contract-Tests für reale Responses- und SSE-Payloads, Tests für Tool-Runden und Redaction sowie kleine Evals für Prompt-Injection, falsche Tool-Argumente, unbekannte Tools und Token-/Latenzbudget. Modellabhängige Evals werden getrennt von deterministischen Unit-Tests ausgeführt und blockieren Releases nur über ausdrücklich definierte Schwellenwerte.

## § 6 Vereinfachen oder entfernen

`README.md` wird vollständig durch eine kurze Installations-, Schnellstart-, Sicherheits- und Kompatibilitätsdokumentation ersetzt; ausführliche AI-Nutzung kann in `.ai-usage-info.md` oder einer einzigen Docs-Datei bleiben, aber nicht divergent dupliziert werden. Eingecheckte PHPUnit-Caches werden gelöscht und ignoriert. IDE-Dateien bleiben nur, wenn sie teamweit benötigt werden; andernfalls gehören sie nicht ins Paket. Das SSH-Submodul `.pi/skills` wird auf HTTPS umgestellt oder aus dem auslieferbaren Paket ausgeschlossen.

`UsageInfoType` soll keine veränderlichen Modellpreise hart im Quellcode als scheinbar dauerhafte Wahrheit führen. Tokenzahlen bleiben Kernfunktion; Kostenschätzung wird entweder über einen injizierbaren, versionierten `PriceCatalog` angeboten oder als klar datierte optionale Schätzung ausgelagert. `AiRequest::$last`, `AiResponse::$last` und die globalen `get_last_ai_*`-Funktionen werden zugunsten von `AiRunResult` deprecatiert, weil globaler Zustand bei parallelen und lang laufenden Prozessen nicht zuverlässig ist.

Die leeren Wrapper für integrierte Tools sind nur dann sinnvoll, wenn sie Optionen validieren oder eine stabile Entwickler-API bieten. Andernfalls sollte eine kleine deklarative Tool-Factory die rein mechanischen Klassen ersetzen. `with()` wird entweder in `withItems()`/ `replaceItems()` umbenannt oder um echte additive Methoden `add()`, `addPrompt()` und `addTool()` ergänzt.

## § 7 Sicherheitsmaßnahmen

### § 7.1 Credentials und Transport

Ein API-Key darf nur an den erwarteten OpenAI-Host gesendet werden. Ein benutzerdefinierter `baseUrl` erfordert einen expliziten Credential-Vertrag oder eine ausdrückliche Opt-in-Option; der Keystore-Key darf nicht automatisch an einen fremden Host weitergereicht werden. Header-Namen und -Werte werden gegen CR/LF-Injection validiert. Authorization-, Cookie-, API-Key- und MCP-Token-Werte werden in Exceptions, Events und Debug-Ausgaben zentral redigiert. Die vorhandene Nutzung von Umgebungsvariablen beziehungsweise Secret-Dateien entspricht der [OpenAI-Empfehlung zur API-Key-Sicherheit](https://developers.openai.com/api/docs/guides/production-best-practices) und bleibt erhalten.

### § 7.2 MCP und Tools mit Nebenwirkungen

`McpTool` erhält sichere Defaults: HTTPS für Remote-Server, eine explizite `allowed_tools`-Liste für produktive Nutzung und eine Approval-Policy, die schreibende oder unbekannte Aktionen nicht automatisch freigibt. Authorization und eigene Header werden als sensible Werte behandelt. Local Shell, Computer Use und schreibende Callback-Tools benötigen eine dokumentierte Sandbox- und Freigabegrenze; der Modellprompt allein ist keine Sicherheitsbarriere.

### § 7.3 Datenminimierung

Dateianhänge senden standardmäßig nur einen logischen Dateinamen statt des vollständigen lokalen Pfads. Größen-, MIME- und Output-Limits greifen vor dem API-Aufruf. Roh-Request, Roh-Response und Stream-Events werden nur opt-in aufbewahrt; normale Resultate enthalten die für Betrieb und Support benötigten IDs und Metriken. `safety_identifier` akzeptiert nur einen bereits pseudonymisierten stabilen Wert und dokumentiert ausdrücklich, dass keine E-Mail-Adresse oder andere direkte Kennung übertragen werden soll.

## § 8 Repository- und Lieferqualität

Nach dem PHP-8.5-Fix läuft CI auf Push und Pull Request mit minimalen GitHub-Token-Rechten. Actions werden auf überprüfte Commit-SHAs gepinnt. Composer validiert `composer.json`, installiert reproduzierbar und führt PHPUnit sowie einen kompakten Static-Analysis-/Style-Check aus. Für eine Library werden mindestens die unterstützte Mindestversion und eine aktuelle PHP-Version getestet. E2E-Tests mit API-Kosten bleiben separat und secret-geschützt.

Vor dem ersten Release werden `phore/schema:dev-main` und `minimum-stability: dev` durch einen stabilen kompatiblen Versionsvertrag ersetzt oder bewusst auf einen unveränderlichen Commit begrenzt. `preferred-install` wird auf `dist` beziehungsweise Composer-Default gestellt. Ein echtes MIT-`LICENSE`, Paketbeschreibung, Repository-URL, Support-Link, Changelog und SemVer-Policy werden ergänzt. Release-Artefakte schließen `.idea`, `.phpunit.cache`, E2E-Fixtures und agentenspezifische Skills per `export-ignore` aus.

## § 9 Umsetzungsplan und PR-Schnitt

1. P0a: PR #3 in einen ausführbaren CI-Fix überführen; Cache-Dateien entfernen und Composer-Validierung ergänzen.
2. P0b: PR #2 nach grüner CI abschließen; anschließend fail-closed Tool-Loop, doppelte/unbekannte Tools und `temperature` separat beheben.
3. P1: `AiRunResult` und einen gemeinsamen internen Orchestrator einführen; globale Last-State-APIs deprecaten.
4. P1b: PR #4 auf sichere Datei-Policies, rückwärtskompatible Deprecation und atomare Writes begrenzen.
5. P2: `AiRequestOptions` und dokumentierte High-Level-Optionen einführen; `extraBody` gegen Kernfeld-Überschreibung härten.
6. P3: PR #1 auf dem Orchestrator als Observer/Event-Sink umsetzen; Redaction und aggregierte Usage testen.
7. P3b: Prompt-Caching, Transport-Retry, Conversation-Fortsetzung und Datei-Patch-Modus jeweils als getrennte, messbare PRs liefern.
8. Release: Dokumentation, Dependency-Stabilisierung, Security-Policy und erster SemVer-Tag.

Jeder Implementierungs-PR soll einen einzigen zusammenhängenden Risikobereich ändern. Öffentliche API-Brüche werden nicht zwischen andere Optimierungen gemischt, sondern deprecatiert, dokumentiert und erst in einer angekündigten Major-Version entfernt.

## § 10 Akzeptanzkriterien

Das Zielbild ist erreicht, wenn CI vor einem Merge tatsächlich Unit-Tests ausführt; jeder Agent-Lauf eindeutig abgeschlossen oder fehlgeschlagen ist; kein unbekannter beziehungsweise ungelöster Tool-Aufruf als Erfolg zurückkehrt; alle unterstützten Request-Optionen serialisiert und validiert werden; Token-, Cache-, Tool- und Request-ID-Metriken pro Lauf ohne globalen Zustand verfügbar sind; sensible Header und Tool-Argumente nachweislich redigiert werden; MCP- und Dateioperationen sichere Defaults und harte Größen-/Pfadgrenzen besitzen; die High-Level-API weiterhin kurze Einzeiler erlaubt; und die README Installation, Kernbeispiele, Sicherheitsannahmen sowie unterstützte PHP-/API-Versionen korrekt beschreibt.

Für Effizienz werden mindestens drei Messwerte vor und nach einer Änderung verglichen: API-Requests pro Aufgabe, generierte Output-Tokens und End-to-End-Latenz. Prompt-Caching wird zusätzlich über `cachedInputTokens` beobachtet. Eine Optimierung gilt nur dann als erfolgreich, wenn sie mindestens einen Messwert verbessert, ohne Contract-Tests, Evals oder die festgelegten Sicherheitsgrenzen zu verschlechtern.
