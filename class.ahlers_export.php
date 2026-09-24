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

require_once __DIR__ . '/inc/class.AhlersExportRunner.php';

/**
 * AhlersExport extension
 *
 * Exportiert die Dokumente eines Suchergebnisses (speziell PDFs) als ZIP
 * oder als ein zusammengeführtes PDF.
 *
 * @author  Michael Ahlers <michael171185@gmail.com>
 * @package SeedDMS
 * @subpackage  AhlersExport
 */
class SeedDMS_ExtAhlersExport extends SeedDMS_ExtBase
{

	/**
	 * Initialization
	 *
	 * Registriert die Hooks für die Such-Ansicht (View 'Search').
	 */
	function init()
	{ /* {{{ */
		$GLOBALS['SEEDDMS_HOOKS']['view']['search'][] = new SeedDMS_ExtAhlersExport_Search;
	} /* }}} */

	function main()
	{ /* {{{ */
	} /* }}} */
}

/**
 * Hooks der Such-Ansicht
 *
 * Ablauf:
 * - startPage: bindet js/ahlers_export.js ein (übernimmt markierte Treffer
 *   ins Export-Formular; ohne JavaScript werden alle Treffer exportiert)
 * - extraTabs: zusätzlicher Reiter „PDF-Export" neben den Suchformularen
 * - preRun:    fängt out.Search.php?action=export&ahx=1 ab und führt den
 *   eigenen Export aus. action=export ist nötig, damit out.Search.php alle
 *   Treffer ohne Seitenaufteilung an die View übergibt.
 *
 * @author  Michael Ahlers <michael171185@gmail.com>
 * @package SeedDMS
 * @subpackage  AhlersExport
 */
class SeedDMS_ExtAhlersExport_Search
{

	protected function extUrl($view, $path)
	{ /* {{{ */
		$settings = $view->getParam('settings');
		return rtrim($settings->_httpRoot, '/') . '/ext/ahlers_export/' . $path;
	} /* }}} */

	function startPage($view)
	{ /* {{{ */
		if (!SeedDMS_AhlersExport_Runner::isAllowed($view))
			return null;
		$url = $this->extUrl($view, 'js/ahlers_export.js');
		if (method_exists($view, 'htmlAddJsHeader'))
			$view->htmlAddJsHeader($url);
		else
			$view->htmlAddHeader('<script type="text/javascript" src="' . htmlspecialchars($url) . '"></script>' . "\n", 'js');
		return null;
	} /* }}} */

	function preRun($view, $classname, $action)
	{ /* {{{ */
		if ($action !== 'export' || empty($_GET['ahx']))
			return null;
		$runner = new SeedDMS_AhlersExport_Runner($view);
		$runner->run();
		/* true verhindert, dass SeedDMS zusätzlich den eingebauten Export ausführt */
		return true;
	} /* }}} */

	/**
	 * Aktuelle Suchparameter als (Name, Wert)-Paare für versteckte Felder.
	 * Verschachtelte Parameter (z. B. attributes[attr_3][from]) bleiben erhalten.
	 */
	protected function searchParamPairs()
	{ /* {{{ */
		$params = $_GET;
		foreach (SeedDMS_AhlersExport_Runner::$ownParams as $p)
			unset($params[$p]);
		$pairs = array();
		$query = http_build_query($params);
		if ($query === '')
			return $pairs;
		foreach (explode('&', $query) as $part) {
			$kv = explode('=', $part, 2);
			$pairs[] = array(urldecode($kv[0]), isset($kv[1]) ? urldecode($kv[1]) : '');
		}
		return $pairs;
	} /* }}} */

	function extraTabs($view)
	{ /* {{{ */
		if (!SeedDMS_AhlersExport_Runner::isAllowed($view))
			return null;

		$settings = $view->getParam('settings');
		$conf = SeedDMS_AhlersExport_Runner::getConf($settings);
		$totaldocs = (int) $view->getParam('totaldocs');
		$t = function ($key, $replace = array()) {
			return SeedDMS_AhlersExport_Runner::t($key, $replace);
		};

		ob_start();
		echo '<div class="ahlers-export mb-4">';
		if ($totaldocs < 1) {
			echo $view->infoMsg(htmlspecialchars($t('ahlers_export_search_first')));
			echo '</div>';
			return array('ahlersexport' => array('title' => htmlspecialchars($t('ahlers_export_tab')), 'content' => ob_get_clean()));
		}

		$mergetool = SeedDMS_AhlersExport_Runner::findMergeTool($conf);

		echo '<form class="form-horizontal" method="get" id="ahlers-export-form" action="' . htmlspecialchars(rtrim($settings->_httpRoot, '/') . '/out/out.Search.php') . '">';
		echo '<input type="hidden" name="action" value="export" />';
		echo '<input type="hidden" name="ahx" value="1" />';
		foreach ($this->searchParamPairs() as $pair)
			echo '<input type="hidden" name="' . htmlspecialchars($pair[0]) . '" value="' . htmlspecialchars($pair[1]) . '" />';

		$view->contentContainerStart();
		echo '<p>' . htmlspecialchars($t('ahlers_export_intro', array('count' => $totaldocs))) . '</p>';

		$options = array();
		$options[] = array('zip', htmlspecialchars($t('ahlers_export_format_zip')), true);
		if ($mergetool)
			$options[] = array('merge', htmlspecialchars($t('ahlers_export_format_merge') . ' (' . $mergetool['tool'] . ')'), false);
		$view->formField(
			htmlspecialchars($t('ahlers_export_format')),
			array(
				'element' => 'select',
				'name' => 'ahx_format',
				'id' => 'ahx_format',
				'options' => $options,
			)
		);
		if (!$mergetool)
			echo '<p class="small text-muted">' . htmlspecialchars($t('ahlers_export_merge_unavailable')) . '</p>';

		$view->formField(
			htmlspecialchars($t('ahlers_export_nonpdf')),
			array(
				'element' => 'select',
				'name' => 'ahx_nonpdf',
				'id' => 'ahx_nonpdf',
				'options' => array(
					array('skip', htmlspecialchars($t('ahlers_export_nonpdf_skip')), true),
					array('original', htmlspecialchars($t('ahlers_export_nonpdf_original')), false),
				),
			)
		);

		$view->formField(
			htmlspecialchars($t('ahlers_export_names')),
			array(
				'element' => 'select',
				'name' => 'ahx_names',
				'id' => 'ahx_names',
				'options' => array(
					array('name', htmlspecialchars($t('ahlers_export_names_name')), true),
					array('id_name', htmlspecialchars($t('ahlers_export_names_id_name')), false),
					array('original', htmlspecialchars($t('ahlers_export_names_original')), false),
				),
			)
		);

		$view->formField(
			htmlspecialchars($t('ahlers_export_folders')),
			array(
				'element' => 'input',
				'type' => 'checkbox',
				'name' => 'ahx_folders',
				'id' => 'ahx_folders',
				'value' => '1',
				'default' => '0',
			)
		);

		$view->formField(
			htmlspecialchars($t('ahlers_export_index')),
			array(
				'element' => 'input',
				'type' => 'checkbox',
				'name' => 'ahx_index',
				'id' => 'ahx_index',
				'value' => '1',
				'default' => '0',
				'checked' => true,
			)
		);

		echo '<p class="small text-muted">' . htmlspecialchars($t('ahlers_export_marks_hint')) . '</p>';
		echo '<p id="ahlers-export-selection" class="small font-weight-bold" data-text="' . htmlspecialchars($t('ahlers_export_marks_selected')) . '"></p>';
		echo '<button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> ' . htmlspecialchars($t('ahlers_export_button')) . '</button>';
		$view->contentContainerEnd();
		echo '</form>';
		echo '</div>';

		return array('ahlersexport' => array('title' => htmlspecialchars($t('ahlers_export_tab')), 'content' => ob_get_clean()));
	} /* }}} */
}
