/**
 * Mundo phpBB Workspace - Tools (Diff, Search, Cache)
 * Versão 4.2: i18n Pura & Conformidade CDB (Sem omissões)
 * Gerencia utilitários de busca, substituição, comparação e manutenção.
 */
WSP.tools = {
    /**
     * Vincula todos os eventos das ferramentas
     */
    bindEvents: function ($) {
        var self = this;

        // Helper para verificar existência de elementos no DOM
        var hasEl = function (sel) { return $(sel).length > 0; };

        // Prevenção de execução múltipla (Higiene de Eventos)
        $('body').off('click.wsp_tools');

        /**
         * 1. BUSCA E SUBSTITUIÇÃO (GLOBAL OU ARQUIVO ÚNICO)
         */
        $('body').on('click.wsp_tools', '.open-search-replace', function (e) {
            e.preventDefault();
            var projectId = WSP.activeProjectId;

            if (!projectId) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), "warning");
            }

            if (!hasEl('#search-replace-modal')) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), "error");
            }

            // Limpa campos e prepara o modal para o projeto ativo
            $('#wsp-search-term').val('');
            $('#wsp-replace-term').val('');
            $('#search-project-id').val(projectId);

            // Abre o modal com foco automático no termo de busca
            $('#search-replace-modal').fadeIn(200, function() {
                $('#wsp-search-term').focus();
            });
        });

        // Executar a Substituição no Banco de Dados
        $('body').on('click.wsp_tools', '#exec-replace-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var projectId = $('#search-project-id').val() || WSP.activeProjectId;
            
            var data = {
                project_id: projectId,
                file_id: WSP.activeFileId || 0, // 0 indica "Substituir em todo o projeto"
                search: $('#wsp-search-term').val(),
                replace: $('#wsp-replace-term').val()
            };

            if (!data.search) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_TERM_REQUIRED'), "warning");
            }

            WSP.ui.confirm(WSP.lang('WSP_TOOLS_SEARCH_CONFIRM'), function () {
                // Feedback visual de processamento (Tradução vinda do Core)
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_PROCESSING'));

                $.post(window.wspVars.replaceUrl, data, function (r) {
                    if (r && r.success) {
                        // Sucesso com placeholder numérico %d (Ex: "5 arquivos alterados")
                        var successMsg = WSP.lang('WSP_REPLACE_SUCCESS', {'%d': r.updated});
                        WSP.ui.notify(successMsg, "success");
                        
                        // Limpa backups locais para garantir que a nova versão do banco seja a oficial
                        self._clearBackups(data.file_id);

                        $('#search-replace-modal').fadeOut(200);
                        WSP.ui.seamlessRefresh();

                        // Se havia um arquivo aberto, força o recarregamento do novo conteúdo no editor
                        if (WSP.activeFileId) {
                            $('.active-file .load-file').click();
                        }
                    } else {
                        WSP.ui.notify(r.error || WSP.lang('WSP_ERROR_CRITICAL'), "error");
                    }
                }, 'json').always(function() {
                    // Restaura o botão para o estado original traduzido
                    $btn.prop('disabled', false).text(WSP.lang('WSP_REPLACE_ALL'));
                });
            });
        });

        /**
         * 2. FERRAMENTA DE DIFERENÇA (DIFF / COMPARAR ARQUIVOS)
         */
        $('body').on('click.wsp_tools', '#open-diff-tool', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), "info");
            }

            var $orig = $('#diff-original').empty();
            var $mod = $('#diff-modified').empty();
            var foundFiles = 0;

            // Popula os selects dinamicamente usando os arquivos visíveis na árvore
            $('.load-file').each(function () {
                var $a = $(this);
                var id = $a.data('id');
                var path = $a.attr('data-path') || $a.text().trim();

                // Ignora arquivos técnicos de marcação de pasta
                if (path.indexOf('.placeholder') !== -1) return;

                $orig.append($('<option>').val(id).text(path));
                $mod.append($('<option>').val(id).text(path));
                foundFiles++;
            });

            if (foundFiles < 2) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_DIFF_MIN_FILES'), "info");
            }

            $('#diff-modal').fadeIn(200);
        });

        // Gerar o Resultado Visual do Diff
        $('body').on('click.wsp_tools', '#generate-diff-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var data = {
                original_id: $('#diff-original').val(),
                modified_id: $('#diff-modified').val(),
                filename: $('#diff-original option:selected').text()
            };

            if (data.original_id === data.modified_id) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_DIFF_SAME_FILES'), "warning");
            }

            // Bloqueia botão e exibe "Comparando..."
            $btn.prop('disabled', true).text(WSP.lang('WSP_TOOLS_COMPARING'));

            $.post(window.wspVars.diffUrl, data, function (r) {
                if (r && r.success) {
                    $('#diff-modal').fadeOut(200);

                    // Modo visual: Desativa ID de arquivo ativo para evitar salvamento acidental do Diff sobre um arquivo real
                    WSP.activeFileId = null;
                    
                    if (WSP.editor) {
                        WSP.editor.setReadOnly(false);
                        WSP.editor.session.setMode("ace/mode/diff");
                        WSP.editor.setValue(r.bbcode || '', -1);
                        WSP.editor.focus();
                    }

                    // Ajusta botões da toolbar para o modo de visualização
                    $('#copy-bbcode').fadeIn(300);
                    $('#save-file').hide();
                    
                    // Header traduzido com o nome do arquivo (Ex: "Diff: funcoes.php")
                    var labelHtml = '<i class="fa fa-columns diff-label-icon"></i> ' + WSP.lang('WSP_LABEL_DIFF', {'%s': data.filename});
                    $('#current-file').html(labelHtml);
                } else {
                    WSP.ui.notify(r.error || WSP.lang('WSP_ERROR_CRITICAL'), "error");
                }
            }, 'json').always(function() {
                $btn.prop('disabled', false).text(WSP.lang('WSP_DIFF_GENERATE'));
            });
        });

        /**
         * 3. LIMPEZA DE CACHE DO phpBB
         */
        $('body').on('click.wsp_tools', '#refresh-phpbb-cache', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('i').addClass('fa-spin');
            
            $btn.addClass('btn-cache-loading');
            WSP.ui.notify(WSP.lang('WSP_PROCESSING'), "info");

            $.post(window.wspVars.refreshCacheUrl, function (r) {
                if (r && r.success) {
                    WSP.ui.notify(WSP.lang('WSP_CACHE_CLEANED'), "success");
                    // Recarregamento forçado após limpeza de cache para atualizar traduções e assets
                    setTimeout(function() { window.location.reload(true); }, 1200);
                } else {
                    WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), "error");
                    $icon.removeClass('fa-spin');
                    $btn.removeClass('btn-cache-loading');
                }
            }, 'json');
        });

        /**
         * 4. ACESSIBILIDADE E FECHAMENTO (Teclado)
         */
        $(document).off('keydown.wsp_tools').on('keydown.wsp_tools', function (e) {
            // Esc: Fecha todos os modais de ferramentas
            if (e.key === "Escape") {
                $('#search-replace-modal, #diff-modal').fadeOut(150);
            }
        });
    },

    /**
     * Gerencia a limpeza de backups locais (localStorage)
     * @param {number} fileId - ID do arquivo ou 0 para limpar o projeto inteiro
     */
    _clearBackups: function(fileId) {
        if (!fileId || fileId === 0 || fileId === '0') {
            // Limpeza em massa
            Object.keys(localStorage).forEach(function (key) {
                if (key.indexOf('wsp_backup_') === 0) {
                    localStorage.removeItem(key);
                }
            });
        } else {
            // Limpeza cirúrgica
            localStorage.removeItem('wsp_backup_' + fileId);
        }
    }
};