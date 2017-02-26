<?php
namespace DmitryDulepov\RealurlTrace\Xclass;
/***************************************************************
*  Copyright notice
*
*  (c) 2016 Dmitry Dulepov <dmitry.dulepov@gmail.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
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

use DmitryDulepov\Realurl\Cache\DatabaseCache;
use DmitryDulepov\Realurl\Cache\UrlCacheEntry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseCacheWithTrace extends DatabaseCache  {

	/** @var array */
	protected $traceConfiguration;

	/**
	 * Creates the instance of the class.
	 */
	public function __construct() {
		parent::__construct();

		$this->traceConfiguration = (array)@unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['realurl_trace']);
	}

	/**
	 * Adds entry to the cache.
	 *
	 * @param UrlCacheEntry $cacheEntry
	 */
	public function putUrlToCache(UrlCacheEntry $cacheEntry) {
		$traceCall = ($this->traceConfiguration['logFilePath'] &&
			($this->traceConfiguration['originalUrlRegExp'] &&
			preg_match($this->traceConfiguration['originalUrlRegExp'], $cacheEntry->getOriginalUrl()) ||
			$this->traceConfiguration['speakingUrlRegExp'] &&
			preg_match($this->traceConfiguration['speakingUrlRegExp'], $cacheEntry->getSpeakingUrl())));

		if ($traceCall) {
				$this->dumpStack($cacheEntry);
		}

		parent::putUrlToCache($cacheEntry);
	}

	/**
	 * Dumps function arguments in a log-friendly way.
	 *
	 * @param array $arguments
	 * @return string
	 */
	protected function dumpFunctionArguments(array $arguments) {
		$dumpedArguments = array();
		foreach ($arguments as $argument) {
			if (is_numeric($argument)) {
				$dumpedArguments[] = $argument;
			} elseif (is_string($argument)) {
				if (strlen($argument) > 80) {
					$argument = substr($argument, 0, 30) . '...';
				}
				$argument = addslashes($argument);
				$argument = preg_replace('/\r/', '\r', $argument);
				$argument = preg_replace('/\n/', '\n', $argument);
				$dumpedArguments[] = '\'' . $argument . '\'';
			}
			elseif (is_null($argument)) {
				$dumpedArguments[] = 'null';
			}
			elseif (is_object($argument)) {
				$dumpedArguments[] = get_class($argument);
			}
			elseif (is_array($argument)) {
				$dumpedArguments[] = 'array(' . (count($arguments) ? '...' : '') . ')';
			}
			else {
				$dumpedArguments[] = gettype($argument);
			}
		}

		return '(' . implode(', ', $dumpedArguments) . ')';
	}

	/**
	 * Dumps stack trace to the log file.
	 *
	 * @param UrlCacheEntry $cacheEntry
	 */
	protected function dumpStack(UrlCacheEntry $cacheEntry) {
		$trace = debug_backtrace();
		array_shift($trace);
		$traceCount = count($trace);
		$tracePointer = 0;
		$lines = array(
			'==== ' . date('d.m.Y H:i:s') . ' ====',
			'Original URL: ' . $cacheEntry->getOriginalUrl(),
			'Speaking URL: ' . $cacheEntry->getSpeakingUrl(),
			'Stack:',
		);
		foreach ($trace as $traceEntry) {
			$codeLine = '';
			if (isset($traceEntry['class']) && $traceEntry['class']) {
				$codeLine .= $traceEntry['class'];
				$codeLine .= (isset($traceEntry['type']) && $traceEntry['type']) ? $traceEntry['type'] : '::';
			}
			if (isset($traceEntry['function']) && $traceEntry['function']) {
				$codeLine .= $traceEntry['function'];
				$codeLine .= isset($traceEntry['args']) && is_array($traceEntry['args']) ? $this->dumpFunctionArguments($traceEntry['args']) : '()';
				$codeLine .= ' ';
			}
			$codeLine .= 'at ';
			$codeLine .= ((isset($traceEntry['file']) && $traceEntry['file']) ? $traceEntry['file'] : '(unknown)');
			$codeLine .= ':';
			$codeLine .= ((isset($traceEntry['line']) && $traceEntry['line']) ? $traceEntry['line'] : '(?)');

			$lines[] = sprintf('  %3d: %s', $traceCount - $tracePointer, $codeLine);
			$tracePointer++;
		}
		// Free memory
		unset($trace);

		$file = fopen(GeneralUtility::getFileAbsFileName($this->traceConfiguration['logFilePath']), 'at');
		flock($file, LOCK_EX);
		fwrite($file, implode(LF, $lines) . LF . LF);
		flock($file, LOCK_UN);
		fclose($file);
	}
}
