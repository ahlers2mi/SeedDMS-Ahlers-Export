# SeedDMS-Ahlers-Export

SeedDMS-Erweiterung `ahlers_export`: exportiert die Dokumente eines
Suchergebnisses – speziell PDFs – als **ZIP-Archiv** oder als **ein
zusammengeführtes PDF**.

Getestet mit SeedDMS 6.0.38 (Quickstart, Theme bootstrap4).

## Installation

1. Release-ZIP `ahlers_export-<version>.zip` unter *Admin → Erweiterungen*
   hochladen (oder den Inhalt nach `ext/ahlers_export/` kopieren).
2. Die Erweiterung unter *Admin → Erweiterungen* **aktivieren**.
3. Optional in der Erweiterungskonfiguration einstellen (siehe unten).

## Bedienung

1. Suche ausführen (Datenbank- oder Volltextsuche).
2. Links den Reiter **„PDF-Export"** öffnen.
3. Optionen wählen und **Exportieren** klicken.

Sind in der Trefferliste Dokumente markiert (Kästchen rechts), werden nur diese
exportiert, sonst alle gefundenen Dokumente. Exportiert wird jeweils die
aktuelle Version; Ordner in den Treffern werden ignoriert.

| Option | Bedeutung |
| ------ | --------- |
| Format | ZIP mit einzelnen PDFs oder ein zusammengeführtes PDF |
| Dokumente, die kein PDF sind | weglassen oder im Original beilegen (nur ZIP) |
| Dateinamen | Dokumentname, ID + Dokumentname oder Original-Dateiname |
| Ordnerstruktur nachbilden | legt im ZIP die SeedDMS-Ordner als Verzeichnisse an |
| Inhaltsverzeichnis (CSV) | Liste aller Dokumente mit Status, Kategorien, Attributen |

Doppelte Dateinamen werden als `Name (2).pdf` usw. abgelegt. Beim
zusammengeführten PDF mit Inhaltsverzeichnis kommt ein ZIP mit PDF + CSV.

## Konfiguration

| Einstellung | Standard | Bedeutung |
| ----------- | -------- | --------- |
| Export nur für Administratoren | aus | Reiter und Export nur für Admins |
| Maximale Anzahl Dokumente | 1000 | Obergrenze der exportierten Dateien pro Export |
| Werkzeug zum Zusammenführen | auto | `qpdf`, `pdfunite` oder `gs` (Ghostscript); `auto` nimmt das erste gefundene |
| Pfad zum Programm | – | z. B. `/usr/bin/qpdf`, falls nicht im `PATH` |

Für „ein zusammengeführtes PDF" muss eines der Programme auf dem Server
installiert sein (Debian/Ubuntu: `apt install qpdf` oder `poppler-utils`).
Ohne Werkzeug wird die Option im Formular nicht angeboten.

## Technik

- Hook `preRun` der View `Search` fängt `out.Search.php?action=export&ahx=1`
  ab. `action=export` sorgt dafür, dass SeedDMS alle Treffer ohne
  Seitenaufteilung liefert; Suche und Rechteprüfung bleiben die von SeedDMS.
- Der eingebaute SeedDMS-Export (Stapelverarbeitung) bleibt unverändert nutzbar.
- Die Volltextsuche liefert von SeedDMS aus höchstens 1000 Treffer.
- Ist in SeedDMS „Einzelnes Suchergebnis direkt anzeigen" aktiv und gibt es nur
  genau einen Treffer, springt SeedDMS direkt zum Dokument – der Export greift
  dann nicht (Verhalten des SeedDMS-Kerns).
