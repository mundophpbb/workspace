<?php
/**
 * Pure catalog selection logic. Only call with rows already authorized for
 * viewing by permission_service::can_view_project().
 */
namespace mundophpbb\workspace\service;

class public_catalog
{
	public static function select(array $authorized, $search, $sort, $page, $page_size)
	{
		$search = trim((string) $search);
		$search = self::truncate_utf8($search, 80);
		$sort = in_array((string) $sort, ['recent', 'name'], true) ? (string) $sort : 'recent';
		$page_size = max(6, min(48, (int) $page_size));
		$page = max(1, (int) $page);
		$filtered = [];
		foreach ($authorized as $row)
		{
			if ($search !== '' && !self::matches($row, $search))
			{
				continue;
			}
			$filtered[] = $row;
		}

		usort($filtered, function ($a, $b) use ($sort) {
			if ($sort === 'recent')
			{
				$latest_a = max((int) ($a['last_updated'] ?? 0), (int) ($a['project_time'] ?? 0));
				$latest_b = max((int) ($b['last_updated'] ?? 0), (int) ($b['project_time'] ?? 0));
				if ($latest_a !== $latest_b)
				{
					return $latest_a < $latest_b ? 1 : -1;
				}
			}
			$c = strcasecmp((string) ($a['project_name'] ?? ''), (string) ($b['project_name'] ?? ''));
			return $c ?: ((int) ($a['project_id'] ?? 0) <=> (int) ($b['project_id'] ?? 0));
		});
		$total = count($filtered);
		$pages = max(1, (int) ceil($total / $page_size));
		$page = min($pages, $page);

		return [
			'rows' => array_slice($filtered, ($page - 1) * $page_size, $page_size),
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
			'search' => $search,
			'sort' => $sort,
		];
	}

	/** Preserve valid UTF-8 boundaries even if mbstring is unavailable. */
	public static function truncate_utf8($text, $limit)
	{
		$text = (string) $text;
		$limit = max(0, (int) $limit);
		if (function_exists('mb_substr'))
		{
			return mb_substr($text, 0, $limit, 'UTF-8');
		}
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		return is_array($chars) ? implode('', array_slice($chars, 0, $limit)) : substr($text, 0, $limit);
	}

	/** UTF-8 case-insensitive fallback for common Latin accents on hosts without mbstring. */
	private static function contains($text, $query)
	{
		if (function_exists('mb_stripos'))
		{
			return mb_stripos($text, $query, 0, 'UTF-8') !== false;
		}
		$upper = ['Á'=>'á', 'À'=>'à', 'Â'=>'â', 'Ã'=>'ã', 'Ä'=>'ä', 'É'=>'é', 'Ê'=>'ê', 'È'=>'è', 'Í'=>'í', 'Ì'=>'ì', 'Ó'=>'ó', 'Ô'=>'ô', 'Õ'=>'õ', 'Ú'=>'ú', 'Ü'=>'ü', 'Ç'=>'ç', 'Ñ'=>'ñ'];
		return stripos(strtr($text, $upper), strtr($query, $upper)) !== false;
	}

	private static function matches(array $row, $query)
	{
		foreach (['project_name', 'project_desc', 'owner_name'] as $field)
		{
			$haystack = (string) ($row[$field] ?? '');
			if (self::contains($haystack, $query))
			{
				return true;
			}
		}
		return false;
	}
}
