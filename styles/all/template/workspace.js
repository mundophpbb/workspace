/**
 * Mundo phpBB Workspace - JS Principal (Versão Integral Final)
 * Atualização: Fullscreen, Changelog, Exclusão Recursiva e Árvore Otimizada
 */

var initMundoPhpBBWorkspace = function($) {
    'use strict';

    $(window).on('load', function() {
        
        $.ajaxSetup({ cache: false });

        if (typeof ace === 'undefined') {
            return;
        }

        ace.config.set("basePath", wspVars.basePath);

        // --- VARIÁVEIS DE ESTADO ---
        var activeFileId = null;
        var originalContent = ""; 
        var uploadTargetProject = null; 
        
        // --- INICIALIZAÇÃO DO EDITOR ACE ---
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

        // --- LÓGICA DE INTERFACE ---

        // Atualização da Sidebar sem F5
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
                            $targetFile.parents('.folder-content').show().prev('.folder-title').find('i.fa-folder').removeClass('fa-folder').addClass('fa-folder-open');
                            $targetFile.click();
                        }
                    }, 200);
                }
            });
        }

        // Alternar Modo Tela Cheia
        $('body').off('click', '#toggle-fullscreen').on('click', '#toggle-fullscreen', function() {
            var $wrapper = $('.workspace-wrapper');
            var $icon = $(this).find('i');
            $wrapper.toggleClass('fullscreen-mode');

            if ($wrapper.hasClass('fullscreen-mode')) {
                $icon.removeClass('fa-expand').addClass('fa-compress');
                $('body').css('overflow', 'hidden');
            } else {
                $icon.removeClass('fa-compress').addClass('fa-expand');
                $('body').css('overflow', '');
            }
            setTimeout(function() { editor.resize(); editor.focus(); }, 150);
        });

        // --- SISTEMA DE MODAIS CUSTOMIZADOS ---
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

        // --- RENDERIZAÇÃO DA ÁRVORE DINÂMICA ---
        
        var renderTree = function() {
            $('.project-group').each(function() {
                var $project = $(this), $fileList = $project.find('.file-list'), files = [];
                
                $fileList.find('.file-item').each(function() {
                    var $el = $(this), $link = $el.find('.load-file');
                    files.push({ id: $link.data('id'), name: $link.text().trim(), html: $el[0].outerHTML });
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

                var buildHtml = function(obj, currentPath) {
                    currentPath = currentPath || '';
                    var html = '', keys = Object.keys(obj).sort((a, b) => (obj[b]._isDir||0) - (obj[a]._isDir||0) || a.localeCompare(b));
                    
                    keys.forEach(key => {
                        if (key === '_isDir' || key === '_children') return;
                        if (obj[key]._isDir) {
                            var fullFolderPath = currentPath + key + '/';
                            html += '<li class="folder-item">' +
                                        '<div class="folder-title" data-path="' + fullFolderPath + '">' +
                                            '<i class="icon fa-folder fa-fw"></i> ' + key + 
                                            '<span class="folder-context-actions">' +
                                                '<i class="fa fa-plus-square ctx-add-file" title="Novo Arquivo"></i>' +
                                                '<i class="fa fa-plus-circle ctx-add-folder" title="Nova Pasta"></i>' +
                                                '<i class="fa fa-trash ctx-delete-folder" title="Excluir Pasta" style="color:#f44336; margin-left:4px;"></i>' +
                                            '</span>' +
                                        '</div>' +
                                        '<ul class="folder-content" style="display:block;">' + buildHtml(obj[key]._children, fullFolderPath) + '</ul>' +
                                    '</li>';
                        } else {
                            if (key === '.placeholder' || obj[key].name.endsWith('.placeholder')) return;
                            var $itemHtml = $(obj[key].html);
                            $itemHtml.find('.load-file').text(key); 
                            html += $itemHtml[0].outerHTML;
                        }
                    });
                    return html;
                };
                $fileList.append(buildHtml(structure));
            });
        };
        renderTree();

        // --- MANIPULADORES DE EVENTOS ---

        // Novo Arquivo na Raiz
        $('body').off('click', '.add-file-to-project').on('click', '.add-file-to-project', function() {
            var projectId = $(this).data('id');
            wspPrompt("Novo arquivo na raiz", '', function(name) {
                $.post(wspVars.addFileUrl, { project_id: projectId, name: name }, function(r) {
                    if (r.success) seamlessRefresh(r.file_id); else alert(r.error);
                }, 'json');
            });
        });

        // Nova Pasta na Raiz
        $('body').off('click', '.add-folder-to-project').on('click', '.add-folder-to-project', function() {
            var projectId = $(this).data('id');
            wspPrompt("Nome da pasta na raiz", '', function(name) {
                $.post(wspVars.addFileUrl, { project_id: projectId, name: name + '/.placeholder' }, function(r) {
                    if (r.success) seamlessRefresh(); else alert(r.error);
                }, 'json');
            });
        });

        // Criar Arquivo em Pasta
        $('body').off('click', '.ctx-add-file').on('click', '.ctx-add-file', function(e) {
            e.stopPropagation();
            var $folder = $(this).closest('.folder-title'), path = $folder.data('path');
            var projectId = $(this).closest('.project-group').data('project-id');
            wspPrompt("Novo arquivo em " + path, '', function(name) {
                $.post(wspVars.addFileUrl, { project_id: projectId, name: path + name }, function(r) {
                    if (r.success) seamlessRefresh(r.file_id); else alert(r.error);
                }, 'json');
            });
        });

        // Criar Subpasta
        $('body').off('click', '.ctx-add-folder').on('click', '.ctx-add-folder', function(e) {
            e.stopPropagation();
            var $folder = $(this).closest('.folder-title'), path = $folder.data('path');
            var projectId = $(this).closest('.project-group').data('project-id');
            wspPrompt("Nova subpasta em " + path, '', function(name) {
                $.post(wspVars.addFileUrl, { project_id: projectId, name: path + name + '/.placeholder' }, function(r) {
                    if (r.success) seamlessRefresh(); else alert(r.error);
                }, 'json');
            });
        });

        // Excluir Pasta (Recursivo)
        $('body').off('click', '.ctx-delete-folder').on('click', '.ctx-delete-folder', function(e) {
            e.stopPropagation();
            var $folderTitle = $(this).closest('.folder-title'), path = $folderTitle.data('path');
            var projectId = $(this).closest('.project-group').data('project-id');
            wspConfirm("Excluir a pasta '" + path + "' e todo seu conteúdo?", function() {
                $.post(wspVars.deleteFolderUrl, { project_id: projectId, path: path }, function(r) {
                    if (r.success) {
                        if (activeFileId && $('#current-file').text().startsWith(path)) {
                            activeFileId = null; editor.setValue(wspVars.lang.welcome_msg, -1);
                            $('#current-file').text(wspVars.lang.select_file); $('#save-file').hide();
                        }
                        seamlessRefresh();
                    } else alert(r.error);
                }, 'json');
            });
        });

        // Gerar Changelog
        $('body').off('click', '.generate-project-changelog').on('click', '.generate-project-changelog', function(e) {
            e.preventDefault();
            var $btn = $(this), projectId = $btn.data('id'), originalHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin fa-fw"></i>');
            $.post(wspVars.changelogUrl, { project_id: projectId }, function(r) {
                if (r.success) seamlessRefresh(); else alert("Erro: " + r.error);
                $btn.html(originalHtml);
            }, 'json');
        });

        // Filtro Sidebar
        $('body').on('input', '#wsp-sidebar-filter', function() {
            var searchTerm = $(this).val().toLowerCase();
            if (searchTerm === "") { $('.file-item, .folder-item, .project-group').show(); return; }
            $('.project-group').each(function() {
                var $project = $(this), hasMatch = false;
                $project.find('.file-item').each(function() {
                    var $file = $(this), fileName = $file.find('.load-file').text().toLowerCase();
                    if (fileName.indexOf(searchTerm) > -1) {
                        $file.show(); hasMatch = true; $file.parents('.folder-content').show();
                        $file.parents('.folder-item').find('> .folder-title i').removeClass('fa-folder').addClass('fa-folder-open');
                    } else $file.hide();
                });
                if ($project.find('.project-name-row').text().toLowerCase().indexOf(searchTerm) > -1) {
                    hasMatch = true; $project.find('.file-item, .folder-item').show();
                }
                if (hasMatch) $project.show(); else $project.hide();
            });
        });

        // Toggle Pastas
        $('body').off('click', '.folder-title').on('click', '.folder-title', function() {
            $(this).next('.folder-content').slideToggle(200);
            $(this).find('i.icon').toggleClass('fa-folder fa-folder-open');
        });

        // Projetos e Arquivos
        $('body').off('click', '#add-project').on('click', '#add-project', function() {
            wspPrompt(wspVars.lang.prompt_name, '', name => $.post(wspVars.addUrl, { name: name }, () => seamlessRefresh()));
        });

        $('body').off('click', '.rename-file').on('click', '.rename-file', function() {
            var id = $(this).data('id'), oldName = $(this).data('name');
            wspPrompt(wspVars.lang.prompt_file_name, oldName, name => {
                if (name !== oldName) $.post(wspVars.renameUrl, { file_id: id, new_name: name }, () => seamlessRefresh());
            });
        });

        $('body').off('click', '.delete-file').on('click', '.delete-file', function() {
            var id = $(this).data('id');
            wspConfirm(wspVars.lang.confirm_file_delete, function() {
                $.post(wspVars.deleteFileUrl, { file_id: id }, function(){
                    if(activeFileId === id) {
                        activeFileId = null; editor.setValue(wspVars.lang.file_eliminated, -1);
                        $('#current-file').text(wspVars.lang.select_file); $('#save-file').hide();
                    }
                    seamlessRefresh();
                });
            });
        });

        $('body').off('click', '.delete-project').on('click', '.delete-project', function() {
            var id = $(this).data('id');
            wspConfirm(wspVars.lang.confirm_delete, () => $.post(wspVars.deleteUrl, { project_id: id }, () => seamlessRefresh()));
        });

        // Carregar e Salvar
        $('body').off('click', '.load-file').on('click', '.load-file', function(e) {
            e.preventDefault();
            activeFileId = $(this).data('id');
            $('.file-item').removeClass('active-file'); $(this).closest('.file-item').addClass('active-file');
            $('#current-file').text(wspVars.lang.loading).css('color', '#e2c08d');
            $.post(wspVars.loadUrl, { file_id: activeFileId, _nocache: new Date().getTime() }, function(r) {
                if (r.success) {
                    $('#current-file').text(r.name).css('color', '#569cd6');
                    editor.setReadOnly(false); editor.session.setMode(modes[r.type] || 'ace/mode/text');
                    editor.setValue(r.content, -1); originalContent = r.content; 
                    editor.resize(); editor.focus(); $('#save-file').fadeIn(300);
                }
            }, 'json');
        });

        $('body').off('click', '#save-file').on('click', '#save-file', function() {
            var $btn = $(this); if (!activeFileId || $btn.prop('disabled')) return;
            $btn.prop('disabled', true).html('<i class="icon fa-spinner fa-spin fa-fw"></i> ' + wspVars.lang.saving);
            var contentToSave = editor.getValue();
            $.post(wspVars.saveUrl, { file_id: activeFileId, content: contentToSave }, function(r) {
                if (r.success) {
                    originalContent = contentToSave; 
                    var $activeLink = $('.load-file[data-id="' + activeFileId + '"]');
                    if ($activeLink.text().startsWith('* ')) $activeLink.text($activeLink.text().substring(2)).css('font-style', 'normal');
                    $btn.html('<i class="icon fa-check fa-fw"></i> ' + wspVars.lang.saved).css('background', '#28a745');
                    setTimeout(() => { $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes).css('background', ''); }, 1500);
                } else {
                    alert("Erro ao salvar: " + r.error); $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes);
                }
            }, 'json');
        });

        // --- UPLOADS E DRAG & DROP ---
        var performUpload = function(file, projectId, customPath) {
            if (!file || !projectId) return;
            var formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', projectId);
            var path = customPath || file.webkitRelativePath || file.name;
            formData.append('full_path', path);
            $.ajax({
                url: wspVars.uploadUrl, type: 'POST', data: formData, contentType: false, processData: false,
                success: function(r) { if (r.success) seamlessRefresh(); }
            });
        };

        function traverseFileTree(item, path, projectId) {
            path = path || "";
            if (item.isFile) { item.file(function(file) { performUpload(file, projectId, path + file.name); }); } 
            else if (item.isDirectory) {
                var dirReader = item.createReader();
                dirReader.readEntries(function(entries) {
                    for (var i = 0; i < entries.length; i++) { traverseFileTree(entries[i], path + item.name + "/", projectId); }
                });
            }
        }

        $('body').off('click', '.trigger-upload').on('click', '.trigger-upload', function(e) {
            e.preventDefault(); uploadTargetProject = $(this).data('id');
            if ($('#wsp-single-file-upload').length === 0) { $('body').append('<input type="file" id="wsp-single-file-upload" multiple style="display:none;">'); }
            $('#wsp-single-file-upload').click(); 
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
            var projectId = $(this).closest('.project-group').data('project-id'), items = e.originalEvent.dataTransfer.items;
            if (items) { for (var i = 0; i < items.length; i++) { var item = items[i].webkitGetAsEntry(); if (item) traverseFileTree(item, "", projectId); } }
        });

        // --- FERRAMENTAS ---
        $('body').off('click', '.open-search-replace').on('click', '.open-search-replace', function() {
            $('#search-project-id').val($(this).data('id')); $('#search-replace-modal').fadeIn(200);
        });

        $('body').off('click', '#exec-replace-btn').on('click', '#exec-replace-btn', function() {
            var data = { project_id: $('#search-project-id').val(), file_id: activeFileId || 0, search: $('#wsp-search-term').val(), replace: $('#wsp-replace-term').val() };
            if (!data.search) return;
            wspConfirm(wspVars.lang.confirm_replace_all, () => $.post(wspVars.replaceUrl, data, () => { seamlessRefresh(); $('#search-replace-modal').hide(); }));
        });

        $('body').off('click', '#open-diff-tool').on('click', '#open-diff-tool', function() {
            var $orig = $('#diff-original').empty(), $mod = $('#diff-modified').empty();
            $('.load-file').each(function() {
                var id = $(this).data('id'), name = $(this).text().trim(), opt = $('<option>').val(id).text(name);
                $orig.append(opt.clone()); $mod.append(opt);
            });
            $('#diff-modal').fadeIn(200);
        });

        $('body').off('click', '#generate-diff-btn').on('click', '#generate-diff-btn', function() {
            $.post(wspVars.diffUrl, { original_id: $('#diff-original').val(), modified_id: $('#diff-modified').val(), filename: $('#diff-original option:selected').text() }, function(r) {
                if (r.success) {
                    $('#diff-modal').hide(); editor.setReadOnly(false); editor.session.setMode("ace/mode/diff"); 
                    editor.setValue(r.bbcode, -1); $('#copy-bbcode').show(); $('#save-file').hide();
                }
            }, 'json');
        });

        $('body').off('click', '#copy-bbcode').on('click', '#copy-bbcode', function() {
            var bbcodeText = editor.getValue(), $btn = $(this), originalHtml = $btn.html();
            navigator.clipboard.writeText(bbcodeText).then(() => {
                $btn.html('<i class="icon fa-check fa-fw"></i> Copiado').css('background', '#28a745');
                setTimeout(() => $btn.html(originalHtml).css('background', ''), 2000);
            });
        });

    }); // Fim do window.on('load')
};

(function checkDependencies() {
    if (typeof jQuery !== 'undefined' && typeof wspVars !== 'undefined') initMundoPhpBBWorkspace(jQuery);
    else setTimeout(checkDependencies, 50);
})();