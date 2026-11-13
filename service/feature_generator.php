<?php
namespace mundophpbb\workspace\service;

/**
 * PHP 7.4-compatible, deterministic phpBB extension scaffolds.
 * No user-supplied PHP/YAML fragments are evaluated or injected.
 */
class feature_generator
{
	const TYPES = ['route', 'listener', 'migration', 'permission', 'acp'];
	const EVENTS = ['core.user_setup', 'core.page_header', 'core.viewtopic_modify_post_row'];

	public function validate($type, $feature, $vendor, $extension, $event = '')
	{
		if (!in_array($type, self::TYPES, true)) return 'type';
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $feature)) return 'feature';
		if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $vendor) ||
			!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $extension)) return 'identity';
		if ($type === 'listener' && !in_array($event, self::EVENTS, true)) return 'event';
		if ($type === 'permission' && strlen('u_' . $vendor . '_' . $extension . '_' . $feature) > 48) return 'permission_length';
		return '';
	}

	private function template($source, array $values)
	{
		return strtr($source, $values);
	}

	/** Return ['create'=>path => contents, 'append'=>path => [format,marker,snippet,header]]. */
	public function build($type, $feature, $vendor, $extension, $event = '')
	{
		if ($this->validate($type, $feature, $vendor, $extension, $event) !== '')
		{
			throw new \InvalidArgumentException('Invalid feature specification');
		}
		$ns = $vendor . '\\' . $extension;
		$svc = $vendor . '.' . $extension;
		$key = $vendor . '_' . $extension . '_' . $feature;
		$name = ucwords(str_replace('_', ' ', $feature));
		$acl = 'u_' . $key;
		$upper = strtoupper($key);
		$vars = [
			'__NS__' => $ns, '__SERVICE__' => $svc, '__KEY__' => $key,
			'__SLUG__' => $feature, '__TITLE__' => $name, '__EVENT__' => $event,
			'__VENDOR__' => $vendor, '__EXT__' => $extension,
			'__ACL__' => $acl, '__ACL_LANG__' => 'ACL_' . strtoupper($acl),
			'__ACP_LANG__' => 'ACP_' . $upper, '__CONFIG__' => $key . '_enabled',
			'__ACP_TEMPLATE__' => 'acp_' . $feature . '_body',
			'__TEMPLATE__' => $feature . '_body',
		];
		$create = [];
		$append = [];
		if ($type === 'route')
		{
			$create['controller/' . $feature . '_controller.php'] = <<<'SOURCE'
<?php
namespace __NS__\controller;

/** Generated controller: adjust authorization and data access before use. */
class __SLUG___controller
{
	private $helper;
	private $template;

	public function __construct(\phpbb\controller\helper $helper, \phpbb\template\template $template)
	{
		$this->helper = $helper;
		$this->template = $template;
	}

	public function handle()
	{
		$this->template->assign_var('GENERATED_FEATURE_TITLE', '__TITLE__');
		return $this->helper->render('@__VENDOR_____EXT__/__TEMPLATE__.html', '__TITLE__');
	}
}
SOURCE;
			$create['styles/all/template/' . $feature . '_body.html'] = <<<'SOURCE'
{% INCLUDE 'overall_header.html' %}
<h2>{GENERATED_FEATURE_TITLE}</h2>
<p>This page was created by Workspace IDE. Add authorization before showing private data.</p>
{% INCLUDE 'overall_footer.html' %}
SOURCE;
			$append['config/services.yml'] = [
				'format' => 'services', 'marker' => $svc . '.controller.' . $feature . ':',
				'snippet' => <<<'SOURCE'

	__SERVICE__.controller.__SLUG__:
		class: __NS__\controller\__SLUG___controller
		arguments:
			- '@controller.helper'
			- '@template'
		tags:
			- { name: controller.service }
SOURCE,
				'header' => "services:\n",
			];
			$append['config/routing.yml'] = [
				'format' => 'routing', 'marker' => $key . ':',
				'snippet' => <<<'SOURCE'

__KEY__:
	path: /__VENDOR__-__EXT__/__SLUG__
	defaults: { _controller: __SERVICE__.controller.__SLUG__:handle }
	methods: [GET]
SOURCE,
				'header' => '',
			];
		}
		elseif ($type === 'listener')
		{
			$create['event/' . $feature . '_listener.php'] = <<<'SOURCE'
<?php
namespace __NS__\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Generated event subscriber. No side effects until you implement on_event(). */
class __SLUG___listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'__EVENT__' => 'on_event',
		];
	}

	public function on_event($event)
	{
		// Implement the event behavior after reviewing the available variables.
	}
}
SOURCE;
			$append['config/services.yml'] = [
				'format' => 'services', 'marker' => $svc . '.listener.' . $feature . ':',
				'snippet' => <<<'SOURCE'

	__SERVICE__.listener.__SLUG__:
		class: __NS__\event\__SLUG___listener
		tags:
			- { name: event.listener }
SOURCE,
				'header' => "services:\n",
			];
		}
		elseif ($type === 'migration')
		{
			$create['migrations/v1130/add_' . $feature . '.php'] = <<<'SOURCE'
<?php
namespace __NS__\migrations\v1130;

/** Add a configuration flag. phpBB migrator also knows how to revert config.add. */
class add___SLUG__ extends \phpbb\db\migration\migration
{
	public function update_data()
	{
		return [
			['config.add', ['__CONFIG__', 1]],
		];
	}
}
SOURCE;
		}
		elseif ($type === 'permission')
		{
			$create['migrations/v1130/permission_' . $feature . '.php'] = <<<'SOURCE'
<?php
namespace __NS__\migrations\v1130;

/** Register a user permission (never grants it to existing roles automatically). */
class permission___SLUG__ extends \phpbb\db\migration\migration
{
	public function update_data()
	{
		return [
			['permission.add', ['__ACL__', true]],
		];
	}
}
SOURCE;
			$create['event/' . $feature . '_permission_listener.php'] = <<<'SOURCE'
<?php
namespace __NS__\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Tell phpBB's ACL system where to display the new permission. */
class __SLUG___permission_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.permissions' => 'add_permissions',
		];
	}

	public function add_permissions($event)
	{
		$event->update_subarray('permissions', '__ACL__', [
			'lang' => '__ACL_LANG__',
			'cat' => 'misc',
		]);
	}
}
SOURCE;
			foreach (['en' => 'Can use __TITLE__', 'pt_br' => 'Pode utilizar __TITLE__'] as $language => $description)
			{
				$create['language/' . $language . '/permissions_' . $feature . '.php'] =
					"<?php\nif (!defined('IN_PHPBB')) { exit; }\n" .
					"\$lang = isset(\$lang) && is_array(\$lang) ? \$lang : [];\n" .
					"\$lang['__ACL_LANG__'] = '" . $description . "';\n";
			}
			$append['config/permissions.yml'] = [
				'format' => 'permissions', 'marker' => $acl . ':',
				'snippet' => <<<'SOURCE'

  __ACL__:
	lang: __ACL_LANG__
	cat: misc
SOURCE,
				'header' => "permissions:\n",
			];
			$append['config/services.yml'] = [
				'format' => 'services', 'marker' => $svc . '.permission.' . $feature . ':',
				'snippet' => <<<'SOURCE'

	__SERVICE__.permission.__SLUG__:
		class: __NS__\event\__SLUG___permission_listener
		tags:
			- { name: event.listener }
SOURCE,
				'header' => "services:\n",
			];
		}
		elseif ($type === 'acp')
		{
			$create['acp/' . $feature . '_module.php'] = <<<'SOURCE'
<?php
namespace __NS__\acp;

/** Starter ACP page; add form keys and checks before processing any POST data. */
class __SLUG___module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $template, $user;
		$user->add_lang_ext('__VENDOR__/__EXT__', 'info_acp___SLUG__');
		$this->tpl_name = '__ACP_TEMPLATE__';
		$this->page_title = $user->lang('__ACP_LANG__');
		$template->assign_var('GENERATED_ACP_MESSAGE', $user->lang('__ACP_LANG__'));
	}
}
SOURCE;
			$create['acp/' . $feature . '_info.php'] = <<<'SOURCE'
<?php
namespace __NS__\acp;

class __SLUG___info
{
	public function module()
	{
		return [
			'filename' => '\\__NS__\\acp\\__SLUG___module',
			'title' => '__ACP_LANG__',
			'modes' => [
				'settings' => [
					'title' => '__ACP_LANG__',
					'auth' => 'ext___VENDOR__/__EXT__ && acl_a_board',
					'cat' => ['__ACP_LANG__'],
				],
			],
		];
	}
}
SOURCE;
			$create['adm/style/acp_' . $feature . '_body.html'] = <<<'SOURCE'
{% INCLUDE 'overall_header.html' %}
<h1>{GENERATED_ACP_MESSAGE}</h1>
<p>This ACP module is a starter scaffold. No settings are changed.</p>
{% INCLUDE 'overall_footer.html' %}
SOURCE;
			$create['migrations/v1130/acp_' . $feature . '.php'] = <<<'SOURCE'
<?php
namespace __NS__\migrations\v1130;

class acp___SLUG__ extends \phpbb\db\migration\migration
{
	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', '__ACP_LANG__']],
			['module.add', ['acp', '__ACP_LANG__', [
				'module_basename' => '\\__NS__\\acp\\__SLUG___module',
				'modes' => ['settings'],
			]]],
		];
	}
}
SOURCE;
			foreach (['en' => 'Settings', 'pt_br' => 'Configurações'] as $language => $suffix)
			{
				$create['language/' . $language . '/info_acp_' . $feature . '.php'] =
					"<?php\nif (!defined('IN_PHPBB')) { exit; }\n" .
					"\$lang = isset(\$lang) && is_array(\$lang) ? \$lang : [];\n" .
					"\$lang['__ACP_LANG__'] = '__TITLE__ - " . $suffix . "';\n";
			}
		}
		foreach ($create as &$content) $content = $this->template($content, $vars);
		unset($content);
		foreach ($append as &$item)
		{
			$item['snippet'] = $this->template($item['snippet'], $vars);
			$item['marker'] = $this->template($item['marker'], $vars);
		}
		unset($item);
		return ['create' => $create, 'append' => $append];
	}
}
