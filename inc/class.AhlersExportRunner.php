<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Michael Ahlers <michael171185@gmail.com>
 *  All rights reserved
 *
 *  This script is part of the SeedDMS project. The SeedDMS project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Führt den Export der Suchtreffer aus.
 *
 * Wird aus dem preRun-Hook der Search-View aufgerufen. Die Treffer kommen
 * direkt aus out.Search.php (Parameter 'searchhits'), d. h. Suchlogik und
 * Rechteprüfung (M_READ) von SeedDMS werden unverändert genutzt.
 *
 * @author  Michael Ahlers <michael171185@gmail.com>
 * @package SeedDMS
 * @subpackage  AhlersExport
 */
class SeedDMS_AhlersExport_Runner
{
	const EXTNAME = 'ahlers_export';
	const DEFAULT_MAX = 1000;

	/** Parameter, die das Export-Formular selbst setzt (nicht aus der Suche übernehmen) */
	public static $ownParams = array('action', 'pg', 'marks', 'ahx', 'ahx_format', 'ahx_nonpdf', 'ahx_names', 'ahx_folders', 'ahx_index', 'includecontent', 'skipdefaultcols', 'export_options');

	protected $view;
	protected $dms;
	protected $user;
	protected $settings;
	protected $conf;
	protected $opts;
	protected $tmpdir = '';

	public function __construct($view)
	{ /* {{{ */
		$this->view = $view;
		$this->dms = $view->getParam('dms');
		$this->user = $view->getParam('user');
		$this->settings = $view->getParam('settings');
		$this->conf = self::getConf($this->settings);
	} /* }}} */

	/* ---------------------------------------------------------------
	 * Hilfsfunktionen (auch von der Oberfläche genutzt)
	 * ------------------------------------------------------------- */

	public static function getConf($settings)
	{ /* {{{ */
		if (isset($settings->_extensions[self::EXTNAME]) && is_array($settings->_extensions[self::EXTNAME]))
			return $settings->_extensions[self::EXTNAME];
		return array();
	} /* }}} */

	/**
	 * Liefert einen Konfigurationswert als String.
	 * Select-Felder speichert SeedDMS ggf. als Array bzw. kommagetrennt.
	 */
	public static function confValue($conf, $key, $default = '')
	{ /* {{{ */
		if (!isset($conf[$key]))
			return $default;
		$v = $conf[$key];
		if (is_array($v))
			$v = reset($v);
		$v = trim((string) $v);
		if (strpos($v, ',') !== false)
			$v = trim(explode(',', $v)[0]);
		return $v === '' ? $default : $v;
	} /* }}} */

	/**
	 * Übersetzung mit Rückfall auf en_GB, falls weder Benutzer- noch
	 * Standardsprache den Schlüssel kennen.
	 */
	public static function t($key, $replace = array())
	{ /* {{{ */
		$txt = getMLText($key, $replace);
		if (strpos($txt, '**') === 0)
			$txt = getMLText($key, $replace, null, 'en_GB');
		return $txt;
	} /* }}} */

	/**
	 * Darf der aktuelle Benutzer exportieren?
	 */
	public static function isAllowed($view)
	{ /* {{{ */
		$user = $view->getParam('user');
		$settings = $view->getParam('settings');
		if (!$user || $user->isGuest())
			return false;
		$conf = self::getConf($settings);
		if (!empty($conf['admin_only']) && !$user->isAdmin())
			return false;
		/* Gleiche Rechteprüfung wie beim eingebauten Export von SeedDMS
		 * (greift nur bei aktivierter erweiterter Zugriffskontrolle) */
		$accessobject = $view->getParam('accessobject');
		if ($accessobject && !$accessobject->check_view_access($view, array('action' => 'export')))
			return false;
		return true;
	} /* }}} */

	/**
	 * Sucht das Werkzeug zum Zusammenführen von PDFs.
	 *
	 * @return array|null array('tool'=>'qpdf|pdfunite|gs', 'bin'=>Pfad) oder null
	 */
	public static function findMergeTool($conf)
	{ /* {{{ */
		$tool = self::confValue($conf, 'merge_tool', 'auto');
		$bin = self::confValue($conf, 'merge_bin', '');
		$known = array('qpdf', 'pdfunite', 'gs');
		if (!in_array($tool, $known))
			$tool = 'auto';

		/* Expliziter Pfad */
		if ($bin !== '') {
			if (!@is_file($bin) || !@is_executable($bin))
				return null;
			if ($tool == 'auto') {
				$base = strtolower(basename($bin));
				if (strpos($base, 'qpdf') !== false)
					$tool = 'qpdf';
				elseif (strpos($base, 'pdfunite') !== false)
					$tool = 'pdfunite';
				elseif (strpos($base, 'gs') === 0)
					$tool = 'gs';
				else
					return null;
			}
			return array('tool' => $tool, 'bin' => $bin);
		}

		/* Im PATH suchen */
		$candidates = ($tool == 'auto') ? $known : array($tool);
		$path = getenv('PATH');
		$dirs = $path ? explode(PATH_SEPARATOR, $path) : array();
		$dirs = array_merge($dirs, array('/usr/local/bin', '/usr/bin', '/bin'));
		$dirs = array_unique($dirs);
		foreach ($candidates as $cand) {
			foreach ($dirs as $dir) {
				$f = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $cand;
				if (@is_file($f) && @is_executable($f))
					return array('tool' => $cand, 'bin' => $f);
			}
		}
		return null;
	} /* }}} */

	/* ---------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------- */

	protected function readOptions()
	{ /* {{{ */
		$g = $_GET;
		$opts = array();
		$opts['format'] = (isset($g['ahx_format']) && $g['ahx_format'] == 'merge') ? 'merge' : 'zip';
		$opts['nonpdf'] = (isset($g['ahx_nonpdf']) && $g['ahx_nonpdf'] == 'original') ? 'original' : 'skip';
		$opts['names'] = (isset($g['ahx_names']) && in_array($g['ahx_names'], array('name', 'id_name', 'original'))) ? $g['ahx_names'] : 'name';
		$opts['folders'] = !empty($g['ahx_folders']);
		$opts['index'] = !empty($g['ahx_index']);
		/* Beim Zusammenführen können nur PDFs verwendet werden */
		if ($opts['format'] == 'merge')
			$opts['nonpdf'] = 'skip';
		return $opts;
	} /* }}} */

	/**
	 * Fehlerseite ausgeben und beenden.
	 */
	protected function fail($msg)
	{ /* {{{ */
		$this->cleanup();
		/* UI::exitError() erzeugt eine neue View, die die Aktion aus dem
		 * Request liest – ohne das Entfernen würde sie 'export' aufrufen. */
		$request = $this->view->getParam('request');
		if ($request && isset($request->query))
			$request->query->remove('action');
		UI::exitError(self::t('ahlers_export'), $msg);
		exit;
	} /* }}} */

	public function cleanup()
	{ /* {{{ */
		if ($this->tmpdir && is_dir($this->tmpdir)) {
			foreach (glob($this->tmpdir . DIRECTORY_SEPARATOR . '*') as $f)
				@unlink($f);
			@rmdir($this->tmpdir);
		}
		$this->tmpdir = '';
	} /* }}} */

	protected function makeTmpDir()
	{ /* {{{ */
		$base = tempnam(sys_get_temp_dir(), 'ahlersexport-');
		if ($base === false)
			return false;
		@unlink($base);
		if (!@mkdir($base, 0700))
			return false;
		$this->tmpdir = $base;
		register_shutdown_function(array($this, 'cleanup'));
		return true;
	} /* }}} */

	/**
	 * Die ausgewählten Dokumente aus den Suchtreffern ermitteln.
	 * Sind in der Trefferliste Einträge markiert, werden nur diese exportiert.
	 */
	protected function collectDocuments()
	{ /* {{{ */
		$entries = $this->view->getParam('searchhits');
		$marks = $this->view->getParam('marks');
		if (!is_array($marks))
			$marks = array();
		$docs = array();
		if (!$entries)
			return $docs;
		foreach ($entries as $entry) {
			if (!$entry->isType('document'))
				continue;
			if ($marks && empty($marks['D' . $entry->getID()]))
				continue;
			if ($entry->getAccessMode($this->user) < M_READ)
				continue;
			$docs[] = $entry;
		}
		return $docs;
	} /* }}} */

	public static function isPdf($content)
	{ /* {{{ */
		if (strtolower($content->getMimeType()) == 'application/pdf')
			return true;
		return strtolower($content->getFileType()) == '.pdf';
	} /* }}} */

	/**
	 * Pfad der Datei im Dateisystem; bei Storage-Backend wird eine
	 * temporäre Kopie angelegt.
	 */
	protected function sourceFile($content, $n)
	{ /* {{{ */
		$storage = method_exists($this->dms, 'getStorage') ? $this->dms->getStorage() : null;
		if ($storage) {
			$data = $content->content();
			if ($data === false || $data === null)
				return false;
			$file = $this->tmpdir . DIRECTORY_SEPARATOR . 'src' . $n . $content->getFileType();
			if (file_put_contents($file, $data) === false)
				return false;
			return $file;
		}
		$file = $this->dms->contentDir . $content->getPath();
		return is_readable($file) ? $file : false;
	} /* }}} */

	public static function sanitize($name)
	{ /* {{{ */
		$name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', (string) $name);
		if ($name === null)
			$name = '';
		$name = trim($name, " .\t");
		if (function_exists('mb_substr'))
			$name = mb_substr($name, 0, 150, 'UTF-8');
		else
			$name = substr($name, 0, 150);
		return $name === '' ? 'dokument' : $name;
	} /* }}} */

	/**
	 * Ordnerpfad (ohne Wurzelordner) als Liste von Namen.
	 */
	protected function folderNames($document)
	{ /* {{{ */
		$names = array();
		$folder = $document->getFolder();
		if (!$folder)
			return $names;
		$path = $folder->getPath();
		foreach ($path as $i => $f) {
			if ($i == 0)
				continue; /* Wurzelordner auslassen */
			$names[] = $f->getName();
		}
		return $names;
	} /* }}} */

	protected function buildFileName($document, $content)
	{ /* {{{ */
		$ext = strtolower($content->getFileType());
		if ($ext === '' || $ext[0] != '.')
			$ext = $ext === '' ? '' : '.' . $ext;

		switch ($this->opts['names']) {
			case 'original':
				$base = $content->getOriginalFileName();
				if (!$base)
					$base = $document->getName();
				break;
			case 'id_name':
				$base = $document->getID() . '_' . $document->getName();
				break;
			case 'name':
			default:
				$base = $document->getName();
		}
		$base = self::sanitize($base);
		if ($ext && strtolower(substr($base, -strlen($ext))) != $ext)
			$base .= $ext;

		if ($this->opts['folders']) {
			$parts = array();
			foreach ($this->folderNames($document) as $fn)
				$parts[] = self::sanitize($fn);
			if ($parts)
				$base = implode('/', $parts) . '/' . $base;
		}
		return $base;
	} /* }}} */

	/**
	 * Doppelte Namen im Archiv vermeiden: "name (2).pdf"
	 */
	protected function uniqueName($name, &$used)
	{ /* {{{ */
		$key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
		if (!isset($used[$key])) {
			$used[$key] = 1;
			return $name;
		}
		$dot = strrpos($name, '.');
		$slash = strrpos($name, '/');
		if ($dot === false || ($slash !== false && $dot < $slash)) {
			$stem = $name;
			$ext = '';
		} else {
			$stem = substr($name, 0, $dot);
			$ext = substr($name, $dot);
		}
		$i = $used[$key];
		do {
			$i++;
			$cand = $stem . ' (' . $i . ')' . $ext;
			$ckey = function_exists('mb_strtolower') ? mb_strtolower($cand, 'UTF-8') : strtolower($cand);
		} while (isset($used[$ckey]));
		$used[$key] = $i;
		$used[$ckey] = 1;
		return $cand;
	} /* }}} */

	/**
	 * Inhaltsverzeichnis als CSV (Semikolon, UTF-8 mit BOM – öffnet in Excel direkt)
	 */
	protected function buildIndex($items)
	{ /* {{{ */
		/* Alle vorkommenden Attributdefinitionen als eigene Spalten */
		$attrcols = array();
		foreach ($items as $item) {
			foreach ($item['document']->getAttributes() as $attr) {
				$def = $attr->getAttributeDefinition();
				if ($def && !isset($attrcols[$def->getID()]))
					$attrcols[$def->getID()] = $def->getName();
			}
		}

		$header = array(
			self::t('ahlers_export_col_no'),
			self::t('ahlers_export_col_docid'),
			getMLText('version'),
			getMLText('name'),
			getMLText('folder'),
			self::t('ahlers_export_col_file'),
			getMLText('status'),
			self::t('ahlers_export_col_orgname'),
			self::t('ahlers_export_col_size'),
			getMLText('creation_date'),
			getMLText('owner'),
			getMLText('categories'),
			getMLText('keywords'),
			getMLText('comment'),
		);
		foreach ($attrcols as $name)
			$header[] = $name;

		$fh = fopen('php://temp', 'w+');
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, $header, ';', '"', '\\');
		$nr = 0;
		foreach ($items as $item) {
			$doc = $item['document'];
			$content = $item['content'];
			$cats = array();
			foreach ($doc->getCategories() as $cat)
				$cats[] = $cat->getName();
			$owner = $doc->getOwner();
			$row = array(
				++$nr,
				$doc->getID(),
				$content->getVersion(),
				$doc->getName(),
				implode(' / ', $this->folderNames($doc)),
				$item['file'],
				self::t('ahlers_export_status_' . $item['status']),
				$content->getOriginalFileName(),
				$content->getFileSize(),
				date('Y-m-d H:i', $doc->getDate()),
				$owner ? $owner->getFullName() : '',
				implode(', ', $cats),
				$doc->getKeywords(),
				$doc->getComment(),
			);
			$attrs = $doc->getAttributes();
			foreach ($attrcols as $id => $name)
				$row[] = isset($attrs[$id]) ? $attrs[$id]->getValueAsString() : '';
			fputcsv($fh, $row, ';', '"', '\\');
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		/* Windows-Zeilenenden für Excel */
		return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $csv));
	} /* }}} */

	protected function mergePdfs($files, $target)
	{ /* {{{ */
		$tool = self::findMergeTool($this->conf);
		if (!$tool)
			$this->fail(self::t('ahlers_export_no_merge_tool'));

		$args = array();
		switch ($tool['tool']) {
			case 'qpdf':
				$args = array_merge(array('--empty', '--pages'), $files, array('--', $target));
				break;
			case 'pdfunite':
				$args = array_merge($files, array($target));
				break;
			case 'gs':
				$args = array_merge(array('-dBATCH', '-dNOPAUSE', '-dQUIET', '-sDEVICE=pdfwrite', '-sOutputFile=' . $target), $files);
				break;
		}
		$cmd = escapeshellarg($tool['bin']);
		foreach ($args as $a)
			$cmd .= ' ' . escapeshellarg($a);
		$output = array();
		$rc = -1;
		@exec($cmd . ' 2>&1', $output, $rc);
		/* qpdf liefert 3, wenn nur Warnungen aufgetreten sind */
		$ok = ($rc === 0 || ($tool['tool'] == 'qpdf' && $rc === 3));
		if (!$ok || !is_file($target) || filesize($target) == 0) {
			$msg = self::t('ahlers_export_merge_failed', array('tool' => $tool['tool'], 'rc' => $rc));
			if ($output)
				$msg .= '<br><pre>' . htmlspecialchars(implode("\n", array_slice($output, 0, 20))) . '</pre>';
			$this->fail($msg);
		}
	} /* }}} */

	protected function send($file, $downloadname, $mimetype)
	{ /* {{{ */
		while (ob_get_level() > 0)
			@ob_end_clean();
		$ascii = preg_replace('/[^A-Za-z0-9_.-]/', '_', $downloadname);
		header('Content-Type: ' . $mimetype);
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . filesize($file));
		header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadname));
		header('Cache-Control: must-revalidate');
		readfile($file);
	} /* }}} */

	/**
	 * Export ausführen und Datei ausliefern.
	 */
	public function run()
	{ /* {{{ */
		if (!self::isAllowed($this->view))
			$this->fail(getMLText('access_denied'));
		if (!class_exists('ZipArchive'))
			$this->fail(self::t('ahlers_export_no_zip'));

		@set_time_limit(0);
		$this->opts = $this->readOptions();

		$docs = $this->collectDocuments();
		if (!$docs)
			$this->fail(self::t('ahlers_export_no_documents'));

		if (!$this->makeTmpDir())
			$this->fail(self::t('ahlers_export_tmp_failed'));

		/* Einträge aufbauen */
		$items = array();
		$used = array();
		$n = 0;
		foreach ($docs as $doc) {
			$content = $doc->getLatestContent();
			if (!$content)
				continue;
			$n++;
			$item = array('document' => $doc, 'content' => $content, 'file' => '', 'src' => '', 'status' => 'pdf');
			$pdf = self::isPdf($content);
			if (!$pdf && $this->opts['nonpdf'] == 'skip') {
				$item['status'] = 'skipped';
				$items[] = $item;
				continue;
			}
			$src = $this->sourceFile($content, $n);
			if (!$src) {
				$item['status'] = 'missing';
				$items[] = $item;
				continue;
			}
			$item['src'] = $src;
			$item['status'] = $pdf ? 'pdf' : 'original';
			if ($this->opts['format'] == 'zip')
				$item['file'] = $this->uniqueName($this->buildFileName($doc, $content), $used);
			$items[] = $item;
		}

		$exported = array();
		foreach ($items as $item)
			if ($item['src'])
				$exported[] = $item;
		if (!$exported)
			$this->fail(self::t('ahlers_export_no_pdf'));

		/* Obergrenze bezieht sich auf die tatsächlich exportierten Dateien */
		$max = (int) self::confValue($this->conf, 'max_documents', (string) self::DEFAULT_MAX);
		if ($max < 1)
			$max = self::DEFAULT_MAX;
		if (count($exported) > $max)
			$this->fail(self::t('ahlers_export_too_many', array('count' => count($exported), 'max' => $max)));

		$stamp = date('Y-m-d_Hi');

		if ($this->opts['format'] == 'merge') {
			$merged = $this->tmpdir . DIRECTORY_SEPARATOR . 'merged.pdf';
			$files = array();
			foreach ($exported as $item)
				$files[] = $item['src'];
			$mergedname = 'export-' . $stamp . '.pdf';
			foreach ($items as &$item)
				if ($item['src'])
					$item['file'] = $mergedname;
			unset($item);
			$this->mergePdfs($files, $merged);

			if (!$this->opts['index']) {
				$this->send($merged, $mergedname, 'application/pdf');
				$this->cleanup();
				return true;
			}
			/* Mit Inhaltsverzeichnis: PDF + CSV als ZIP */
			$zipfile = $this->tmpdir . DIRECTORY_SEPARATOR . 'export.zip';
			$zip = new ZipArchive();
			if ($zip->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
				$this->fail(self::t('ahlers_export_tmp_failed'));
			$zip->addFile($merged, $mergedname);
			$zip->addFromString(self::t('ahlers_export_index_file') . '.csv', $this->buildIndex($items));
			$zip->close();
			$this->send($zipfile, 'export-' . $stamp . '.zip', 'application/zip');
			$this->cleanup();
			return true;
		}

		/* ZIP mit Einzeldateien */
		$zipfile = $this->tmpdir . DIRECTORY_SEPARATOR . 'export.zip';
		$zip = new ZipArchive();
		if ($zip->open($zipfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
			$this->fail(self::t('ahlers_export_tmp_failed'));
		foreach ($exported as $item)
			$zip->addFile($item['src'], $item['file']);
		if ($this->opts['index'])
			$zip->addFromString(self::t('ahlers_export_index_file') . '.csv', $this->buildIndex($items));
		if (!$zip->close())
			$this->fail(self::t('ahlers_export_tmp_failed'));
		$this->send($zipfile, 'export-' . $stamp . '.zip', 'application/zip');
		$this->cleanup();
		return true;
	} /* }}} */
}
