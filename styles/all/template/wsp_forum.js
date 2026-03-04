/**
 * Mundo phpBB Workspace - Colorizador de Diff (Versão GH-BOX)
 * Atualização: Blindagem contra erro "jQuery is not defined" + parsing seguro (text-only)
 */
(function checkjQuery() {
    // Verifica se a biblioteca jQuery está carregada na memória do navegador
    if (window.jQuery) {

        (function($) {
            'use strict';

            $(document).ready(function() {
                // Seleciona todos os blocos de conteúdo diff no fórum
                $('.diff-content').each(function() {
                    var $el = $(this);

                    /**
                     * 1) Pegue o conteúdo como TEXTO (seguro)
                     * - Evita depender de html entities
                     * - Evita quebrar caso phpBB injete tags
                     */
                    var rawText = $el.text() || '';

                    // Normaliza finais de linha (Windows/Mac)
                    rawText = rawText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

                    // 2) Divide o texto em linhas
                    var lines = rawText.split('\n');

                    // 3) Processa cada linha para aplicar as cores
                    var coloredLines = lines.map(function(line) {
                        var cleanLine = (line || '');

                        // Linha vazia (mantém altura)
                        if (cleanLine.trim() === '') {
                            return '<span class="diff-line-context">&nbsp;</span>';
                        }

                        // Headers comuns do unified diff
                        // --- a/file / +++ b/file
                        if (cleanLine.indexOf('--- ') === 0 || cleanLine.indexOf('+++ ') === 0) {
                            return '<span class="diff-line-info">' + escapeHtml(cleanLine) + '</span>';
                        }

                        // Linha de Informação (@@)
                        if (cleanLine.indexOf('@@') === 0) {
                            return '<span class="diff-line-info">' + escapeHtml(cleanLine) + '</span>';
                        }

                        // Linha Adicionada (+) - mas evita confundir com "+++"
                        if (cleanLine.charAt(0) === '+' && cleanLine.indexOf('+++ ') !== 0) {
                            return '<span class="diff-line-add">' + escapeHtml(cleanLine) + '</span>';
                        }

                        // Linha Removida (-) - mas evita confundir com "---"
                        if (cleanLine.charAt(0) === '-' && cleanLine.indexOf('--- ') !== 0) {
                            return '<span class="diff-line-del">' + escapeHtml(cleanLine) + '</span>';
                        }

                        // Linha Neutra (Contexto)
                        return '<span class="diff-line-context">' + escapeHtml(cleanLine) + '</span>';
                    });

                    // 4) Injeta o HTML processado mantendo as quebras de linha
                    $el.html(coloredLines.join('\n'));
                });

                // Escape básico para HTML (evita XSS e tags acidentais)
                function escapeHtml(str) {
                    return String(str)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }
            });

        })(window.jQuery);

    } else {
        // Se o jQuery não estiver pronto, tenta novamente em 50ms
        setTimeout(checkjQuery, 50);
    }
})();