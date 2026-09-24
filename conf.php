<?php
$EXT_CONF['ahlers_export'] = array(
	'title' => 'Ahlers Dokumentenexport',
	'description' => 'Exportiert die PDF-Dokumente aus einem Suchergebnis als ZIP-Archiv oder als ein zusammengeführtes PDF',
	'disable' => false,
	'version' => '1.0.0',
	'releasedate' => '2026-09-24',
	'author' => array('name' => 'Michael Ahlers', 'email' => 'michael171195@gmail.com', 'company' => 'Ahlers Soft'),
	'config' => array(
		'admin_only' => array(
			'title' => 'Export nur für Administratoren',
			'type' => 'checkbox',
		),
		'max_documents' => array(
			'title' => 'Maximale Anzahl Dokumente pro Export (leer = 1000)',
			'type' => 'input',
			'size' => 6,
		),
		'merge_tool' => array(
			'title' => 'Werkzeug zum Zusammenführen von PDFs',
			'type' => 'select',
			'options' => array('auto' => 'auto', 'qpdf' => 'qpdf', 'pdfunite' => 'pdfunite', 'gs' => 'gs'),
		),
		'merge_bin' => array(
			'title' => 'Pfad zum Programm (optional, z. B. /usr/bin/qpdf)',
			'type' => 'input',
			'size' => 40,
		),
	),
	'constraints' => array(
		'depends' => array('php' => '7.0.0-', 'seeddms' => '6.0.0-'),
	),
	'icon' => 'icon.svg',
	'changelog' => 'changelog.md',
	'class' => array(
		'file' => 'class.ahlers_export.php',
		'name' => 'SeedDMS_ExtAhlersExport'
	),
	'language' => array(
		'file' => 'lang.php',
	),
);
