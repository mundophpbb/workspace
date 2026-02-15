/**
 * Mundo phpBB Workspace - JS Principal
 * Atualização: Filtro de Busca na Sidebar + Blindagem + Upload Recursivo
 */

var initMundoPhpBBWorkspace = function($) {
    'use strict';

    $(window).on('load', function() {
        
        $.ajaxSetup({ cache: false });

        if (typeof ace === 'undefined') {
            return;
        }

        ace.config.set("basePath", wspVars.basePath);

        var activeFileId = null;
        var originalContent = ""; 
        var uploadTargetProject = null; 
        
        var editor = ace.edit("editor");
        
        editor.setTheme("ace/theme/monokai");
        editor.setOptions({
            fontSize: "14px",
            fontFamily: "Consolas, 'Courier New', monospace",
            showPrintMargin: false,
            displayIndentGuides: true,
            highlightActiveLine: true,
            cursorStyle: "smooth",
            behavioursEnabled: true,
            wrap: true,
            tabSize: 4,
            useSoftTabs: true,
            scrollPastEnd: 0.5
        });
        
        editor.session.setMode("ace/mode/php");
        editor.setReadOnly(true);
        editor.session.setUseWorker(false);

        // Mensagem inicial vinda da tradução
        editor.setValue(wspVars.lang.welcome_msg, -1);

        // Detector de Alterações (Indicador Dirty *)
        editor.on("input", function() {
            if (activeFileId) {
                var currentContent = editor.getValue();
                var $activeLink = $('.load-file[data-id="' + activeFileId + '"]');
                var currentText = $activeLink.text();

                if (currentContent !== originalContent) {
                    if (!currentText.startsWith('* ')) {
                        $activeLink.text('* ' + currentText).css('font-style', 'italic');
                    }
                } else {
                    if (currentText.startsWith('* ')) {
                        $activeLink.text(currentText.substring(2)).css('font-style', 'normal');
                    }
                }
            }
        });

        // Atalho Ctrl+S para salvar
        editor.commands.addCommand({
            name: 'save',
            bindKey: {win: 'Ctrl-S',  mac: 'Command-S'},
            exec: function(editor) { $('#save-file').click(); },
            readOnly: false
        });

        var modes = {
            'php': 'ace/mode/php', 'js': 'ace/mode/javascript', 'ts': 'ace/mode/typescript',
            'css': 'ace/mode/css', 'scss': 'ace/mode/scss', 'html': 'ace/mode/html',
            'htm': 'ace/mode/html', 'py': 'ace/mode/python', 'sql': 'ace/mode/sql',
            'rb': 'ace/mode/ruby', 'java': 'ace/mode/java', 'cs': 'ace/mode/csharp',
            'cpp': 'ace/mode/c_cpp', 'c': 'ace/mode/c_cpp', 'go': 'ace/mode/golang',
            'rs': 'ace/mode/rust', 'lua': 'ace/mode/lua', 'yml': 'ace/mode/yaml',
            'yaml': 'ace/mode/yaml', 'json': 'ace/mode/json', 'xml': 'ace/mode/xml',
            'md': 'ace/mode/markdown', 'txt': 'ace/mode/text', 'ini': 'ace/mode/ini',
            'sh': 'ace/mode/sh', 'bat': 'ace/mode/batchfile', 'diff': 'ace/mode/diff',
            'patch': 'ace/mode/diff'
        };

        function seamlessRefresh(fileIdToOpen = null) {
            var currentUrl = window.location.href.split('?')[0];
            $.get(currentUrl, { _nocache: new Date().getTime() }, function(data) {
                var newProjectList = $(data).find('#project-list').html();
                $('#project-list').html(newProjectList);
                renderTree();
                
                if (fileIdToOpen) {
                    setTimeout(function() {
                        var $targetFile = $('.load-file[data-id="' + fileIdToOpen + '"]');
                        if ($targetFile.length) {
                            $targetFile.parents('.folder-content').show().prev('.folder-title').find('i').removeClass('fa-folder').addClass('fa-folder-open');
                            $targetFile.click();
                        }
                    }, 200);
                }
            });
        }

        // --- MODAIS CUSTOMIZADOS ---
        if ($('#wsp-custom-modal').length === 0) {
            $('body').append(`
                <div id="wsp-custom-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); align-items:center; justify-content:center;">
                    <div style="background:#252526; padding:20px; width:400px; border:1px solid #444; border-radius:4px; color:#d4d4d4; font-family:sans-serif;">
                        <h4 id="wsp-modal-title" style="margin-top:0; color:#fff; font-size:14px;"></h4>
                        <input type="text" id="wsp-modal-input" style="width:100%; padding:8px; margin:15px 0; background:#333; border:1px solid #555; color:#fff; display:none; outline:none;">
                        <div style="text-align:right; margin-top:15px; display:flex; justify-content:flex-end; gap:10px;">
                            <button id="wsp-modal-cancel" class="button" style="background:#444; color:#fff; border:none; padding:6px 12px; cursor:pointer;">${wspVars.lang.cancel}</button>
                            <button id="wsp-modal-ok" class="button" style="background:#569cd6; color:#fff; border:none; padding:6px 12px; cursor:pointer;">${wspVars.lang.ok}</button>
                        </div>
                    </div>
                </div>
            `);
        }

        function wspPrompt(title, defaultValue, callback) {
            $('#wsp-modal-title').text(title);
            $('#wsp-modal-input').val(defaultValue || '').show().focus();
            $('#wsp-custom-modal').css('display', 'flex');
            $('#wsp-modal-ok').off('click').on('click', function() {
                var val = $('#wsp-modal-input').val();
                $('#wsp-custom-modal').hide();
                if (val && val.trim() !== '') callback(val.trim());
            });
            $('#wsp-modal-cancel').off('click').on('click', function() { $('#wsp-custom-modal').hide(); });
            $('#wsp-modal-input').off('keypress').on('keypress', function(e) { if (e.which == 13) $('#wsp-modal-ok').click(); });
        }

        function wspConfirm(title, callback) {
            $('#wsp-modal-title').text(title);
            $('#wsp-modal-input').hide();
            $('#wsp-custom-modal').css('display', 'flex');
            $('#wsp-modal-ok').off('click').on('click', function() { $('#wsp-custom-modal').hide(); callback(); });
            $('#wsp-modal-cancel').off('click').on('click', function() { $('#wsp-custom-modal').hide(); });
        }

        // --- RENDERIZAÇÃO DA ÁRVORE (Pastas e Subpastas) ---
        var renderTree = function() {
            $('.project-group').each(function() {
                var $project = $(this), $fileList = $project.find('.file-list'), files = [];
                $fileList.find('.file-item').each(function() {
                    var $el = $(this);
                    files.push({ id: $el.find('.load-file').data('id'), name: $el.find('.load-file').text().trim(), html: $el[0].outerHTML });
                });
                if (!files.some(f => f.name.includes('/'))) return;
                $fileList.empty();
                var structure = {};
                files.forEach(file => {
                    var parts = file.name.split('/'), current = structure;
                    for (var i = 0; i < parts.length - 1; i++) {
                        if (!current[parts[i]]) current[parts[i]] = { _isDir: true, _children: {} };
                        current = current[parts[i]]._children;
                    }
                    current[parts[parts.length - 1]] = file;
                });
                var buildHtml = function(obj) {
                    var html = '', keys = Object.keys(obj).sort((a, b) => (obj[b]._isDir||0) - (obj[a]._isDir||0) || a.localeCompare(b));
                    keys.forEach(key => {
                        if (key === '_isDir' || key === '_children') return;
                        if (obj[key]._isDir) {
                            html += '<li class="folder-item"><div class="folder-title"><i class="icon fa-folder fa-fw"></i> ' + key + '</div>' +
                                    '<ul class="folder-content" style="display:block;">' + buildHtml(obj[key]._children) + '</ul></li>';
                        } else html += obj[key].html;
                    });
                    return html;
                };
                $fileList.append(buildHtml(structure));
            });
        };
        renderTree();

        // --- NOVO: FILTRO DE BUSCA RÁPIDA NA SIDEBAR ---
        $('body').on('input', '#wsp-sidebar-filter', function() {
            var searchTerm = $(this).val().toLowerCase();
            
            if (searchTerm === "") {
                // Se vazio, mostra tudo e reseta as pastas para o estado padrão
                $('.file-item, .folder-item, .project-group').show();
                return;
            }

            $('.project-group').each(function() {
                var $project = $(this);
                var hasMatch = false;

                // Percorre arquivos para filtrar
                $project.find('.file-item').each(function() {
                    var $file = $(this);
                    var fileName = $file.find('.load-file').text().toLowerCase();

                    if (fileName.indexOf(searchTerm) > -1) {
                        $file.show();
                        hasMatch = true;
                        
                        // Expande as pastas "pai" para o arquivo ficar visível
                        $file.parents('.folder-content').show();
                        $file.parents('.folder-item').find('> .folder-title i').removeClass('fa-folder').addClass('fa-folder-open');
                    } else {
                        $file.hide();
                    }
                });

                // Verifica se o título do projeto combina (para mostrar tudo se o projeto bater)
                if ($project.find('.project-title').text().toLowerCase().indexOf(searchTerm) > -1) {
                    hasMatch = true;
                    $project.find('.file-item, .folder-item').show();
                }

                if (hasMatch) $project.show(); else $project.hide();
            });
        });
        
        $('body').off('click', '.folder-title').on('click', '.folder-title', function() {
            $(this).next('.folder-content').slideToggle(200);
            $(this).find('i').toggleClass('fa-folder fa-folder-open');
        });

        // --- AÇÕES DE PROJETO ---
        $('body').off('click', '#add-project').on('click', '#add-project', function() {
            wspPrompt(wspVars.lang.prompt_name, '', function(name) {
                $.post(wspVars.addUrl, { name: name }, () => seamlessRefresh()); 
            });
        });

        $('body').off('click', '.add-file-to-project').on('click', '.add-file-to-project', function() {
            var projectId = $(this).data('id'); 
            wspPrompt(wspVars.lang.prompt_file_name, '', function(name) {
                $.post(wspVars.addFileUrl, { project_id: projectId, name: name }, function(r) {
                    if (r.success) seamlessRefresh(r.file_id);
                    else alert(r.error);
                }, 'json');
            });
        });

        $('body').off('click', '.rename-file').on('click', '.rename-file', function() {
            var id = $(this).data('id');
            var oldName = $(this).data('name');
            wspPrompt(wspVars.lang.prompt_file_name, oldName, function(name) {
                if (name !== oldName) $.post(wspVars.renameUrl, { file_id: id, new_name: name }, r => r.success ? seamlessRefresh() : alert(r.error), 'json');
            });
        });

        $('body').off('click', '.delete-file').on('click', '.delete-file', function() {
            var id = $(this).data('id');
            wspConfirm(wspVars.lang.confirm_file_delete, function() {
                $.post(wspVars.deleteFileUrl, { file_id: id }, function(){
                    if(activeFileId === id) {
                        activeFileId = null;
                        editor.setValue(wspVars.lang.file_eliminated, -1);
                        $('#current-file').text(wspVars.lang.select_file).css('color', '');
                        $('#save-file').hide();
                    }
                    seamlessRefresh(); 
                });
            });
        });

        $('body').off('click', '.delete-project').on('click', '.delete-project', function() {
            var id = $(this).data('id');
            wspConfirm(wspVars.lang.confirm_delete, function() {
                $.post(wspVars.deleteUrl, { project_id: id }, () => seamlessRefresh()); 
            });
        });

        // --- CARREGAMENTO DE FICHEIRO ---
        $('body').off('click', '.load-file').on('click', '.load-file', function(e) {
            e.preventDefault();
            activeFileId = $(this).data('id');
            $('.file-item').removeClass('active-file');
            $(this).closest('.file-item').addClass('active-file');
            $('#current-file').text(wspVars.lang.loading).css('color', '#e2c08d');
            $.post(wspVars.loadUrl, { file_id: activeFileId, _nocache: new Date().getTime() }, function(r) {
                if (r.success) {
                    $('#current-file').text(r.name).css('color', '#569cd6');
                    editor.setReadOnly(false);
                    editor.session.setMode(modes[r.type] || 'ace/mode/text');
                    editor.setValue(r.content, -1);
                    originalContent = r.content; 
                    editor.resize(); editor.focus();
                    $('#save-file').fadeIn(300); $('#copy-bbcode').hide();
                }
            }, 'json');
        });

        // --- SALVAMENTO COM TRATAMENTO DE ERRO (BLINDAGEM) ---
        $('body').off('click', '#save-file').on('click', '#save-file', function() {
            var $btn = $(this);
            if (!activeFileId || $btn.prop('disabled')) return;
            
            $btn.prop('disabled', true).html('<i class="icon fa-spinner fa-spin fa-fw"></i> ' + wspVars.lang.saving);
            var contentToSave = editor.getValue();
            
            $.post(wspVars.saveUrl, { file_id: activeFileId, content: contentToSave }, function(r) {
                if (r.success) {
                    originalContent = contentToSave; 
                    var $activeLink = $('.load-file[data-id="' + activeFileId + '"]');
                    var currentText = $activeLink.text();
                    if (currentText.startsWith('* ')) { 
                        $activeLink.text(currentText.substring(2)).css('font-style', 'normal'); 
                    }
                    $btn.html('<i class="icon fa-check fa-fw"></i> ' + wspVars.lang.saved).css('background', '#28a745');
                    setTimeout(() => { 
                        $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes).css('background', ''); 
                    }, 1500);
                } else {
                    alert("Erro ao salvar: " + r.error);
                    $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes);
                }
            }, 'json').fail(function() {
                alert("Erro Crítico: O servidor não conseguiu processar este conteúdo. Verifique se o Banco de Dados suporta Emojis (utf8mb4).");
                $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes);
            });
        });

        // --- UPLOAD RECURSIVO ---
        if ($('#wsp-single-file-upload').length === 0) {
            $('body').append('<input type="file" id="wsp-single-file-upload" multiple style="display:none;">');
        }

        var performUpload = function(file, projectId, customPath) {
            if (!file || !projectId) return;
            var formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', projectId);
            var path = customPath || file.webkitRelativePath || file.name;
            formData.append('full_path', path);

            $('#current-file').text(wspVars.lang.uploading + path).css('color', '#e2c08d');
            $.ajax({
                url: wspVars.uploadUrl, type: 'POST', data: formData, contentType: false, processData: false,
                success: function(r) { if (r.success) seamlessRefresh(); else { console.error(r.error); $('#current-file').text(wspVars.lang.select_file).css('color', ''); } }
            });
        };

        function traverseFileTree(item, path, projectId) {
            path = path || "";
            if (item.isFile) { 
                item.file(function(file) { performUpload(file, projectId, path + file.name); }); 
            } 
            else if (item.isDirectory) {
                var dirReader = item.createReader();
                dirReader.readEntries(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        traverseFileTree(entries[i], path + item.name + "/", projectId);
                    }
                });
            }
        }

        $('body').off('click', '.trigger-upload').on('click', '.trigger-upload', function(e) {
            e.preventDefault(); uploadTargetProject = $(this).data('id'); $('#wsp-single-file-upload').click(); 
        });

        $('body').off('change', '#wsp-single-file-upload').on('change', '#wsp-single-file-upload', function() {
            var files = this.files;
            if (files.length > 0 && uploadTargetProject) { for (var i = 0; i < files.length; i++) performUpload(files[i], uploadTargetProject); }
            $(this).val('');
        });

        $('body').off('dragover', '.project-title').on('dragover', '.project-title', function(e) { e.preventDefault(); $(this).addClass('drag-over'); });
        $('body').off('dragleave', '.project-title').on('dragleave', '.project-title', function() { $(this).removeClass('drag-over'); });
        $('body').off('drop', '.project-title').on('drop', '.project-title', function(e) {
            e.preventDefault(); $(this).removeClass('drag-over');
            var projectId = $(this).closest('.project-group').find('.open-search-replace').data('id');
            var items = e.originalEvent.dataTransfer.items;
            if (items) { 
                for (var i = 0; i < items.length; i++) { 
                    var item = items[i].webkitGetAsEntry(); 
                    if (item) traverseFileTree(item, "", projectId); 
                } 
            }
        });

        // --- BUSCA E SUBSTITUIÇÃO ---
        $('body').off('click', '.open-search-replace').on('click', '.open-search-replace', function() {
            var projectId = $(this).data('id');
            var projectName = $(this).data('name');
            $('#search-project-id').val(projectId);
            if (activeFileId) $('#search-project-name').text(wspVars.lang.replace_only_file + projectName).css('color', '#4CAF50');
            else $('#search-project-name').text(wspVars.lang.replace_in_project + projectName).css('color', '#888');
            $('#wsp-search-term').val(''); $('#wsp-replace-term').val('');
            $('#search-replace-modal').fadeIn(200);
        });

        $('body').off('click', '#exec-replace-btn').on('click', '#exec-replace-btn', function() {
            var $btn = $(this);
            var data = { project_id: $('#search-project-id').val(), file_id: activeFileId || 0, search: $('#wsp-search-term').val(), replace: $('#wsp-replace-term').val() };
            if (!data.search) { alert(wspVars.lang.search_empty_err); return; }
            var msgConfirm = activeFileId ? wspVars.lang.confirm_replace_file : wspVars.lang.confirm_replace_all;
            wspConfirm(msgConfirm, function() {
                $btn.prop('disabled', true).text(wspVars.lang.processing);
                $.post(wspVars.replaceUrl, data, function(r) {
                    if (r.success) {
                        alert(wspVars.lang.replace_success.replace('%d', r.updated));
                        if (activeFileId) $('.load-file[data-id="' + activeFileId + '"]').click();
                        else seamlessRefresh(); 
                    } else alert(wspVars.lang.error_prefix + r.error); 
                }, 'json').always(() => { $btn.prop('disabled', false).text(wspVars.lang.replace_all); $('#search-replace-modal').hide(); });
            });
        });

        // --- DIFF TOOL ---
        $('body').off('click', '#open-diff-tool').on('click', '#open-diff-tool', function() {
            var $orig = $('#diff-original').empty(), $mod = $('#diff-modified').empty();
            $('.load-file').each(function() {
                var id = $(this).data('id'), name = $(this).text().trim(), opt = $('<option>').val(id).text(name);
                $orig.append(opt.clone()); $mod.append(opt);
            });
            $('#diff-modal').fadeIn(200);
        });

        $('body').off('click', '#generate-diff-btn').on('click', '#generate-diff-btn', function() {
            var $btn = $(this); $btn.prop('disabled', true).text(wspVars.lang.diff_generating);
            $.post(wspVars.diffUrl, { original_id: $('#diff-original').val(), modified_id: $('#diff-modified').val(), filename: $('#diff-original option:selected').text() }, function(r) {
                if (r.success) {
                    $('#diff-modal').hide();
                    editor.setReadOnly(false); editor.session.setMode("ace/mode/diff"); editor.setValue(r.bbcode, -1);
                    editor.resize(); editor.focus();
                    $('#current-file').text('Diff: ' + r.filename).css('color', '#4CAF50');
                    $('#save-file').hide(); $('#copy-bbcode').fadeIn(300);
                } else alert(r.error);
            }, 'json').fail(() => alert(wspVars.lang.err_server_500))
            .always(() => $btn.prop('disabled', false).text(wspVars.lang.diff_generate));
        });

        // --- COPIAR BBCODE ---
        $('body').off('click', '#copy-bbcode').on('click', '#copy-bbcode', function() {
            var bbcodeText = editor.getValue();
            var $btn = $(this);
            var originalHtml = $btn.html();
            var showSuccess = function() {
                $btn.html('<i class="icon fa-check fa-fw"></i> ' + wspVars.lang.copied).css('background', '#28a745');
                setTimeout(() => $btn.html(originalHtml).css('background', ''), 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(bbcodeText).then(showSuccess).catch(err => alert(wspVars.lang.err_copy + err));
            } else {
                var $temp = $("<textarea>"); $("body").append($temp); $temp.val(bbcodeText).select(); document.execCommand("copy"); $temp.remove(); showSuccess();
            }
        });
    });
};

(function checkDependencies() {
    if (typeof jQuery !== 'undefined' && typeof wspVars !== 'undefined') initMundoPhpBBWorkspace(jQuery);
    else setTimeout(checkDependencies, 50);
})();