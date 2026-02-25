/**
 * Mundo phpBB Workspace - File Tree Logic
 * Versão 4.1: i18n Pura & Reconstrução Atómica (Sem omissões)
 * Transforma caminhos planos em estrutura de pastas visuais e gerencia o foco.
 */
WSP.tree = {
    activeFolderPath: '',

    /**
     * Normaliza caminhos para o padrão Unix
     */
    _normalizePath: function (p) {
        if (!p) return '';
        return p.trim().replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+$/, '');
    },

    /**
     * Garante a barra no final para caminhos de diretório
     */
    _ensureTrailingSlash: function (p) {
        p = this._normalizePath(p);
        return (p && !p.endsWith('/')) ? p + '/' : p;
    },

    /**
     * Escape básico de HTML para segurança (XSS Prevention)
     */
    _escapeHtml: function (s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    /**
     * Verifica se o arquivo é apenas um marcador técnico de pasta
     */
    _isPlaceholder: function (name) {
        var s = (name || '').toLowerCase().trim();
        return (s === '.placeholder' || s.indexOf('/.placeholder') !== -1);
    },

    /**
     * Sincroniza o indicador de pasta ativa (breadcrumb ou label) via motor i18n
     */
    _syncActiveFolderIndicator: function () {
        var path = (this.activeFolderPath || '').trim();
        // Usa o motor de tradução para a label "Raiz"
        var display = path ? path : WSP.lang('WSP_TREE_ROOT');

        if (jQuery('#wsp-active-folder-text').length) {
            jQuery('#wsp-active-folder-text').text(display);
        }

        var $container = jQuery('#wsp-main-container');
        if ($container.length) {
            $container.attr('data-active-folder', path);
        }
    },

    /**
     * Limpa o foco da pasta selecionada voltando para a raiz
     */
    clearActiveFolder: function () {
        this.activeFolderPath = '';
        WSP.activeFolderPath = '';
        jQuery('.folder-title').removeClass('active-folder');
        this._syncActiveFolderIndicator();
    },

    /**
     * Define o foco atual para uma pasta específica
     */
    _selectFolder: function ($folderTitle) {
        if (!$folderTitle || !$folderTitle.length) return;
        var path = this._ensureTrailingSlash($folderTitle.attr('data-path') || '');
        this.activeFolderPath = path;
        WSP.activeFolderPath = path;
        jQuery('.folder-title').removeClass('active-folder');
        $folderTitle.addClass('active-folder');
        this._syncActiveFolderIndicator();
    },

    /**
     * Renderização da Árvore Hierárquica
     * Reconstrói a sidebar transformando caminhos planos em pastas visuais.
     */
    render: function ($) {
        var self = this;

        $('.project-group').each(function () {
            var $project = $(this),
                $fileList = $project.find('.file-list'),
                files = [];

            // 1. Coleta dados apenas dos links reais (Anti-Ghosting)
            $fileList.find('.load-file').each(function () {
                var $link = $(this);
                var fileId = $link.data('id');
                if (fileId) {
                    files.push({
                        id: fileId,
                        name: ($link.attr('data-path') || $link.text()).trim(),
                        type: $link.data('type') || 'php'
                    });
                }
            });

            // 2. Limpeza total do container para reconstrução limpa
            $fileList.empty();
            var structure = {};

            // 3. Reconstrói a estrutura lógica do objeto aninhado
            files.forEach(function (file) {
                var name = self._normalizePath(file.name);
                var parts = name.split('/');
                var current = structure;

                for (var i = 0; i < parts.length - 1; i++) {
                    var part = parts[i];
                    if (!part) continue;
                    if (!current[part]) {
                        current[part] = { _isDir: true, _children: {} };
                    }
                    current = current[part]._children;
                }

                var leaf = parts[parts.length - 1];
                if (leaf) {
                    current[leaf] = file;
                }
            });

            // 4. Função recursiva para gerar HTML visual final
            var buildHtml = function (obj, currentPath) {
                currentPath = currentPath || '';
                var html = '';
                var keys = Object.keys(obj).sort(function (a, b) {
                    var aIsDir = obj[a]._isDir ? 1 : 0;
                    var bIsDir = obj[b]._isDir ? 1 : 0;
                    return (bIsDir - aIsDir) || a.localeCompare(b);
                });

                keys.forEach(function (key) {
                    if (key === '_isDir' || key === '_children') return;

                    // Renderiza Pasta
                    if (obj[key] && obj[key]._isDir) {
                        var fullFolderPath = self._ensureTrailingSlash(currentPath + key);

                        html += `
                            <li class="folder-item">
                                <div class="folder-title" data-path="${self._escapeHtml(fullFolderPath)}">
                                    <i class="icon fa fa-folder fa-fw"></i> ${self._escapeHtml(key)}
                                    <span class="folder-context-actions">
                                        <i class="fa fa-plus-square ctx-add-file" title="${WSP.lang('WSP_TREE_NEW_FILE')}"></i>
                                        <i class="fa fa-plus-circle ctx-add-folder" title="${WSP.lang('WSP_TREE_NEW_FOLDER')}"></i>
                                        <i class="fa fa-i-cursor ctx-rename-folder" title="${WSP.lang('WSP_TREE_RENAME')}"></i>
                                        <i class="fa fa-trash ctx-delete-folder" title="${WSP.lang('WSP_TREE_DELETE')}"></i>
                                    </span>
                                </div>
                                <!-- FIX: nasce FECHADO sempre (evita CSS/JS brigarem) -->
                                <ul class="folder-content" style="display:none;">${buildHtml(obj[key]._children, fullFolderPath)}</ul>
                            </li>`;
                        return;
                    }

                    // Oculta Marcadores técnicos
                    if (self._isPlaceholder(key)) return;

                    var file = obj[key];
                    var fullFilePath = self._normalizePath(file.name);

                    // Renderiza Arquivo
                    html += `
                        <li class="file-item">
                            <i class="icon fa fa-file-text-o fa-fw"></i> 
                            <a href="javascript:void(0);" class="load-file" 
                               data-id="${file.id}" 
                               data-path="${self._escapeHtml(fullFilePath)}"
                               data-type="${self._escapeHtml(file.type)}">${self._escapeHtml(key)}</a>
                            <span class="file-context-actions">
                                <i class="fa fa-arrows ctx-move-file" title="${WSP.lang('WSP_TREE_MOVE')}"></i>
                                <i class="fa fa-pencil ctx-rename-file" data-id="${file.id}" data-name="${self._escapeHtml(key)}" title="${WSP.lang('WSP_TREE_RENAME')}"></i>
                                <i class="fa fa-trash-o ctx-delete-file" data-id="${file.id}" title="${WSP.lang('WSP_TREE_DELETE')}"></i>
                            </span>
                        </li>`;
                });

                return html;
            };

            $fileList.append(buildHtml(structure, ''));

            // Segurança extra: garante que tudo começa fechado (caso algum CSS force display)
            $fileList.find('.folder-item').removeClass('is-open');
            $fileList.find('.folder-title i.icon').removeClass('fa-folder-open').addClass('fa-folder');
            $fileList.find('.folder-content').hide();
        });

        this._syncActiveFolderIndicator();
    },

    /**
     * Vincula eventos de clique e expansão
     */
    bindEvents: function ($) {
        var self = this;
        $('body').off('click.wsp_tree');

        // Toggle de abertura/fecho de Pastas (FIX: determinístico)
        $('body').on('click.wsp_tree', '.folder-title', function (e) {
            // Proteção: Se clicar nos ícones de ação, não dispara o toggle da pasta
            if ($(e.target).closest('.folder-context-actions').length) return;

            var $title = $(this);
            var $item = $title.closest('.folder-item');
            var $content = $title.next('.folder-content');
            var $icon = $title.find('i.icon');

            self._selectFolder($title);

            // Estado atual ANTES de mexer em classe/animação
            var isOpen = $item.hasClass('is-open');

            // Evita fila de animações
            $content.stop(true, true);

            if (isOpen) {
                $item.removeClass('is-open');
                $content.slideUp(150);
                $icon.removeClass('fa-folder-open').addClass('fa-folder');
            } else {
                $item.addClass('is-open');
                $content.slideDown(150);
                $icon.removeClass('fa-folder').addClass('fa-folder-open');
            }
        });

        // Clique em ações de contexto da pasta (seleciona a pasta sem fechar)
        $('body').on('click.wsp_tree', '.folder-context-actions i', function (e) {
            e.stopPropagation();
            var $title = $(this).closest('.folder-title');
            self._selectFolder($title);
        });

        // Clique no indicador de pasta ativa para voltar à raiz
        $('body').on('click.wsp_tree', '#wsp-active-folder-indicator', function (e) {
            e.preventDefault();
            self.clearActiveFolder();
        });
    }
};