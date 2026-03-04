<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\workspace;

class ext extends \phpbb\extension\base
{
    /**
     * Check whether or not the extension can be enabled.
     *
     * @return bool
     */
    public function is_enableable()
    {
        return phpbb_version_compare(PHPBB_VERSION, '3.3.0', '>=');
    }
}