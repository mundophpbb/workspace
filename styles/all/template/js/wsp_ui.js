/**
 * Mundo phpBB Workspace - UI & Modals
 * Versão 4.1: i18n Pura & Conformidade CDB (Sem omissões)
 * Gerencia notificações, diálogos e a persistência de eventos via delegação.
 */
WSP.ui = {
    /**
     * Inicialização
     */
    init: function ($) {
        this.injectModal($);
        this.bindEvents($);
        this.initSplitter();
    },

    /**
     * Injeta a estrutura de Modais e Notificações (Singleton)
     */
    injectModal: function ($) {
        if ($('#wsp-custom-modal').length === 0) {
            $('body').append(`
                <div id="wsp-custom-modal">
                    <div id="wsp-modal-card">
                        <h4 id="wsp-modal-title"></h4>
                        <div id="wsp-modal-body-custom" style="display:none;"></div>
                        <input type="text" id="wsp-modal-input" style="display:none;">
                        <div class="wsp-modal-footer">
                            <button id="wsp-modal-cancel" class="button">${WSP.lang('WSP_UI_CANCEL')}</button>
                            <button id="wsp-modal-ok" class="button secondary">${WSP.lang('WSP_UI_CONFIRM')}</button>
                        </div>
                    </div>
                </div>
            `);
        }

        if ($('#wsp-notify-container').length === 0) {
            $('body').append('<div id="wsp-notify-container"></div>');
        }

        // Fechar modal ao clicar no fundo
        $('body').off('mousedown.wsp_modal').on('mousedown.wsp_modal', '#wsp-custom-modal', function (e) {
            if ($(e.target).attr('id') === 'wsp-custom-modal') $('#wsp-custom-modal').hide();
        });
    },

    /**
     * Notificações Toast
     */
    notify: function (message, type) {
        type = type || 'success';
        var id = 'notif-' + Date.now();
        var icons = { success: 'check-circle', error: 'exclamation-triangle', info: 'info-circle', warning: 'exclamation-circle' };

        var html = `
            <div id="${id}" class="wsp-notification is-${type}">
                <i class="fa fa-${icons[type]}"></i>
                <span>${message}</span>
            </div>
        `;

        $('#wsp-notify-container').append(html);
        setTimeout(function () { 
            $('#' + id).fadeOut(400, function () { $(this).remove(); }); 
        }, 4000);
    },

    /**
     * Diálogo de Prompt / Lista
     */
    prompt: function (title, defaultValue, callback) {
        callback = callback || function () { };
        $('#wsp-modal-title').text(title);
        $('#wsp-modal-body-custom').hide().empty();
        $('#wsp-modal-ok, #wsp-modal-cancel').off('click');

        if (defaultValue === 'LIST_MODE') {
            $('#wsp-modal-input').hide();
            $('#wsp-modal-ok').hide();
            $('#wsp-modal-body-custom').show();
        } else {
            $('#wsp-modal-input').val(defaultValue || '').show();
            $('#wsp-modal-ok').show();
        }

        $('#wsp-custom-modal').css('display', 'flex');

        if (defaultValue !== 'LIST_MODE') {
            $('#wsp-modal-input').focus().select();
            $('#wsp-modal-ok').on('click', function () {
                var val = $('#wsp-modal-input').val();
                $('#wsp-custom-modal').hide();
                callback(val);
            });
        }

        $('#wsp-modal-cancel').on('click', function () { $('#wsp-custom-modal').hide(); });
        $('#wsp-modal-input').on('keypress', function (e) { if (e.which === 13) $('#wsp-modal-ok').click(); });
    },

    /**
     * Confirmação de ação crítica
     */
    confirm: function (title, callback) {
        this.prompt(title, 'LIST_MODE');
        // Mensagem de aviso irrevessível traduzida
        $('#wsp-modal-body-custom').html(`<p style="margin:10px 0; color:#aaa;">${WSP.lang('WSP_UI_ACTION_WARNING')}</p>`).show();
        $('#wsp-modal-ok').show().off('click').on('click', function() {
            $('#wsp-custom-modal').hide();
            callback();
        });
    },

    /**
     * Atualização da Sidebar com Reconstrução da Árvore
     */
    seamlessRefresh: function (fileIdToOpen) {
        var self = this;
        var currentUrl = window.location.href;

        // 1. Salva o estado atual das pastas abertas
        var openFolders = [];
        jQuery('.folder-item').each(function() {
            if (jQuery(this).find('> .folder-content').is(':visible')) {
                var path = jQuery(this).find('> .folder-title').attr('data-path');
                if (path) openFolders.push(path);
            }
        });

        // 2. Busca o novo HTML
        jQuery.get(currentUrl, { _nocache: Date.now() }, function (data) {
            var $newSidebar = jQuery(data).find('#project-list');
            if ($newSidebar.length) {
                jQuery('#project-list').html($newSidebar.html());

                // 3. RECONSTRÓI A ÁRVORE
                if (window.WSP.tree && typeof window.WSP.tree.render === 'function') {
                    window.WSP.tree.render(jQuery);
                    window.WSP.tree.bindEvents(jQuery);
                }

                // 4. Restaura pastas abertas
                openFolders.forEach(function(path) {
                    var $folder = jQuery(`.folder-title[data-path="${path}"]`);
                    $folder.next('.folder-content').show();
                    $folder.find('i.icon').removeClass('fa-folder').addClass('fa-folder-open');
                });

                // 5. Restaura arquivo ativo
                if (window.WSP.activeFileId) {
                    jQuery(`.load-file[data-id="${window.WSP.activeFileId}"]`).closest('.file-item').addClass('active-file');
                }

                // 6. Abre arquivo recém-criado (se solicitado)
                if (fileIdToOpen) {
                    setTimeout(function() {
                        var $target = jQuery(`.load-file[data-id="${fileIdToOpen}"]`);
                        if ($target.length) $target.click();
                    }, 200);
                }

                // 7. Re-inicializa o splitter
                self.initSplitter();
            }
        });
    },

    /**
     * EVENTOS DELEGADOS (UI Global)
     */
    bindEvents: function ($) {
        var self = this;

        // 1. GESTÃO DE PASTA ATIVA (RESET)
        $('body').off('click', '#wsp-active-folder-indicator').on('click', '#wsp-active-folder-indicator', function() {
            if (window.WSP.tree && window.WSP.tree.clearActiveFolder) {
                window.WSP.tree.clearActiveFolder();
                self.notify(WSP.lang('WSP_UI_ROOT_FOCUS'), "info");
            }
        });

        // 2. FILTRO DA SIDEBAR
        $('body').off('input', '#wsp-sidebar-filter').on('input', '#wsp-sidebar-filter', function () {
            var term = $(this).val().toLowerCase();

            if (term === '') {
                $('.file-item, .folder-item').show();
                return;
            }

            $('.file-item').each(function () {
                var fileName = $(this).find('.load-file').text().toLowerCase();
                $(this).toggle(fileName.indexOf(term) > -1);
            });

            $('.folder-item').each(function() {
                var hasMatch = $(this).find('.file-item:visible').length > 0;
                $(this).toggle(hasMatch);

                if (hasMatch) {
                    $(this).find('> .folder-content').show();
                    $(this).find('> .folder-title i.icon').removeClass('fa-folder').addClass('fa-folder-open');
                }
            });
        });

        // 3. FULLSCREEN TOGGLE
        $('body').off('click', '#toggle-fullscreen').on('click', '#toggle-fullscreen', function () {
            $('.workspace-container').toggleClass('fullscreen-mode');
            $(this).find('i').toggleClass('fa-expand fa-compress');
            if (window.WSP.editor) {
                setTimeout(function() { window.WSP.editor.resize(); }, 150);
            }
        });
    },

    /**
     * Atualiza o label do arquivo atual e o breadcrumb
     */
    updateBreadcrumbs: function (path) {
        var $target = $('#current-file');

        if (!path) {
            $target.text(WSP.lang('WSP_UI_SELECT_FILE'));
            return;
        }

        var parts = String(path).split('/');
        var html = '<i class="fa fa-folder-open-o" style="color:var(--wsp-accent); margin-right:5px;"></i> ';
        parts.forEach(function (part, index) {
            html += `<span class="breadcrumb-item">${$('<div/>').text(part).html()}</span>`;
            if (index < parts.length - 1) {
                html += ' <i class="fa fa-angle-right breadcrumb-separator"></i> ';
            }
        });
        $target.html(html);
    },

    /**
     * BARRA DIVISÓRIA REDIMENSIONÁVEL
     */
    initSplitter: function () {
        var $sidebar  = $('#sidebar-dropzone');
        var $splitter = $('#wsp-splitter');

        if (!$splitter.length || !$sidebar.length) return;

        var isDragging = false;
        var startX, startWidth;

        var savedWidth = localStorage.getItem('wsp_sidebar_width');
        if (savedWidth) $sidebar.css('width', savedWidth + 'px');

        $splitter.off('mousedown.wsp_splitter').on('mousedown.wsp_splitter', function (e) {
            isDragging = true;
            startX = e.pageX;
            startWidth = $sidebar.outerWidth();
            $('body').addClass('is-resizing');
            $splitter.addClass('dragging');
        });

        $(document).off('mousemove.wsp_splitter').on('mousemove.wsp_splitter', function (e) {
            if (!isDragging) return;
            var delta = e.pageX - startX;
            var newWidth = Math.max(180, Math.min(620, startWidth + delta));

            $sidebar.css('width', newWidth + 'px');
            localStorage.setItem('wsp_sidebar_width', newWidth);

            if (window.WSP.editor) window.WSP.editor.resize();
        });

        $(document).off('mouseup.wsp_splitter').on('mouseup.wsp_splitter', function () {
            if (isDragging) {
                isDragging = false;
                $('body').removeClass('is-resizing');
                $splitter.removeClass('dragging');
            }
        });

        // Atalho de teclado (Alt + ← / →)
        $(document).off('keydown.wsp_splitter').on('keydown.wsp_splitter', function (e) {
            if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                e.preventDefault();
                var step = e.key === 'ArrowLeft' ? -30 : 30;
                var current = $sidebar.outerWidth();
                var newW = Math.max(180, Math.min(620, current + step));

                $sidebar.css('width', newW + 'px');
                localStorage.setItem('wsp_sidebar_width', newW);
                if (window.WSP.editor) window.WSP.editor.resize();
            }
        });

        // Log de inicialização do splitter traduzido
        console.log("WSP: " + WSP.lang('WSP_UI_SPLITTER_READY'));
    }
};