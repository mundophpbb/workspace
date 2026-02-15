/**
 * Mundo phpBB Workspace - Colorizador de Diff (Estilo Patch Wizard)
 * Versão consolidada para renderização em tópicos
 */
(function($) {
    'use strict';
    $(document).ready(function() {
        $('.diff-content').each(function() {
            var $el = $(this);
            // Pegamos o texto puro para evitar conflitos de renderizações anteriores
            var lines = $el.text().split('\n');
            
            var coloredLines = lines.map(function(line) {
                // Remove espaços fantasmas de retorno de carro
                var cleanLine = line.replace(/\r/g, "");

                // 1. Cabeçalhos (---, +++, Index:) - Destaque de arquivo
                if (cleanLine.indexOf('---') === 0 || cleanLine.indexOf('+++') === 0 || cleanLine.indexOf('Index:') === 0) {
                    return '<span class="diff-patch-header">' + line + '</span>';
                } 
                // 2. Linhas adicionadas (+)
                else if (cleanLine.indexOf('+') === 0) {
                    return '<span class="diff-line-add">' + line + '</span>';
                } 
                // 3. Linhas removidas (-)
                else if (cleanLine.indexOf('-') === 0) {
                    return '<span class="diff-line-del">' + line + '</span>';
                } 
                // 4. Fragmentos de contexto (@@)
                else if (cleanLine.indexOf('@@') === 0) {
                    return '<span class="diff-line-info">' + line + '</span>';
                }
                
                // 5. Linhas de contexto ou vazias
                // O span vazio é necessário para manter o preenchimento de cor na linha
                return '<span>' + (line.trim() === '' ? '&nbsp;' : line) + '</span>';
            });

            // Injetamos o HTML e forçamos a limpeza de espaços em branco do container
            $el.html(coloredLines.join(''));
        });
    });
})(jQuery);