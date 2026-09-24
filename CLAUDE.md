# CLAUDE.md

Leitfaden für die Arbeit an diesem Repository mit Claude Code.

## Was ist das?

`ahlers_export` ist eine **SeedDMS-Erweiterung** (PHP), die die Dokumente eines
Suchergebnisses (speziell PDFs) als ZIP oder als ein zusammengeführtes PDF
exportiert. Kein Build-/Dependency-Management; die Dateien liegen in SeedDMS
unter `ext/ahlers_export/`.

## Dateien

| Pfad | Rolle |
| ---- | ----- |
| `conf.php` | Manifest: Version, `releasedate`, Config-Optionen |
| `class.ahlers_export.php` | Extension-Klasse + Hooks der View `Search` (`startPage`, `extraTabs`, `preRun`) |
| `inc/class.AhlersExportRunner.php` | Export-Logik (Auswahl, Dateinamen, ZIP, Merge, CSV) |
| `js/ahlers_export.js` | übernimmt markierte Treffer (`marks[D<id>]`) ins Formular |
| `lang.php` | Übersetzungen (de_DE, en_GB; Rückfall auf en_GB über `Runner::t()`) |

## Wichtige Details

- Export läuft über `out.Search.php?action=export&ahx=1`: nur mit
  `action=export` liefert `out.Search.php` alle Treffer ungeteilt. `preRun`
  gibt `true` zurück, damit der eingebaute Export nicht zusätzlich läuft.
- Fehler über `Runner::fail()`: entfernt `action` aus dem Request, sonst würde
  `UI::exitError()` in der ErrorDlg-View die Aktion `export` aufrufen.
- Inline-JavaScript ist per CSP verboten → JS als Datei über `htmlAddJsHeader`.
- Konventionen wie in den anderen Ahlers-Erweiterungen: deutsche Kommentare,
  `/* {{{ */ … /* }}} */`-Folds, GPL-2-Header.

## Versionierung

Bei funktionalen Änderungen `version`/`releasedate` in `conf.php` und
`changelog.md` pflegen. Release-ZIP über `.github/workflows/build-zip.yml`
(`workflow_dispatch` oder Tag `v*`).

## Prüfen

```bash
for f in conf.php class.ahlers_export.php inc/class.AhlersExportRunner.php lang.php; do php -l $f; done
```

Für einen echten Test lässt sich das SeedDMS-Quickstart-Paket (SQLite) mit
`php -S` starten (Umgebungsvariable `SEEDDMS_CONFIG_FILE` setzen, Pfade in
`settings.xml` eintragen, Erweiterung dort aktivieren).
