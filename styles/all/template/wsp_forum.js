/**
 * Mundo phpBB Workspace - Colorizador de Diff (Versão GH-BOX)
 * Atualização: Blindagem contra erro "jQuery is not defined"
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
                    
                    // 1. Decodifica o HTML básico injetado pelo phpBB
                    var rawContent = $el.html()
                        .replace(/&lt;/g, '<')
                        .replace(/&gt;/g, '>')
                        .replace(/&amp;/g, '&')
                        .replace(/&quot;/g, '"');

                    // 2. Divide o texto em linhas
                    var lines = rawContent.split(/\n/);
                    
                    // 3. Processa cada linha para aplicar as cores
                    var coloredLines = lines.map(function(line) {
                        var cleanLine = line.replace(/\r/g, ""); // Limpa carriage return

                        // Linha Adicionada (+)
                        if (cleanLine.indexOf('+') === 0) {
                            return '<span class="diff-line-add">' + cleanLine + '</span>';
                        } 
                        // Linha Removida (-)
                        else if (cleanLine.indexOf('-') === 0) {
                            return '<span class="diff-line-del">' + cleanLine + '</span>';
                        } 
                        // Linha de Informação (@@)
                        else if (cleanLine.indexOf('@@') === 0) {
                            return '<span class="diff-line-info">' + cleanLine + '</span>';
                        }
                        
                        // Linha Neutra (Contexto)
                        return '<span class="diff-line-context">' + (cleanLine.trim() === '' ? '&nbsp;' : cleanLine) + '</span>';
                    });

                    // 4. Injeta o HTML processado mantendo as quebras de linha
                    // O separador \n dentro de um <pre> ou div com white-space: pre garante a visualização correta
                    $el.html(coloredLines.join('\n'));
                });
            });

        })(window.jQuery);

    } else {
        // Se o jQuery não estiver pronto, tenta novamente em 50ms
        setTimeout(checkjQuery, 50);
    }
})();