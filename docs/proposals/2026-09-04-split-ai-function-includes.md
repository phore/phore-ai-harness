# Proposal: Globale AI-Funktionen in einzelne Includes aufteilen

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–5: Proposal angelegt |

## § 1 Kurzfassung

Die globalen `phore_ai_*`-Funktionen werden aus der gemeinsamen Datei `src/functions.php` in jeweils eine eigene Datei unter `src/functions/` ausgelagert. `src/functions.php` bleibt der einzige Composer-Autoload-Einstiegspunkt und bindet die einzelnen Implementierungen explizit ein.

## § 2 Motivation

Die bisherige Datei bündelt unterschiedliche Anwendungsfälle für Text, Bild, strukturierte Einzelobjekte, strukturierte Listen und Dateibearbeitung. Dadurch müssen Änderungen an einer einzelnen Spezialimplementierung stets in derselben großen Datei erfolgen. Separate Includes verkleinern den jeweiligen Änderungskontext, verbessern die Auffindbarkeit und reduzieren Konflikte bei parallelen Änderungen.

## § 3 Zielstruktur

Jede öffentliche `phore_ai_*`-Funktion erhält eine gleichnamige Datei:

- `src/functions/phore_ai_text.php`
- `src/functions/phore_ai_image.php`
- `src/functions/phore_ai_struct.php`
- `src/functions/phore_ai_struct_array.php`
- `src/functions/phore_ai_file.php`

`src/functions.php` lädt diese Dateien über eine explizite Liste von `require_once`-Anweisungen. Die explizite Liste macht den Autoload-Pfad deterministisch und vermeidet versteckte Dateisystem-Semantik durch `glob()` oder eine vom Dateinamen abhängige Sortierung. Die beiden Prozessstatus-Helfer `get_last_ai_request()` und `get_last_ai_response()` bleiben in der Aggregator-Datei, da sie keine Harness-Spezialimplementierungen darstellen.

## § 4 Kompatibilität

Die Funktionsnamen, Parameter, Rückgabetypen, PHPDoc-Verträge und Implementierungen bleiben unverändert. Auch `composer.json` bleibt unverändert und lädt weiterhin ausschließlich `src/functions.php`. Für Bibliotheksnutzer entsteht daher keine API- oder Autoload-Änderung.

## § 5 Umsetzung und Prüfung

Die fünf Implementierungen werden ohne fachliche Änderung verschoben. Der bestehende Funktionstest wird so erweitert, dass alle fünf öffentlichen `phore_ai_*`-Funktionen nach dem Composer-Autoload verfügbar sein müssen. Anschließend werden die fokussierten Funktionstests ausgeführt.
