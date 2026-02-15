/**
 * Mundo phpBB Workspace - Colorizador de Diff (Versão GH-BOX)
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Alinhado com a classe que você enviou no bbcode_diff.html
        $('.diff-content').each(function() {
            var $el = $(this);
            
            // 1. Decodifica o HTML (Transforma &gt; em >)
            var rawContent = $el.html()
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&amp;/g, '&')
                .replace(/&quot;/g, '"');

            var lines = rawContent.split('\n');
            
            var coloredLines = lines.map(function(line) {
                // Remove quebras de linha fantasmas
                var cleanLine = line.replace(/\r/g, "");

                // Verifica o sinal no início da linha (baseado no seu diff_logic.js)
                // Usamos .startsWith ou indexOf para precisão
                if (cleanLine.indexOf('+') === 0) {
                    return '<span class="diff-line-add">' + cleanLine + '</span>';
                } 
                else if (cleanLine.indexOf('-') === 0) {
                    return '<span class="diff-line-del">' + cleanLine + '</span>';
                } 
                else if (cleanLine.indexOf('@@') === 0) {
                    return '<span class="diff-line-info">' + cleanLine + '</span>';
                }
                
                // Linhas neutras
                return '<span>' + (cleanLine.trim() === '' ? '&nbsp;' : cleanLine) + '</span>';
            });

            // Injeta o HTML processado
            $el.html(coloredLines.join(''));
        });
    });
})(jQuery);