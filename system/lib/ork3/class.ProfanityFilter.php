<?php

class ProfanityFilter
{
	const ERROR_MESSAGE = 'Your entry cannot be saved due to inclusion of inappropriate or offensive material. Please update the text provided and try again.';

	private const WORDS_FILE     = 'profanity-words.txt';
	private const WHITELIST_FILE = 'profanity-whitelist.txt';

	private static $LEET_MAP = [
		'0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
		'5' => 's', '7' => 't', '8' => 'b', '@' => 'a',
		'$' => 's', '!' => 'i',
	];

	private $regex     = null;
	private $whitelist = [];
	private $disabled  = false;

	public function __construct()
	{
		try {
			$dir = __DIR__ . '/data/';
			$words = $this->loadList($dir . self::WORDS_FILE);
			if (empty($words)) {
				$this->disabled = true;
				return;
			}
			$this->regex = $this->buildRegex($words);
			foreach ($this->loadList($dir . self::WHITELIST_FILE) as $w) {
				$this->whitelist[mb_strtolower($w, 'UTF-8')] = true;
			}
		} catch (\Throwable $e) {
			error_log('ProfanityFilter init failed: ' . $e->getMessage());
			$this->disabled = true;
		}
	}

	public function containsProfanity($input)
	{
		if ($this->disabled || $this->regex === null) return false;
		if (!is_string($input) || $input === '') return false;

		$normalized = $this->normalize($input);
		if ($normalized === '') return false;

		if (!preg_match_all($this->regex, $normalized, $m, PREG_OFFSET_CAPTURE)) {
			return false;
		}
		if (empty($this->whitelist)) return true;

		foreach ($m[0] as $hit) {
			$word = $this->wordAtNormalizedOffset($input, $normalized, $hit[1], strlen($hit[0]));
			if ($word === null || !isset($this->whitelist[mb_strtolower($word, 'UTF-8')])) {
				return true;
			}
		}
		return false;
	}

	private function loadList($path)
	{
		if (!is_readable($path)) return [];
		$out = [];
		foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$t = trim($line);
			if ($t === '' || $t[0] === '#') continue;
			$out[] = $t;
		}
		return $out;
	}

	private function buildRegex(array $words)
	{
		$parts = [];
		foreach ($words as $w) {
			$w = mb_strtolower($w, 'UTF-8');
			$loose = '';
			$len = mb_strlen($w, 'UTF-8');
			for ($i = 0; $i < $len; $i++) {
				$ch = mb_substr($w, $i, 1, 'UTF-8');
				$loose .= preg_quote($ch, '/') . '+';
			}
			$parts[] = $loose;
		}
		usort($parts, function ($a, $b) { return strlen($b) - strlen($a); });
		return '/\b(?:' . implode('|', $parts) . ')\b/iu';
	}

	private function normalize($s)
	{
		$s = mb_strtolower($s, 'UTF-8');
		if (class_exists('Normalizer')) {
			$n = \Normalizer::normalize($s, \Normalizer::FORM_KD);
			if (is_string($n)) $s = $n;
		} elseif (function_exists('iconv')) {
			// Fallback when intl ext is unavailable: iconv //TRANSLIT folds Latin diacritics
			// to ASCII (e.g. "fück" -> "fuck"). //IGNORE drops anything untranslatable.
			$prevLocale = setlocale(LC_CTYPE, '0');
			setlocale(LC_CTYPE, 'en_US.UTF-8');
			$n = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if (is_string($n) && $n !== '') $s = $n;
			if ($prevLocale !== false) setlocale(LC_CTYPE, $prevLocale);
		}
		$s = preg_replace('/\p{M}+/u', '', $s);
		$s = strtr($s, self::$LEET_MAP);
		// Collapse runs of single letters separated by separator chars (e.g. "f u c k", "f.u.c.k")
		// into a single contiguous token. We deliberately do NOT collapse separators between
		// multi-letter words, so that word boundaries (\b) remain meaningful and we don't turn
		// "is fucking" into "isfucking" (which would defeat the leading \b in the regex).
		$s = preg_replace_callback(
			'/(?<![\p{L}\p{N}])(?:\p{L}[\s.\-_*+]+){1,}\p{L}(?![\p{L}\p{N}])/u',
			function ($m) { return preg_replace('/[\s.\-_*+]+/u', '', $m[0]); },
			$s
		);
		return $s;
	}

	private function wordAtNormalizedOffset($original, $normalized, $offset, $matchLen)
	{
		if (preg_match_all('/[\p{L}\p{N}\']+/u', $normalized, $nm, PREG_OFFSET_CAPTURE)) {
			$ord = -1;
			foreach ($nm[0] as $i => $w) {
				if ($w[1] <= $offset && $offset < $w[1] + strlen($w[0])) {
					$ord = $i;
					break;
				}
			}
			if ($ord >= 0 && preg_match_all('/[\p{L}\p{N}\']+/u', $original, $om, PREG_OFFSET_CAPTURE)) {
				if (isset($om[0][$ord])) return $om[0][$ord][0];
			}
		}
		return null;
	}
}
