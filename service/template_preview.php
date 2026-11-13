<?php
namespace mundophpbb\workspace\service;

/**
 * Non-executing, bounded VISUAL MOCK of phpBB templates. NOT a Twig interpreter.
 * The output is untrusted HTML: the browser MUST sanitize and show it in a
 * script-disabled, opaque-origin sandbox iframe with a deny-by-default CSP.
 */
final class template_preview
{
	const MAX_TEMPLATE_BYTES = 131072;
	const MAX_CSS_BYTES = 98304;
	const MAX_HTML_BYTES = 360000;
	const MAX_TOKENS = 7000;

	private $warnings = [];
	private $profile = 'member';
	private $replacements = 0;
	private $emittedBytes = 0;

	public static function isTemplate($path)
	{
		return preg_match('/\.(?:html?|twig)$/i', (string) $path) === 1;
	}

	public static function isCss($path)
	{
		return preg_match('/\.css$/i', (string) $path) === 1;
	}

	private function warn($code)
	{
		$this->warnings[$code] = true;
	}

	private static function escape($text)
	{
		return utf8_htmlspecialchars((string) $text);
	}

	private function variable($expr, array $scope)
	{
		$expr = trim((string) $expr);
		if (preg_match('/^lang(?:_js)?\s*\(\s*[\'\"]([A-Za-z0-9_]+)[\'\"](?:\s*,.*?)?\s*\)$/s', $expr, $m))
		{
			$expr = 'L_' . $m[1];
		}
		if (strpos($expr, '|') !== false)
		{
			$this->warn('twig_filters');
			$expr = trim(explode('|', $expr, 2)[0]);
		}
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $expr))
		{
			$this->warn('twig_expressions');
			return '[expressão]';
		}
		if (array_key_exists($expr, $scope)) return (string) $scope[$expr];
		$base = strtoupper($expr);
		$samples = [
			'SITENAME' => 'Fórum de exemplo', 'SITE_NAME' => 'Fórum de exemplo',
			'USERNAME' => 'Usuário de exemplo', 'USER_NAME' => 'Usuário de exemplo',
			'L_FORUM' => 'Fórum', 'L_LOGIN' => 'Entrar', 'L_LOGOUT' => 'Sair',
			'L_SUBMIT' => 'Enviar', 'L_SAVE' => 'Salvar', 'L_CANCEL' => 'Cancelar',
			'L_SEARCH' => 'Pesquisar', 'L_OPTIONS' => 'Opções', 'L_USERNAME' => 'Usuário',
			'L_PASSWORD' => 'Senha', 'L_TITLE' => 'Título', 'L_MESSAGE' => 'Mensagem',
			'U_INDEX' => '#', 'U_PROFILE' => '#', 'U_LOGIN_LOGOUT' => '#',
			'S_USER_LOGGED_IN' => $this->profile === 'member' ? '1' : '',
		];
		if (isset($samples[$base])) return $samples[$base];
		$bits = explode('.', $expr);
		$field = strtoupper((string) end($bits));
		if (strpos($field, 'U_') === 0) return '#';
		if (strpos($field, 'S_') === 0) return $this->profile === 'member' ? '1' : '';
		if (strpos($field, 'L_') === 0) $field = substr($field, 2);
		if (preg_match('/(?:IMAGE|ICON|AVATAR|SRC|URL)$/', $field)) return '';
		if (preg_match('/(?:DATE|TIME)$/', $field)) return '12/11/2026';
		if (preg_match('/(?:COUNT|TOTAL|NUMBER)$/', $field)) return '2';
		$this->warn('sample_data');
		return ucwords(strtolower(str_replace('_', ' ', $field))) . ' de exemplo';
	}

	private function testCondition($condition, array $scope)
	{
		$condition = trim((string) $condition);
		if (preg_match('/^not\s+(.+)$/i', $condition, $m) || preg_match('/^!\s*(.+)$/', $condition, $m))
		{
			return !$this->testCondition($m[1], $scope);
		}
		if (preg_match('/^([A-Za-z_][\w.]*)\s*(?:==|is\s+same\s+as)\s*(true|false)$/i', $condition, $m))
		{
			return $this->testCondition($m[1], $scope) === (strtolower($m[2]) === 'true');
		}
		if (array_key_exists($condition, $scope)) return (bool) $scope[$condition];
		$known = [
			'S_USER_LOGGED_IN' => $this->profile === 'member',
			'S_REGISTERED_USER' => $this->profile === 'member',
			'S_IS_BOT' => false, 'S_ADMIN' => false, 'S_MODERATOR' => false,
			'S_DISPLAY_ONLINE_LIST' => true,
		];
		if (array_key_exists($condition, $known)) return $known[$condition];
		if (in_array(strtolower($condition), ['true','1'], true)) return true;
		if (in_array(strtolower($condition), ['false','0'], true)) return false;
		$this->warn('unknown_conditions');
		return true; // illustrative branch; never an authorization decision
	}

	/** Parse control tags without evaluating Twig expressions or PHP. */
	private function parse(array $tokens, &$position, array $stoppers = [], $depth = 0)
	{
		$nodes = [];
		$count = count($tokens);
		if ($depth > 14) { $this->warn('nested_blocks'); return $nodes; }
		while ($position < $count)
		{
			$part = $tokens[$position];
			if (!preg_match('/^\{%\s*([A-Za-z_]+)\b(.*?)%\}$/s', $part, $match))
			{
				$nodes[] = ['text', $part]; ++$position; continue;
			}
			$tag = strtolower($match[1]); $arg = trim($match[2]);
			if (in_array($tag, $stoppers, true)) break;
			++$position;
			if ($tag === 'if')
			{
				$branches = [];
				$branches[] = [$arg, $this->parse($tokens, $position, ['elseif','else','endif'], $depth+1)];
				while ($position < $count && preg_match('/^\{%\s*elseif\s+(.*?)\s*%\}$/s', $tokens[$position], $next))
				{
					++$position; $branches[] = [trim($next[1]), $this->parse($tokens, $position, ['elseif','else','endif'], $depth+1)];
				}
				if ($position < $count && preg_match('/^\{%\s*else\s*%\}$/', $tokens[$position]))
				{
					++$position; $branches[] = [null, $this->parse($tokens, $position, ['endif'], $depth+1)];
				}
				if ($position < $count && preg_match('/^\{%\s*endif\s*%\}$/', $tokens[$position])) ++$position;
				else $this->warn('unbalanced_blocks');
				$nodes[] = ['if', $branches];
			}
			elseif ($tag === 'for' && preg_match('/^([A-Za-z_]\w*)\s+in\s+(?:loops\.)?([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)$/', $arg, $loop))
			{
				$body = $this->parse($tokens, $position, ['else','endfor'], $depth+1);
				$fallback = [];
				if ($position < $count && preg_match('/^\{%\s*else\s*%\}$/', $tokens[$position]))
				{
					++$position; $fallback = $this->parse($tokens, $position, ['endfor'], $depth+1);
				}
				if ($position < $count && preg_match('/^\{%\s*endfor\s*%\}$/', $tokens[$position])) ++$position;
				else $this->warn('unbalanced_blocks');
				$nodes[] = ['for', $loop[1], $loop[2], $body, $fallback];
			}
			elseif (in_array($tag, ['include','includejs','includecss','event','embed','extends'], true))
			{
				$this->warn('includes_not_rendered');
				$label = $tag . ': ' . substr($arg, 0, 85);
				$nodes[] = ['text', '<div class="wsp-preview-placeholder">' . self::escape($label) . '</div>'];
			}
			else
			{
				$this->warn('unsupported_twig');
				if (in_array($tag, ['endif','else','endfor','elseif'], true)) $this->warn('unbalanced_blocks');
			}
		}
		return $nodes;
	}

	private function renderNodes(array $nodes, array $scope, $depth = 0)
	{
		if ($depth > 14) return '';
		$text = '';
		foreach ($nodes as $node)
		{
			if ($this->emittedBytes >= self::MAX_HTML_BYTES) { $this->warn('output_limited'); break; }
			switch ($node[0])
			{
				case 'text':
					$fragment = preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function ($m) use ($scope) {
						if (++$this->replacements > 3000) { $this->warn('output_limited'); return ''; }
						return self::escape($this->variable($m[1], $scope));
					}, $node[1]);
					$remaining = max(0, self::MAX_HTML_BYTES - $this->emittedBytes);
					if (strlen($fragment) > $remaining) { $fragment = substr($fragment, 0, $remaining); $this->warn('output_limited'); }
					$this->emittedBytes += strlen($fragment);
					$text .= $fragment;
					break;
				case 'if':
					foreach ($node[1] as $branch)
					{
						if ($branch[0] === null || $this->testCondition($branch[0], $scope))
						{ $text .= $this->renderNodes($branch[1], $scope, $depth+1); break; }
					}
					break;
				case 'for':
					for ($i=1; $i<=2; ++$i)
					{
						$next = $scope;
						$next[$node[1]] = 'Exemplo ' . $i;
						$next['loop.first'] = $i === 1;
						$next['loop.last'] = $i === 2;
						$next['loop.index'] = $i;
						foreach (['TITLE','NAME','SUBJECT','TEXT','MESSAGE','USERNAME','DESCRIPTION','VALUE'] as $field)
						{
							$next[$node[1] . '.' . $field] = ucfirst(strtolower($field)) . ' ' . $i;
							$next[$node[2] . '.' . $field] = ucfirst(strtolower($field)) . ' ' . $i;
						}
						$text .= $this->renderNodes($node[3], $next, $depth+1);
					}
					$this->warn('sample_loops');
					break;
			}
		}
		return $text;
	}

	/** @return array{html:string,css:string,warnings:array,static_only:bool} */
	public function preview($markup, $css = '', $profile = 'member')
	{
		$markup = (string) $markup; $css = (string) $css;
		if (strlen($markup) > self::MAX_TEMPLATE_BYTES || strlen($css) > self::MAX_CSS_BYTES)
			throw new \InvalidArgumentException('preview_limits');
		$this->profile = ($profile === 'visitor') ? 'visitor' : 'member';
		$this->warnings = []; $this->replacements = 0; $this->emittedBytes = 0;
		$markup = preg_replace('/\{#.*?#\}/s', '', $markup);
		$markup = preg_replace('/<\?php\b.*?\?>/is', '<div class="wsp-preview-placeholder">PHP não executado</div>', $markup);
		$markup = preg_replace_callback('/<!--\s*(IF|ELSEIF|ELSE|ENDIF|END IF|BEGIN|END|INCLUDE|INCLUDECSS|INCLUDEJS|EVENT)\b(.*?)-->/is', function ($m) {
			$tag = strtoupper($m[1]); $arg = trim($m[2]);
			if ($tag === 'END IF') return '{% endif %}';
			if ($tag === 'BEGIN') return '{% for preview_item in loops.' . $arg . ' %}';
			if ($tag === 'END') return '{% endfor %}';
			return '{% ' . strtolower($tag) . ($arg !== '' ? ' ' . $arg : '') . ' %}';
		}, $markup);
		$markup = preg_replace('/\{([A-Za-z_][A-Za-z0-9_.]*)\}/', '{{ $1 }}', $markup);
		$tokens = preg_split('/(\{%.*?%\})/s', $markup, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (count($tokens) > self::MAX_TOKENS) throw new \InvalidArgumentException('preview_limits');
		$position = 0; $tree = $this->parse($tokens, $position);
		$html = $this->renderNodes($tree, []);
		if (strlen($html) > self::MAX_HTML_BYTES) $html = substr($html, 0, self::MAX_HTML_BYTES);
		// CSS is included as plain text, never executed on the server; the iframe CSP
		// blocks external resources. Remove imports/URLs as an additional precaution.
		$css = preg_replace('/@import\b[^;]*;/i', '/* import disabled */', $css);
		$css = preg_replace('/url\s*\(\s*(?:[^()]|\([^)]*\))*\s*\)/i', 'none', $css);
		$css = preg_replace('/expression\s*\(/i', 'disabled(', $css);
		return ['html' => $html, 'css' => $css,
			'warnings' => array_keys($this->warnings), 'static_only' => true];
	}
}
