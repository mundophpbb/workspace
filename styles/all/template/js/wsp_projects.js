 /**
 * Mundo phpBB Workspace - Project Operations
 * Versão 4.2: i18n Pura & Conformidade CDB (Sem omissões)
 * Gerencia a estrutura de projetos, movimentação hierárquica e estados de I/O.
 */
WSP.projects = {
    /**
     * Normaliza caminhos de arquivos para o padrão Unix
     */
    _normalizePath: function (p) {
        if (!p) return '';
        return p.toString().trim().replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+$/, '');
    },

    /**
     * Une caminhos garantindo a integridade das barras
     */
    _joinPath: function (base, name) {
        base = this._normalizePath(base);
        name = this._normalizePath(name);
        return base ? base + '/' + name : name;
    },

    /**
     * Vincula todos os eventos de clique e interação do módulo
     */
    bindEvents: function ($) {
        var self = this;
        
        // Prevenção de execução múltipla (Higiene de Eventos)
        $('body').off('click.wsp_projects');

        // =====================================================
        // 1. GESTÃO DE PROJETOS (TOOLBAR)
        // =====================================================
        
        // Criar Novo Projeto
        $('body').on('click.wsp_projects', '#add-project', function (e) {
            e.preventDefault();
            WSP.ui.prompt(WSP.lang('WSP_PROMPT_PROJECT_NAME'), '', function (name) {
                if (!name) return;
                $.post(window.wspVars.addUrl, { name: name }, function (r) {
                    if (r && r.success) {
                        // Redireciona para o novo projeto via URL amigável
                        window.location.href = window.location.href.split('?')[0] + '?p=' + r.project_id;
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERROR_PROJECT_CREATE'), "error");
                    }
                }, 'json');
            });
        });

        // Selecionar/Abrir Projeto (Modal Switcher)
        $('body').on('click.wsp_projects', '#open-project', function (e) {
            e.preventDefault();
            var $projects = $('.project-group');
            if ($projects.length === 0) return WSP.ui.notify(WSP.lang('WSP_PROJECT_NOT_FOUND'), "warning");

            var listHtml = '<div class="project-switcher-box">';
            $projects.each(function () {
                var pid = $(this).data('project-id');
                var pname = $(this).find('.project-title-simple').text().trim();
                var isActive = (String(pid) === String(WSP.activeProjectId));
                
                listHtml += `
                    <div class="switcher-item ${isActive ? 'active' : ''}" onclick="window.location.href='?p=${pid}'">
                        <i class="fa ${isActive ? 'fa-folder-open' : 'fa-folder'}" style="color:var(--wsp-folder); font-size:18px;"></i>
                        <div class="switcher-info">
                            <span style="color:#fff; font-weight:bold; font-size:14px;">${$('<div/>').text(pname).html()}</span>
                            <small style="color:${isActive ? 'var(--wsp-accent)' : '#888'}">
                                ${isActive ? WSP.lang('WSP_LABEL_ACTIVE_PROJECT') : WSP.lang('WSP_LABEL_CLICK_OPEN')}
                            </small>
                        </div>
                    </div>`;
            });
            listHtml += '</div>';

            WSP.ui.prompt(WSP.lang('WSP_MODAL_TITLE_SELECT'), 'LIST_MODE');
            $('#wsp-modal-body-custom').html(listHtml).show();
        });

        // Renomear Projeto Ativo
        $('body').on('click.wsp_projects', '.rename-active-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            var oldName = $('.project-group.active-focus .project-title-simple').text().trim();
            
            WSP.ui.prompt(WSP.lang('WSP_RENAME_PROJECT_TITLE'), oldName, function (newName) {
                if (!newName || newName === oldName) return;
                $.post(window.wspVars.renameProjectUrl, { project_id: WSP.activeProjectId, name: newName }, function (r) {
                    if (r && r.success) {
                        window.location.reload();
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // Excluir Projeto Ativo
        $('body').on('click.wsp_projects', '.delete-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_DELETE_PROJ'), function () {
                $.post(window.wspVars.deleteUrl, { project_id: WSP.activeProjectId }, function (r) {
                    if (r && r.success) {
                        window.location.href = window.location.href.split('?')[0];
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_DELETE_FAILED'), "error");
                    }
                }, 'json');
            });
        });

        // =====================================================
        // 2. ARQUIVOS (SIDEBAR / ÁRVORE)
        // =====================================================

        // Mover Arquivo (Sistema de Hierarquia)
        $('body').on('click.wsp_projects', '.ctx-move-file', function (e) {
            e.stopPropagation();
            var $link = $(this).closest('.file-item').find('.load-file');
            var id = $(this).data('id') || $link.data('id');
            var fullPath = $link.attr('data-path') || $link.text();
            var fileName = fullPath.split('/').pop();

            // Gera o mapa de pastas atual da árvore para o modal
            var folderTree = {};
            $('.folder-title').each(function () {
                var path = $(this).attr('data-path');
                if (!path) return;
                var parts = path.split('/');
                var current = folderTree;
                parts.forEach(function(part, index) {
                    if (!current[part]) {
                        current[part] = { _path: parts.slice(0, index + 1).join('/'), _children: {} };
                    }
                    current = current[part]._children;
                });
            });

            var buildModalTree = function(obj) {
                var html = '<ul class="modal-tree-ul">';
                var keys = Object.keys(obj).sort();
                keys.forEach(function(key) {
                    html += `
                        <li>
                            <div class="folder-option" data-path="${obj[key]._path}">
                                <i class="fa fa-folder" style="color:var(--wsp-folder); margin-right:6px;"></i>
                                <span style="font-size:12px; color:#eee;">${key}</span>
                            </div>
                            ${buildModalTree(obj[key]._children)}
                        </li>`;
                });
                html += '</ul>';
                return (keys.length > 0) ? html : '';
            };

            var modalHtml = '<div class="modal-folder-tree-selector">';
            modalHtml += `
                <div class="folder-option is-root" data-path="">
                    <i class="fa fa-home" style="color:var(--wsp-accent); margin-right:8px;"></i>
                    <span style="font-weight:bold; color:#fff;">${WSP.lang('WSP_LABEL_MOVE_ROOT')}</span>
                </div>`;
            modalHtml += buildModalTree(folderTree);
            modalHtml += '</div>';

            WSP.ui.prompt(WSP.lang('WSP_MODAL_TITLE_MOVE'), 'LIST_MODE');
            $('#wsp-modal-body-custom').html(modalHtml).show();

            $('.folder-option').off('click').on('click', function () {
                var targetDir = $(this).attr('data-path');
                var newFullPath = targetDir ? (targetDir.replace(/\/$/, '') + '/' + fileName) : fileName;
                $('#wsp-custom-modal').hide();
                WSP.ui.notify(WSP.lang('WSP_LOADING'), "info");

                $.post(window.wspVars.moveFileUrl, { file_id: id, new_path: newFullPath }, function (r) {
                    if (r && r.success) {
                        WSP.ui.notify(WSP.lang('WSP_SAVED'));
                        WSP.ui.seamlessRefresh(); 
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });
        
        // Renomear Arquivo (Manter Prefixo de Pasta)
        $('body').on('click.wsp_projects', '.ctx-rename-file', function (e) {
            e.stopPropagation();
            var $link = $(this).closest('.file-item').find('.load-file');
            var id = $link.data('id');
            var fullPath = $link.attr('data-path') || $link.text(); 
            var parts = fullPath.split('/');
            var oldFileName = parts.pop(); 
            var folderPrefix = parts.join('/'); 

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_RENAME_FILE'), oldFileName, function (newName) {
                var cleanName = newName.replace(/[\/\\?%*:|"<>]/g, '').trim();
                if (!cleanName || cleanName === oldFileName) return;
                var newFullPath = folderPrefix ? folderPrefix + '/' + cleanName : cleanName;
                $.post(window.wspVars.renameUrl, { file_id: id, new_name: newFullPath }, function (r) {
                    if (r && r.success) {
                        WSP.ui.notify(WSP.lang('WSP_SAVED'));
                        WSP.ui.seamlessRefresh();
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // Excluir Arquivo
        $('body').on('click.wsp_projects', '.ctx-delete-file', function (e) {
            e.stopPropagation();
            var id = $(this).data('id') || $(this).closest('.file-item').find('.load-file').data('id');
            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_DELETE_FILE'), function () {
                $.post(window.wspVars.deleteFileUrl, { file_id: id }, function (r) {
                    if (r && r.success) {
                        if (String(WSP.activeFileId) === String(id)) {
                            WSP.activeFileId = null;
                            if (WSP.editor) {
                                WSP.editor.setValue(WSP.lang('WSP_WELCOME_MSG'), -1);
                                WSP.editor.setReadOnly(true);
                            }
                        }
                        WSP.ui.seamlessRefresh();
                    }
                }, 'json');
            });
        });

        // =====================================================
        // 3. PASTAS (CONTEXTO)
        // =====================================================

        // Renomear Pasta (Recursivo no Banco)
        $('body').on('click.wsp_projects', '.ctx-rename-folder', function (e) {
            e.stopPropagation();
            var oldPath = self._normalizePath(WSP.activeFolderPath);
            if (!oldPath) return;
            var parts = oldPath.split('/');
            var oldName = parts.pop();
            var parentPath = parts.join('/');

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_RENAME_FOLDER'), oldName, function (newName) {
                var clean = newName.replace(/[\/\\?%*:|"<>]/g, '').trim();
                if (!clean || clean === oldName) return;
                var newPath = parentPath ? parentPath + '/' + clean : clean;
                $.post(window.wspVars.renameFolderUrl, { project_id: WSP.activeProjectId, old_path: oldPath, new_path: newPath }, function (r) {
                    if (r && r.success) {
                        WSP.activeFolderPath = newPath;
                        WSP.ui.seamlessRefresh();
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // Criar Arquivo dentro de Pasta
        $('body').on('click.wsp_projects', '.ctx-add-file', function (e) {
            e.stopPropagation();
            var title = WSP.lang('WSP_PROMPT_NEW_FILE', {'%s': WSP.activeFolderPath});
            WSP.ui.prompt(title, '', function (name) {
                if (!name) return;
                var full = self._joinPath(WSP.activeFolderPath, name);
                $.post(window.wspVars.addFileUrl, { project_id: WSP.activeProjectId, name: full }, function (r) {
                    if (r && r.success) WSP.ui.seamlessRefresh(r.file_id);
                }, 'json');
            });
        });

        // Criar Subpasta (Via .placeholder)
        $('body').on('click.wsp_projects', '.ctx-add-folder', function (e) {
            e.stopPropagation();
            var title = WSP.lang('WSP_PROMPT_NEW_FOLDER', {'%s': WSP.activeFolderPath});
            WSP.ui.prompt(title, '', function (name) {
                if (!name) return;
                var full = self._joinPath(WSP.activeFolderPath, name) + '/.placeholder';
                $.post(window.wspVars.addFileUrl, { project_id: WSP.activeProjectId, name: full }, function (r) {
                    if (r && r.success) {
                        WSP.ui.notify(WSP.lang('WSP_READY'));
                        WSP.ui.seamlessRefresh(); 
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // Excluir Pasta e Todo Conteúdo
        $('body').on('click.wsp_projects', '.ctx-delete-folder', function (e) {
            e.stopPropagation();
            var path = self._normalizePath(WSP.activeFolderPath);
            var msg = WSP.lang('WSP_CONFIRM_DELETE_FOLDER', {'%s': path});
            WSP.ui.confirm(msg, function () {
                $.post(window.wspVars.deleteFolderUrl, { project_id: WSP.activeProjectId, path: path }, function (r) {
                    if (r && r.success) {
                        WSP.activeFolderPath = '';
                        WSP.ui.seamlessRefresh();
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // =====================================================
        // 4. AÇÕES NA RAIZ (TOOLBAR ATIVA)
        // =====================================================
        
        // Novo Arquivo na Raiz
        $('body').on('click.wsp_projects', '.add-file-to-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            WSP.ui.prompt(WSP.lang('WSP_PROMPT_ROOT_FILE'), '', function (name) {
                if (!name) return;
                $.post(window.wspVars.addFileUrl, { project_id: WSP.activeProjectId, name: name }, function (r) {
                    if (r && r.success) WSP.ui.seamlessRefresh(r.file_id);
                }, 'json');
            });
        });

        // Nova Pasta na Raiz
        $('body').on('click.wsp_projects', '.add-folder-to-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            WSP.ui.prompt(WSP.lang('WSP_PROMPT_ROOT_FOLDER'), '', function (name) {
                if (!name) return;
                var placeholder = name + '/.placeholder';
                $.post(window.wspVars.addFileUrl, { project_id: WSP.activeProjectId, name: placeholder }, function (r) {
                    if (r && r.success) {
                        WSP.ui.notify(WSP.lang('WSP_READY'));
                        WSP.ui.seamlessRefresh();
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERR_INVALID_DATA'), "error");
                    }
                }, 'json');
            });
        });

        // =====================================================
        // 5. CHANGELOG & HISTÓRICO (FIX v4.2)
        // =====================================================
        
        // Gerar Build de Versão (Header no Changelog)
        $('body').on('click.wsp_projects', '.generate-project-changelog', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            $.post(window.wspVars.changelogUrl, { project_id: WSP.activeProjectId }, function (r) {
                if (r && r.success) {
                    WSP.ui.notify(WSP.lang('WSP_NOTIFY_CHANGELOG_OK'), "success");
                    // Recarrega o editor se o changelog estiver aberto
                    if ($('.active-file .load-file').text().trim() === 'changelog.txt') {
                        $('.active-file .load-file').click();
                    }
                }
            }, 'json');
        });

        // Limpar Todo o Histórico (Fix de Tradução)
        $('body').on('click.wsp_projects', '.clear-project-changelog', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_CLEAR_CHANGE'), function () {
                $.post(window.wspVars.clearChangelogUrl, { project_id: WSP.activeProjectId }, function (r) {
                    if (r && r.success) {
                        // Usa a chave que corrigimos para PT-BR
                        WSP.ui.notify(WSP.lang('WSP_HISTORY_CLEANED'), "info");
                        // Força o reload do arquivo no editor se ele for o changelog.txt
                        if ($('.active-file .load-file').text().trim() === 'changelog.txt') {
                            $('.active-file .load-file').click();
                        }
                    }
                }, 'json');
            });
        });
    }
};