<?php

namespace mundophpbb\workspace\migrations\v100;

class initial_schema extends \phpbb\db\migration\migration
{
	public function update_schema()
	{
		return array(
			'add_tables' => array(
				// Tabela de Projetos
				$this->table_prefix . 'workspace_projects' => array(
					'COLUMNS' => array(
						'project_id'    => array('UINT', null, 'auto_increment'),
						'project_name'  => array('VCHAR:255', ''),
						'project_desc'  => array('TEXT_UNI', ''),
						'project_time'  => array('TIMESTAMP', 0),
						'user_id'       => array('UINT', 0),
					),
					'PRIMARY_KEY' => 'project_id',
				),
				// Tabela de Arquivos Virtuais
				$this->table_prefix . 'workspace_files' => array(
					'COLUMNS' => array(
						'file_id'      => array('UINT', null, 'auto_increment'),
						'project_id'   => array('UINT', 0),
						'file_name'    => array('VCHAR:255', ''),
						'file_content' => array('MTEXT_UNI', ''),
						'file_type'    => array('VCHAR:50', 'php'),
						'file_time'    => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'file_id',
					'KEYS' => array(
						'project_id' => array('INDEX', 'project_id'),
					),
				),
			),
		);
	}

	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'workspace_projects',
				$this->table_prefix . 'workspace_files',
			),
		);
	}

	public function update_data()
	{
		return array(
			// Usamos 'custom' para chamar nossa própria função de instalação de BBCode
			array('custom', array(array($this, 'install_diff_bbcode'))),
		);
	}

	/**
	 * Instala o BBCode [diff] com segurança total e suporte a nomes de arquivos com extensões
	 */
	public function install_diff_bbcode()
	{
		$bbcode_tag = 'diff';
		$bbcodes_table = $this->table_prefix . 'bbcodes';
		
		// 1. Verificamos se o BBCode já existe para evitar erros
		$sql = 'SELECT bbcode_id FROM ' . $bbcodes_table . ' 
				WHERE bbcode_tag = "' . $this->db->sql_escape($bbcode_tag) . '"';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			// 2. Se não existir, inserimos os dados de parser
			$sql_ary = array(
				'bbcode_tag'          => $bbcode_tag,
				'bbcode_match'        => '[diff={TEXT}]{TEXT1}[/diff]',
				'bbcode_tpl'          => '<div class="diff-wizard"><div class="diff-header">{TEXT}</div><pre class="diff-content">{TEXT1}</pre></div>',
				'display_on_posting'  => 0,
				'bbcode_helpline'     => 'Comparação de código: [diff=arquivo.php]conteúdo[/diff]',
				'first_pass_match'    => '/\[diff=(.*?)\](.*?)\[\/diff\]/is',
				'first_pass_replace'  => '[diff=$1]$2[/diff]',
				'second_pass_match'   => '/\[diff=(.*?)\](.*?)\[\/diff\]/is',
				'second_pass_replace' => '<div class="diff-wizard"><div class="diff-header">$1</div><pre class="diff-content">$2</pre></div>',
			);

			$this->db->sql_query('INSERT INTO ' . $bbcodes_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
		}
		
		return true; 
	}
}