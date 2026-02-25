/**
 * Mundo phpBB Workspace - File Operations
 * Versão 4.1: i18n Pura & Conformidade CDB (Sem omissões)
 *
 * Correções aplicadas:
 * - Confirmação de alterações não salvas ANTES de trocar arquivo (WSP_UNSAVED_CHANGES)
 * - Não seta WSP.activeFileId antes do load ter sucesso (evita estado quebrado)
 * - Restaura UI (highlight/breadcrumb) se o load falhar
 * - Dirty detector usando evento correto do Ace (session.on('change')) em vez de "input"
 * - Uso seguro do mapa de modos (WSP.modes / window.WSP.modes)
 * - Fail handler no save (conexão)
 * - Loading effect via classe (mantém compatível com seu CSS)
 * - Corrige label do botão SALVAR para WSP_SAVE_CHANGES (e evita aparecer chave literal)
 * - Corrige botões/labels do Changelog caso o template tenha renderizado a chave literal
 */

WSP.files = {
    autoSaveInterval: null,
    _dirtyBound: false,
    _saveCommandBound: false,
    _toolbarI18nApplied: false,

    /**
     * Helper: obtém a instância do Ace Editor (compatível com core/legado)
     * - Core: WSP.editor é a instância do Ace
     * - Legado: WSP.editor.ace é a instância do Ace
     */
    _getEditor: function () {
        if (WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) {
            return WSP.editor;
        }
        if (WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) {
            return WSP.editor.ace;
        }
        return null;
    },

    /**
     * Aplica traduções na toolbar quando o template renderiza texto literal (ex: "WSP_SAVE_CHANGES")
     * Isso é um "airbag" — o ideal é o Twig usar {{ lang('WSP_...') }}.
     */
    applyToolbarTranslations: function () {
        if (this._toolbarI18nApplied) return;
        this._toolbarI18nApplied = true;

        // Botão salvar
        var $save = jQuery('#save-file');
        if ($save.length) {
            // Se o botão estiver com texto literal "WSP_SAVE_CHANGES" ou vazio, força tradução
            var cur = ($save.text() || '').trim();
            if (!cur || cur === 'WSP_SAVE_CHANGES' || cur === 'WSP_SAVE') {
                $save.html('<i class="fa fa-save"></i> ' + WSP.lang('WSP_SAVE_CHANGES'));
            }
        }

        // Botão gerar changelog (caso exista)
        jQuery('.generate-project-changelog').each(function () {
            var $el = jQuery(this);
            var t = ($el.text() || '').trim();
            if (!t || t === 'WSP_GENERATE_CHANGELOG') {
                $el.text(WSP.lang('WSP_GENERATE_CHANGELOG'));
            }
        });

        // Botão limpar changelog (caso exista)
        jQuery('.clear-project-changelog').each(function () {
            var $el = jQuery(this);
            var t = ($el.text() || '').trim();
            if (!t || t === 'WSP_CLEAR_CHANGELOG') {
                $el.text(WSP.lang('WSP_CLEAR_CHANGELOG'));
            }
        });

        // Mensagens que às vezes aparecem em spans/labels no painel
        jQuery('[data-wsp-i18n]').each(function () {
            var $el = jQuery(this);
            var key = ($el.attr('data-wsp-i18n') || '').trim();
            if (key) {
                $el.text(WSP.lang(key));
            }
        });
    },

    /**
     * Vincula todos os eventos de manipulação de arquivos
     */
    bindEvents: function ($) {
        var self = this;

        // Airbag de tradução para toolbar (evita aparecer "WSP_SAVE_CHANGES" literal)
        self.applyToolbarTranslations();

        // Helper para verificar se o editor está pronto
        var isEditorReady = function () {
            return !!self._getEditor();
        };

        // Helper: aplica/remove efeito de carregamento (compatível com seu CSS)
        var setLoadingEffect = function (on) {
            if (on) $('#current-file').addClass('loading-effect');
            else $('#current-file').removeClass('loading-effect');
        };

        // Helper: detecta alterações não salvas com comparação real
        var hasUnsavedChanges = function () {
            var ed = self._getEditor();
            if (!ed) return false;
            if (!WSP.activeFileId) return false;

            var current = ed.getValue();
            var original = (typeof WSP.originalContent === 'string') ? WSP.originalContent : '';
            return current !== original;
        };

        // Helper: restaura highlight/breadcrumb para o arquivo anterior (quando load falha)
        var restorePreviousSelection = function (prevActiveId) {
            if (!prevActiveId) {
                // Sem anterior: limpa UI
                $('.file-item').removeClass('active-file');
                self.renderBreadcrumbs('');
                return;
            }

            var $prevLink = $('.load-file[data-id="' + prevActiveId + '"]');
            $('.file-item').removeClass('active-file');
            if ($prevLink.length) {
                $prevLink.closest('.file-item').addClass('active-file');
                var prevPath = ($prevLink.attr('data-path') || $prevLink.text() || '').trim();
                self.renderBreadcrumbs(prevPath);
            } else {
                self.renderBreadcrumbs('');
            }
        };

        // 1) CARREGAR ARQUIVO (Click na Sidebar)
        $('body').off('click', '.load-file').on('click', '.load-file', function (e) {
            e.preventDefault();

            if (!isEditorReady()) {
                // Notificação via motor de tradução
                WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
                return;
            }

            var $link = $(this);
            var fileId = $link.data('id');
            if (!fileId) return;

            // Proteção: Não deixa trocar se tiver alteração não salva
            if (hasUnsavedChanges() && !confirm(WSP.lang('WSP_UNSAVED_CHANGES'))) {
                return;
            }

            // Guarda estado anterior para rollback se falhar
            var prevActiveId = WSP.activeFileId || null;

            // Breadcrumbs dinâmicos (pode renderizar já, mesmo antes do load)
            var fullPath = ($link.attr('data-path') || $link.text() || '').trim();
            self.renderBreadcrumbs(fullPath);

            // Highlight visual na sidebar (visual imediato)
            $('.file-item').removeClass('active-file');
            $link.closest('.file-item').addClass('active-file');

            // Feedback de progresso via classe CSS
            setLoadingEffect(true);

            $.post(
                window.wspVars.loadUrl,
                { file_id: fileId, _nocache: Date.now() },
                function (r) {
                    setLoadingEffect(false);

                    if (!r || !r.success) {
                        // Erro traduzido vindo do servidor ou fallback do motor
                        var errorMsg = (r && r.error) ? r.error : WSP.lang('WSP_ERROR_OPEN_FILE');
                        WSP.ui.notify(errorMsg, 'error');

                        // Restaura seleção anterior para não deixar UI “presa” no arquivo que falhou
                        restorePreviousSelection(prevActiveId);
                        return;
                    }

                    // Agora sim define o arquivo ativo e persiste no localStorage
                    WSP.activeFileId = fileId;
                    localStorage.setItem('wsp_active_file_id', fileId);

                    var ed = self._getEditor();
                    if (!ed) {
                        WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
                        restorePreviousSelection(prevActiveId);
                        return;
                    }

                    // Prepara o editor
                    ed.setReadOnly(false);

                    // --- LÓGICA DE COLORIZAÇÃO ---
                    var fileName = (r.name || '').toLowerCase();
                    if (fileName === 'changelog.txt') {
                        ed.session.setMode("ace/mode/diff");
                        $('#copy-bbcode').fadeIn(200);
                    } else if (fileName.endsWith('.txt')) {
                        ed.session.setMode("ace/mode/text");
                        $('#copy-bbcode').hide();
                    } else {
                        // Usa o mapa de modos definido no core (com fallback seguro)
                        var modesMap = (WSP.modes && typeof WSP.modes === 'object')
                            ? WSP.modes
                            : ((window.WSP && window.WSP.modes) ? window.WSP.modes : {});

                        ed.session.setMode(modesMap[r.type] || 'ace/mode/text');
                        $('#copy-bbcode').hide();
                    }

                    // Gerencia conteúdo
                    var finalContent = (typeof r.content === 'string') ? r.content : '';

                    ed.setValue(finalContent, -1);
                    WSP.originalContent = finalContent;

                    ed.resize();
                    ed.focus();

                    // Limpa marcador de dirty no link ativo (caso viesse com "* ")
                    var $activeLink = $('.load-file[data-id="' + WSP.activeFileId + '"]');
                    if ($activeLink.length) {
                        var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
                        $activeLink.text(cleanName).removeClass('is-dirty');
                    }

                    // Mostra botão salvar e garante label traduzida
                    $('#save-file').fadeIn(200)
                        .html('<i class="fa fa-save"></i> ' + WSP.lang('WSP_SAVE_CHANGES'));

                    if (typeof WSP.updateUIState === 'function') {
                        WSP.updateUIState();
                    }

                    // Inicia rotinas auxiliares (bound flags impedem duplicar)
                    self.initAutoSave();
                    self.bindDirtyDetector();
                    self.bindSaveShortcut();
                },
                'json'
            ).fail(function () {
                setLoadingEffect(false);
                // Erro crítico de conexão traduzido
                WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), "error");

                // rollback UI para arquivo anterior
                restorePreviousSelection(prevActiveId);
            });
        });

        // 2) SALVAR ARQUIVO (Botão ou Atalho)
        $('body').off('click', '#save-file').on('click', '#save-file', function () {
            if (!isEditorReady() || !WSP.activeFileId) return;

            var ed = self._getEditor();
            if (!ed) return;

            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            // Tradução dinâmica de "Salvando..." via motor
            $btn.prop('disabled', true)
                .html('<i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_SAVING'));

            var contentToSave = ed.getValue();

            $.post(
                window.wspVars.saveUrl,
                { file_id: WSP.activeFileId, content: contentToSave },
                function (r) {
                    if (r && r.success) {
                        WSP.originalContent = contentToSave;
                        localStorage.removeItem('wsp_backup_' + WSP.activeFileId);

                        // Remove a marca de "sujo (*)" usando classes
                        var $activeLink = $('.load-file[data-id="' + WSP.activeFileId + '"]');
                        if ($activeLink.length) {
                            var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
                            $activeLink.text(cleanName).removeClass('is-dirty');
                        }

                        // Tradução de "Salvo!" via motor
                        $btn.html('<i class="fa fa-check"></i> ' + WSP.lang('WSP_SAVED_SHORT'))
                            .addClass('btn-success-temporary');

                        setTimeout(function () {
                            $btn.prop('disabled', false)
                                .html('<i class="fa fa-save"></i> ' + WSP.lang('WSP_SAVE_CHANGES'))
                                .removeClass('btn-success-temporary');
                        }, 1000);
                    } else {
                        // Erro ao salvar traduzido
                        WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_SAVE'), 'error');
                        $btn.prop('disabled', false)
                            .html('<i class="fa fa-save"></i> ' + WSP.lang('WSP_SAVE_CHANGES'));
                    }
                },
                'json'
            ).fail(function () {
                WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), 'error');
                $btn.prop('disabled', false)
                    .html('<i class="fa fa-save"></i> ' + WSP.lang('WSP_SAVE_CHANGES'));
            });
        });
    },

    /**
     * Monitora mudanças no editor para mostrar o asterisco (*) na sidebar
     */
    bindDirtyDetector: function () {
        if (this._dirtyBound) return;
        this._dirtyBound = true;

        var self = this;
        var ed = self._getEditor();
        if (!ed) return;

        var handler = function () {
            if (!WSP.activeFileId) return;

            var current = ed.getValue();
            var $link = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
            if (!$link.length) return;

            var rawName = ($link.text() || '');
            var nameNoStar = rawName.replace(/^\* /, '');
            var isDirty = (current !== WSP.originalContent);

            if (isDirty && rawName.indexOf('* ') !== 0) {
                $link.text('* ' + nameNoStar).addClass('is-dirty');
            } else if (!isDirty && rawName.indexOf('* ') === 0) {
                $link.text(nameNoStar).removeClass('is-dirty');
            }
        };

        // Ace: o evento correto é change (preferir session.on)
        if (ed.session && typeof ed.session.on === 'function') {
            ed.session.on('change', handler);
        } else if (typeof ed.on === 'function') {
            ed.on('change', handler);
        }
    },

    /**
     * Atalhos de teclado (Ctrl+S / Cmd+S)
     */
    bindSaveShortcut: function () {
        if (this._saveCommandBound) return;
        this._saveCommandBound = true;

        var self = this;
        var ed = self._getEditor();
        if (!ed) return;

        if (ed.commands && typeof ed.commands.addCommand === 'function') {
            ed.commands.addCommand({
                name: 'save',
                bindKey: { win: 'Ctrl-S', mac: 'Command-S' },
                exec: function () { jQuery('#save-file').click(); }
            });
        }

        // Evento do botão de Copiar BBCode para o fórum
        jQuery('body').off('click', '#copy-bbcode').on('click', '#copy-bbcode', function () {
            var content = ed.getValue();
            var name = jQuery('.active-file .load-file').text().replace(/^\* /, '');
            var bbcode = "[diff=" + name + "]\n" + content + "\n[/diff]";

            var $temp = jQuery("<textarea>").val(bbcode).appendTo("body").select();
            document.execCommand("copy");
            $temp.remove();

            // Notificação de sucesso traduzida
            WSP.ui.notify(WSP.lang('WSP_BBCODE_COPIED'), 'info');
        });
    },

    /**
     * Renderiza o caminho do arquivo (Breadcrumbs)
     */
    renderBreadcrumbs: function (path) {
        var self = this;
        var $target = jQuery('#current-file');

        path = (path || '').trim();
        if (!path) {
            // Placeholder traduzido quando não há arquivo
            $target.text(WSP.lang('WSP_SELECT_FILE'));
            return;
        }

        var parts = path.split('/');
        var html = '<i class="fa fa-folder-open-o breadcrumb-folder-icon"></i> ';

        parts.forEach(function (part, index) {
            html += '<span class="breadcrumb-item">' + self._escape(part) + '</span>';
            if (index < parts.length - 1) {
                html += ' <i class="fa fa-angle-right breadcrumb-sep"></i> ';
            }
        });

        $target.html(html);
    },

    _escape: function (s) {
        return jQuery('<div/>').text(s).html();
    },

    /**
     * Auto-Save Local
     */
    initAutoSave: function () {
        if (this.autoSaveInterval) clearInterval(this.autoSaveInterval);

        var self = this;

        this.autoSaveInterval = setInterval(function () {
            var ed = self._getEditor();
            if (WSP.activeFileId && ed) {
                var current = ed.getValue();
                if (current !== WSP.originalContent) {
                    localStorage.setItem('wsp_backup_' + WSP.activeFileId, current);

                    // Log de depuração traduzido via motor
                    var logMsg = WSP.lang('WSP_LOG_BACKUP_UPDATED', { '%s': WSP.activeFileId });
                    console.log("WSP: " + logMsg);
                }
            }
        }, 15000);
    }
};