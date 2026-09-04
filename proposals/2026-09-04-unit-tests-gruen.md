# Proposal: Unit-Tests zuverlässig grün bekommen

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-04 | dermatthes | §§ 1–7: Proposal angelegt |

## § 1 Kurzfassung

Die Unit-Tests werden in GitHub Actions aktuell nicht ausgeführt. Der Workflow verwendet den Container `php:8.4-cli`, während das Projekt in `composer.json` PHP `>=8.5` verlangt. Dadurch bricht `composer install` bereits bei der Plattformprüfung ab und der Schritt `UnitTests` wird übersprungen.

Empfohlen wird, die CI-Runtime als kleinsten zusammenhängenden Fix auf PHP 8.5 anzuheben. Erst danach lässt sich belastbar feststellen, ob zusätzlich fachliche Testfehler bestehen.

## § 2 Nachgewiesener Ist-Zustand

### § 2.1 Aktueller CI-Fehler

GitHub-Actions-Lauf [#16](https://github.com/phore/phore-ai-harness/actions/runs/33404520901) auf Commit `0b7d0f728d14d77d3bc3af57c26eee415ad1bab8` endet beim Schritt `Install dependencies` mit:

```text
Root composer.json requires php >=8.5 but your php version (8.4.25) does not satisfy that requirement.
```

Der nachfolgende Schritt `UnitTests` wird deshalb übersprungen. Der rote Status belegt derzeit also keinen fehlgeschlagenen PHPUnit-Test, sondern eine nicht erfüllbare CI-Plattform.

### § 2.2 Vorhandenes Testsignal

Die eingecheckte Datei `.phpunit.cache/test-run-history` enthält für die erfassten Tests keine Defekte. Das ist nur ein Hinweis auf einen früheren lokalen Lauf und kein Ersatz für einen frischen CI-Lauf auf dem aktuellen Commit.

### § 2.3 Abgrenzung

Dieses Proposal betrifft die Unit-Test-Suite unter `test/`. Die E2E-Suite unter `e2etests/` benötigt externe OpenAI-Zugänge und gehört nicht in den credential-freien Unit-Test-Job.

## § 3 Zielbild

Der Standard-CI-Job soll eine vom Paket unterstützte PHP-Version verwenden, die Abhängigkeiten ohne ignorierte Plattformanforderungen installieren und anschließend `vendor/bin/phpunit -c phpunit.xml.dist` tatsächlich ausführen. Ein grüner Lauf bedeutet: Installation erfolgreich, PHPUnit gestartet und null Fehler beziehungsweise Fehlschläge.

## § 4 Lösungsoptionen

### § 4.1 Option A: CI auf PHP 8.5 anheben

In `.github/workflows/tests.yml` wird `php:8.4-cli` durch `php:8.5-cli` ersetzt. Das hält die deklarierte Mindestversion in `composer.json` unverändert und beseitigt die belegte Ursache mit einem einzeiligen funktionalen Diff.

Vorteile:

- Paketvertrag und CI-Runtime stimmen überein.
- PHPUnit 13 und die Projektanforderung werden auf der deklarierten Mindestversion geprüft.
- Keine Plattformanforderung wird umgangen.

Nachteile:

- Falls nach erfolgreicher Installation echte Testfehler sichtbar werden, ist ein zweiter, fachlicher Fix erforderlich.
- Die Verfügbarkeit des verwendeten PHP-Images bleibt eine externe Voraussetzung.

### § 4.2 Option B: Mindestversion auf PHP 8.4 senken

Alternativ kann `composer.json` auf PHP `>=8.4` geändert werden. Diese Option ist nur sinnvoll, wenn PHP 8.4 offiziell unterstützt werden soll und Source sowie alle Abhängigkeiten damit kompatibel sind.

Vorteil ist die Beibehaltung des vorhandenen CI-Containers. Nachteil ist eine Erweiterung des öffentlichen Kompatibilitätsversprechens; sie darf nicht allein zum Erzeugen eines grünen Checks vorgenommen werden und benötigt eine vollständige Prüfung auf PHP 8.4.

### § 4.3 Option C: Versionsmatrix einführen

Der Workflow kann auf eine Matrix mit PHP 8.5 als Mindestversion und einer zusätzlichen neueren Version umgestellt werden, beispielsweise über `shivammathur/setup-php` auf dem Ubuntu-Runner. Das verbessert die Kompatibilitätsabdeckung, ist aber ein größerer Umbau und für die unmittelbare Entblockung nicht erforderlich.

### § 4.4 Verworfene Option: Plattformprüfung ignorieren

`composer install --ignore-platform-req=php` würde den belegten Fehler verdecken, aber Tests auf einer laut Paketvertrag nicht unterstützten Runtime ausführen. Diese Variante soll nicht verwendet werden.

## § 5 Empfehlung und Umsetzung

Option A wird als erster Schritt umgesetzt:

1. In `.github/workflows/tests.yml` den Container auf `php:8.5-cli` setzen.
2. Den bestehenden Installations- und PHPUnit-Befehl unverändert lassen.
3. Den neuen Workflow-Lauf bis zum PHPUnit-Schritt prüfen.
4. Nur wenn danach echte Testfehler auftreten, diese anhand ihrer Fehlermeldungen in einem separaten, fokussierten Diff beheben.

Optional kann anschließend Option C in einem eigenen Pull Request umgesetzt werden. Option B bleibt einer bewussten Produktentscheidung zur PHP-8.4-Unterstützung vorbehalten.

## § 6 Akzeptanzkriterien

- Der CI-Job meldet PHP 8.5 oder neuer.
- `composer install --no-interaction --prefer-dist --no-progress` endet erfolgreich, ohne Plattformanforderungen zu ignorieren.
- `vendor/bin/phpunit -c phpunit.xml.dist` wird ausgeführt.
- PHPUnit endet mit Exit-Code 0 sowie null Errors und null Failures.
- Die Unit-Tests benötigen weder `OPENAI_API_KEY` noch andere externe Secrets.
- Die E2E-Suite bleibt vom Unit-Test-Job getrennt.

## § 7 Risiken und Rückfallplan

Das Anheben des Container-Tags kann bislang verdeckte Inkompatibilitäten in Abhängigkeiten oder Tests sichtbar machen. Solche Fehler werden nicht durch Absenken des Paketvertrags oder Ignorieren von Plattformanforderungen kaschiert, sondern einzeln analysiert. Falls das PHP-8.5-Image selbst nicht verfügbar oder instabil ist, wird Option C mit explizitem PHP-Setup verwendet; der bisherige Workflow lässt sich durch Rücksetzen der einzelnen Workflow-Änderung wiederherstellen.
