# Proposal: Struct Patch für partielle, atomare AI-Änderungen

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–12: Proposal angelegt |

## § 1 Kurzfassung

Der AI Harness kann strukturierte Eingaben über `StructPrompt` übergeben und vollständige strukturierte Ergebnisse über `StructOutput`, `phore_ai_struct()` und `phore_ai_struct_array()` erzeugen. Eine vorhandene Struktur lässt sich aber noch nicht gezielt ändern: Das Modell muss heute das komplette Zielobjekt erneut ausgeben, auch wenn nur ein Feld oder ein Listenelement geändert werden soll. Das erhöht Output-Tokens, Latenz und das Risiko unbeabsichtigter Änderungen an unveränderten Feldern.

Empfohlen wird zuerst ein providerunabhängiger, deterministischer Patch-Kern auf Basis von JSON Patch nach RFC 6902 und JSON Pointer nach RFC 6901. Darauf folgen ein typisierter `StructPatcher` für PHP-Objekte und ein expliziter AI-Helper `phore_ai_edit_struct()`. Das Modell soll standardmäßig in genau einer strukturierten Antwort eine geordnete Liste von Patch-Operationen liefern; der Harness wendet diese Liste transaktional auf eine Kopie an, validiert das Endergebnis gegen das bekannte Struct-Schema und gibt erst danach das neu hydrierte Objekt frei.

Für Arrays reicht positionsbasiertes RFC 6902 funktional aus, ist für Modelle bei mehreren Änderungen aber fehleranfällig. Deshalb wird zusätzlich eine optionale Stable-ID-Ansicht empfohlen, die Listenelemente während des Modellaufrufs über unveränderliche Schlüssel adressierbar macht und ihre Reihenfolge separat abbildet. Ein API-Roundtrip pro einzelner Operation ist nicht der Standard, sondern nur ein begrenzter Reparaturpfad nach einem fehlgeschlagenen Batch.

## § 2 Aktueller Stand und Problem

### § 2.1 Vorhandene Struct-Funktionen

`StructPrompt` serialisiert ein Objekt oder Array einschließlich optionalem Alias und Instruktionen als JSON. `StructOutput` erzeugt aus einer PHP-Klasse ein JSON Schema. `phore_ai_struct()` und `phore_ai_struct_array()` lassen das Modell jeweils einen vollständigen neuen Wert erzeugen und hydratisieren ihn anschließend. Eine Patch-Klasse, eine Patch-Ausgabe oder ein Struct-Editor existiert auf `main` nicht.

Der vorhandene `phore_ai_file()` zeigt dasselbe Grundproblem für Dateien: Er lässt den vollständigen Inhalt ersetzen. PR [#4](https://github.com/phore/phore-ai-harness/pull/4) enthält bereits ein separates Proposal zu Batch-Dateien und hunk-basierten Datei-Patches. Das vorliegende Proposal dupliziert diesen Dateientwurf nicht, sondern definiert den allgemeinen Patch-Kern und die Struct-spezifische Semantik, die später mit Datei- und weiteren Patch-Werkzeugen eine gemeinsame Policy- und Resultatsschicht nutzen kann.

### § 2.2 Fachliche Risiken vollständiger Regeneration

Bei einer kleinen gewünschten Änderung muss das Modell alle unveränderten Werte korrekt kopieren. Der Output wächst mit der Größe des gesamten Objekts statt mit der Größe der Änderung. Lange Strukturen erhöhen außerdem das Risiko, relevante Werte in der Mitte des Kontexts zu übersehen; die Untersuchung [Lost in the Middle](https://aclanthology.org/2024.tacl-1.9/) zeigt, dass die Nutzung langer Kontexte trotz ausreichendem Kontextfenster positionsabhängig deutlich nachlassen kann.

Ein Patch verkleinert primär den Modell-Output. Das ist für die Latenz besonders relevant: Die offizielle [OpenAI-Latenzempfehlung](https://developers.openai.com/api/docs/guides/latency-optimization) nennt weniger generierte Tokens als wichtigsten Hebel und empfiehlt für sequentielle Schritte möglichst eine gemeinsame Antwort statt zusätzlicher Requests. Der vollständige Ausgangsdatensatz muss ohne zusätzliche Index- oder Retrieval-Schicht weiterhin als Input verfügbar sein; Patch-Ausgabe allein beseitigt daher nicht automatisch alle Kosten großer Eingaben.

## § 3 Standards und Erfahrungswerte

### § 3.1 JSON Patch und JSON Pointer

[RFC 6902](https://datatracker.ietf.org/doc/rfc6902/) definiert eine Patch-Datei als geordnete Liste der Operationen `add`, `remove`, `replace`, `move`, `copy` und `test`. Pfade verwenden [RFC 6901 JSON Pointer](https://www.rfc-editor.org/info/rfc6901/). Die Operationen werden ausdrücklich nacheinander ausgeführt; das Ergebnis einer Operation ist die Eingabe der nächsten. Ein `add` an einem Array-Index fügt vor diesem Index ein und verschiebt folgende Elemente nach rechts, `remove` verschiebt folgende Elemente nach links, und `-` hängt an das Arrayende an.

Damit ist eine Liste mehrerer Patch-Befehle wohldefiniert. Problematisch wird sie nur, wenn das Modell alle Indizes gedanklich auf den unveränderten Ursprungszustand bezieht. Wer aus dem ursprünglichen Array die Positionen 1 und 3 entfernen will, muss beispielsweise in absteigender Reihenfolge `/items/3` und danach `/items/1` entfernen oder nach jedem Schritt den neuen Index verwenden. Für sicherheitsrelevante positionsbasierte Änderungen soll unmittelbar vor der Mutation ein `test` auf eine stabile Eigenschaft oder den erwarteten Wert stehen.

### § 3.2 Merge Patch als begrenzte Alternative

[RFC 7396 JSON Merge Patch](https://www.rfc-editor.org/info/rfc7396/) ist für objektlastige Daten einfacher lesbar: vorhandene Felder werden ersetzt, neue ergänzt und `null` löscht ein Feld. Das Format kann jedoch `null` nicht eindeutig als normalen Zielwert ausdrücken und ersetzt Arrays als Ganzes, statt einzelne Listenelemente zu ändern. Es eignet sich deshalb als späterer Komfortmodus für einfache Maps, aber nicht als primärer Struct-Patch-Vertrag.

### § 3.3 Empirie für LLM-generierte JSON-Patches

Die EMNLP-2025-Arbeit [JSON Whisperer](https://aclanthology.org/2025.emnlp-industry.88/) vergleicht vollständige JSON-Regeneration mit RFC-6902-Patches. Mit passenden Few-Shot-Beispielen und einer stabil adressierten Listenrepräsentation lag die Editierqualität innerhalb von fünf Prozent der Vollregeneration, während die Tokenmenge um 31 Prozent sank. In den berichteten Versuchen sank die Laufzeit je nach Modell um rund 31,5 bis 42,3 Prozent. Gleichzeitig identifiziert die Arbeit Array-Indexverschiebungen als zentrale Fehlerquelle und zeigt, dass die Umformung von Sequenzen in per Schlüssel adressierte Einträge die Ausführung und Genauigkeit besonders bei mehreren Listenoperationen verbessert.

Diese Werte sind ein positiver Machbarkeitsnachweis, aber keine Garantie für die Modelle und Structs dieses Projekts. Die Studie verwendet ungefähr 400 domänenspezifische Filmszenen und zwei Modelle. Vor einer automatischen Moduswahl braucht der Harness deshalb eigene Evals mit realen PHP-Structs, unterschiedlichen Tiefen, Nullwerten, großen Arrays und kombinierten Änderungen.

### § 3.4 Strukturierte Ausgabe und Tool Calls

OpenAI [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) kann die syntaktische Form einer Patch-Antwort an ein JSON Schema binden. Bei Function Tools stellt `strict: true` laut [Function-Calling-Dokumentation](https://developers.openai.com/api/docs/guides/function-calling) zuverlässige Schemaeinhaltung her; dabei müssen Objektfelder vollständig als erforderlich modelliert und optionale Werte über nullable Typen dargestellt werden. Das garantiert die Form der Operationen, nicht die Existenz eines Pfads, die fachliche Richtigkeit eines Werts oder die Gültigkeit des resultierenden Structs. Diese Prüfungen bleiben Aufgabe des lokalen Patch-Kerns.

## § 4 Architekturentscheidung

### § 4.1 Drei getrennte Schichten

Die Lösung wird in drei voneinander verwendbare Schichten geteilt:

1. `JsonPatch` und `JsonPatchOperation` repräsentieren einen RFC-6902-Patch ohne AI-, Provider- oder PHP-Klassenbezug.
2. `JsonPatchApplier` wendet einen Patch deterministisch, atomar und mit Limits auf JSON-kompatible Werte an; `StructPatcher` ergänzt Normalisierung, Schema-Prüfung und Hydration für PHP-Objekte.
3. `phore_ai_edit_struct()` beziehungsweise ein `StructPatchOutput` erzeugt per Modell einen Patch für ein konkretes Zielobjekt und verwendet ausschließlich die beiden unteren Schichten zur Ausführung.

Der generische Kern darf daher auch für Konfigurationen, API-Payloads, gespeicherte JSON-Dokumente und Tests benutzt werden. Eine Modellantwort erhält niemals direkten Schreibzugriff auf ein Objekt oder eine Datei; sie liefert nur deklarative Änderungsabsichten, die lokal validiert und angewendet werden.

### § 4.2 Expliziter Editor statt stiller Struct-Option

`phore_ai_struct()` erzeugt heute ein neues Objekt aus einer Klasse, während ein Patch zwingend ein konkretes Ausgangsobjekt, Konfliktsemantik und eine Patch-Policy benötigt. Eine bloße Option wie `['patch' => true]` würde diese unterschiedlichen Verträge verstecken. Empfohlen wird deshalb die explizite API `phore_ai_edit_struct()`.

Ein gemeinsames Optionsobjekt darf dennoch `mode: 'patch'|'replace'|'auto'` anbieten. `patch` verlangt einen gültigen Patch, `replace` erzeugt bewusst das vollständige Struct und `auto` wählt anhand von Größe und Änderungsbreite. Bis Evals belastbare Schwellenwerte liefern, bleibt `patch` der explizite Standard des neuen Helpers und `auto` experimentell beziehungsweise opt-in.

## § 5 Vorgeschlagene Kern-API

### § 5.1 Patch-Wertobjekte

```php
final readonly class JsonPatchOperation
{
    public function __construct(
        public string $op,
        public string $path,
        public mixed $value = null,
        public ?string $from = null,
    ) {}
}

final readonly class JsonPatch
{
    /** @param list<JsonPatchOperation> $operations */
    public function __construct(public array $operations) {}
}
```

`value` und `from` müssen für ein OpenAI-kompatibles striktes Schema gegebenenfalls als erforderliche nullable Felder ausgegeben werden. Der lokale Konstruktor validiert anschließend operationsspezifisch: `add`, `replace` und `test` benötigen `value`; `move` und `copy` benötigen `from`; `remove` darf beides nicht verwenden. Eine spätere interne discriminated union ist möglich, sofern `phore/schema` und alle unterstützten Provider sie zuverlässig abbilden.

### § 5.2 Deterministisches Anwenden

```php
$patch = JsonPatch::fromArray($operations);
$result = (new JsonPatchApplier())->apply(
    target: $jsonValue,
    patch: $patch,
    options: new PatchApplyOptions(
        atomic: true,
        maxOperations: 100,
        allowedOperations: ['add', 'remove', 'replace', 'test'],
    ),
);
```

Der Applier arbeitet immer auf einer tiefen Kopie oder einer intern unveränderlichen Darstellung. Erst wenn alle Operationen, Limits und die optionale Endvalidierung erfolgreich sind, wird das Ergebnis zurückgegeben. Fehler nennen Operationsindex, `op`, `path` und einen maschinenlesbaren Fehlercode, aber keine unnötigen sensiblen Daten.

### § 5.3 Struct-API

```php
/** @template T of object
 *  @param T $target
 *  @return T
 */
function phore_ai_edit_struct(
    string|PromptType|ToolType|array $prompts,
    object $target,
    array $options = [],
): object;
```

Vorgeschlagene Optionen sind `mode`, `addressing`, `allowed_operations`, `max_operations`, `max_patch_bytes`, `require_tests`, `expected_hash`, `dry_run`, `return_patch`, `client`, `model`, `timeout` und `connect_timeout`. `addressing` akzeptiert zunächst `pointer` oder `stable`; `allowed_operations` ist standardmäßig auf `add`, `remove`, `replace` und `test` begrenzt. `move` und `copy` werden erst nach eigenen Evals freigegeben, weil ihre Quell- und Zielabhängigkeiten zusätzliche Fehlerfälle erzeugen.

`StructPatcher` normalisiert das Zielobjekt wie `StructPrompt` zu JSON, wendet den Patch auf eine Kopie an, validiert die resultierende Struktur gegen dasselbe aus der Zielklasse erzeugte Schema und hydratisiert erst danach eine neue Instanz. Das ursprüngliche Objekt wird nicht teilweise mutiert.

## § 6 Adressierung von Arrays und Konflikten

### § 6.1 Reiner Pointer-Modus

Im Modus `pointer` gelten RFC 6901 und RFC 6902 ohne Erweiterungen. Pfade beziehen sich bei jeder Operation auf den durch alle vorherigen Operationen entstandenen Zustand. Der Prompt nennt ausdrücklich nullbasierte Indizes, sequentielle Ausführung, `-` zum Anhängen und die Regel, mehrere Löschungen aus dem ursprünglichen Array absteigend zu sortieren.

Für destruktive oder ersetzende Array-Operationen kann `require_tests: 'arrays'` erzwingen, dass direkt davor ein passender `test` steht. Beispiel:

```json
{
  "operations": [
    {"op":"test","path":"/items/2/id","value":"customer-17","from":null},
    {"op":"remove","path":"/items/2","value":null,"from":null}
  ]
}
```

Der `test` verhindert, dass bei einer veralteten oder falsch berechneten Position das falsche Element gelöscht wird. Er löst jedoch nicht die kognitive Indexarithmetik innerhalb eines großen Patches; dafür dient der Stable-Modus.

### § 6.2 Stable-ID-Modus

Im Modus `stable` erzeugt der Harness für jedes Array eine modellseitige Ansicht aus einer Element-Map und einer getrennten Reihenfolge. Vorhandene skalare Identitätsfelder wie `id`, `key`, `uuid` oder ein explizit konfigurierter JSON Pointer werden bevorzugt; fehlen sie, erzeugt der Harness nur für diesen Request kurze, opake IDs. Ein schematisches Beispiel lautet:

```json
{
  "items": {
    "$order": ["i_a7", "i_f2"],
    "$values": {
      "i_a7": {"id":"customer-17","name":"Ada"},
      "i_f2": {"id":"customer-29","name":"Lin"}
    }
  }
}
```

Feldänderungen adressieren `/items/$values/i_a7/name`; Löschen entfernt den Map-Eintrag und seine ID aus `$order`; Verschieben ändert nur `$order`. Dadurch bleiben Referenzen auf andere Elemente stabil, unabhängig davon, welche Operation zuerst ausgeführt wird. Dieses Prinzip entspricht der in JSON Whisperer evaluierten EASE-Repräsentation und der etablierten Idee schema-definierter Listenschlüssel, wie sie beispielsweise Kubernetes mit [`x-kubernetes-list-map-keys`](https://kubernetes.io/docs/reference/using-api/server-side-apply/) nutzt.

Die Stable-Ansicht ist ein Transportformat und darf nicht als neues RFC-6902-Dialekt ausgegeben werden. Der Harness wendet einen standardkonformen Patch auf die Ansicht an, prüft Map, Reihenfolge, Eindeutigkeit und referenzielle Vollständigkeit und dekodiert sie anschließend zurück in das ursprüngliche Array-Schema. Zufällige oder neue IDs dürfen nicht mit fachlichen IDs des Structs verwechselt werden.

### § 6.3 Vorabauflösung und Konkurrenz

Alle Pointer vorab gegen den Ursprungsbaum in direkte Objekt-Referenzen umzuwandeln wird nicht empfohlen. Das erzeugt unklare Semantik, wenn eine frühere Operation einen Elternknoten entfernt, ersetzt oder verschiebt. Stattdessen werden Operationen deterministisch der Reihe nach auf einer privaten Kopie ausgewertet. Stable-ID-Adressierung reduziert Positionskonflikte, ohne die Reihenfolge- und Fehlersemantik des Standards zu verändern.

Gegen Änderungen außerhalb des Modellaufrufs dient ein `expected_hash` über die kanonisch serialisierte Ausgangsstruktur. Vor der Freigabe wird per Compare-and-Swap geprüft, dass der aktuelle Stand denselben Hash besitzt. Die Kombination aus Versions-/Hash-Prüfung und `test`-Operationen folgt derselben Grundidee wie bedingte PATCH-Anfragen mit ETag und `If-Match`, die [RFC 5789](https://www.rfc-editor.org/info/rfc5789/) für basisabhängige Patches empfiehlt.

## § 7 Ein Batch oder mehrere Agentenrunden

### § 7.1 Empfohlener Standard: ein strukturierter Batch

Das Modell erhält Zielstruktur, Schema, Arbeitsauftrag, Patch-Regeln und wenige passende Beispiele und liefert genau ein Objekt mit `operations`. Der Harness validiert und simuliert alle Operationen lokal, prüft das endgültige Struct und übernimmt das Ergebnis atomar. Abhängige Operationen bleiben in der vom Modell festgelegten Reihenfolge; schreibende Patch-Operationen werden niemals als parallele Tool Calls ausgeführt.

Dieser Weg minimiert Requests und Output-Tokens, erlaubt echte All-or-nothing-Semantik und vermeidet, dass nach jeder Kleinigkeit der Datensatz oder ein großer Conversation-Zustand erneut verarbeitet werden muss. Für den vorhandenen Harness passt eine Structured-Output-Antwort besser als mehrere Callback-Aufrufe, weil der Patch zunächst nur Daten ist und lokal ohne Modellfeedback vollständig geprüft werden kann.

### § 7.2 Ein Tool Call mit kompletter Patch-Liste

Für agentische Abläufe, in denen das Modell die Änderung innerhalb einer Tool-Schleife ausführen und das technische Ergebnis sehen muss, kann alternativ genau ein mutierendes Tool `apply_struct_patch` die vollständige Operationsliste akzeptieren. Das Tool führt denselben atomaren Kern aus und gibt nur Hash, Operationszahl, geänderte Top-Level-Pfade und Status zurück. `parallel_tool_calls` wird für diesen Ablauf deaktiviert oder die Tool-Policy markiert das Werkzeug als nicht parallelisierbar.

Mehrere einzelne `apply_patch`-Tool Calls in derselben Modellantwort sind zu vermeiden: Sie basieren alle auf derselben beobachteten Ausgangslage, können vom Provider parallel angefordert werden und erschweren Rollback über bereits bestätigte Seiteneffekte. Wenn ein Tool eingesetzt wird, kapselt ein Aufruf die ganze logische Transaktion.

### § 7.3 Mehrere Roundtrips nur als Reparaturpfad

Ein Roundtrip je Operation ist nur sinnvoll, wenn der nächste Schritt fachlich vom Ergebnis eines nicht deterministischen externen Tools abhängt oder wenn ein großer Batch wegen eines lokalisierbaren Konflikts nicht erstellt werden kann. Bei einem ungültigen Patch darf der Harness dem Modell einmal eine kompakte Fehlermeldung mit Operationsindex, Fehlercode und kleinem relevanten Ausschnitt geben. Das Modell erzeugt danach einen vollständigen Ersatzpatch gegen den unveränderten Ausgangszustand; es setzt keinen teilweise angewendeten Patch fort.

Nach dem konfigurierten Reparaturversuch bricht der Lauf fail-closed ab oder wechselt nur bei ausdrücklichem `mode: auto` und ausreichendem Budget zur Vollregeneration. Eine stille Teilanwendung ist unzulässig.

## § 8 Modell-Prompt und Ausgabeformat

### § 8.1 Basisprompt

Vorgeschlagene zusätzliche System-Instruktion:

```text
Edit only the supplied target struct. Return one JSON object with an operations array.
Use RFC 6902 operations and RFC 6901 JSON Pointer paths.
Operations are executed sequentially; every path refers to the state produced by all previous operations.
Use zero-based array indices and '-' only to append. When removing multiple positions from the original array, remove higher indices first.
Before a positional remove or replace, add a test operation for the element's stable id or expected value.
Change only what the user requested and the fields logically required by that change. Do not copy unchanged subtrees into the patch.
Do not change the schema, invent paths, or exceed the configured operation limit.
If the request cannot be represented safely under these rules, return unsupported=true and an empty operations array.
```

Im Stable-ID-Modus ersetzt eine kurze Erklärung der `$values`-/`$order`-Ansicht die Positionshinweise. Mindestens zwei Few-Shot-Beispiele sollen automatisch aus dem konkreten Struct-Schema abgeleitet oder pro Domäne hinterlegt werden: ein einfaches Feld-Replace und eine kombinierte Listenänderung mit Test, Löschung, Ergänzung und Reihenfolge. JSON Whisperer zeigt, dass passende Few-Shot-Beispiele für Patch-Qualität einen wesentlichen Unterschied machen.

### § 8.2 Antwort-Envelope

```json
{
  "unsupported": false,
  "operations": [
    {"op":"replace","path":"/profile/name","value":"Ada Lovelace","from":null}
  ]
}
```

Eine freie Begründung wird standardmäßig nicht angefordert, weil sie zusätzliche Output-Tokens erzeugt und nicht ausgeführt wird. Diagnosebedarf wird über lokale Validierungsfehler und optionales Debug-Metadatum abgedeckt. `unsupported=true` ist nur für unpassende Aufträge, verbotene Schemaänderungen oder nicht sicher ausdrückbare Änderungen zulässig; es darf nicht gemeinsam mit Operationen auftreten.

## § 9 Validierung, Sicherheit und Fehlersemantik

Vor der Ausführung prüft der Harness JSON-Syntax, Envelope, Operationslimit, Patch-Byte-Limit, erlaubte Operationen, Pointer-Syntax, verbotene Pfadpräfixe und operationsspezifische Felder. Danach simuliert er den vollständigen Patch auf einer Kopie. Jeder fehlende Pfad, fehlgeschlagene `test`, ungültige Array-Index, verbotene Root-Ersetzung, zyklische oder ungültige `move`-Beziehung und jede Überschreitung bricht die gesamte Anwendung ab.

Nach der Simulation wird das Ergebnis gegen das aus der ursprünglichen Klasse erzeugte JSON Schema validiert und anschließend hydratisiert. Zusätzliche Properties, Typänderungen, verletzte Pflichtfelder und ungültige Konstruktorwerte sind Fehler. Ob das Entfernen eines optionalen PHP-Felds zulässig ist, bestimmt allein das Schema; ein `remove` darf nicht implizit einen beliebigen Default erfinden.

`PatchApplyResult` enthält mindestens `status`, alten und neuen SHA-256-Hash, Operationszahl, geänderte Pfade und optional das neue Objekt. `PatchConflictException`, `PatchValidationException`, `PatchTestFailedException` und `PatchLimitException` bilden unterschiedliche Fehlerklassen. Kein Fehlerobjekt gibt den vollständigen Struct-Inhalt zurück.

Für HTTP- oder persistente Ressourcen gilt die atomare Anforderung aus RFC 5789: Entweder werden alle Änderungen übernommen oder keine. Bei reinen PHP-Wertobjekten ist dies durch Copy-then-hydrate erreichbar; bei späteren externen Speichern braucht der Adapter eine Transaktion oder Compare-and-Swap-Schnittstelle.

## § 10 Weitere sinnvolle Patch-Kommandos

### § 10.1 File Patch

`FilePatch` beziehungsweise ein `apply_file_patch`-Werkzeug soll für lokale Änderungen an großen Textdateien kontextgebundene Hunks statt kompletter Inhalte verwenden. Es benötigt eine Ziel-Allowlist, Ausgangshash, maximale Hunk- und Dateigröße, Anwendung auf eine temporäre Kopie und atomaren Austausch. Neue Dateien, Löschungen und Binärdaten bleiben eigene ausdrücklich erlaubte Operationen. Die Detailentscheidung bleibt im vorhandenen Proposal auf PR #4.

### § 10.2 Text Patch

`TextPatch` kann für einzelne Strings oder Dokumentfelder exakte Ersetzungen mit `expected`, `replacement` und optionalem Vorkommenslimit anbieten. Eine rein numerische Zeichenoffset-API ist für Modelle ähnlich fragil wie Array-Indizes; Kontextanker plus Hash sind robuster. Intern kann ein erfolgreicher Text-Patch als `replace` auf den betroffenen Struct-Pfad erscheinen.

### § 10.3 Merge Patch

`JsonMergePatch` ist ein kleiner optionaler Komforttyp für objektartige Konfigurationen ohne relevante Arrays und ohne semantische Nullwerte. Er wird nicht automatisch in RFC 6902 umgedeutet und trägt einen eigenen Formatnamen, damit Lösch- und Nullsemantik sichtbar bleiben.

### § 10.4 Tree Patch und Collection Patch

Ein späterer `TreePatch` kann mehrere freigegebene Dateien oder Ressourcen als Manifest mit `create`, `update`, `delete` und `move` behandeln. Ein `CollectionPatch` kann fachlich über deklarierte Primärschlüssel adressierte Mengenänderungen anbieten und sie intern in die Stable-ID-Ansicht übersetzen. Beide Formate müssen denselben Policy-Vertrag für erlaubte Ziele, Limits, Hashes, Dry-Run und atomare Übernahme verwenden; sie gehören nicht in die erste Struct-Patch-Implementierung.

## § 11 Umsetzung und Evals

Die Umsetzung erfolgt in kleinen, getrennt prüfbaren Pull Requests:

1. RFC-6901-Pointer, `JsonPatchOperation`, `JsonPatch`, `JsonPatchApplier`, Exceptions und offizielle RFC-Testfälle ohne AI-Abhängigkeit.
2. `StructPatcher` mit Normalisierung, Schema-Endvalidierung, Hydration, Hash-Konfliktschutz und Dry-Run.
3. `StructPatchOutput` und `phore_ai_edit_struct()` im Pointer-Modus mit striktem Structured Output, Basisprompt, Limits und Unit-/E2E-Tests.
4. Stable-ID-Ansicht für Arrays einschließlich expliziter Identitätskonfiguration, ephemerer IDs, `$order`-Validierung und Roundtrip-Tests.
5. Erst nach Messergebnissen optional `auto`-Modus, einmalige Patch-Reparatur und ein agentisches `apply_struct_patch`-Tool.

Ein Eval-Korpus enthält mindestens: einzelnes Feld, tief verschachteltes Feld, Entfernen optionaler Werte, Nullwerte, Anhängen, Einfügen, mehrere Löschungen, Move/Copy, kombinierte Feld- und Listenänderungen, doppelte IDs, fehlende IDs, veralteten Hash, falschen `test`, ungültigen Pfad, Schemaänderungsversuch und No-op. Für kleine, mittlere und große Structs werden vollständige Regeneration, Pointer-Patch und Stable-Patch verglichen.

Gemessen werden mindestens exakte Endzustandsübereinstimmung, Patch-Anwendungsrate, Schema-Validierungsrate, unbeabsichtigt geänderte Pfade, Input-/Output-Tokens, Requests, End-to-End-Latenz und Reparaturquote. Ein Modus wird nur als Default freigegeben, wenn er über das definierte Korpus weniger Output und keine schlechtere fachliche Erfolgsrate als Vollregeneration innerhalb einer vorab festgelegten Toleranz zeigt.

## § 12 Akzeptanzkriterien

Das Proposal ist umgesetzt, wenn ein beliebiges JSON-kompatibles Dokument ohne Modell deterministisch mit den sechs RFC-6902-Operationen gepatcht werden kann; Fehler niemals ein Teilergebnis freigeben; Pointer-Escaping, Root-, Array- und `-`-Semantik getestet sind; ein PHP-Struct erst nach vollständiger Schema-Prüfung in eine neue Instanz hydratisiert wird; Hash und `test` veraltete oder falsch adressierte Änderungen verhindern; der AI-Helper genau einen begrenzten Patch-Batch per Strict Structured Output erzeugen kann; Pointer- und Stable-ID-Modus mehrere Array-Änderungen korrekt abbilden; der Prompt sequentielle Semantik und unveränderte Felder eindeutig behandelt; und Evals die Entscheidung zwischen Patch, Stable-Patch und vollständiger Regeneration anhand von Qualität, Tokens, Requests und Latenz belegen.
