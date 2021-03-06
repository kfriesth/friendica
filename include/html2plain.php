<?php

require_once 'include/html2bbcode.php';

function breaklines($line, $level, $wraplength = 75)
{
	if ($wraplength == 0) {
		$wraplength = 2000000;
	}

	$wraplen = $wraplength - $level;

	$newlines = [];

	do {
		$oldline = $line;

		$subline = substr($line, 0, $wraplen);

		$pos = strrpos($subline, ' ');

		if ($pos == 0) {
			$pos = strpos($line, ' ');
		}

		if (($pos > 0) && strlen($line) > $wraplen) {
			$newline = trim(substr($line, 0, $pos));
			if ($level > 0) {
				$newline = str_repeat(">", $level) . ' ' . $newline;
			}

			$newlines[] = $newline . " ";
			$line = substr($line, $pos + 1);
		}
	} while ((strlen($line) > $wraplen) && !($oldline == $line));

	if ($level > 0) {
		$line = str_repeat(">", $level) . ' ' . $line;
	}

	$newlines[] = $line;

	return implode($newlines, "\n");
}

function quotelevel($message, $wraplength = 75)
{
	$lines = explode("\n", $message);

	$newlines = [];
	$level = 0;
	foreach ($lines as $line) {
		$line = trim($line);
		$startquote = false;
		while (strpos("*" . $line, '[quote]') > 0) {
			$level++;
			$pos = strpos($line, '[quote]');
			$line = substr($line, 0, $pos) . substr($line, $pos + 7);
			$startquote = true;
		}

		$currlevel = $level;

		while (strpos("*" . $line, '[/quote]') > 0) {
			$level--;
			if ($level < 0) {
				$level = 0;
			}

			$pos = strpos($line, '[/quote]');
			$line = substr($line, 0, $pos) . substr($line, $pos + 8);
		}

		if (!$startquote || ($line != '')) {
			$newlines[] = breaklines($line, $currlevel, $wraplength);
		}
	}

	return implode($newlines, "\n");
}

function collecturls($message)
{
	$pattern = '/<a.*?href="(.*?)".*?>(.*?)<\/a>/is';
	preg_match_all($pattern, $message, $result, PREG_SET_ORDER);

	$urls = [];
	foreach ($result as $treffer) {
		$ignore = false;

		// A list of some links that should be ignored
		$list = ["/user/", "/tag/", "/group/", "/profile/", "/search?search=", "/search?tag=", "mailto:", "/u/", "/node/",
			"//facebook.com/profile.php?id=", "//plus.google.com/", "//twitter.com/"];
		foreach ($list as $listitem) {
			if (strpos($treffer[1], $listitem) !== false) {
				$ignore = true;
			}
		}

		if ((strpos($treffer[1], "//twitter.com/") !== false) && (strpos($treffer[1], "/status/") !== false)) {
			$ignore = false;
		}

		if ((strpos($treffer[1], "//plus.google.com/") !== false) && (strpos($treffer[1], "/posts") !== false)) {
			$ignore = false;
		}

		if ((strpos($treffer[1], "//plus.google.com/") !== false) && (strpos($treffer[1], "/photos") !== false)) {
			$ignore = false;
		}

		if (!$ignore) {
			$urls[$treffer[1]] = $treffer[1];
		}
	}

	return $urls;
}

function html2plain($html, $wraplength = 75, $compact = false)
{
	global $lang;

	$message = str_replace("\r", "", $html);

	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = false;

	$message = mb_convert_encoding($message, 'HTML-ENTITIES', "UTF-8");

	@$doc->loadHTML($message);

	$xpath = new DomXPath($doc);
	$list = $xpath->query("//pre");
	foreach ($list as $node) {
		$node->nodeValue = str_replace("\n", "\r", $node->nodeValue);
	}

	$message = $doc->saveHTML();
	$message = str_replace(["\n<", ">\n", "\r", "\n", "\xC3\x82\xC2\xA0"], ["<", ">", "<br>", " ", ""], $message);
	$message = preg_replace('= [\s]*=i', " ", $message);

	// Collecting all links
	$urls = collecturls($message);

	@$doc->loadHTML($message);

	node2bbcode($doc, 'html', [], '', '');
	node2bbcode($doc, 'body', [], '', '');

	// MyBB-Auszeichnungen
	/*
	  node2bbcode($doc, 'span', array('style'=>'text-decoration: underline;'), '_', '_');
	  node2bbcode($doc, 'span', array('style'=>'font-style: italic;'), '/', '/');
	  node2bbcode($doc, 'span', array('style'=>'font-weight: bold;'), '*', '*');

	  node2bbcode($doc, 'strong', array(), '*', '*');
	  node2bbcode($doc, 'b', array(), '*', '*');
	  node2bbcode($doc, 'i', array(), '/', '/');
	  node2bbcode($doc, 'u', array(), '_', '_');
	 */

	if ($compact) {
		node2bbcode($doc, 'blockquote', [], "»", "«");
	} else {
		node2bbcode($doc, 'blockquote', [], '[quote]', "[/quote]\n");
	}

	node2bbcode($doc, 'br', [], "\n", '');

	node2bbcode($doc, 'span', [], "", "");
	node2bbcode($doc, 'pre', [], "", "");
	node2bbcode($doc, 'div', [], "\r", "\r");
	node2bbcode($doc, 'p', [], "\n", "\n");

	//node2bbcode($doc, 'ul', array(), "\n[list]", "[/list]\n");
	//node2bbcode($doc, 'ol', array(), "\n[list=1]", "[/list]\n");
	node2bbcode($doc, 'li', [], "\n* ", "\n");

	node2bbcode($doc, 'hr', [], "\n" . str_repeat("-", 70) . "\n", "");

	node2bbcode($doc, 'tr', [], "\n", "");
	node2bbcode($doc, 'td', [], "\t", "");

	node2bbcode($doc, 'h1', [], "\n\n*", "*\n");
	node2bbcode($doc, 'h2', [], "\n\n*", "*\n");
	node2bbcode($doc, 'h3', [], "\n\n*", "*\n");
	node2bbcode($doc, 'h4', [], "\n\n*", "*\n");
	node2bbcode($doc, 'h5', [], "\n\n*", "*\n");
	node2bbcode($doc, 'h6', [], "\n\n*", "*\n");

	// Problem: there is no reliable way to detect if it is a link to a tag or profile
	//node2bbcode($doc, 'a', array('href'=>'/(.+)/'), ' $1 ', ' ', true);
	//node2bbcode($doc, 'a', array('href'=>'/(.+)/', 'rel'=>'oembed'), ' $1 ', '', true);
	//node2bbcode($doc, 'img', array('alt'=>'/(.+)/'), '$1', '');
	//node2bbcode($doc, 'img', array('title'=>'/(.+)/'), '$1', '');
	//node2bbcode($doc, 'img', array(), '', '');
	if (!$compact) {
		node2bbcode($doc, 'img', ['src' => '/(.+)/'], ' [img]$1', '[/img] ');
	} else {
		node2bbcode($doc, 'img', ['src' => '/(.+)/'], ' ', ' ');
	}

	node2bbcode($doc, 'iframe', ['src' => '/(.+)/'], ' $1 ', '');

	$message = $doc->saveHTML();

	if (!$compact) {
		$message = str_replace("[img]", "", $message);
		$message = str_replace("[/img]", "", $message);
	}

	// was ersetze ich da?
	// Irgendein stoerrisches UTF-Zeug
	$message = str_replace(chr(194) . chr(160), ' ', $message);

	$message = str_replace("&nbsp;", " ", $message);

	// Aufeinanderfolgende DIVs
	$message = preg_replace('=\r *\r=i', "\n", $message);
	$message = str_replace("\r", "\n", $message);

	$message = strip_tags($message);

	$message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');

	if (!$compact && ($message != '')) {
		foreach ($urls as $id => $url) {
			if ($url != '' && strpos($message, $url) === false) {
				$message .= "\n" . $url . ' ';
			}
		}
	}

	$message = str_replace("\n«", "«\n", $message);
	$message = str_replace("»\n", "\n»", $message);

	do {
		$oldmessage = $message;
		$message = str_replace("\n\n\n", "\n\n", $message);
	} while ($oldmessage != $message);

	$message = quotelevel(trim($message), $wraplength);

	return trim($message);
}
