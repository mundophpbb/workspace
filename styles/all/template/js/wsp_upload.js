/**
 * Mundo phpBB Workspace - Upload & DragDrop
 * Versão 4.1: i18n Pura & Normalização Blindada (Sem omissões)
 * Gerencia o envio de arquivos e a varredura de pastas via API Webkit.
 */
WSP.upload = {
    _refreshTimer: null,
    _pendingUploads: 0,

    /**
     * Normaliza caminhos para o padrão Unix e previne Path Traversal
     */
    _normalizePath: function(p) {
        if (!p) return '';
        p = p.toString().trim().replace(/\\/g, '/');
        p = p.replace(/^\/+/, '');              // Remove / inicial
        p = p.replace(/(\.\.\/)+/g, '');        // Bloqueia traversal
        p = p.replace(/(^|\/)\.\//g, '$1');     // Remove ./
        p = p.replace(/\/{2,}/g, '/');          // Colapsa barras duplas
        return p;
    },

    /**
     * Filtra arquivos inúteis de sistema que poluem o projeto
     */
    _isIgnoredFile: function(name) {
        var ignored = ['thumbs.db', '.ds_store', 'desktop.ini', '__macosx'];
        var base = name.toLowerCase();
        return ignored.some(item => base.includes(item));
    },

    /**
     * Debounce: Atualiza a Sidebar apenas quando o lote de uploads termina
     */
    _scheduleRefresh: function() {
        var self = this;
        clearTimeout(self._refreshTimer);
        
        self._refreshTimer = setTimeout(function() {
            if (self._pendingUploads <= 0) {
                self._pendingUploads = 0; // Reset de segurança
                WSP.ui.seamlessRefresh();
                // Notificação de sucesso traduzida
                WSP.ui.notify(WSP.lang('WSP_UPLOAD_LIST_UPDATED'), "success");
            }
        }, 800);
    },

    /**
     * Envia o arquivo para o servidor via AJAX (Base64 Encode para evitar Firewall)
     */
    performUpload: function(file, projectId, customPath) {
        var self = this;
        if (!file || !projectId) return;

        var finalPath = self._normalizePath(customPath || file.name);
        if (self._isIgnoredFile(finalPath)) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            var formData = new FormData();
            formData.append('file_content', e.target.result); 
            formData.append('project_id', projectId);
            formData.append('full_path', finalPath);
            formData.append('is_encoded', '1'); 

            self._pendingUploads++;
            $.ajax({
                url: window.wspVars.uploadUrl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(r) {
                    self._pendingUploads--;
                    if (!r || !r.success) {
                        // Erro traduzido com placeholder %s para o nome do arquivo
                        var errorMsg = (r && r.error) ? r.error : WSP.lang('WSP_UPLOAD_FAILED', {'%s': finalPath});
                        WSP.ui.notify(errorMsg, "error");
                    }
                    self._scheduleRefresh();
                },
                error: function() {
                    self._pendingUploads--;
                    self._scheduleRefresh();
                }
            });
        };
        reader.readAsDataURL(file); 
    },

    /**
     * Varre estruturas de pastas arrastadas (API Webkit)
     */
    traverseFileTree: function(item, path, projectId) {
        var self = this;
        path = self._normalizePath(path || '');
        if (path && !path.endsWith('/')) path += '/';

        if (!item) return;

        if (item.isFile) {
            item.file(function(file) {
                self.performUpload(file, projectId, path + file.name);
            });
        } else if (item.isDirectory) {
            var dirReader = item.createReader();
            dirReader.readEntries(function(entries) {
                // TRATAMENTO DE PASTA VAZIA: Envia marcador .placeholder
                if (entries.length === 0) {
                    var blob = new Blob([""], { type: 'text/plain' });
                    var placeholder = new File([blob], ".placeholder");
                    self.performUpload(placeholder, projectId, path + item.name + "/.placeholder");
                } else {
                    for (var i = 0; i < entries.length; i++) {
                        self.traverseFileTree(entries[i], path + item.name + "/", projectId);
                    }
                }
            });
        }
    },

    /**
     * Vincula eventos de upload e drag-and-drop
     */
    bindEvents: function($) {
        var self = this;

        // 1. UPLOAD VIA BOTÃO (TOOLBAR)
        $('body').off('click', '.trigger-upload').on('click', '.trigger-upload', function(e) {
            e.preventDefault();
            if (!WSP.activeProjectId) {
                return WSP.ui.notify(WSP.lang('WSP_UPLOAD_NEED_PROJECT'), "warning");
            }

            if ($('#wsp-upload-input').length === 0) {
                $('body').append('<input type="file" id="wsp-upload-input" multiple>');
            }
            $('#wsp-upload-input').click();
        });

        $('body').off('change', '#wsp-upload-input').on('change', '#wsp-upload-input', function() {
            var files = this.files;
            if (files.length > 0) {
                // Mensagem com placeholder %d para contagem de arquivos
                var msg = WSP.lang('WSP_UPLOAD_SENDING_COUNT', {'%d': files.length});
                WSP.ui.notify(msg, "info");
                
                for (var i = 0; i < files.length; i++) {
                    self.performUpload(files[i], WSP.activeProjectId, null);
                }
            }
            $(this).val(''); // Limpa para permitir re-upload
        });

        // 2. DRAG & DROP (SIDEBAR)
        var $zone = $('#sidebar-dropzone');
        if ($zone.length) {
            $zone.on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('sidebar-drag-active');
            });

            $zone.on('dragleave', function(e) {
                e.preventDefault();
                $(this).removeClass('sidebar-drag-active');
            });

            $zone.on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('sidebar-drag-active');

                if (!WSP.activeProjectId) {
                    return WSP.ui.notify(WSP.lang('WSP_UPLOAD_DROP_PROJECT'), "warning");
                }

                var dt = e.originalEvent.dataTransfer;
                var items = dt.items;

                WSP.ui.notify(WSP.lang('WSP_UPLOAD_PROCESSING'), "info");

                // Se houver uma pasta ativa selecionada na árvore, os arquivos serão enviados para dentro dela
                var prefix = WSP.activeFolderPath || "";

                if (items && items.length) {
                    for (var i = 0; i < items.length; i++) {
                        // webkitGetAsEntry permite processar pastas recursivamente
                        var entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
                        if (entry) {
                            self.traverseFileTree(entry, prefix, WSP.activeProjectId);
                        } else {
                            var f = items[i].getAsFile();
                            if (f) {
                                var path = self._normalizePath(prefix) ? self._normalizePath(prefix) + '/' + f.name : f.name;
                                self.performUpload(f, WSP.activeProjectId, path);
                            }
                        }
                    }
                }
            });
        }
    }
};