/**
 * Mundo phpBB Workspace - Core & State
 * Versão 4.2: Fix de Autocompletar, Motor i18n Blindado & Conformidade CDB
 * Centraliza o estado global, configurações do editor e motor de internacionalização.
 */
(function (window, $) {
    'use strict';

    // Fallback de segurança para evitar quebras em páginas comuns do fórum
    if (typeof window.wspVars === 'undefined' || !window.wspVars) {
        window.wspVars = {
            basePath: '',
            allowedExt: '',
            activeProjectId: 0,
            lang: {} // As chaves virão do arquivo de linguagem do phpBB via JSON
        };
    }

    window.WSP = {
        activeFileId: null,
        activeProjectId: null,
        activeFolderPath: '', // Armazena a pasta selecionada na árvore
        originalContent: "",
        editor: null,
        allowedExtensions: [],

        /**
         * MOTOR DE TRADUÇÃO (i18n)
         * @param {string} key - A chave de tradução (ex: 'WSP_WELCOME_MSG')
         * @param {object} replacements - Objeto com placeholders para substituição (opcional)
         * @returns {string} - Texto traduzido ou a chave entre colchetes se não encontrada
         */
        lang: function (key, replacements) {
            var str = (window.wspVars.lang && window.wspVars.lang[key]) ? window.wspVars.lang[key] : '[' + key + ']';
            
            if (replacements && typeof replacements === 'object') {
                for (var placeholder in replacements) {
                    // Substitui todas as ocorrências do placeholder (ex: %s ou %d)
                    str = str.split(placeholder).join(replacements[placeholder]);
                }
            }
            return str;
        },

        // MAPA UNIVERSAL DE LINGUAGENS (Mapeia Extensão -> Modo ACE)
        modes: {
            // Web & Front-end
            'php': 'ace/mode/php',
            'js': 'ace/mode/javascript',
            'ts': 'ace/mode/typescript',
            'css': 'ace/mode/css',
            'scss': 'ace/mode/scss',
            'sass': 'ace/mode/sass',
            'less': 'ace/mode/less',
            'html': 'ace/mode/html',
            'htm': 'ace/mode/html',
            'twig': 'ace/mode/twig',
            'svg': 'ace/mode/svg',
            // Dados & Documentação
            'json': 'ace/mode/json',
            'xml': 'ace/mode/xml',
            'yml': 'ace/mode/yaml',
            'yaml': 'ace/mode/yaml',
            'sql': 'ace/mode/sql',
            'md': 'ace/mode/markdown',
            'csv': 'ace/mode/text',
            // Programação Geral
            'c': 'ace/mode/c_cpp',
            'cpp': 'ace/mode/c_cpp',
            'h': 'ace/mode/c_cpp',
            'hpp': 'ace/mode/c_cpp',
            'cs': 'ace/mode/csharp',
            'java': 'ace/mode/java',
            'py': 'ace/mode/python',
            'rb': 'ace/mode/ruby',
            'lua': 'ace/mode/lua',
            'go': 'ace/mode/golang',
            'rs': 'ace/mode/rust',
            'kt': 'ace/mode/kotlin',
            'swift': 'ace/mode/swift',
            'dart': 'ace/mode/dart',
            'pl': 'ace/mode/perl',
            'r': 'ace/mode/r',
            'scala': 'ace/mode/scala',
            // Scripts & DevOps
            'sh': 'ace/mode/sh',
            'bash': 'ace/mode/sh',
            'ini': 'ace/mode/ini',
            'htaccess': 'ace/mode/apache_conf',
            'conf': 'ace/mode/text',
            'bat': 'ace/mode/batchfile',
            'ps1': 'ace/mode/powershell',
            'dockerfile': 'ace/mode/dockerfile',
            'makefile': 'ace/mode/makefile',
            // Especiais / Log
            'txt': 'ace/mode/text',
            'log': 'ace/mode/text',
            'diff': 'ace/mode/diff'
        },

        /**
         * Inicializa o Editor ACE com suporte a Autocomplete
         */
        initEditor: function () {
            if (typeof window.ace === 'undefined') return false;
            if (!document.getElementById('editor')) return false;

            // Se o editor já foi inicializado, não faz nada
            if (this.editor) return true;

            // Configura caminhos internos do Ace (vital para carregar os modos dinamicamente)
            if (window.wspVars.basePath) {
                ace.config.set("basePath", window.wspVars.basePath);
                ace.config.set("modePath", window.wspVars.basePath);
                ace.config.set("themePath", window.wspVars.basePath);
            }

            // ATIVA LANGUAGE TOOLS (Fix para o erro de "misspelled options")
            // Requer que o arquivo ext-language_tools.js tenha sido carregado no HTML
            if (typeof ace.require !== 'undefined') {
                try {
                    ace.require("ace/ext/language_tools");
                } catch(e) {
                    console.warn("WSP: Language tools extension not found.");
                }
            }

            this.editor = ace.edit("editor");
            this.editor.setTheme("ace/theme/monokai");
            
            this.editor.setOptions({
                fontSize: "14px",
                fontFamily: "Consolas, 'Courier New', monospace",
                showPrintMargin: false,
                displayIndentGuides: true,
                highlightActiveLine: true,
                behavioursEnabled: true,
                wrap: true,
                tabSize: 4,
                useSoftTabs: true,
                scrollPastEnd: 0.5,
                readOnly: true, // Começa travado até abrir um arquivo
                // Opções que agora serão reconhecidas pelo Ace:
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                enableSnippets: true
            });

            this.editor.session.setUseWorker(false);

            // Carrega whitelist dinâmica vinda do PHP
            this.allowedExtensions = this.parseAllowedExtensions(window.wspVars.allowedExt);

            // Sincroniza ID do Projeto Ativo
            this.activeProjectId = this.normalizeProjectId(window.wspVars.activeProjectId);

            // Ajuste de tamanho automático ao redimensionar janela
            var self = this;
            window.addEventListener('resize', function() {
                if (self.editor) self.editor.resize();
            });

            this.updateUIState();
            return true;
        },

        /**
         * Normaliza o ID do projeto
         */
        normalizeProjectId: function (value) {
            if (!value || value === '0' || value === 0) return null;
            return parseInt(value, 10);
        },

        /**
         * Transforma a string de extensões do PHP em Array
         */
        parseAllowedExtensions: function (str) {
            if (!str || typeof str !== 'string') return [];
            return str.split(',').map(s => s.trim().toLowerCase()).filter(s => s.length > 0);
        },

        /**
         * Validação de segurança no Frontend
         */
        isExtensionAllowed: function (filename) {
            if (!filename) return false;
            const name = filename.trim();
            const lowerName = name.toLowerCase();

            // Arquivos técnicos sempre permitidos
            if (lowerName === '.placeholder' || lowerName === '.htaccess' || lowerName === 'changelog.txt' || 
                name === 'Dockerfile' || name === 'Makefile' || lowerName === 'dockerfile' || lowerName === 'makefile') {
                return true;
            }

            const parts = name.split('.');
            if (parts.length === 1) return true; // LICENSE, README, etc.

            const ext = parts.pop().toLowerCase();
            return this.allowedExtensions.indexOf(ext) !== -1;
        },

        /**
         * Gerencia o estado visual da Toolbar e do Editor
         */
        updateUIState: function () {
            const hasProject = !!this.activeProjectId;
            const hasFile = !!this.activeFileId;

            const $toolbarActions = $('.actions-active');
            const $saveBtn = $('#save-file');
            const $bbcodeBtn = $('#copy-bbcode');
            const $currentFileLabel = $('#current-file');

            if (!hasProject) {
                // Estado: Nada aberto (Usa classes CSS para performance)
                $toolbarActions.removeClass('is-enabled').addClass('is-disabled');
                
                if (this.editor) {
                    this.editor.setReadOnly(true);
                    this.editor.setValue(this.lang('WSP_WELCOME_MSG'), -1);
                }
                
                $saveBtn.hide();
                $bbcodeBtn.hide();
                $currentFileLabel.text(this.lang('WSP_SELECT_FILE'));
            } else {
                // Estado: Projeto Ativo
                $toolbarActions.removeClass('is-disabled').addClass('is-enabled');
                
                if (!hasFile) {
                    if (this.editor) {
                        this.editor.setReadOnly(true);
                        // Mensagem específica para quando o projeto está aberto mas sem arquivo selecionado
                        this.editor.setValue(this.lang('WSP_EDITOR_START_MSG'), -1);
                    }
                    $saveBtn.hide();
                    $bbcodeBtn.hide();
                } else {
                    if (this.editor) this.editor.setReadOnly(false);
                }
            }
        }
    };

})(window, jQuery);