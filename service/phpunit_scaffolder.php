<?php
namespace mundophpbb\workspace\service;

/**
 * Read-only PHP source inspection and deterministic PHPUnit scaffolding.
 * No project file is included, evaluated, or executed by this service.
 * Generated behavior tests deliberately stay incomplete until reviewed.
 */
final class phpunit_scaffolder
{
	const TYPES = ['test_service', 'test_controller', 'test_permission'];
	const MAX_SOURCE_BYTES = 196608;

	public function validate($kind, $feature, $vendor, $extension)
	{
		return in_array($kind, self::TYPES, true)
			&& preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $feature)
			&& preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $vendor)
			&& preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension);
	}

	/**
	 * Extract the first named class and its namespace from PHP tokens, ignoring
	 * strings and comments. This is a discovery aid, not a PHP parser.
	 */
	public function inspectClass($source)
	{
		if (strlen($source) > self::MAX_SOURCE_BYTES || strpos($source, '<?php') === false)
		{
			return null;
		}
		$tokens = token_get_all($source);
		$namespace = '';
		$className = '';
		$methods = [];
		$total = count($tokens);
		for ($i = 0; $i < $total; $i++)
		{
			$token = $tokens[$i];
			if (!is_array($token)) continue;
			if ($token[0] === T_NAMESPACE)
			{
				$namespace = '';
				for ($j = $i + 1; $j < $total; $j++)
				{
					$part = $tokens[$j];
					if ($part === ';' || $part === '{') break;
					if (is_array($part) && in_array($part[0], [T_STRING, T_NS_SEPARATOR], true))
						$namespace .= $part[1];
					elseif (is_array($part) && defined('T_NAME_QUALIFIED') && $part[0] === constant('T_NAME_QUALIFIED'))
						$namespace .= $part[1];
				}
			}
			if ($token[0] === T_CLASS)
			{
				// Ignore anonymous classes and ::class constants.
				$previous = null;
				for ($k = $i - 1; $k >= 0; $k--)
				{
					if (!is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
					{ $previous = $tokens[$k]; break; }
				}
				if ((is_array($previous) && $previous[0] === T_NEW) || $previous === '::') continue;
				for ($j = $i + 1; $j < $total; $j++)
				{
					$next = $tokens[$j];
					if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
					if (is_array($next) && $next[0] === T_STRING) $className = $next[1];
					break;
				}
				if ($className !== '') break;
			}
		}
		if ($className === '' || $namespace === '') return null;
		for ($i = 0; $i < $total && count($methods) < 5; $i++)
		{
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_PUBLIC) continue;
			for ($j = $i + 1; $j < $total && $j <= $i + 10; $j++)
			{
				$t = $tokens[$j];
				if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC], true)) continue;
				if (is_array($t) && $t[0] === T_FUNCTION)
				{
					for ($k = $j + 1; $k < $total && $k <= $j + 4; $k++)
					{
						$n = $tokens[$k];
						if (is_array($n) && $n[0] === T_WHITESPACE) continue;
						if (is_array($n) && $n[0] === T_STRING && strtolower($n[1]) !== '__construct')
							$methods[$n[1]] = $n[1];
						break;
					}
				}
				break;
			}
		}
		// Public-method checks are structural contracts; behavioral scenarios remain incomplete.
		return ['namespace' => $namespace, 'class' => $className, 'methods' => array_values($methods)];
	}

	/** Require a literal ACL declaration, not a comment or an arbitrary usage. */
	public function hasPermissionDeclaration($source, $sourcePath, $permission)
	{
		if (!preg_match('/^[aufm]_[a-z0-9_]+$/D', $permission)) return false;
		if ($sourcePath === 'config/permissions.yml')
		{
			return (bool) preg_match('/^\s*' . preg_quote($permission, '/') . '\s*:/m', $source);
		}
		if (strpos($sourcePath, 'migrations/') !== 0 || substr($sourcePath, -4) !== '.php') return false;
		// Remove comments but preserve string literals (the migration DSL uses strings).
		$code = '';
		foreach (token_get_all($source) as $token)
		{
			if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
			$code .= is_array($token) ? $token[1] : $token;
		}
		return strpos($code, 'permission.add') !== false &&
			(bool) preg_match('/[\'\"]' . preg_quote($permission, '/') . '[\'\"]/', $code);
	}

	/**
	 * @return array{create:array,append:array} Test file + optional PHPUnit config.
	 */
	public function build($kind, $feature, $vendor, $extension, $sourcePath, $source)
	{
		if (!$this->validate($kind, $feature, $vendor, $extension))
			throw new \InvalidArgumentException('Invalid PHPUnit scaffold request');
		if (strlen($source) > self::MAX_SOURCE_BYTES)
			throw new \InvalidArgumentException('Source too large');

		$area = $kind === 'test_controller' ? 'controller' : ($kind === 'test_service' ? 'service' : 'permissions');
		$testNs = $vendor . '\\' . $extension . '\\tests\\' . $area;
		$fqcn = '';
		$methods = [];
		if ($kind !== 'test_permission')
		{
			$decl = $this->inspectClass($source);
			if (!$decl || $decl['namespace'] !== $vendor . '\\' . $extension . '\\' . $area)
				throw new \InvalidArgumentException('Target class or namespace not found');
			$fqcn = $decl['namespace'] . '\\' . $decl['class'];
			$methods = $decl['methods'];
		}
		else
		{
			if (!$this->hasPermissionDeclaration($source, $sourcePath, $feature))
				throw new \InvalidArgumentException('ACL declaration not found');
			if (strpos($sourcePath, 'migrations/') !== 0 && $sourcePath !== 'config/permissions.yml')
				throw new \InvalidArgumentException('Invalid ACL source');
		}
		$validSourcePath = ($kind === 'test_service' && (bool) preg_match('#^service/[a-z0-9_]+\.php$#D', $sourcePath)) ||
			($kind === 'test_controller' && (bool) preg_match('#^controller/[a-z0-9_]+\.php$#D', $sourcePath)) ||
			($kind === 'test_permission' && ($sourcePath === 'config/permissions.yml' ||
				(bool) preg_match('#^migrations/(?:[a-zA-Z0-9_]+/)*[a-zA-Z0-9_]+\.php$#D', $sourcePath)));
		if (!$validSourcePath)
			throw new \InvalidArgumentException('Invalid target source path');

		if ($kind === 'test_permission')
		{
			$body = <<<'PHPTEST'
<?php
namespace __TEST_NS__;

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit scaffold: declaration checks do not prove runtime ACL enforcement.
 * Execute this test in a phpBB source checkout, never in a live board.
 */
class __FEATURE___test extends TestCase
{
	public function test_acl_declaration_is_still_present()
	{
		$path = dirname(__DIR__, 2) . '/__SOURCE_PATH__';
		$source = @file_get_contents($path);
		self::assertNotFalse($source, 'ACL source was removed');
		self::assertNotFalse(strpos($source, '__FEATURE__'), 'ACL declaration is missing');
	}

	public function test_permission_is_enforced_for_user_profiles()
	{
		$this->markTestIncomplete('TODO: create isolated phpBB fixtures and assert guest, member, and administrator access.');
	}
}
PHPTEST;
		}
		else
		{
			$body = <<<'PHPTEST'
<?php
namespace __TEST_NS__;

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit scaffold: smoke coverage only. Add behavior assertions and mocks.
 * Execute via the phpBB extension test framework in a separate environment.
 */
class __FEATURE___test extends TestCase
{
	public function test_target_class_can_be_loaded()
	{
		self::assertTrue(class_exists(\__TARGET_CLASS__::class), 'Expected target class is missing');
	}

__PUBLIC_METHOD_TEST__
	public function test_behavior_with_dependencies_and_permissions()
	{
		$this->markTestIncomplete('TODO: instantiate the class with mocks; assert result, permission denial and error handling.');
	}
}
PHPTEST;
		}
		$methodTest = '';
		if ($kind !== 'test_permission' && $methods)
		{
			$methodTest = "    public function test_public_api_is_still_available()\n    {\n";
			foreach ($methods as $method)
			{
				$methodTest .= "        self::assertTrue(method_exists(\\__TARGET_CLASS__::class, '" .
					$method . "'), 'Public method " . $method . " is missing');\n";
			}
			$methodTest .= "    }\n\n";
		}
		$body = str_replace('__PUBLIC_METHOD_TEST__', $methodTest, $body);
		$body = strtr($body, [
			'__TEST_NS__' => $testNs,
			'__FEATURE__' => $feature,
			'__TARGET_CLASS__' => $fqcn,
			'__SOURCE_PATH__' => $sourcePath,
		]) . "\n";
		$config = <<<'PHPUNIT'
<?xml version="1.0" encoding="UTF-8"?>
<!-- Execute tests inside a phpBB git checkout with dev dependencies installed. -->
<phpunit bootstrap="../../../../tests/bootstrap.php" colors="true">
	<testsuites>
		<testsuite name="Extension Unit Tests">
			<directory suffix="_test.php">./tests</directory>
		</testsuite>
	</testsuites>
</phpunit>
PHPUNIT;
		return [
			'create' => [
				'tests/' . $area . '/' . $feature . '_test.php' => $body,
				'phpunit.xml.dist' => $config . "\n",
			],
			'append' => [],
		];
	}
}
