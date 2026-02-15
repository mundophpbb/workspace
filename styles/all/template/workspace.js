/**
 * Mundo phpBB Workspace - JS Principal (Versão Mega + Pastas e Edição em Massa)
 */

var initMundoPhpBBWorkspace = function($) {
    'use strict';

    $(window).on('load', function() {
        
        if (typeof ace === 'undefined') {
            console.error("Mundo phpBB Workspace: O motor Ace Editor não foi encontrado!");
            return;
        }

        ace.config.set("basePath", wspVars.basePath);

        var activeFileId = null;
        var uploadTargetProject = null; 
        
        var editor = ace.edit("editor");
        editor.setTheme("ace/theme/monokai");
        editor.session.setMode("ace/mode/php");
        editor.setShowPrintMargin(false);
        editor.setReadOnly(true);
        editor.session.setUseWorker(false);

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

        // --- FUNÇÃO CENTRAL DE UPLOAD (Suporta Pastas) ---
        var performUpload = function(file, projectId) {
            if (!file || !projectId) return;

            var formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', projectId);
            
            // Captura o caminho relativo para recriar pastas no servidor
            var path = file.webkitRelativePath || file.name;
            formData.append('full_path', path);

            $('#current-file').text('Uploading: ' + path).css('color', '#e2c08d');

            $.ajax({
                url: wspVars.uploadUrl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(r) {
                    if (r.success) {
                        // Fazemos reload para atualizar a árvore após o upload
                        location.reload();
                    } else {
                        console.error(r.error);
                        $('#current-file').text(wspVars.lang.select_file).css('color', '');
                    }
                }
            });
        };

        // --- FUNÇÃO PARA PERCORRER PASTAS (DRAG & DROP) ---
        function traverseFileTree(item, path, projectId) {
            path = path || "";
            if (item.isFile) {
                item.file(function(file) {
                    performUpload(file, projectId);
                });
            } else if (item.isDirectory) {
                var dirReader = item.createReader();
                dirReader.readEntries(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        traverseFileTree(entries[i], path + item.name + "/", projectId);
                    }
                });
            }
        }

        // --- RENDERIZAÇÃO DE ÁRVORE ---
        var renderTree = function() {
            $('.project-group').each(function() {
                var $project = $(this), $fileList = $project.find('.file-list'), files = [];
                $fileList.find('.file-item').each(function() {
                    var $el = $(this);
                    files.push({
                        id: $el.find('.load-file').data('id'),
                        name: $el.find('.load-file').text().trim(),
                        html: $el[0].outerHTML
                    });
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

        $(document).on('click', '.folder-title', function() {
            $(this).next('.folder-content').slideToggle(200);
            $(this).find('i').toggleClass('fa-folder fa-folder-open');
        });

        // --- PROCURAR E SUBSTITUIR ---
        $(document).on('click', '.open-search-replace', function() {
            var projectId = $(this).data('id');
            var projectName = $(this).data('name');
            $('#search-project-id').val(projectId);
            $('#search-project-name').text('Projeto: ' + projectName);
            $('#wsp-search-term').val('');
            $('#wsp-replace-term').val('');
            $('#search-replace-modal').fadeIn(200);
        });

        $(document).on('click', '#exec-replace-btn', function() {
            var $btn = $(this);
            var data = {
                project_id: $('#search-project-id').val(),
                search: $('#wsp-search-term').val(),
                replace: $('#wsp-replace-term').val()
            };

            if (!data.search) { alert('Digite o termo de busca.'); return; }
            if (!confirm('Deseja realmente substituir em todo o projeto?')) return;

            $btn.prop('disabled', true).text('Processando...');

            $.post(wspVars.replaceUrl, data, function(r) {
                if (r.success) {
                    alert(r.updated + ' arquivos foram atualizados!');
                    location.reload();
                } else { alert('Erro: ' + r.error); }
            }, 'json').always(() => {
                $btn.prop('disabled', false).text('Substituir Tudo');
                $('#search-replace-modal').hide();
            });
        });

        // --- GATILHOS DE UPLOAD (Arraste e Botão) ---
        $(document).on('click', '.trigger-upload', function(e) {
            e.preventDefault();
            uploadTargetProject = $(this).data('id');
            $('#wsp-universal-upload').click();
        });

        $(document).on('change', '#wsp-universal-upload', function() {
            var files = this.files;
            if (files.length > 0 && uploadTargetProject) {
                for (var i = 0; i < files.length; i++) {
                    performUpload(files[i], uploadTargetProject);
                }
            }
            $(this).val('');
        });

        $(document).on('dragover', '.project-title', function(e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        });

        $(document).on('dragleave', '.project-title', function() { $(this).removeClass('drag-over'); });

        $(document).on('drop', '.project-title', function(e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            var projectId = $(this).find('.open-search-replace').data('id');
            var items = e.originalEvent.dataTransfer.items;

            if (items) {
                for (var i = 0; i < items.length; i++) {
                    var item = items[i].webkitGetAsEntry();
                    if (item) traverseFileTree(item, "", projectId);
                }
            }
        });

        // --- CARREGAMENTO E SALVAMENTO ---
        $(document).on('click', '.load-file', function(e) {
            e.preventDefault();
            activeFileId = $(this).data('id');
            $('.file-item').removeClass('active-file');
            $(this).closest('.file-item').addClass('active-file');

            $('#current-file').text(wspVars.lang.loading).css('color', '#e2c08d');
            $.post(wspVars.loadUrl, { file_id: activeFileId }, function(r) {
                if (r.success) {
                    $('#current-file').text(r.name).css('color', '#569cd6');
                    editor.setReadOnly(false);
                    editor.session.setMode(modes[r.type] || 'ace/mode/text');
                    editor.setValue(r.content, -1);
                    $('#save-file').fadeIn(300);
                    $('#copy-bbcode').hide();
                }
            }, 'json');
        });

        $(document).on('click', '#save-file', function() {
            var $btn = $(this);
            if (!activeFileId || $btn.prop('disabled')) return;
            $btn.prop('disabled', true).html('<i class="icon fa-spinner fa-spin fa-fw"></i> ' + wspVars.lang.saving);
            $.post(wspVars.saveUrl, { file_id: activeFileId, content: editor.getValue() }, function(r) {
                if (r.success) {
                    $btn.html('<i class="icon fa-check fa-fw"></i> ' + wspVars.lang.saved).css('background', '#28a745');
                    setTimeout(() => { $btn.prop('disabled', false).html('<i class="icon fa-save fa-fw"></i> ' + wspVars.lang.save_changes).css('background', ''); }, 1500);
                }
            }, 'json');
        });

        // --- OUTRAS AÇÕES (Novo, Renomear, Deletar, Diff) ---
        $(document).on('click', '#open-diff-tool', function() {
            var $orig = $('#diff-original').empty(), $mod = $('#diff-modified').empty();
            $('.load-file').each(function() {
                var id = $(this).data('id'), name = $(this).text().trim(), opt = $('<option>').val(id).text(name);
                $orig.append(opt.clone()); $mod.append(opt);
            });
            $('#diff-modal').fadeIn(200);
        });

        $(document).on('click', '#generate-diff-btn', function() {
            var $btn = $(this); $btn.prop('disabled', true).text('...');
            $.post(wspVars.diffUrl, { original_id: $('#diff-original').val(), modified_id: $('#diff-modified').val(), filename: $('#diff-original option:selected').text() }, function(r) {
                if (r.success) {
                    $('#diff-modal').hide();
                    editor.setReadOnly(false);
                    editor.session.setMode("ace/mode/diff");
                    editor.setValue(r.bbcode, -1);
                    $('#current-file').text('BBCode Diff: ' + r.filename).css('color', '#4CAF50');
                    $('#save-file').hide(); $('#copy-bbcode').fadeIn(300);
                } else alert(r.error);
            }, 'json').always(() => $btn.prop('disabled', false).text(wspVars.lang.diff_generate));
        });

        $(document).on('click', '#add-project', function() {
            var name = prompt(wspVars.lang.prompt_name);
            if (name) $.post(wspVars.addUrl, { name: name }, () => location.reload());
        });

        $(document).on('click', '.add-file-to-project', function() {
            var name = prompt(wspVars.lang.prompt_file_name);
            if (name) $.post(wspVars.addFileUrl, { project_id: $(this).data('id'), name: name }, () => location.reload());
        });

        $(document).on('click', '.rename-file', function() {
            var id = $(this).data('id'), old = $(this).data('name'), name = prompt(wspVars.lang.prompt_file_name, old);
            if (name && name !== old) $.post(wspVars.renameUrl, { file_id: id, new_name: name }, r => r.success ? location.reload() : alert(r.error), 'json');
        });

        $(document).on('click', '.delete-file', function() {
            if (confirm(wspVars.lang.confirm_file_delete)) $.post(wspVars.deleteFileUrl, { file_id: $(this).data('id') }, () => location.reload());
        });

        $(document).on('click', '.delete-project', function() {
            if (confirm(wspVars.lang.confirm_delete)) $.post(wspVars.deleteUrl, { project_id: $(this).data('id') }, () => location.reload());
        });
    });
};

(function checkDependencies() {
    if (typeof jQuery !== 'undefined' && typeof wspVars !== 'undefined') initMundoPhpBBWorkspace(jQuery);
    else setTimeout(checkDependencies, 50);
})();